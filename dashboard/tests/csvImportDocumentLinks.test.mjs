import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const bundle = await build({
  entryPoints: [fileURLToPath(new URL('../src/lib/csvImportDocumentLinks.js', import.meta.url))],
  bundle: true,
  platform: 'node',
  format: 'cjs',
  write: false,
});
const module = { exports: {} };
new Function('module', 'exports', bundle.outputFiles[0].text)(module, module.exports);
const { csvImportDocumentLinks } = module.exports;

test('verified invoice and bill IDs open their source documents', () => {
  assert.deepEqual(csvImportDocumentLinks('billing_invoices', { 'QA-INV-1': 71 }), [
    { label: 'QA-INV-1', to: '/modules/billing/invoices/71' },
  ]);
  assert.deepEqual(csvImportDocumentLinks('ap_bills', { 'QA-BILL-1': 42 }), [
    { label: 'QA-BILL-1', to: '/modules/ap/bills/42' },
  ]);
});

test('recovery navigation refuses invalid IDs and limits long lists', () => {
  assert.deepEqual(csvImportDocumentLinks('billing_invoices', { valid: 1, invalid: '../admin', zero: 0 }), [
    { label: 'valid', to: '/modules/billing/invoices/1' },
  ]);
  assert.equal(csvImportDocumentLinks('billing_invoices', { a: 1, b: 2 }, 1).length, 1);
  assert.deepEqual(csvImportDocumentLinks('people', { person: 3 }), []);
});
