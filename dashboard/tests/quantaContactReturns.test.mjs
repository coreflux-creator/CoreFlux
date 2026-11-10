import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../src/pages/QuantaContactReturns.jsx', import.meta.url))],
  bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'isolated-api', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
  } }] });
const row = { change_id: 'saved-decision', state: 'pending', decision: 'accepted', person_number: 'P-9108', person_name: 'Synthetic Worker' };
const completed = { ...row, state: 'delivered', outcome: { contact_state: 'applied', conflict_fields: [] } };
const text = n => typeof n === 'string' ? n : (n.children || []).map(text).join('');
const button = (f, label) => f.tree.root.findAllByType('button').find(n => text(n) === label);
async function fixture(respond = async action => action === 'profile_return_list' ? { rows: [row], delivery_enabled: true } : completed) {
  const f = { pinned: '11', calls: [] }, module = { exports: {} };
  const api = { getPinnedTenantId: () => f.pinned, api: { post(url, body, options) {
    const action = url.split('action=')[1]; f.calls.push({ action, body, options }); return respond(action, body);
  } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id), module, module.exports);
  await act(async () => { f.tree = create(React.createElement(module.exports.default, { workspaceId: 11 })); });
  f.close = () => act(() => f.tree.unmount());
  f.click = async label => { const b = button(f, label); assert.ok(b, label); assert.equal(!!b.props.disabled, false, label);
    await act(async () => { await b.props.onClick(); }); };
  f.confirm = async () => act(async () => f.tree.root.findByType('input').props.onChange({ target: { checked: true } }));
  return f;
}
test('saved decision return needs confirmation and sends only its identity', async () => {
  const f = await fixture(); try {
    assert.equal(f.calls.length, 0); await f.click('Load decision returns');
    assert.equal(button(f, 'Return saved decision').props.disabled, true);
    await f.confirm(); await f.click('Return saved decision');
    assert.deepEqual(f.calls.at(-1).body, { change_id: 'saved-decision', confirm_return: true });
    assert.equal(f.calls.at(-1).options.headers['X-CoreFlux-Tenant-Id'], '11');
    assert.ok(text(f.tree.root).includes('Quanta confirmed the contact update'));
    assert.ok(!button(f, 'Return saved decision'));
  } finally { f.close(); }
});
test('lost acknowledgement requires receipt inspection and separately confirmed recovery', async () => {
  const f = await fixture(async action => action === 'profile_return_list' ? { rows: [row], delivery_enabled: true }
    : action === 'profile_return_send' ? Promise.reject(new Error('Lost response'))
    : action === 'profile_return_status' ? { ...row, state: 'uncertain', receipt_state: 'received', resume_allowed: true }
    : completed);
  try {
    await f.click('Load decision returns'); await f.confirm(); await f.click('Return saved decision');
    assert.ok(!button(f, 'Resume original return')); assert.ok(!button(f, 'Return saved decision'));
    await f.click('Inspect return receipt'); assert.equal(button(f, 'Resume original return').props.disabled, true);
    assert.equal(f.calls.filter(c => c.action === 'profile_return_send').length, 1);
    await f.confirm(); await f.click('Resume original return');
    assert.deepEqual(f.calls.at(-1).body, { change_id: 'saved-decision', confirm_return: true });
    assert.equal(f.calls.at(-1).action, 'profile_return_resume');
  } finally { f.close(); }
});
test('native conflict is shown as reconciliation work, not a successful contact update', async () => {
  const f = await fixture(async () => ({ delivery_enabled: true, rows: [{ ...completed, outcome: { contact_state: 'conflict', conflict_fields: ['phone'] } }] }));
  try {
    await f.click('Load decision returns'); assert.ok(text(f.tree.root).includes('Quanta kept a newer contact edit'));
    assert.ok(text(f.tree.root).includes('Fields to reconcile: Phone'));
    assert.ok(!text(f.tree.root).includes('Quanta confirmed the contact update'));
    assert.ok(!button(f, 'Return saved decision'));
  } finally { f.close(); }
});
test('workspace switch cannot submit an already confirmed return', async () => {
  const f = await fixture(); try {
    await f.click('Load decision returns'); await f.confirm(); f.pinned = '12';
    await f.click('Return saved decision'); assert.equal(f.calls.length, 1); assert.ok(text(f.tree.root).includes('workspace changed'));
  } finally { f.close(); }
});
test('disabled pilot allows inspection without exposing write controls', async () => {
  const f = await fixture(async () => ({ delivery_enabled: false, rows: [row] }));
  try {
    await f.click('Load decision returns'); assert.ok(!button(f, 'Return saved decision'));
    assert.ok(button(f, 'Inspect return receipt')); assert.ok(text(f.tree.root).includes('not enabled for this pilot'));
  } finally { f.close(); }
});
