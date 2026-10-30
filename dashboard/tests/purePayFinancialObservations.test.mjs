import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { readFile } from 'node:fs/promises';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/treasury/ui/PurePayFinancialObservations.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
const observation = JSON.parse(await readFile(new URL('../../tests/fixtures/purepay_financial_observations/bill.json', import.meta.url), 'utf8'));
observation.document.currency = null;
const capture = { tenant_id: 11, observation, review_hash: 'review-original', comparison: { core_record: null, amounts: [{ provider_field: 'amount', provider_value: '100.00', core_field: 'total', core_value: null, difference: null, status: 'currency_missing' }] } };
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
const button = (f, label) => f.tree.root.findAllByType('button').find(n => text(n) === label);
const standard = async action => structuredClone(action === 'save' ? { id: 1, replayed: true, saved: capture } : action === 'history' ? { tenant_id: 11, items: [{ id: 1, saved: capture, saved_at: '2026-10-30' }], next_before: null } : capture);
async function fixture(respond = standard) {
  const f = { calls: [], pinned: '11' }, module = { exports: {} };
  const mock = { getPinnedTenantId: () => f.pinned, api: { post(url, body, options) { const action = url.split('action=')[1]; f.calls.push({ action, body, options }); return respond(action, body); } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? mock : require(id), module, module.exports);
  f.Component = module.exports.default; f.props = { tenantId: 11, connectionVersion: 'v1' };
  await act(async () => { f.tree = create(React.createElement(f.Component, f.props)); });
  f.click = async label => { const b = button(f, label); assert.ok(b); assert.ok(!b.props.disabled); await act(async () => { await b.props.onClick(); }); };
  f.setId = async value => act(async () => f.tree.root.findAllByType('input')[0].props.onChange({ target: { value } }));
  f.open = async () => { await f.setId('bill-synthetic'); await f.click('Review source'); };
  f.confirm = async () => act(async () => f.tree.root.findAllByType('input').find(n => n.props.type === 'checkbox').props.onChange({ target: { checked: true } }));
  f.close = () => act(() => f.tree.unmount()); return f;
}
test('review shows unknown currency, separate fee cents and explicit capture confirmation', async () => {
  const f = await fixture(); try {
    assert.equal(f.calls.length, 0); await f.open();
    assert.ok(text(f.tree.root).includes('Currency unknown')); assert.ok(text(f.tree.root).includes('fee cents'));
    assert.ok(text(f.tree.root).includes('do not establish bank delivery'));
    assert.ok(button(f, 'Save evidence capture').props.disabled); await f.confirm(); await f.click('Save evidence capture');
    assert.deepEqual(f.calls.at(-1).body, { kind: 'bill', document_id: 'bill-synthetic', core_id: null, confirmed: true, review_hash: 'review-original' });
    assert.ok(text(f.tree.root).includes('No duplicate created')); assert.ok(!button(f, 'Save evidence capture'));
    assert.ok(f.calls.every(c => c.options.headers['X-CoreFlux-Tenant-Id'] === '11'));
  } finally { f.close(); }
});
test('lost save clears review; history offers evidence without a posting or resave action', async () => {
  const f = await fixture(async action => { if (action === 'save') throw new Error('Lost response'); return standard(action); });
  try { await f.open(); await f.confirm(); await f.click('Save evidence capture'); assert.ok(!button(f, 'Save evidence capture'));
    await f.click('Saved captures'); assert.ok(text(f.tree.root).includes('Capture saved')); assert.ok(!button(f, 'Save evidence capture')); assert.equal(f.calls.filter(c => c.action === 'save').length, 1);
  } finally { f.close(); }
});
test('changing selected record invalidates displayed comparison', async () => {
  const f = await fixture(); try { await f.open(); await f.setId('other'); assert.ok(!button(f, 'Save evidence capture')); assert.ok(!text(f.tree.root).includes('Currency unknown')); } finally { f.close(); }
});
test('workspace switch before read sends no request', async () => {
  const f = await fixture(); try { await f.setId('bill-synthetic'); f.pinned = '12'; await f.click('Review source'); assert.equal(f.calls.length, 0); } finally { f.close(); }
});
test('workspace switch in flight rejects foreign result', async () => {
  let resolve; const f = await fixture(() => new Promise(done => { resolve = done; }));
  try { await f.setId('bill-synthetic'); let waiting; await act(async () => { waiting = button(f, 'Review source').props.onClick(); });
    f.pinned = '12'; await act(async () => { resolve(structuredClone(capture)); await waiting; });
    assert.ok(!text(f.tree.root).includes('Currency unknown')); assert.ok(text(f.tree.root).includes('Workspace changed'));
  } finally { f.close(); }
});
test('connection change aborts old request and clears its result', async () => {
  let resolve; const f = await fixture(() => new Promise(done => { resolve = done; }));
  try { await f.setId('bill-synthetic'); let waiting; await act(async () => { waiting = button(f, 'Review source').props.onClick(); });
    await act(async () => f.tree.update(React.createElement(f.Component, { ...f.props, connectionVersion: 'v2' })));
    assert.ok(f.calls[0].options.fetch.signal.aborted); await act(async () => { resolve(capture); await waiting; });
    assert.ok(!text(f.tree.root).includes('Currency unknown'));
  } finally { f.close(); }
});
test('large payment sets remain fully reachable in bounded pages', async () => {
  const variant = structuredClone(capture); variant.observation.outgoing_payments = Array.from({ length: 51 }, (_, i) => ({ ...observation.outgoing_payments[0], id: `p-${i}` }));
  const f = await fixture(async () => variant); try { await f.open();
    assert.ok(text(f.tree.root).includes('Showing 1–25 of 51')); await f.click('Next outgoing payments'); await f.click('Next outgoing payments');
    assert.ok(text(f.tree.root).includes('Showing 51–51 of 51')); assert.ok(button(f, 'Next outgoing payments').props.disabled);
  } finally { f.close(); }
});
