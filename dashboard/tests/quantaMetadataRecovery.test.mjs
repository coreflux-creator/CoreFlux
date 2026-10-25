import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const require = createRequire(import.meta.url);
const React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../src/pages/QuantaMetadataPanel.jsx', import.meta.url))],
  bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture-api', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
  } }] });
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
const button = (f, label) => f.tree.root.findAllByType('button').find(n => text(n) === label);
const delivery = { id: 8, entity_type: 'placement', review_hash: 'original-review', state: 'preview', created_at: '2026-10-25' };
const review = { status: 'preview', workspace: { name: 'Demo Quanta' }, reference: { number: 'PL-42' },
  proposed: { title: 'Reviewed assignment', work_location: { city: 'Montréal', state: 'QC', country: 'CA' } },
  current: { title: 'Prior assignment' }, delivery_enabled: true, delivery };
const pending = { rows: [{ delivery: { ...delivery, state: 'uncertain' } }], next_before: null };
async function fixture(name, respond) {
  const f = { pinned: '11', calls: [] };
  const module = { exports: {} };
  const api = { getPinnedTenantId: () => f.pinned, api: { post(url, body, options) {
    const action = url.split('action=metadata_')[1]; f.calls.push({ action, body, options }); return respond(action, body);
  } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id), module, module.exports);
  f.Component = module.exports[name];
  f.props = { tenantId: 11, connectionRevision: 1, people: [{ id: 15, person_number: 'P-15', name: 'Demo worker' }], placements: [], worksites: [] };
  await act(async () => { f.tree = create(React.createElement(f.Component, f.props)); });
  f.close = () => act(() => f.tree.unmount());
  f.click = async label => {
    const b = button(f, label); assert.ok(b, label); assert.equal(!!b.props.disabled, false);
    await act(async () => { await b.props.onClick(); });
  };
  f.choose = async () => { if (name === 'default') await act(async () => f.tree.root.findAllByType('select')[1].props.onChange({ target: { value: '15' } })); };
  f.confirm = async () => act(async () => f.tree.root.findByType('input').props.onChange({ target: { checked: true } }));
  return f;
}

test('metadata review shows full location, pins the tenant and sends only the confirmed envelope', async () => {
  const f = await fixture('default', async action => action === 'preview' ? structuredClone(review) : { ...delivery, state: 'delivered' });
  try {
    assert.equal(f.calls.length, 0); await f.choose(); await f.click('Preview shared record');
    assert.ok(text(f.tree.root).includes('Montréal · QC · CA'));
    assert.equal(button(f, 'Send reviewed metadata').props.disabled, true);
    await f.confirm(); await f.click('Send reviewed metadata');
    assert.deepEqual(f.calls[1].body, { delivery_id: 8, review_hash: 'original-review', confirm_metadata: true });
    assert.equal(f.calls[1].options.headers['X-CoreFlux-Tenant-Id'], '11');
    assert.ok(f.calls[1].options.fetch.signal); assert.ok(!button(f, 'Send reviewed metadata'));
  } finally { f.close(); }
});

test('lost response retains the original receipt and does not claim the current assignment agrees', async () => {
  const f = await fixture('default', async action => {
    if (action === 'preview') return structuredClone(review);
    if (action === 'send') throw new Error('Lost acknowledgement');
    return { delivery: { ...delivery, state: 'delivered' }, receipt_state: 'confirmed' };
  });
  try {
    await f.choose(); await f.click('Preview shared record'); await f.confirm(); await f.click('Send reviewed metadata');
    assert.ok(!button(f, 'Send reviewed metadata')); await f.click('Check original receipt');
    assert.equal(f.calls.filter(c => c.action === 'send').length, 1);
    assert.deepEqual(f.calls.at(-1).body, { delivery_id: 8, review_hash: 'original-review' });
    assert.ok(text(f.tree.root).includes('Preview the current record again to check for later changes.'));
    assert.ok(!text(f.tree.root).includes('The shared record already agrees.'));
  } finally { f.close(); }
});

test('preview can return only the original envelope when a record is held', async () => {
  const f = await fixture('default', async () => ({ receipt_only: true, status: 'reconciliation_required', delivery: pending.rows[0].delivery }));
  try {
    await f.choose(); await f.click('Preview shared record');
    assert.ok(button(f, 'Check original receipt')); assert.ok(!button(f, 'Send reviewed metadata'));
    assert.equal(f.tree.root.findAllByType('table').length, 0);
  } finally { f.close(); }
});

test('recovery survives remount with no source record or provider catalog', async () => {
  const f = await fixture('QuantaMetadataRecovery', async action => action === 'pending' ? structuredClone(pending)
    : { delivery: { ...delivery, state: 'delivered' }, receipt_state: 'confirmed' });
  try {
    await f.click('Find unconfirmed identity and assignment deliveries');
    await act(async () => { f.tree.update(React.createElement(f.Component, { tenantId: 11, key: 'reloaded' })); });
    await f.click('Find unconfirmed identity and assignment deliveries'); await f.click('Check original receipt #8');
    assert.ok(text(f.tree.root).includes('Preview the current record separately for later changes.'));
    assert.equal(f.calls.filter(c => c.action === 'send' || c.action === 'preview').length, 0);
  } finally { f.close(); }
});

for (const receiptState of ['not_found', 'unconfirmed']) test(receiptState + ' keeps the original attempt recoverable', async () => {
  const f = await fixture('QuantaMetadataRecovery', async action => action === 'pending' ? structuredClone(pending)
    : { delivery: pending.rows[0].delivery, receipt_state: receiptState });
  try {
    await f.click('Find unconfirmed identity and assignment deliveries'); await f.click('Check original receipt #8');
    assert.ok(button(f, 'Check original receipt #8')); assert.ok(!button(f, 'Send reviewed metadata'));
    assert.equal(f.calls.filter(c => c.action === 'send').length, 0);
  } finally { f.close(); }
});

test('older pages remain accessible without duplicating a delivery', async () => {
  const f = await fixture('QuantaMetadataRecovery', async (action, body) => body.before
    ? { rows: [...pending.rows, { delivery: { ...delivery, id: 7, entity_type: 'person', state: 'uncertain' } }], next_before: null }
    : { ...structuredClone(pending), next_before: 8 });
  try {
    await f.click('Find unconfirmed identity and assignment deliveries'); await f.click('Earlier unconfirmed identity and assignment deliveries');
    assert.equal(f.tree.root.findAllByType('button').filter(b => text(b) === 'Check original receipt #8').length, 1);
    assert.ok(button(f, 'Check original receipt #7')); assert.ok(text(f.tree.root).includes('Person identity'));
    assert.equal(f.calls.at(-1).body.before, 8);
  } finally { f.close(); }
});

for (const component of ['default', 'QuantaMetadataRecovery']) {
  const label = component === 'default' ? 'Preview shared record' : 'Find unconfirmed identity and assignment deliveries';
  const response = component === 'default' ? review : pending;
  test(component + ' discards a response after a pinned workspace switch', async () => {
    let resolve; const f = await fixture(component, () => new Promise(done => { resolve = done; }));
    try {
      await f.choose(); let waiting; await act(async () => { waiting = button(f, label).props.onClick(); });
      f.pinned = '12'; await act(async () => { resolve(structuredClone(response)); await waiting; });
      assert.ok(!text(f.tree.root).includes('PL-42')); assert.ok(!button(f, 'Check original receipt #8'));
      assert.ok(text(f.tree.root).includes('Workspace changed'));
    } finally { f.close(); }
  });
  test(component + ' aborts and discards a request when its connection changes', async () => {
    let resolve; const f = await fixture(component, () => new Promise(done => { resolve = done; }));
    try {
      await f.choose(); let waiting; await act(async () => { waiting = button(f, label).props.onClick(); });
      await act(async () => { f.tree.update(React.createElement(f.Component, { ...f.props, connectionRevision: 2 })); });
      assert.equal(f.calls[0].options.fetch.signal.aborted, true);
      await act(async () => { resolve(structuredClone(response)); await waiting; });
      assert.ok(!text(f.tree.root).includes('PL-42')); assert.ok(!button(f, 'Check original receipt #8'));
    } finally { f.close(); }
  });
  test(component + ' refuses a request after a workspace switch or without a workspace', async () => {
    const f = await fixture(component, async () => structuredClone(response));
    try {
      await f.choose(); f.pinned = '12'; await f.click(label); assert.equal(f.calls.length, 0);
      await act(async () => { f.tree.update(React.createElement(f.Component, { ...f.props, tenantId: null })); });
      assert.equal(button(f, label).props.disabled, true);
    } finally { f.close(); }
  });
}
