import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const result = await build({
  entryPoints: [fileURLToPath(new URL('../src/lib/csvImportAudit.js', import.meta.url))],
  bundle: true,
  platform: 'node',
  format: 'cjs',
  write: false,
  plugins: [{
    name: 'audit-test-doubles',
    setup(plugin) {
      plugin.onResolve({ filter: /^\.\/(api|csvAuditAttach)$/ }, args => ({
        path: args.path, namespace: 'audit-test',
      }));
      plugin.onLoad({ filter: /.*/, namespace: 'audit-test' }, args => ({
        contents: args.path === './api'
          ? 'export const api = { post: (...args) => globalThis.csvAuditTestApi.post(...args) };'
          : 'export const attachCsvToImportRun = (...args) => globalThis.csvAuditTestAttach(...args);',
        loader: 'js',
      }));
    },
  }],
});
const module = { exports: {} };
new Function('module', 'exports', result.outputFiles[0].text)(module, module.exports);
const {
  reserveCsvImportAudit, archiveCsvImportAudit, finalizeCsvImportAudit, recoverCsvImportAudit,
  abandonUnstartedCsvImportAudit,
  pendingCsvImportRuns, rememberCsvImportRun, forgetCsvImportRun,
} = module.exports;

test('finance run IDs survive a tab reload without storing CSV contents', () => {
  const values = new Map();
  const storage = {
    getItem: key => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: key => values.delete(key),
  };
  rememberCsvImportRun({ id: 10, entity: 'ap_vendors' }, storage);
  rememberCsvImportRun({ id: 10, entity: 'ap_vendors' }, storage);
  rememberCsvImportRun({ id: 11, entity: 'billing_invoices' }, storage);
  rememberCsvImportRun({ id: 12, entity: 'people' }, storage);
  assert.deepEqual(pendingCsvImportRuns(storage), [
    { id: 10, entity: 'ap_vendors' },
    { id: 11, entity: 'billing_invoices' },
  ]);
  assert.doesNotMatch([...values.values()][0], /Vendor name|invoice amount|csv:/i);
  forgetCsvImportRun(10, storage);
  assert.deepEqual(pendingCsvImportRuns(storage), [{ id: 11, entity: 'billing_invoices' }]);
  forgetCsvImportRun(11, storage);
  assert.deepEqual(pendingCsvImportRuns(storage), []);
  assert.equal(values.size, 0);
  for (let id = 20; id <= 45; id += 1) {
    rememberCsvImportRun({ id, entity: 'ap_vendors' }, storage);
  }
  assert.equal(pendingCsvImportRuns(storage).length, 26);
});

test('malformed or inaccessible tab storage cannot invent a recoverable run', () => {
  const storage = {
    getItem: () => '[{"id":0,"entity":"ap_vendors"},{"id":2,"entity":"people"}]',
    setItem: () => { throw new Error('storage denied'); },
    removeItem: () => { throw new Error('storage denied'); },
  };
  assert.deepEqual(pendingCsvImportRuns(storage), []);
  assert.doesNotThrow(() => rememberCsvImportRun({ id: 3, entity: 'ap_vendors' }, storage));
  assert.doesNotThrow(() => forgetCsvImportRun(3, storage));
  assert.deepEqual(pendingCsvImportRuns({ getItem: () => '{' }), []);
});

test('reservation fails closed without a persisted ID', async () => {
  globalThis.csvAuditTestApi = { post: async () => ({ recorded: true, id: null }) };
  await assert.rejects(reserveCsvImportAudit({
    entity: 'billing_invoices', fileName: 'sample.csv', csvText: 'a,b\n1,2',
    preview: { row_count: 1 }, skipInvalid: false, updateExisting: false,
  }), /No import was started/);
});

test('source is archived after reservation and before result finalization', async () => {
  const calls = [];
  globalThis.csvAuditTestApi = {
    post: async (path, body) => {
      calls.push({ path, body });
      return path.includes('reserve')
        ? { recorded: true, id: 42 }
        : { recorded: true, id: 42, status: 'success',
          server_outcome_recorded: true, rows_imported: 3, rows_skipped: 0 };
    },
  };
  globalThis.csvAuditTestAttach = async payload => {
    calls.push({ attachment: payload });
    return { csv: { id: 11 } };
  };
  const runId = await reserveCsvImportAudit({
    entity: 'billing_invoices', fileName: 'sample.csv', csvText: 'a,b\n1,2',
    sourceFile: new Blob([new Uint8Array([0xef, 0xbb, 0xbf, 0x61, 0x2c, 0x62, 0x0a, 0x31, 0x2c, 0x32])]),
    preview: { row_count: 3 }, skipInvalid: false, updateExisting: false,
  });
  const sourceFile = new Blob([new Uint8Array([0xef, 0xbb, 0xbf, 0x61, 0x2c, 0x62, 0x0a, 0x31, 0x2c, 0x32])]);
  const attachment = await archiveCsvImportAudit({
    runId, csvText: 'a,b\n1,2', sourceFile, fileName: 'sample.csv', entity: 'billing_invoices',
  });
  const receipt = await finalizeCsvImportAudit({
    runId, result: { row_count: 3, imported_count: 1, skipped_count: 0,
      imported_row_count: 3, skipped_row_count: 0, errors: {} },
    startedAt: Date.now(),
  });
  assert.equal(receipt.id, 42);
  assert.equal(attachment.csv.id, 11);
  assert.equal(calls[0].body.rows_total, 3);
  assert.equal(calls[0].body.bytes_processed, 10);
  assert.equal(calls[1].attachment.importRunId, 42);
  assert.deepEqual(calls[2].body, { id: 42 });
});

test('standalone refuses missing or conflicting server counts', async () => {
  globalThis.csvAuditTestApi = { post: async () => ({
    recorded: true, id: 42, server_outcome_recorded: true,
    rows_imported: 0, rows_skipped: 1,
  }) };
  await assert.rejects(finalizeCsvImportAudit({
    runId: 42, result: { imported_count: 1, skipped_count: 0 }, startedAt: Date.now(),
  }), /does not match/);
  globalThis.csvAuditTestApi = { post: async () => ({ recorded: true, id: 42 }) };
  await assert.rejects(finalizeCsvImportAudit({
    runId: 42, result: { imported_count: 1, skipped_count: 0 }, startedAt: Date.now(),
  }), /does not match/);
});

test('lost response recovers only a finalized server-owned outcome', async () => {
  const calls = [];
  globalThis.csvAuditTestApi = { post: async (path, body) => {
    calls.push({ path, body });
    return { recorded: true, id: 42, server_outcome_recorded: true,
      rows_total: 3, rows_imported: 2, rows_skipped: 1, status: 'partial' };
  } };
  const receipt = await recoverCsvImportAudit({ runId: 42 });
  assert.match(calls[0].path, /csv_import_history\.php\?action=finalize$/);
  assert.deepEqual(calls[0].body, { id: 42 });
  assert.equal(receipt.result.imported_count, 2);
  assert.equal(receipt.result.skipped_count, 1);
  assert.equal(receipt.result.status, 'partial');
  assert.equal(receipt.result.recovered_from_receipt, true);
});

test('lost response cannot treat an unconfirmed or mismatched run as imported', async () => {
  globalThis.csvAuditTestApi = { post: async () => ({
    recorded: true, id: 42, rows_total: 1, rows_imported: 1, rows_skipped: 0, status: 'success',
  }) };
  await assert.rejects(recoverCsvImportAudit({ runId: 42 }), /No verified server outcome/);
  globalThis.csvAuditTestApi = { post: async () => ({
    recorded: true, id: 43, server_outcome_recorded: true,
    rows_total: 1, rows_imported: 1, rows_skipped: 0, status: 'success',
  }) };
  await assert.rejects(recoverCsvImportAudit({ runId: 42 }), /No verified server outcome/);
  globalThis.csvAuditTestApi = { post: async () => ({
    recorded: true, id: 42, server_outcome_recorded: true,
    rows_total: 1, rows_imported: 2, rows_skipped: 0, status: 'success',
  }) };
  await assert.rejects(recoverCsvImportAudit({ runId: 42 }), /No verified server outcome/);
  globalThis.csvAuditTestApi = { post: async () => { throw new Error('server outcome still unconfirmed'); } };
  await assert.rejects(recoverCsvImportAudit({ runId: 42 }), /still unconfirmed/);
});

test('a closed unstarted run survives another reload without pretending to be imported', async () => {
  const calls = [];
  globalThis.csvAuditTestApi = { post: async (path, body) => {
    calls.push({ path, body });
    return path.includes('abandon_unstarted')
      ? { id: 42, abandoned: true, already_recorded: false }
      : { id: 42, recorded: true, abandoned: true, server_outcome_recorded: false,
        rows_total: 1, rows_imported: 0, rows_skipped: 0, status: 'failed' };
  } };
  await abandonUnstartedCsvImportAudit({ runId: 42 });
  const closed = await recoverCsvImportAudit({ runId: 42 });
  assert.deepEqual(calls.map(call => call.body), [{ id: 42 }, { id: 42 }]);
  assert.equal(closed.result.abandoned, true);
  assert.equal(closed.result.imported_count, 0);
  assert.equal(closed.result.status, 'abandoned');
});

test('closure needs an exact server confirmation and never imports a file', async () => {
  globalThis.csvAuditTestApi = { post: async () => ({ id: 43, abandoned: true }) };
  await assert.rejects(abandonUnstartedCsvImportAudit({ runId: 42 }), /did not confirm/);
  globalThis.csvAuditTestApi = { post: async () => ({ id: 42, abandoned: false }) };
  await assert.rejects(abandonUnstartedCsvImportAudit({ runId: 42 }), /did not confirm/);
  globalThis.csvAuditTestApi = { post: async () => { const error = new Error('still needs review'); error.status = 409; throw error; } };
  await assert.rejects(abandonUnstartedCsvImportAudit({ runId: 42 }), /still needs review/);
});

test('a malformed closed-run receipt is not treated as a verified zero-row outcome', async () => {
  globalThis.csvAuditTestApi = { post: async () => ({
    id: 42, recorded: false, abandoned: true, server_outcome_recorded: false,
    status: 'failed', rows_total: null, rows_imported: 0, rows_skipped: 0,
  }) };
  await assert.rejects(recoverCsvImportAudit({ runId: 42 }), /No verified server outcome/);
});

test('failed finalization never attempts source attachment', async () => {
  let attachments = 0;
  globalThis.csvAuditTestApi = { post: async () => { throw new Error('history unavailable'); } };
  globalThis.csvAuditTestAttach = async () => { attachments += 1; };
  await assert.rejects(finalizeCsvImportAudit({
    runId: 42, result: { imported_count: 1 }, startedAt: Date.now(),
    csvText: 'a,b\n1,2', fileName: 'sample.csv', entity: 'ap_bills',
  }), /history unavailable/);
  assert.equal(attachments, 0);
});

test('failed source archive blocks the source commit', async () => {
  let finalized = 0;
  globalThis.csvAuditTestApi = { post: async () => { finalized += 1; return { recorded: true, id: 42 }; } };
  globalThis.csvAuditTestAttach = async () => ({ csv: null });
  await assert.rejects(archiveCsvImportAudit({
    runId: 42,
    csvText: 'a,b\n1,2', fileName: 'sample.csv', entity: 'ap_bills',
  }), /No records were imported/);
  assert.equal(finalized, 0);
});

test('the broader ERP can still attach source evidence after finalization', async () => {
  const calls = [];
  globalThis.csvAuditTestApi = { post: async () => {
    calls.push('finalize');
    return { recorded: true, id: 42 };
  } };
  globalThis.csvAuditTestAttach = async () => {
    calls.push('attach');
    return { csv: null };
  };
  const receipt = await finalizeCsvImportAudit({
    runId: 42, result: { imported_count: 1 }, startedAt: Date.now(),
    postCommitSource: { csvText: 'a,b\n1,2', fileName: 'sample.csv', entity: 'ap_bills' },
  });
  assert.deepEqual(calls, ['finalize', 'attach']);
  assert.equal(receipt.sourceAttached, false);
});
