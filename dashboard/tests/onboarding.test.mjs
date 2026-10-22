import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url);
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');
const { create, act } = require('react-test-renderer');
let apiImpl;
const built = await build({ entryPoints: [fileURLToPath(new URL('../../modules/people/ui/OnboardingTab.jsx', import.meta.url))],
  bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture-api', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'onboarding-fixture-api', external: true }));
  } }] });
const mod = { exports: {} };
new Function('require', 'module', 'exports', built.outputFiles[0].text)(name => name === 'onboarding-fixture-api' ? { api: { get: (...a) => apiImpl.get(...a), post: (...a) => apiImpl.post(...a) } } : require(name), mod, mod.exports);
const value = { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', person_id: 1, person_number: 'P-1', placement_id: 2, placement_number: 'PL-2', version: 2,
  plan_version: 2, confirmed_plan_version: null, source_changed: true, readiness: 'requirements_unconfirmed', required_count: 1, satisfied_count: 0,
  observed_at: '2026-10-22T22:00:00Z', tasks: [{ id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', version: 1, title: 'Orientation', instructions: 'Review orientation.', assignee: 'staff', evidence_kind: 'attestation', required: true, state: 'requested', effective_state: 'requested', evidence: null, review: null }], history: [] };
const permissions = { view: true, manage: true, review: true };
const catalog = { cases: [{ id: value.id, placement_id: 2 }], placements: [{ id: 2, placement_number: 'PL-2', title: 'Synthetic placement', status: 'active' }], documents: [], permissions };
const render = (v = value, p = permissions) => renderToStaticMarkup(React.createElement(mod.exports.OnboardingCaseView, { value: v, permissions: p, command: () => {} }));
const button = (tree, label) => tree.root.findAllByType('button').find(b => b.children.join('') === label);

test('empty/unconfirmed case cannot advertise completed onboarding or portal publication', () => {
  const html = render({ ...value, tasks: [], required_count: 0 });
  assert.ok(html.includes('Requirement list needs confirmation'));
  assert.ok(html.includes('An empty case is not complete'));
  assert.ok(html.includes('has not been published to Quanta'));
  assert.ok(!html.includes('Confirmed requirements complete'));
  assert.ok(html.includes('disabled="">Confirm this is the full requirement list'));
});
test('completed case still separates payroll authority and portal delivery', () => {
  const html = render({ ...value, readiness: 'complete', satisfied_count: 1, confirmed_plan_version: 2, source_changed: false });
  assert.ok(html.includes('Confirmed requirements complete'));
  assert.ok(html.includes('Payroll authorization is evaluated separately'));
  assert.ok(html.includes('has not been published to Quanta'));
});
test('read-only capability hides all mutation controls and preserves evidence provenance', () => {
  const v = { ...value, tasks: [{ ...value.tasks[0], state: 'submitted', effective_state: 'needs_review', evidence: { source: 'core_staff', actor_user_id: 90, submitted_at: value.observed_at, document_id: 1 } }] };
  const html = render(v, { view: true, manage: false, review: false });
  assert.ok(!html.includes('<button'));
  assert.ok(html.includes('Recorded by Core staff #90'));
  assert.ok(html.includes('Document changed — resubmit'));
  assert.ok(html.includes('/modules/people/1/documents'));
});
test('source changes require explicit confirmation and all user text is escaped', () => {
  const html = render({ ...value, confirmed_plan_version: 2, tasks: [{ ...value.tasks[0], title: '<script>unsafe</script>' }] });
  assert.ok(html.includes('Placement setup changed'));
  assert.ok(html.includes('Confirm this is the full requirement list'));
  assert.ok(!html.includes('<script>'));
});
test('response loss retains the same request identity and conditional version on retry', async () => {
  const posts = [];
  apiImpl = { get: async path => path.includes('case_id=') ? { case: value, permissions } : catalog,
    post: async (_path, body) => { posts.push(body); if (posts.length === 1) throw new Error('Connection lost'); return { case: { ...value, version: 3, confirmed_plan_version: 2, source_changed: false }, permissions }; } };
  let tree;
  await act(async () => { tree = create(React.createElement(mod.exports.default, { personId: 1 })); });
  await act(async () => { button(tree, 'Confirm this is the full requirement list').props.onClick(); });
  assert.equal(posts.length, 1); assert.equal(posts[0].expected_version, 2);
  assert.ok(button(tree, 'Reload cases and documents').props.disabled);
  await act(async () => { button(tree, 'Retry the same change').props.onClick(); });
  assert.equal(posts.length, 2); assert.deepEqual(posts[0], posts[1]);
  assert.ok(!button(tree, 'Retry the same change'));
  await act(async () => tree.unmount());
});
test('a version conflict removes stale case actions until the user reloads', async () => {
  apiImpl = { get: async path => path.includes('case_id=') ? { case: value, permissions } : catalog,
    post: async () => { throw Object.assign(new Error('This case changed. Reload.'), { status: 409 }); } };
  let tree; await act(async () => { tree = create(React.createElement(mod.exports.default, { personId: 1 })); });
  await act(async () => { button(tree, 'Confirm this is the full requirement list').props.onClick(); });
  assert.ok(!button(tree, 'Confirm this is the full requirement list'));
  assert.ok(!button(tree, 'Record evidence'));
  assert.equal(tree.root.findByProps({ role: 'alert' }).children.join(''), 'This case changed. Reload.');
  assert.ok(!button(tree, 'Reload cases and documents').props.disabled);
  await act(async () => tree.unmount());
});
