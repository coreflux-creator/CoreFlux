import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { webcrypto } from 'node:crypto';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
globalThis.crypto ||= webcrypto;
const text = n => typeof n === 'string' ? n : (n.children || []).map(text).join('');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../src/pages/JobDivaPeopleLifecycleReview.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
async function fixture({ error = null, previewError = null, capabilities = {}, rows } = {}) {
  const calls = [], module = { exports: {} }; let saved = 0, applies = 0;
  const review = { review_token: 'observed-token', limit: 5000, can_manage: true, can_inactivate: true, rows: rows || [
    { id: 17, person_number: 'P-17', name: 'Synthetic active worker', status: 'active', last_placement_end_date: '2026-01-31' },
    { id: 18, person_number: 'P-18', name: 'Synthetic bench worker', status: 'bench' },
  ], warnings: [], ...capabilities };
  const api = { api: { post: async (path, body) => {
    calls.push({ path, body });
    if (body.dry_run) { if (previewError) throw new Error(previewError); return { repair: review }; }
    applies++; if (error && applies === 1) throw new Error(error);
    return { repair: { updated: 1, replayed: applies > 1 } };
  } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id), module, module.exports);
  let tree; await act(async () => { tree = create(React.createElement(module.exports.default, { onSaved: () => saved++ })); });
  return { tree, calls, saved: () => saved, field: label => tree.root.findByProps({ 'aria-label': label }), button: prefix => tree.root.findAllByType('button').find(n => text(n).startsWith(prefix)), close: () => act(() => tree.unmount()) };
}
const choose = async (f, person, value) => act(async () => f.field(`Availability for ${person}`).props.onChange({ target: { value } }));
const reason = async f => act(async () => f.field('Reason for availability changes').props.onChange({ target: { value: 'Reviewed availability' } }));

test('availability defaults to Keep current, retains bench and submits only explicit changed selections', async () => {
  const f = await fixture(); try {
    assert.equal(f.calls.length, 1); assert.ok(f.calls[0].body.dry_run);
    assert.equal(f.field('Availability for P-17').props.value, ''); assert.equal(f.field('Availability for P-18').props.value, '');
    assert.ok(f.button('Save').props.disabled);
    await choose(f, 'P-18', 'bench'); await reason(f); assert.ok(f.button('Save').props.disabled);
    await choose(f, 'P-17', 'inactive'); assert.equal(f.button('Save').props.disabled, false);
    await act(async () => f.button('Save').props.onClick());
    assert.deepEqual(f.calls[1].body.decisions, { 17: 'inactive' });
    assert.equal(f.calls[1].body.review_token, 'observed-token'); assert.equal(f.calls[1].body.confirm, 'APPLY_REVIEWED_PEOPLE_AVAILABILITY');
    assert.match(f.calls[1].body.request_id, /^[a-f0-9-]{36}$/); assert.equal(f.saved(), 1);
    assert.match(text(f.tree.root), /Employment, sign-in access, assignment history and payroll records remain unchanged/);
  } finally { f.close(); }
});

test('an ambiguous save freezes the exact request and retries it without changing its receipt ID', async () => {
  const f = await fixture({ error: 'Connection interrupted' }); try {
    await choose(f, 'P-17', 'bench'); await reason(f); await act(async () => f.button('Save').props.onClick());
    assert.equal(f.saved(), 0); assert.match(text(f.tree.root), /Connection interrupted/);
    assert.ok(f.field('Availability for P-17').props.disabled); assert.ok(f.field('Reason for availability changes').props.disabled);
    await act(async () => f.button('Retry original save').props.onClick());
    assert.deepEqual(f.calls[2].body, f.calls[1].body); assert.equal(f.saved(), 1); assert.match(text(f.tree.root), /original save confirmed/);
  } finally { f.close(); }
});

test('refresh after stale evidence clears choices, reason and the pending request', async () => {
  const f = await fixture({ error: 'People changed. Refresh the availability review.' }); try {
    await choose(f, 'P-17', 'inactive'); await reason(f); await act(async () => f.button('Save').props.onClick());
    await act(async () => f.button('Refresh review').props.onClick());
    assert.equal(f.field('Availability for P-17').props.value, ''); assert.equal(f.field('Reason for availability changes').props.value, '');
    assert.ok(f.button('Save').props.disabled); assert.equal(f.saved(), 0);
    await choose(f, 'P-17', 'bench'); await reason(f); await act(async () => f.button('Save').props.onClick());
    assert.notEqual(f.calls[3].body.request_id, f.calls[1].body.request_id);
  } finally { f.close(); }
});

test('read-only reviewers cannot choose or save and non-admin managers cannot choose Inactive', async () => {
  for (const canManage of [false, true]) {
    const f = await fixture({ capabilities: { can_manage: canManage, can_inactivate: false } }); try {
      assert.equal(f.field('Availability for P-17').props.disabled, !canManage);
      assert.equal(f.field('Availability for P-17').findAllByType('option').some(n => n.props.value === 'inactive'), false);
      assert.ok(f.button('Save').props.disabled); assert.equal(f.calls.length, 1);
    } finally { f.close(); }
  }
});

test('empty, failed and partial previews do not masquerade as completed writes', async () => {
  for (const options of [{ rows: [] }, { previewError: 'Permission denied' }, { capabilities: { truncated: true, checked: 5000, total: 5100 } }]) {
    const f = await fixture(options); try {
      assert.ok(f.button('Save').props.disabled); assert.equal(f.saved(), 0); assert.equal(f.calls.length, 1);
      if (options.rows) assert.match(text(f.tree.root), /No people currently require/);
      if (options.previewError) assert.match(text(f.tree.root), /Permission denied/);
      if (options.capabilities) assert.match(text(f.tree.root), /first 5000 of 5100/);
    } finally { f.close(); }
  }
});
