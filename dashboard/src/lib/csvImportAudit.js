import { api } from './api';
import { attachCsvToImportRun } from './csvAuditAttach';

const HISTORY = '/api/admin/csv_import_history.php';

export async function reserveCsvImportAudit({ entity, fileName, csvText, sourceFile, preview, skipInvalid, updateExisting, aiUsed = false, presetId = null, columnMap = null, extraFlags = null }) {
  const run = await api.post(`${HISTORY}?action=reserve`, {
    entity,
    file_name: fileName || null,
    bytes_processed: sourceFile?.size ?? new Blob([csvText]).size,
    rows_total: preview?.row_count || 0,
    skip_invalid: skipInvalid,
    update_existing: updateExisting,
    ai_used: aiUsed,
    preset_id: presetId,
    column_map: columnMap,
    ...(extraFlags ? { extra_flags: extraFlags } : {}),
  });
  if (!run?.recorded || !run?.id) throw new Error('Import history is unavailable. No import was started.');
  return run.id;
}

export async function archiveCsvImportAudit({ runId, csvText, sourceFile, fileName, entity, columnMap }) {
  const attachment = await attachCsvToImportRun({
    importRunId: runId, csvText, sourceFile, fileName, entity, columnMap,
  });
  if (!attachment?.csv?.id) {
    throw new Error('The source CSV could not be archived. No records were imported.');
  }
  return attachment;
}

export async function finalizeCsvImportAudit({ runId, result, startedAt, postCommitSource = null }) {
  const outcome = postCommitSource ? {
    id: runId,
    rows_total: result?.row_count ?? ((result?.imported_row_count ?? result?.imported_count ?? 0)
      + (result?.skipped_row_count ?? result?.skipped_count ?? 0)),
    rows_imported: result?.imported_row_count ?? result?.imported_count ?? 0,
    rows_skipped: result?.skipped_row_count ?? result?.skipped_count ?? 0,
    errors: result?.errors || {},
    duration_ms: Date.now() - startedAt,
  } : { id: runId };
  const finalized = await api.post(`${HISTORY}?action=finalize`, outcome);
  if (!finalized?.recorded || finalized.id !== runId) {
    throw new Error('The import finished, but its history remains unconfirmed. Do not import the file again.');
  }
  if (!postCommitSource) {
    const imported = result?.imported_row_count ?? result?.imported_count ?? 0;
    const skipped = result?.skipped_row_count ?? result?.skipped_count ?? 0;
    if (!finalized.server_outcome_recorded || Number(finalized.rows_imported) !== Number(imported)
      || Number(finalized.rows_skipped) !== Number(skipped)) {
      throw new Error('The server receipt does not match the import response. Check Import History before retrying.');
    }
  }
  if (!postCommitSource) return { id: runId, sourceAttached: true };
  const attachment = await attachCsvToImportRun({ importRunId: runId, ...postCommitSource });
  return { id: runId, sourceAttached: !!attachment?.csv?.id };
}
