import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const require = createRequire(import.meta.url);
const React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../src/pages/QuantaTimeReview.jsx', import.meta.url))],
  bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture-api', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
  } }] });
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
const button = (f, label) => f.tree.root.findAllByType('button').find(n => text(n) === label);
const delivery = { id: 8, review_hash: 'original-review', state: 'preview', created_at: '2026-10-24' };
const review = { status: 'preview', state: 'pending_review', workspace: { name: 'Demo Quanta' }, placement_number: 'PL-42',
  work_date: '2026-10-19', delivery_enabled: true, delivery };
const pending = { rows: [{ delivery: { ...delivery, state: 'uncertain' }, placement_number: 'PL-42' }], next_before: null };
async function fixture(name, respond) {
  const f = { pinned: '11', calls: [] };
  const module = { exports: {} };
  const api = { getPinnedTenantId: () => f.pinned, api: { post(url, body, options) {
    const action = url.split('action=time_review_')[1]; f.calls.push({ action, body, options }); return respond(action, body);
  } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id), module, module.exports);
  f.Component = module.exports[name];
  f.props = { tenantId: 11, connectionRevision: 1, entryId: 'original-source' };
  await act(async () => { f.tree = create(React.createElement(f.Component, f.props)); });
  f.close = () => act(() => f.tree.unmount());
  f.click = async label => {
    const b = button(f, label); assert.ok(b, label); assert.equal(!!b.props.disabled, false);
    await act(async () => { await b.props.onClick(); });
  };
  f.confirm = async () => act(async () => f.tree.root.findByType('input').props.onChange({ target: { checked: true } }));
  return f;
}

test('review requires confirmation, pins the tenant and sends the original delivery only once', async () => {
  const f = await fixture('default', async action => action === 'preview' ? structuredClone(review) : { ...delivery, state: 'delivered' });
  try {
    assert.equal(f.calls.length, 0); await f.click('Review current status');
    assert.equal(button(f, 'Share reviewed status').props.disabled, true);
    await f.confirm(); await f.click('Share reviewed status');
    assert.equal(f.calls.filter(c => c.action === 'send').length, 1);
    assert.deepEqual(f.calls[1].body, { delivery_id: 8, review_hash: 'original-review', confirm_status: true });
    assert.equal(f.calls[1].options.headers['X-CoreFlux-Tenant-Id'], '11');
    assert.ok(f.calls[1].options.fetch.signal); assert.ok(!button(f, 'Share reviewed status'));
  } finally { f.close(); }
});

test('lost response preserves the original receipt and never reposts automatically', async () => {
  const f = await fixture('default', async action => {
    if (action === 'preview') return structuredClone(review);
    if (action === 'send') throw new Error('Lost acknowledgement');
    return { delivery: { ...delivery, state: 'delivered' }, receipt_state: 'confirmed' };
  });
  try {
    await f.click('Review current status'); await f.confirm(); await f.click('Share reviewed status');
    assert.ok(!button(f, 'Share reviewed status')); await f.click('Check original receipt');
    assert.equal(f.calls.filter(c => c.action === 'send').length, 1);
    assert.equal(f.calls.at(-1).body.delivery_id, 8);
  } finally { f.close(); }
});

test('recovery survives remount without a source feed and confirms only the original delivery', async () => {
  const f = await fixture('QuantaTimeReviewRecovery', async action => action === 'pending' ? structuredClone(pending)
    : { delivery: { ...delivery, state: 'delivered' }, receipt_state: 'confirmed' });
  try {
    await f.click('Find unconfirmed deliveries');
    await act(async () => { f.tree.update(React.createElement(f.Component, { ...f.props, key: 'reloaded' })); });
    await f.click('Find unconfirmed deliveries'); await f.click('Check original receipt #8');
    assert.ok(text(f.tree.root).includes('Review current time separately for later changes.'));
    assert.ok(!text(f.tree.root).includes('Status confirmed in Quanta.'));
    assert.equal(f.calls.filter(c => c.action === 'send' || c.action === 'preview').length, 0);
    assert.deepEqual(f.calls.at(-1).body, { delivery_id: 8, review_hash: 'original-review' });
  } finally { f.close(); }
});

test('missing receipt stays recoverable and cannot enable a send', async () => {
  const f = await fixture('QuantaTimeReviewRecovery', async action => action === 'pending' ? structuredClone(pending)
    : { delivery: pending.rows[0].delivery, receipt_state: 'not_found' });
  try {
    await f.click('Find unconfirmed deliveries'); await f.click('Check original receipt #8');
    assert.ok(text(f.tree.root).includes('This attempt remains unconfirmed.'));
    assert.ok(button(f, 'Check original receipt #8')); assert.ok(!button(f, 'Share reviewed status'));
  } finally { f.close(); }
});

test('older recovery pages remain accessible without duplicating a delivery', async () => {
  const f = await fixture('QuantaTimeReviewRecovery', async (action, body) => body.before
    ? { rows: [...pending.rows, { delivery: { ...delivery, id: 7, state: 'uncertain' }, placement_number: null }], next_before: null }
    : { ...structuredClone(pending), next_before: 8 });
  try {
    await f.click('Find unconfirmed deliveries'); await f.click('Earlier unconfirmed deliveries');
    assert.equal(f.tree.root.findAllByType('button').filter(b => text(b) === 'Check original receipt #8').length, 1);
    assert.ok(button(f, 'Check original receipt #7')); assert.ok(text(f.tree.root).includes('Placement number unavailable'));
    assert.equal(f.calls.at(-1).body.before, 8);
  } finally { f.close(); }
});

for (const component of ['default', 'QuantaTimeReviewRecovery']) {
  const label = component === 'default' ? 'Review current status' : 'Find unconfirmed deliveries';
  const response = component === 'default' ? review : pending;
  test(component + ' discards a response after a pinned workspace switch', async () => {
    let resolve; const f = await fixture(component, () => new Promise(done => { resolve = done; }));
    try {
      let waiting; await act(async () => { waiting = button(f, label).props.onClick(); });
      f.pinned = '12'; await act(async () => { resolve(structuredClone(response)); await waiting; });
      assert.ok(!text(f.tree.root).includes('PL-42')); assert.ok(text(f.tree.root).includes('Workspace changed'));
    } finally { f.close(); }
  });
  test(component + ' aborts a request when its connection changes', async () => {
    let resolve; const f = await fixture(component, () => new Promise(done => { resolve = done; }));
    try {
      let waiting; await act(async () => { waiting = button(f, label).props.onClick(); });
      await act(async () => { f.tree.update(React.createElement(f.Component, { ...f.props, connectionRevision: 2 })); });
      assert.equal(f.calls[0].options.fetch.signal.aborted, true);
      await act(async () => { resolve(structuredClone(response)); await waiting; });
      assert.ok(!text(f.tree.root).includes('PL-42')); assert.equal(button(f, label).props.disabled, false);
    } finally { f.close(); }
  });
}
