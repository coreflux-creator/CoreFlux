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
const { reserveCsvImportAudit, finalizeCsvImportAudit } = module.exports;

test('reservation fails closed without a persisted ID', async () => {
  globalThis.csvAuditTestApi = { post: async () => ({ recorded: true, id: null }) };
  await assert.rejects(reserveCsvImportAudit({
    entity: 'billing_invoices', fileName: 'sample.csv', csvText: 'a,b\n1,2',
    preview: { row_count: 1 }, skipInvalid: false, updateExisting: false,
  }), /No import was started/);
});

test('finalization preserves document and line counts, then archives', async () => {
  const calls = [];
  globalThis.csvAuditTestApi = {
    post: async (path, body) => {
      calls.push({ path, body });
      return path.includes('reserve')
        ? { recorded: true, id: 42 }
        : { recorded: true, id: 42, status: 'success' };
    },
  };
  globalThis.csvAuditTestAttach = async payload => {
    calls.push({ attachment: payload });
    return { csv: { id: 11 } };
  };
  const runId = await reserveCsvImportAudit({
    entity: 'billing_invoices', fileName: 'sample.csv', csvText: 'a,b\n1,2',
    preview: { row_count: 3 }, skipInvalid: false, updateExisting: false,
  });
  const receipt = await finalizeCsvImportAudit({
    runId, result: { row_count: 3, imported_count: 1, skipped_count: 0,
      imported_row_count: 3, skipped_row_count: 0, errors: {} },
    startedAt: Date.now(), csvText: 'a,b\n1,2', fileName: 'sample.csv',
    entity: 'billing_invoices', columnMap: null,
  });
  assert.equal(receipt.id, 42);
  assert.equal(receipt.sourceAttached, true);
  assert.equal(calls[0].body.rows_total, 3);
  assert.equal(calls[1].body.rows_imported, 3);
  assert.equal(calls[1].body.rows_skipped, 0);
  assert.equal(calls[2].attachment.importRunId, 42);
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

test('failed source archive is reported separately from saved history', async () => {
  globalThis.csvAuditTestApi = { post: async () => ({ recorded: true, id: 42 }) };
  globalThis.csvAuditTestAttach = async () => ({ csv: null });
  const receipt = await finalizeCsvImportAudit({
    runId: 42, result: { imported_count: 1 }, startedAt: Date.now(),
    csvText: 'a,b\n1,2', fileName: 'sample.csv', entity: 'ap_bills',
  });
  assert.equal(receipt.sourceAttached, false);
});
