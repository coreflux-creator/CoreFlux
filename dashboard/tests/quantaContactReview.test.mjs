import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../src/pages/QuantaContactReview.jsx', import.meta.url))],
  bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'isolated-api', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
  } }] });
const profile = { first_name: 'Synthetic', last_name: 'Worker', email_primary: 'worker@example.com', phone_primary: null };
const review = { review_token: 'original-review', reference: { number: 'P-9108' }, current: profile,
  proposal: { baseline: { profile }, proposed: { ...profile, phone_primary: '2025550108' } },
  comparison: { state: 'change', conflicts: {} }, apply_enabled: true };
const row = { change_id: 'source-request', person_id: 108, person_number: 'P-9108', person_name: 'Synthetic Worker', changed_fields: ['phone_primary'], state: 'pending_review' };
const baseline = { profile, delivery_enabled: true, delivery: { id: 8, review_hash: 'baseline-review', state: 'preview' } };
const result = { change_id: row.change_id, decision: 'accepted', changed_fields: ['phone_primary'], quanta_return_state: 'pending' };
const text = n => typeof n === 'string' ? n : (n.children || []).map(text).join('');
const button = (f, label) => f.tree.root.findAllByType('button').find(n => text(n) === label);
async function fixture(respond = async action => action === 'profile_review_list' ? { rows: [row] }
  : action === 'profile_review_preview' ? structuredClone(review) : action === 'profile_baseline_preview' ? baseline : result) {
  const f = { pinned: '11', calls: [] }, module = { exports: {} };
  const api = { getPinnedTenantId: () => f.pinned, api: { post(url, body, options) {
    const action = url.split('action=')[1]; f.calls.push({ action, body, options }); return respond(action, body);
  } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id), module, module.exports);
  await act(async () => { f.tree = create(React.createElement(module.exports.default, { workspaceId: 11,
    people: [{ id: 108, name: 'Synthetic Worker', person_number: 'P-9108' }] })); });
  f.close = () => act(() => f.tree.unmount());
  f.click = async label => { const b = button(f, label); assert.ok(b, label); assert.equal(!!b.props.disabled, false, label);
    await act(async () => { await b.props.onClick(); }); };
  f.open = async () => { await f.click('Refresh contact requests'); await f.click('Review request'); };
  f.confirm = async label => act(async () => f.tree.root.findAllByType('input').find(n => text(n.parent).includes(label)).props.onChange({ target: { checked: true } }));
  return f;
}
test('approval needs explicit review and sends one scoped, immutable decision', async () => {
  const f = await fixture(); try {
    assert.equal(f.calls.length, 0); await f.open(); assert.equal(button(f, 'Accept in Core').props.disabled, true);
    assert.ok(text(f.tree.root).includes('Originally shared')); assert.ok(text(f.tree.root).includes('Core now'));
    await f.confirm('against Core'); await f.click('Accept in Core');
    const call = f.calls.at(-1); assert.equal(call.action, 'profile_review_apply');
    assert.deepEqual(call.body, { review_token: 'original-review', decision: 'accepted', reason: '', confirm_decision: true });
    assert.equal(call.options.headers['X-CoreFlux-Tenant-Id'], '11'); assert.ok(!button(f, 'Accept in Core'));
    assert.ok(text(f.tree.root).includes('See the saved decision below for its return status.'));
    assert.ok(!text(f.tree.root).includes('Awaiting review'));
    assert.equal(button(f, 'Review request').props.disabled, true);
    assert.ok(text(f.tree.root).includes('Core at review'));
  } finally { f.close(); }
});
test('conflicts disable acceptance while allowing a reasoned rejection', async () => {
  const f = await fixture(async action => action === 'profile_review_list' ? { rows: [row] } : action === 'profile_review_preview'
    ? { ...review, comparison: { state: 'conflict', conflicts: { phone_primary: {} } } } : { change_id: row.change_id, decision: 'rejected', changed_fields: [] });
  try { await f.open(); await f.confirm('against Core'); assert.equal(button(f, 'Accept in Core').props.disabled, true);
    assert.equal(button(f, 'Reject with note').props.disabled, true);
    await act(async () => f.tree.root.findByType('textarea').props.onChange({ target: { value: 'Please review the updated phone.' } }));
    await f.click('Reject with note'); assert.equal(f.calls.at(-1).body.decision, 'rejected');
    assert.ok(!text(f.tree.root).includes('Awaiting review'));
    assert.ok(!text(f.tree.root).includes('reject this request with guidance'));
    assert.equal(button(f, 'Review request').props.disabled, true);
  } finally { f.close(); }
});
test('unknown result is recovered by receipt without automatic repost', async () => {
  const f = await fixture(async action => action === 'profile_review_list' ? { rows: [row] } : action === 'profile_review_preview' ? review
    : action === 'profile_review_apply' ? Promise.reject(new Error('Lost acknowledgement')) : { state: 'decided', result });
  try { await f.open(); await f.confirm('against Core'); await f.click('Accept in Core');
    assert.ok(!button(f, 'Accept in Core')); await f.click('Check decision receipt');
    assert.equal(f.calls.filter(c => c.action === 'profile_review_apply').length, 1);
    assert.deepEqual(f.calls.at(-1).body, { review_token: 'original-review' });
    assert.ok(!text(f.tree.root).includes('Awaiting review'));
    assert.equal(button(f, 'Review request').props.disabled, true);
  } finally { f.close(); }
});
test('sharing a baseline cannot confirm a separate contact approval', async () => {
  const f = await fixture(); try { await f.open();
    await act(async () => f.tree.root.findByType('select').props.onChange({ target: { value: '108' } }));
    await f.click('Preview contact details'); await f.confirm('these contact details');
    assert.equal(button(f, 'Share contact snapshot').props.disabled, false); assert.equal(button(f, 'Accept in Core').props.disabled, true);
  } finally { f.close(); }
});
test('workspace switch blocks use of a still-visible review', async () => {
  const f = await fixture(); try { await f.open(); await f.confirm('against Core'); const count = f.calls.length;
    f.pinned = '12'; await f.click('Accept in Core'); assert.equal(f.calls.length, count);
    assert.ok(text(f.tree.root).includes('workspace changed'));
  } finally { f.close(); }
});
