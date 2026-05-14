import { api } from './api';

/**
 * Attach the original CSV bytes to a csv_import_history row as a polymorphic
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
 *   });
 *
 * Never throws — audit-write is a nicety, not a critical path. Reuses the
 * generic evidence-upload flow already used by AP bills, invoices, and
 * time bundles.
 */
export async function attachCsvToImportRun({ importRunId, csvText, fileName, entity }) {
  if (!importRunId || !csvText) return null;
  const name = fileName || `${entity || 'import'}-${importRunId}.csv`;

  try {
    // Step 1: presigned upload URL.
    const presign = await api.post('/api/evidence_upload_url.php', {
      subject_type: 'csv_import_run',
      subject_id:   importRunId,
      filename:     name,
      content_type: 'text/csv',
    });

    // Step 2: upload bytes direct to S3 (or local-driver shim).
    if (presign.upload?.url) {
      const blob = new Blob([csvText], { type: 'text/csv' });
      const form = new FormData();
      Object.entries(presign.upload.fields || {}).forEach(([k, v]) => form.append(k, v));
      form.append('file', blob, name);
      const up = await fetch(presign.upload.url, { method: 'POST', body: form, credentials: 'omit' });
      if (!up.ok) throw new Error(`upload failed: ${up.status}`);
    }
    // LocalDriver returns no upload.url — the storage_key IS the path.

    // Step 3: register metadata.
    return await api.post('/api/accounting/evidence.php', {
      subject_type:  'csv_import_run',
      subject_id:    importRunId,
      document_type: 'csv_source',
      label:         name,
      storage_key:   presign.storage_key,
      content_type:  'text/csv',
      size_bytes:    new Blob([csvText]).size,
      source:        'csv_import_auto_attach',
    });
  } catch (e) {
    // Never propagate — the import itself already succeeded.
    if (typeof console !== 'undefined') {
      console.warn('[csv-audit-attach] failed:', e?.message || e);
    }
    return null;
  }
}
