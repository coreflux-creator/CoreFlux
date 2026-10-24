import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const result = await build({
  entryPoints: [fileURLToPath(new URL('../src/lib/csvAuditAttach.js', import.meta.url))],
  bundle: true,
  platform: 'node',
  format: 'cjs',
  write: false,
  plugins: [{
    name: 'audit-attachment-test-api',
    setup(plugin) {
      plugin.onResolve({ filter: /^\.\/api$/ }, () => ({ path: 'api', namespace: 'audit-test' }));
      plugin.onLoad({ filter: /.*/, namespace: 'audit-test' }, () => ({
        contents: 'export const api = { post: (...args) => globalThis.csvAuditTestApi.post(...args) };',
        loader: 'js',
      }));
    },
  }],
});
const module = { exports: {} };
new Function('module', 'exports', result.outputFiles[0].text)(module, module.exports);
const { attachCsvToImportRun } = module.exports;

test('a missing local handler cannot create phantom CSV evidence', async () => {
  const originalFetch = globalThis.fetch;
  const originalWarn = console.warn;
  let evidenceWrites = 0;
  globalThis.csvAuditTestApi = {
    post: async path => {
      if (path.includes('evidence_upload_url')) {
        return { driver: 'local', storage_key: 'csv_imports/1/run/9/source.csv',
          upload: { form_action: '/api/storage/local/put.php', fields: {} } };
      }
      evidenceWrites += 1;
      return { id: 100 };
    },
  };
  globalThis.fetch = async () => ({
    ok: true, status: 200,
    json: async () => { throw new SyntaxError('HTML, not JSON'); },
  });
  console.warn = () => {};
  try {
    const result = await attachCsvToImportRun({
      importRunId: 9, csvText: 'A,B\n1,2', fileName: 'source.csv', entity: 'ap_bills',
    });
    assert.equal(result.csv, null);
    assert.equal(evidenceWrites, 0);
  } finally {
    globalThis.fetch = originalFetch;
    console.warn = originalWarn;
  }
});

test('confirmed local upload registers source evidence', async () => {
  const originalFetch = globalThis.fetch;
  const key = 'csv_imports/1/run/9/source.csv';
  let evidenceWrites = 0;
  globalThis.csvAuditTestApi = {
    post: async path => {
      if (path.includes('evidence_upload_url')) {
        return { driver: 'local', storage_key: key,
          upload: { form_action: '/api/storage/local/put.php', fields: {} } };
      }
      evidenceWrites += 1;
      return { id: 100 };
    },
  };
  globalThis.fetch = async () => ({
    ok: true, status: 201, json: async () => ({ stored: true, storage_key: key }),
  });
  try {
    const result = await attachCsvToImportRun({
      importRunId: 9, csvText: 'A,B\n1,2', fileName: 'source.csv', entity: 'ap_bills',
    });
    assert.equal(result.csv.id, 100);
    assert.equal(evidenceWrites, 1);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('the archived source preserves original file bytes, including a BOM', async () => {
  const originalFetch = globalThis.fetch;
  const key = 'csv_imports/1/csv_import_run/9/source.csv';
  const originalBytes = new Uint8Array([0xef, 0xbb, 0xbf, 0x41, 0x2c, 0x42, 0x0a, 0x31, 0x2c, 0x32]);
  const sourceFile = new Blob([originalBytes], { type: 'text/csv' });
  let uploaded = null;
  let registeredSize = null;
  globalThis.csvAuditTestApi = {
    post: async (path, body) => {
      if (path.includes('evidence_upload_url')) {
        return { driver: 'local', storage_key: key,
          upload: { form_action: '/api/storage/local/put.php', fields: {} } };
      }
      registeredSize = body.size_bytes;
      return { id: 101 };
    },
  };
  globalThis.fetch = async (_url, options) => {
    uploaded = new Uint8Array(await options.body.get('file').arrayBuffer());
    return { ok: true, status: 201, json: async () => ({ stored: true, storage_key: key }) };
  };
  try {
    const result = await attachCsvToImportRun({
      importRunId: 9, csvText: 'A,B\n1,2', sourceFile, fileName: 'source.csv', entity: 'ap_bills',
    });
    assert.equal(result.csv.id, 101);
    assert.deepEqual(uploaded, originalBytes);
    assert.equal(registeredSize, originalBytes.byteLength);
  } finally {
    globalThis.fetch = originalFetch;
  }
});
