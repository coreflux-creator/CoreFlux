import { api } from './api';
import { attachCsvToImportRun } from './csvAuditAttach';

const HISTORY = '/api/admin/csv_import_history.php';
const PENDING_KEY = 'coreaccounting.csv_import.pending.v1';
const FINANCE_ENTITIES = new Set([
  'ap_vendors', 'accounting_accounts', 'billing_items', 'ap_bills',
  'billing_invoices', 'ap_payments', 'billing_payments',
]);

function tabStorage() {
  try { return typeof window === 'undefined' ? null : window.sessionStorage; }
  catch { return null; }
}

export function pendingCsvImportRuns(storage = tabStorage()) {
  if (!storage) return [];
  try {
    const parsed = JSON.parse(storage.getItem(PENDING_KEY) || '[]');
    return Array.isArray(parsed) ? parsed.filter(run =>
      Number.isSafeInteger(run?.id) && run.id > 0 && FINANCE_ENTITIES.has(run.entity)
    ) : [];
  } catch { return []; }
}

export function rememberCsvImportRun({ id, entity }, storage = tabStorage()) {
  if (!storage || !Number.isSafeInteger(id) || id <= 0 || !FINANCE_ENTITIES.has(entity)) return;
  try {
    const runs = pendingCsvImportRuns(storage).filter(run => run.id !== id);
    storage.setItem(PENDING_KEY, JSON.stringify([...runs, { id, entity }]));
  } catch { /* Import History remains authoritative when tab storage is unavailable. */ }
}

export function forgetCsvImportRun(id, storage = tabStorage()) {
  if (!storage) return;
  try {
    const runs = pendingCsvImportRuns(storage).filter(run => run.id !== id);
    if (runs.length) storage.setItem(PENDING_KEY, JSON.stringify(runs));
    else storage.removeItem(PENDING_KEY);
  } catch { /* A storage failure cannot change the server receipt. */ }
}

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
    if (finalized.receipt_result && (Number(finalized.receipt_result.imported_count) !== Number(result?.imported_count)
      || Number(finalized.receipt_result.skipped_count) !== Number(result?.skipped_count))) {
      throw new Error('The document counts do not match the server receipt. Check Import History before retrying.');
    }
  }
  if (!postCommitSource) return { id: runId, sourceAttached: true };
  const attachment = await attachCsvToImportRun({ importRunId: runId, ...postCommitSource });
  return { id: runId, sourceAttached: !!attachment?.csv?.id };
}

export async function recoverCsvImportAudit({ runId }) {
  const receipt = await api.post(`${HISTORY}?action=finalize`, { id: runId });
  const imported = Number(receipt?.rows_imported);
  const skipped = Number(receipt?.rows_skipped);
  const total = Number(receipt?.rows_total);
  if (receipt?.recorded === true && receipt.id === runId && receipt.abandoned === true
    && receipt.server_outcome_recorded === false && receipt.status === 'failed'
    && imported === 0 && skipped === 0 && receipt.rows_total != null
    && Number.isSafeInteger(total) && total >= 0) {
    return { id: runId, result: { abandoned: true, imported_count: 0, skipped_count: 0,
      row_count: total, status: 'abandoned' } };
  }
  if (!receipt?.recorded || receipt.id !== runId || !receipt.server_outcome_recorded
    || !['success', 'partial', 'failed'].includes(receipt.status)
    || ![imported, skipped, total].every(value => Number.isSafeInteger(value) && value >= 0)
    || total < imported + skipped) {
    throw new Error('No verified server outcome is available. Check Import History and source records before retrying.');
  }
  const exact = receipt.receipt_result;
  if (exact != null) {
    const counts = ['imported_count', 'skipped_count', 'row_count', 'imported_row_count', 'skipped_row_count'];
    if (!exact || typeof exact !== 'object' || Array.isArray(exact)
      || !counts.every(key => Number.isSafeInteger(exact[key]) && exact[key] >= 0)
      || exact.row_count !== total || exact.imported_row_count !== imported
      || exact.skipped_row_count !== skipped || exact.ids == null
      || typeof exact.ids !== 'object'
      || !Object.values(exact.ids).every(id => Number.isSafeInteger(id) && id > 0)) {
      throw new Error('The server receipt details are inconsistent. Check Import History before retrying.');
    }
    return {
      id: runId,
      result: { ...exact, errors: {}, status: receipt.status, recovered_from_receipt: true },
    };
  }
  return {
    id: runId,
    result: {
      imported_count: imported,
      skipped_count: skipped,
      imported_row_count: imported,
      skipped_row_count: skipped,
      row_count: total,
      errors: {},
      status: receipt.status,
      recovered_from_receipt: true,
      recovered_counts_are_rows: true,
    },
  };
}

export async function abandonUnstartedCsvImportAudit({ runId }) {
  const resolution = await api.post(`${HISTORY}?action=abandon_unstarted`, { id: runId });
  if (resolution?.id !== runId || !(resolution.abandoned === true || resolution.already_recorded === true)) {
    throw new Error('The server did not confirm this run was closed. Check Import History before retrying.');
  }
  return resolution;
}
