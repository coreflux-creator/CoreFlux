import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const require = createRequire(import.meta.url);
const React = require('react');
const { StaticRouter } = require('react-router-dom/server');
const { renderToStaticMarkup } = require('react-dom/server');
const built = await build({
  entryPoints: [fileURLToPath(new URL('../src/components/CsvImportRecoveryNotice.jsx', import.meta.url))],
  bundle: true,
  platform: 'node',
  format: 'cjs',
  write: false,
  external: ['react', 'react-router-dom'],
  plugins: [{
    name: 'recovery-test-doubles',
    setup(plugin) {
      plugin.onResolve({ filter: /^\.\.\/lib\/csvImportAudit$/ }, () => ({
        path: 'csv-import-audit', namespace: 'recovery-test',
      }));
      plugin.onLoad({ filter: /.*/, namespace: 'recovery-test' }, () => ({
        contents: `
          export const pendingCsvImportRuns = () => globalThis.csvRecoveryTestRuns;
          export const recoverCsvImportAudit = async () => { throw new Error('render cannot submit'); };
          export const abandonUnstartedCsvImportAudit = async () => { throw new Error('render cannot submit'); };
          export const forgetCsvImportRun = () => {};
        `,
        loader: 'js',
      }));
    },
  }],
});
const module = { exports: {} };
new Function('require', 'module', 'exports', built.outputFiles[0].text)(require, module, module.exports);
const RecoveryNotice = module.exports.default;

function render(entity) {
  return renderToStaticMarkup(React.createElement(StaticRouter, { location: '/modules/accounting/imports' },
    React.createElement(RecoveryNotice, { entity })));
}

test('a reloaded import shows only its exact pending finance runs', () => {
  globalThis.csvRecoveryTestRuns = [
    { id: 10, entity: 'ap_vendors' },
    { id: 11, entity: 'billing_invoices' },
  ];
  const html = render('ap_vendors');
  assert.match(html, /Previous import needs checking/);
  assert.match(html, /Vendors run #10/);
  assert.match(html, /Check server outcome/);
  assert.match(html, /before importing that file again/);
  assert.doesNotMatch(html, /run #11/);
  assert.doesNotMatch(html, /Import rows|action=commit/);
});

test('finance bulk history can recover every pending import without CSV content', () => {
  globalThis.csvRecoveryTestRuns = [
    { id: 10, entity: 'ap_vendors' },
    { id: 11, entity: 'billing_invoices' },
  ];
  const html = render(null);
  assert.match(html, /Vendors run #10/);
  assert.match(html, /AR invoices run #11/);
  assert.match(html, /View import history/);
  globalThis.csvRecoveryTestRuns = [];
  assert.equal(render(null), '');
});
