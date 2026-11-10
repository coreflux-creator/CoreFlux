import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/treasury/ui/PureCustomerInputReview.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture-api', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
const preview = { source: { reference: { number: 'CL-12' } }, contact: { name: 'Client LLC', email: 'client@example.test', phone: null }, organization: { name: 'Demo company' }, existing_customer: null, delivery: { id: 28, review_hash: 'a'.repeat(64), state: 'preview' }, can_create: true, delivery_enabled: true };
async function fixture(options = {}, respond) {
  const f = { calls: [], pinned: '999', current: structuredClone(preview), choices: [] };
  const api = { getPinnedTenantId: () => f.pinned, api: { post: async (url, body, config) => { f.calls.push({ url, body, config }); return respond ? respond(url, body, f) : structuredClone(f.current); } } };
  const module = { exports: {} };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id), module, module.exports);
  await act(async () => { f.tree = create(React.createElement(module.exports.default, { tenantId: 999, canCreate: true, canLink: true, onChooseExisting: (...args) => f.choices.push(args), ...options })); });
  f.button = label => f.tree.root.findAllByType('button').find(n => text(n) === label);
  f.click = async label => { const b = f.button(label); assert.ok(b, label); assert.ok(!b.props.disabled); await act(async () => b.props.onClick()); };
  f.review = async () => {
    await act(async () => f.tree.root.findAllByType('input').find(n => n.props.placeholder === 'CL-123').props.onChange({ target: { value: 'CL-12' } }));
    await act(async () => f.tree.root.findByType('form').props.onSubmit({ preventDefault() {} }));
  };
  f.confirm = async () => act(async () => f.tree.root.findAllByType('input').find(n => n.props.type === 'checkbox').props.onChange({ target: { checked: true } }));
  f.close = () => act(() => f.tree.unmount());
  return f;
}
test('client review is explicit, company pinned and read only', async () => {
  const f = await fixture(); try { assert.equal(f.calls.length, 0); await f.review(); assert.equal(f.calls.length, 1); assert.match(f.calls[0].url, /_preview$/); assert.equal(f.calls[0].config.headers['X-CoreFlux-Tenant-Id'], '999'); assert.ok(text(f.tree.root).includes('Client LLC')); assert.ok(f.button('Create customer in PurePay').props.disabled); } finally { f.close(); }
});
test('lost response retains original delivery and offers read-only recovery', async () => {
  const f = await fixture({}, (url, body, state) => { if (url.endsWith('_send')) throw new Error('Lost response'); if (url.endsWith('_reconcile')) return { delivery: { ...preview.delivery, state: 'delivered' }, existing_customer: { identity_basis: 'external_identity', customer: { id: 'cus_1', name: 'Current name' } } }; return structuredClone(state.current); });
  try { await f.review(); await f.confirm(); await f.click('Create customer in PurePay'); assert.equal(f.button('Create customer in PurePay'), undefined); await f.click('Check customer result'); assert.equal(f.calls.filter(c => c.url.endsWith('_send')).length, 1); assert.equal(f.calls.at(-1).body.delivery_id, 28); assert.ok(text(f.tree.root).includes('Current name')); await f.click('Review Core link'); assert.deepEqual(f.choices, [['cus_1', 'CL-12']]); } finally { f.close(); }
});
test('existing legacy Core link never offers creation or remapping', async () => {
  const f = await fixture(); f.current = { ...preview, delivery: null, existing_customer: { identity_basis: 'core_link', customer: { id: 'legacy', name: 'Existing customer' } } };
  try { await f.review(); assert.equal(f.button('Create customer in PurePay'), undefined); assert.equal(f.button('Review Core link'), undefined); assert.ok(text(f.tree.root).includes('Already linked')); assert.equal(f.calls.length, 1); } finally { f.close(); }
});
test('access and environment gates disable writes while keeping recovery available', async () => {
  for (const scenario of ['capability', 'environment', 'local_permission']) {
    const f = await fixture({ canCreate: scenario !== 'capability' });
    f.current = { ...preview, can_create: scenario !== 'local_permission', delivery_enabled: scenario !== 'environment' };
    try { await f.review(); assert.equal(f.button('Create customer in PurePay'), undefined); } finally { f.close(); }
  }
  const f = await fixture({ canCreate: false }); f.current = { ...preview, delivery_enabled: false, delivery: { ...preview.delivery, state: 'uncertain' } };
  try { await f.review(); assert.ok(f.button('Check customer result')); assert.equal(f.button('Create customer in PurePay'), undefined); } finally { f.close(); }
});
test('company switch cannot dispatch a reviewed customer', async () => {
  const f = await fixture(); try { await f.review(); await f.confirm(); f.pinned = '2'; await f.click('Create customer in PurePay'); assert.equal(f.calls.length, 1); assert.ok(text(f.tree.root).includes('The company changed')); } finally { f.close(); }
});
test('repeat clicks while creation is pending send one request', async () => {
  let release;
  const f = await fixture({}, (url, body, state) => url.endsWith('_send') ? new Promise(resolve => { release = resolve; }) : structuredClone(state.current));
  try { await f.review(); await f.confirm(); const click = f.button('Create customer in PurePay').props.onClick; let first, second; await act(async () => { first = click(); second = click(); }); assert.equal(f.calls.filter(c => c.url.endsWith('_send')).length, 1); await act(async () => { release({ delivery: { ...preview.delivery, state: 'uncertain' } }); await Promise.all([first, second]); }); assert.equal(f.button('Create customer in PurePay'), undefined); } finally { f.close(); }
});
test('successful create refreshes current identity before offering the link', async () => {
  const f = await fixture({}, (url, body, state) => { if (url.endsWith('_send')) { state.current = { ...preview, delivery: { ...preview.delivery, state: 'delivered' }, existing_customer: { identity_basis: 'external_identity', customer: { id: 'cus_current', name: 'Refreshed name' } } }; return { delivery: state.current.delivery }; } return structuredClone(state.current); });
  try { await f.review(); await f.confirm(); await f.click('Create customer in PurePay'); assert.deepEqual(f.calls.map(c => c.url.split('_').at(-1)), ['preview', 'send', 'preview']); assert.ok(text(f.tree.root).includes('Refreshed name')); } finally { f.close(); }
});
