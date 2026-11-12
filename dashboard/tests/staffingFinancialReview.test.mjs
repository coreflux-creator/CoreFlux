import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/reports/ui/StaffingFinancialReview.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
async function fixture(data, error = null, pinned = '11') {
  const module = { exports: {} }, calls = [];
  const mock = { getPinnedTenantId: () => pinned, useApi(path) { calls.push(path); return { data, error, loading: false, reload() {} }; }, api: { post() { throw Error('Report data must require an explicit selection'); } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? mock : require(id), module, module.exports);
  let tree; await act(async () => { tree = create(React.createElement(module.exports.default)); });
  return { tree, calls, close: () => act(() => tree.unmount()) };
}
test('financial reader reaches an explicit comparison without connection-admin controls', async () => {
  const f = await fixture({ tenant_id: 11, permissions: { workbook_manage: false } });
  try {
    assert.ok(text(f.tree.root).includes('Compare placement and work period'));
    assert.ok(f.calls.every(x => x === '/api/staffing_reconciliation.php?action=context'));
    assert.ok(!text(f.tree.root).includes('Disconnect'));
    assert.ok(f.tree.root.findAllByType('button').find(x => text(x) === 'Compare saved evidence').props.disabled);
  } finally { f.close(); }
});
test('a stale company context cannot display the report inputs', async () => {
  const f = await fixture({ tenant_id: 12, permissions: { workbook_manage: true } });
  try { assert.ok(text(f.tree.root).includes('company changed')); assert.equal(f.tree.root.findAllByType('input').length, 0); } finally { f.close(); }
});
test('missing underlying grants gives an access message without exposing financial fields', async () => {
  const f = await fixture(null, new Error('Forbidden'));
  try { assert.ok(text(f.tree.root).includes('requires access')); assert.equal(f.tree.root.findAllByType('input').length, 0); } finally { f.close(); }
});
