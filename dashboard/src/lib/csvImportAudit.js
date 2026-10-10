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

export async function finalizeCsvImportAudit({ runId, result, startedAt, csvText, sourceFile, fileName, entity, columnMap }) {
  const finalized = await api.post(`${HISTORY}?action=finalize`, {
    id: runId,
    rows_total: result?.row_count ?? ((result?.imported_row_count ?? result?.imported_count ?? 0)
      + (result?.skipped_row_count ?? result?.skipped_count ?? 0)),
    rows_imported: result?.imported_row_count ?? result?.imported_count ?? 0,
    rows_skipped: result?.skipped_row_count ?? result?.skipped_count ?? 0,
    errors: result?.errors || {},
    duration_ms: Date.now() - startedAt,
  });
  if (!finalized?.recorded || finalized.id !== runId) {
    throw new Error('The import finished, but its history remains unconfirmed. Do not import the file again.');
  }
  const attachment = await attachCsvToImportRun({ importRunId: runId, csvText, sourceFile, fileName, entity, columnMap });
  return { id: runId, sourceAttached: !!attachment?.csv?.id };
}
