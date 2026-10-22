import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const require = createRequire(import.meta.url);
const React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../src/pages/QuantaPublicationPanel.jsx', import.meta.url))],
  bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'isolated-api', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
  } }] });
const people = [{ id: 101, person_number: 'P-9101', name: 'Synthetic <Worker>', email_primary: 'worker@example.com' },
  { id: 102, name: 'No allocated number', email_primary: 'other@example.com' }];
const delivery = { id: 7, state: 'preview', review_hash: 'review-fixture', created_at: '2026-10-22' };
const review = { workspace: { id: 'provider-a', name: 'Synthetic Quanta' }, delivery_enabled: true,
  hold_reason: 'Activation is separate.', rows: [{ person_id: 101, person_number: 'P-9101', person_name: 'Synthetic Worker',
    status: 'preview', operation: 'worker.profile.create', message: 'Prepare a disabled worker.',
    current: null, proposed: { full_name: 'Synthetic Worker', email: 'worker@example.com', phone: null }, delivery }] };
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
const button = (f, label) => f.tree.root.findAllByType('button').find(n => text(n) === label);
const allText = f => text(f.tree.root);
const checkbox = f => f.tree.root.findAllByType('input').filter(n => n.props.type === 'checkbox');
async function fixture(respond = async action => action === 'publication_preview' ? structuredClone(review) : {}) {
  const f = { calls: [], pinned: '11', tree: null };
  const api = { getPinnedTenantId: () => f.pinned, api: { post(url, body, options) {
    const action = url.split('action=')[1]; f.calls.push({ action, body, options }); return respond(action, body, options);
  } } };
  const module = { exports: {} };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id), module, module.exports);
  f.Component = module.exports.default;
  await act(async () => { f.tree = create(React.createElement(f.Component, { people, workspaceId: 11, key: '11:1' })); });
  f.close = () => act(() => f.tree.unmount());
  f.choose = async () => { await act(async () => checkbox(f)[0].props.onChange({ target: { checked: true } })); };
  f.click = async label => { const b = button(f, label); assert.ok(b, label); assert.equal(!!b.props.disabled, false, label + ' enabled'); await act(async () => { await b.props.onClick(); }); };
  f.preview = async () => { await f.choose(); await f.click('Preview 1 selected'); };
  f.confirm = async () => { const box = checkbox(f).find(n => text(n.parent).includes('I reviewed this person')); assert.ok(box); await act(async () => box.props.onChange({ target: { checked: true } })); };
  return f;
}

test('initial render does not send and cannot invent a missing person number', async () => {
  const f = await fixture();
  try { assert.equal(f.calls.length, 0); assert.equal(checkbox(f)[1].props.disabled, true);
    assert.ok(allText(f).includes('Number unavailable')); assert.ok(!allText(f).includes('P-102'));
    assert.equal(button(f, 'Recent deliveries').props.disabled, true);
  } finally { f.close(); }
});

test('review shows contact values and requires confirmation before exact scoped send', async () => {
  const f = await fixture(async action => action === 'publication_preview' ? structuredClone(review) : { ...delivery, state: 'delivered', remote_id: 'worker-777' });
  try {
    await f.preview(); assert.ok(allText(f).includes('Synthetic Quanta')); assert.ok(allText(f).includes('Not supplied'));
    assert.equal(button(f, 'Send reviewed person').props.disabled, true); await f.confirm(); await f.click('Send reviewed person');
    const call = f.calls.find(r => r.action === 'publication_send');
    assert.deepEqual(call.body, { delivery_id: 7, review_hash: 'review-fixture', confirm_profile: true });
    assert.equal(call.options.headers['X-CoreFlux-Tenant-Id'], '11'); assert.ok(call.options.fetch.signal);
    assert.ok(allText(f).includes('Confirmed by Quanta')); assert.ok(!button(f, 'Send reviewed person'));
  } finally { f.close(); }
});

test('lost send acknowledgement retains receipt action and never retries automatically', async () => {
  const f = await fixture(async action => {
    if (action === 'publication_preview') return structuredClone(review);
    if (action === 'publication_send') throw new Error('Connection interrupted');
    return { delivery: { ...delivery, state: 'delivered', remote_id: 'worker-777' }, receipt_state: 'confirmed' };
  });
  try {
    await f.preview(); await f.confirm(); await f.click('Send reviewed person');
    assert.ok(allText(f).includes('Check the receipt before trying another send.')); assert.ok(!button(f, 'Send reviewed person'));
    await f.click('Check receipt'); assert.ok(allText(f).includes('Confirmed by Quanta'));
    assert.equal(f.calls.filter(c => c.action === 'publication_send').length, 1);
  } finally { f.close(); }
});

test('missing receipt stays held while unattempted status allows a fresh confirmation', async () => {
  let status = 'uncertain';
  const f = await fixture(async action => {
    if (action === 'publication_preview') return structuredClone(review);
    if (action === 'publication_send') throw new Error('Interrupted');
    return { delivery: { ...delivery, state: status }, receipt_state: status === 'uncertain' ? 'not_found' : 'not_pending', message: status === 'uncertain' ? 'Still held; no record was resent.' : null };
  });
  try {
    await f.preview(); await f.confirm(); await f.click('Send reviewed person'); await f.click('Check receipt');
    assert.ok(!button(f, 'Send reviewed person')); assert.ok(allText(f).includes('Still held; no record was resent.'));
    status = 'preview'; await f.click('Check receipt'); assert.equal(button(f, 'Send reviewed person').props.disabled, true);
    assert.equal(f.calls.filter(c => c.action === 'publication_send').length, 1);
  } finally { f.close(); }
});

test('a response arriving after a tab workspace switch is discarded', async () => {
  let resolve;
  const f = await fixture(() => new Promise(done => { resolve = done; }));
  try {
    await f.choose(); let pending;
    await act(async () => { pending = button(f, 'Preview 1 selected').props.onClick(); });
    f.pinned = '12'; await act(async () => { resolve(structuredClone(review)); await pending; });
    assert.ok(!allText(f).includes('Synthetic Quanta')); assert.ok(allText(f).includes('workspace changed'));
    assert.ok(!button(f, 'Send reviewed person'));
  } finally { f.close(); }
});

test('connection remount discards an outstanding old review', async () => {
  let resolve;
  const f = await fixture(() => new Promise(done => { resolve = done; }));
  try {
    await f.choose(); let pending;
    await act(async () => { pending = button(f, 'Preview 1 selected').props.onClick(); });
    await act(async () => { f.tree.update(React.createElement(f.Component, { people, workspaceId: 11, key: '11:2' })); });
    await act(async () => { resolve(structuredClone(review)); await pending; });
    assert.ok(!allText(f).includes('Synthetic Quanta')); assert.equal(button(f, 'Preview 0 selected').props.disabled, true);
  } finally { f.close(); }
});

test('recent delivery history is available even without a working provider preview', async () => {
  const f = await fixture(async (action, body) => action === 'publication_history'
    ? { rows: body.before ? [{ ...delivery, id: 7 }, { ...delivery, id: 6 }] : [{ ...delivery, state: 'uncertain' }], next_before: body.before ? null : 7 }
    : { delivery: { ...delivery, state: 'delivered' }, receipt_state: 'confirmed' });
  try {
    await f.choose(); await f.click('Recent deliveries'); assert.ok(allText(f).includes('Receipt check needed'));
    await f.click('Earlier deliveries'); assert.equal(f.tree.root.findAllByType('button').filter(n => text(n) === 'Check receipt #7').length, 1);
    await f.click('Check receipt #7'); assert.ok(allText(f).includes('Confirmed by Quanta'));
    assert.equal(f.calls.filter(c => c.action === 'publication_send').length, 0);
  } finally { f.close(); }
});

test('disabled delivery capability never exposes a send button', async () => {
  const f = await fixture(async () => ({ ...structuredClone(review), delivery_enabled: false }));
  try { await f.preview(); assert.ok(!button(f, 'Send reviewed person')); assert.ok(button(f, 'Check receipt')); }
  finally { f.close(); }
});
