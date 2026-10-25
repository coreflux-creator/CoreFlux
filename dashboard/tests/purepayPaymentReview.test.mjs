import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url);
const React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/ap/ui/PurePayPaymentReview.jsx', import.meta.url))],
  bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture-api', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
  } }] });
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
const review = { tenant_id: 1, payment_id: 42, company_name: 'Synthetic company', vendor_name: 'Synthetic supplier', amount_cents: 12500,
  status: 'prepared', release_requested: false, review_hash: 'a'.repeat(64), reason: '',
  can_resume: true, can_release: true, can_reconcile: true, history: [], bills: [
    { bill_id: 10, invoice_number: 'INV-10', amount_cents: 5000, state: 'held', payment_status: 'unknown', checked_at: '2026-10-25 12:00:00' },
    { bill_id: 11, invoice_number: 'INV-11', amount_cents: 7500, state: 'unreserved', payment_status: 'unknown', checked_at: null },
  ] };
async function fixture(initial = review, respond = async () => ({ review: initial })) {
  const f = { calls: [], pinned: '1', current: structuredClone(initial) };
  const module = { exports: {} };
  const api = { getPinnedTenantId: () => f.pinned, api: {
    get: async (url, options) => { f.calls.push({ method: 'GET', url, options }); return { review: f.current }; },
    post: async (url, body, options) => { f.calls.push({ method: 'POST', url, body, options }); return respond(url, body, f); },
  } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id), module, module.exports);
  await act(async () => { f.tree = create(React.createElement(module.exports.default, { paymentId: 42, onClose() {} })); });
  f.button = label => f.tree.root.findAllByType('button').find(node => text(node) === label);
  f.click = async label => { const button = f.button(label); assert.ok(button, label); assert.ok(!button.props.disabled); await act(async () => button.props.onClick()); };
  f.reason = async value => act(async () => f.tree.root.findByType('textarea').props.onChange({ target: { value } }));
  f.close = () => act(() => f.tree.unmount());
  return f;
}
test('opening review fetches saved results only and identifies the original company and both bills', async () => {
  const f = await fixture();
  try {
    assert.equal(f.calls.length, 1); assert.equal(f.calls[0].method, 'GET');
    assert.equal(f.calls[0].options.headers['X-CoreFlux-Tenant-Id'], '1');
    for (const value of ['Synthetic company', 'INV-10', 'INV-11', '$50.00', '$75.00', 'No confirmed result']) assert.ok(text(f.tree.root).includes(value));
  } finally { f.close(); }
});
test('resuming requires a reason and explicit confirmation of the saved review', async () => {
  const f = await fixture();
  try {
    await f.click('Review resume'); assert.equal(f.calls.length, 1);
    assert.ok(f.button('Resume payment').props.disabled);
    assert.ok(text(f.tree.root).includes('This can send up to $125.00'));
    await f.reason('Independent approval'); await f.click('Resume payment');
    const post = f.calls.find(call => call.method === 'POST');
    assert.match(post.url, /action=purepay_resume$/);
    assert.deepEqual(post.body, { review_hash: review.review_hash, reason: 'Independent approval' });
    assert.equal(post.options.headers['X-CoreFlux-Tenant-Id'], '1');
  } finally { f.close(); }
});
test('cancellation is separate from voiding and a lost response does not retry the mutation', async () => {
  const f = await fixture(review, async (url, body, state) => {
    state.current = { ...review, release_requested: true, can_resume: false, status: 'needs_review' };
    throw new Error('Response interrupted');
  });
  try {
    await f.click('Cancel unexecuted bills');
    assert.ok(text(f.tree.root).includes('accounting entries are not voided'));
    await f.reason('Duplicate request'); await f.click('Confirm cancellation');
    assert.deepEqual(f.calls.map(call => call.method), ['GET', 'POST', 'GET']);
    assert.match(f.calls[1].url, /action=purepay_release$/);
    assert.ok(!f.button('Review resume')); assert.ok(f.button('Finish cancellation'));
    assert.ok(text(f.tree.root).includes('Response interrupted'));
  } finally { f.close(); }
});
test('refresh uses reconciliation only and read-only viewers have no mutation controls', async () => {
  const f = await fixture();
  try { await f.click('Refresh results'); assert.match(f.calls[1].url, /action=purepay_reconcile$/); } finally { f.close(); }
  const read = await fixture({ ...review, can_resume: false, can_release: false, can_reconcile: false });
  try { assert.ok(!read.button('Review resume')); assert.ok(!read.button('Cancel unexecuted bills')); assert.ok(read.button('Refresh results').props.disabled); } finally { read.close(); }
});
test('switching the selected company cannot send an old review into the new company', async () => {
  const f = await fixture();
  try {
    await f.click('Review resume'); await f.reason('Approved'); f.pinned = '2'; await f.click('Resume payment');
    assert.equal(f.calls.length, 1); assert.ok(text(f.tree.root).includes('The company changed'));
  } finally { f.close(); }
});
test('completed cancellation has no resume or finish-cancellation action', async () => {
  const f = await fixture({ ...review, status: 'cancelled', release_requested: true, can_resume: false, can_release: false });
  try { assert.ok(!f.button('Review resume')); assert.ok(!f.button('Finish cancellation')); assert.ok(!text(f.tree.root).includes('An attempt already in progress')); }
  finally { f.close(); }
});
