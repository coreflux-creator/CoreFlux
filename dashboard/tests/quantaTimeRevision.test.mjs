import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url);
const React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../src/pages/QuantaTimeRevision.jsx', import.meta.url))],
  bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture-api', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
  } }] });
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
const button = (f, label) => f.tree.root.findAllByType('button').find(n => text(n) === label);
const review = { review_hash: 'original-hash', source_entry_id: 'entry-1', workspace: { name: 'Synthetic company' },
  before: [{ id: 1, work_date: '2026-10-02', hours: '8.00', category: 'regular_billable', status: 'approved' }],
  after: { work_date: '2026-10-02', placement_id: 42, components: { regular: 7.5 }, source_approval: { approved_at: '2026-10-04' } } };
async function fixture(respond) {
  const f = { pinned: '11', calls: [] }; const module = { exports: {} };
  const api = { getPinnedTenantId: () => f.pinned, api: { post(url, body, options) {
    const action = url.split('action=time_revision_')[1]; f.calls.push({ action, body, options }); return respond(action, body);
  } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id), module, module.exports);
  f.Component = module.exports.default; f.props = { tenantId: 11, connectionRevision: 1, entryId: 'entry-1' };
  await act(async () => { f.tree = create(React.createElement(f.Component, f.props)); });
  f.click = async label => { const b = button(f, label); assert.ok(b); assert.equal(!!b.props.disabled, false); await act(async () => b.props.onClick()); };
  f.confirm = async () => act(async () => {
    f.tree.root.findByType('textarea').props.onChange({ target: { value: 'Corrected source hours' } });
    f.tree.root.findByType('input').props.onChange({ target: { checked: true } });
  });
  f.close = () => act(() => f.tree.unmount()); return f;
}
test('correction shows old/new hours and requires reason and confirmation', async () => {
  const f = await fixture(async action => action === 'preview' ? structuredClone(review) : { id: 3 });
  try {
    await f.click('Review source correction'); assert.ok(text(f.tree.toJSON()).includes('8.00 hours'));
    assert.ok(button(f, 'Apply correction for Core review').props.disabled); await f.confirm(); await f.click('Apply correction for Core review');
    assert.deepEqual(f.calls[1].body, { source_entry_id: 'entry-1', review_hash: 'original-hash', reason: 'Corrected source hours', confirm_revision: true });
    assert.equal(f.calls[1].options.headers['X-CoreFlux-Tenant-Id'], '11'); assert.ok(text(f.tree.toJSON()).includes('independent Core approval'));
  } finally { f.close(); }
});
test('response loss retains exact request and blocks fresh review until resolved', async () => {
  let calls = 0;
  const f = await fixture(async action => { if (action === 'preview') return structuredClone(review); if (!calls++) throw new Error('Lost response'); return { id: 3 }; });
  try {
    await f.click('Review source correction'); await f.confirm(); await f.click('Apply correction for Core review');
    assert.equal(button(f, 'Review source correction').props.disabled, true); assert.equal(f.tree.root.findByType('textarea').props.disabled, true);
    await f.click('Check original correction'); assert.deepEqual(f.calls[1].body, f.calls[2].body);
    assert.ok(!button(f, 'Check original correction'));
  } finally { f.close(); }
});
test('stale review requires fresh preview and never silently resubmits', async () => {
  const f = await fixture(async action => { if (action === 'preview') return structuredClone(review); const err = new Error('Source changed'); err.status = 409; throw err; });
  try {
    await f.click('Review source correction'); await f.confirm(); await f.click('Apply correction for Core review');
    assert.ok(!button(f, 'Apply correction for Core review')); assert.ok(!button(f, 'Check original correction')); assert.equal(f.calls.length, 2);
    assert.equal(button(f, 'Review source correction').props.disabled, false);
  } finally { f.close(); }
});
test('company switch blocks submission of the previous company review', async () => {
  const f = await fixture(async () => structuredClone(review));
  try { await f.click('Review source correction'); await f.confirm(); f.pinned = '12'; await f.click('Apply correction for Core review');
    assert.equal(f.calls.length, 1); assert.ok(text(f.tree.toJSON()).includes('Company changed'));
  } finally { f.close(); }
});
for (const changed of [{ entryId: 'entry-2' }, { tenantId: 12 }, { connectionRevision: 2 }]) {
  test('identity or connection change clears prior review: ' + JSON.stringify(changed), async () => {
    const f = await fixture(async () => structuredClone(review));
    try { await f.click('Review source correction'); await f.confirm(); await act(async () => f.tree.update(React.createElement(f.Component, { ...f.props, ...changed })));
      assert.ok(!button(f, 'Apply correction for Core review')); assert.equal(f.tree.root.findAllByType('textarea').length, 0);
    } finally { f.close(); }
  });
}
test('history can be read without asking the provider for current source', async () => {
  const f = await fixture(async () => ({ items: [], next_before: null }));
  try { await f.click('Correction history'); assert.deepEqual(f.calls.map(c => c.action), ['history']); assert.ok(text(f.tree.toJSON()).includes('No recorded'));
  } finally { f.close(); }
});
