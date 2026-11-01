import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/treasury/ui/PurePayFinancialLineLinks.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
const preview = { tenant_id: 11, capture_id: 5, review_hash: 'review-original', existing_link: null,
  target: { placement: { number: 'PL-1' }, person: { number: 'P-1' }, person_name: 'Synthetic worker', title: 'Synthetic placement', start_date: '2026-01-01', end_date: null },
  source: { line_id: 'line-a', document_currency: null, line: { item_name: 'Synthetic source item', service_start: null, service_end: null } } };
const link = { id: 9, reviewed_by: 7, reviewed_at: '2026-10-30', saved: preview };
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
const button = (f, label) => f.tree.root.findAllByType('button').find(n => text(n) === label);
const standard = async action => structuredClone(action === 'preview' ? preview : action === 'save' ? { tenant_id: 11, replayed: true, link } : { tenant_id: 11, items: [link], next_before: null });
async function fixture(respond = standard) {
  const f = { calls: [], pinned: '11' }, module = { exports: {} };
  const mock = { getPinnedTenantId: () => f.pinned, api: { post(url, body, options) { const action = url.split('action=')[1]; f.calls.push({ action, body, options }); return respond(action, body); } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? mock : require(id), module, module.exports);
  f.Component = module.exports.default; f.props = { tenantId: 11, captureId: 5, lineId: 'line-a' };
  await act(async () => { f.tree = create(React.createElement(f.Component, f.props)); });
  f.click = async label => { const b = button(f, label); assert.ok(b); assert.ok(!b.props.disabled); await act(async () => { await b.props.onClick(); }); };
  f.number = async value => act(async () => f.tree.root.findAllByType('input')[0].props.onChange({ target: { value } }));
  f.open = async () => { await f.number(' pl-1 '); await f.click('Review placement association'); };
  f.confirm = async () => act(async () => f.tree.root.findAllByType('input').find(n => n.props.type === 'checkbox').props.onChange({ target: { checked: true } }));
  f.close = () => act(() => f.tree.unmount()); return f;
}
test('association requires a reviewed exact source line and explicit confirmation', async () => {
  const f = await fixture(); try {
    assert.equal(f.calls.length, 0); await f.open();
    assert.deepEqual(f.calls[0].body, { capture_id: 5, line_id: 'line-a', placement_number: 'PL-1' });
    assert.ok(text(f.tree.root).includes('Synthetic worker')); assert.ok(text(f.tree.root).includes('Source currency: Unknown'));
    assert.ok(button(f, 'Save placement association').props.disabled); await f.confirm(); await f.click('Save placement association');
    assert.deepEqual(f.calls.at(-1).body, { capture_id: 5, line_id: 'line-a', placement_number: 'PL-1', confirmed: true, review_hash: 'review-original' });
    assert.ok(text(f.tree.root).includes('No duplicate created')); assert.ok(text(f.tree.root).includes('historical review evidence'));
    assert.ok(f.calls.every(c => c.options.headers['X-CoreFlux-Tenant-Id'] === '11'));
  } finally { f.close(); }
});
test('editing placement clears confirmation and stale preview', async () => {
  const f = await fixture(); try { await f.open(); await f.confirm(); await f.number('PL-2'); assert.ok(!button(f, 'Save placement association')); assert.ok(!text(f.tree.root).includes('Synthetic worker')); } finally { f.close(); }
});
test('source-line switch aborts its pending request and ignores the old result', async () => {
  let resolve; const f = await fixture(() => new Promise(done => { resolve = done; }));
  try { await f.number('PL-1'); let pending; await act(async () => { pending = button(f, 'Review placement association').props.onClick(); });
    await act(async () => f.tree.update(React.createElement(f.Component, { ...f.props, lineId: 'line-b' })));
    assert.ok(f.calls[0].options.fetch.signal.aborted); await act(async () => { resolve(preview); await pending; });
    assert.ok(!text(f.tree.root).includes('Synthetic worker')); assert.ok(!button(f, 'Save placement association'));
  } finally { f.close(); }
});
test('workspace change cannot disclose a foreign result', async () => {
  const f = await fixture(async () => ({ ...preview, tenant_id: 12 })); try { await f.open(); assert.ok(text(f.tree.root).includes('Workspace changed')); assert.ok(!text(f.tree.root).includes('Synthetic worker')); } finally { f.close(); }
});
test('lost save clears review and history offers recorded evidence without repeating the write', async () => {
  const f = await fixture(async action => { if (action === 'save') throw new Error('Lost response'); return standard(action); });
  try { await f.open(); await f.confirm(); await f.click('Save placement association'); assert.ok(!button(f, 'Save placement association'));
    await f.click('Association history'); assert.ok(text(f.tree.root).includes('Association #9')); assert.equal(f.calls.filter(c => c.action === 'save').length, 1);
    assert.ok(!button(f, 'Save placement association'));
  } finally { f.close(); }
});
