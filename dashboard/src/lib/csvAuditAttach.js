import { api } from './api';

/**
 * Attach the selected source File to a csv_import_history row as a polymorphic
 * evidence_attachments record so auditors can download the EXACT input bytes
 * that produced a batch of records — not just the metadata about them.
 *
 * Called from both CsvImportPage (single-entity) and CsvBulkImport (wizard)
 * right after a successful csv_import_history POST returns the new row id.
 *
 *   await attachCsvToImportRun({
 *     importRunId: 42,
 *     csvText:     '<the bytes>',
 *     fileName:    'people_jan.csv',
 *     entity:      'people',           // for label only
 *     columnMap:   { 'First name': 'first_name', ... }, // optional
 *   });
 *
 * When `columnMap` is provided, a SECOND attachment is uploaded next to the
 * CSV with document_type='column_map' so auditors can see "here are the
 * input bytes AND here's exactly how we interpreted them" in one place.
 *
 * Returns a null attachment when storage fails; the caller must show that
 * source evidence is missing. Reuses the generic evidence-upload flow.
 */
export async function attachCsvToImportRun({ importRunId, csvText, sourceFile, fileName, entity, columnMap }) {
  if (!importRunId || (!sourceFile && !csvText)) return null;
  const baseName = fileName || `${entity || 'import'}-${importRunId}.csv`;

  const result = { csv: null, columnMap: null };
  try {
    result.csv = await _uploadEvidence({
      importRunId,
      bytes:         sourceFile || csvText,
      filename:      baseName,
      contentType:   'text/csv',
      documentType:  'csv_source',
    });
  } catch (e) {
    if (typeof console !== 'undefined') console.warn('[csv-audit-attach:csv] failed:', e?.message || e);
  }

  if (columnMap && Object.keys(columnMap).length > 0) {
    try {
      const mapName  = baseName.replace(/\.csv$/i, '') + '.mapping.json';
      const mapBody  = JSON.stringify({
        import_run_id: importRunId,
        entity:        entity || null,
        file_name:     fileName || null,
        column_map:    columnMap,
        captured_at:   new Date().toISOString(),
      }, null, 2);
      result.columnMap = await _uploadEvidence({
        importRunId,
        bytes:         mapBody,
        filename:      mapName,
        contentType:   'application/json',
        documentType:  'column_map',
      });
    } catch (e) {
      if (typeof console !== 'undefined') console.warn('[csv-audit-attach:map] failed:', e?.message || e);
    }
  }
  return result;
}

async function _uploadEvidence({ importRunId, bytes, filename, contentType, documentType }) {
  // Step 1: presigned upload URL.
  const presign = await api.post('/api/evidence_upload_url.php', {
    subject_type: 'csv_import_run',
    subject_id:   importRunId,
    filename,
    content_type: contentType,
  });

  // Step 2: upload bytes direct to S3 (or local-driver shim).
  const uploadUrl = presign.upload?.form_action || presign.upload?.url;
  if (!uploadUrl) throw new Error('Storage did not provide an upload destination');
  const blob = new Blob([bytes], { type: contentType });
  const form = new FormData();
  Object.entries(presign.upload.fields || {}).forEach(([k, v]) => form.append(k, v));
  form.append('file', blob, filename);
  const up = await fetch(uploadUrl, {
    method: 'POST', body: form,
    credentials: uploadUrl.startsWith('/') ? 'same-origin' : 'omit',
  });
  if (!up.ok) throw new Error(`upload failed: ${up.status}`);
  if (presign.driver === 'local') {
    const receipt = await up.json().catch(() => null);
    if (!receipt?.stored || receipt.storage_key !== presign.storage_key) {
      throw new Error('Local storage did not confirm the uploaded bytes');
    }
  }

  // Step 3: register metadata.
  const attached = await api.post('/api/accounting/evidence.php', {
    subject_type:  'csv_import_run',
    subject_id:    importRunId,
    document_type: documentType,
    label:         filename,
    storage_key:   presign.storage_key,
    content_type:  contentType,
    size_bytes:    blob.size,
    source:        'csv_import_auto_attach',
  });
  if (!attached?.id) throw new Error('Import evidence could not be registered');
  return attached;
}
