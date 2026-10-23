import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
let apiImpl;
const built = await build({ entryPoints: [fileURLToPath(new URL('../../modules/people/ui/OnboardingQuanta.jsx', import.meta.url))],
  bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture-api', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'onboarding-fixture-api', external: true }));
  } }] });
const mod = { exports: {} };
new Function('require', 'module', 'exports', built.outputFiles[0].text)(name => name === 'onboarding-fixture-api' ? { api: { get: (...a) => apiImpl.get(...a), post: (...a) => apiImpl.post(...a) } } : require(name), mod, mod.exports);
const value = { id: 'case-a', version: 4, person_number: 'P-1', placement_number: 'PL-1', tasks: [{ id: 'task-a', title: 'Orientation' }] };
const status = { workspace: { id: 'a', name: 'Synthetic workspace' }, publication: null, delivery_enabled: true, receipts: [], deliveries: [] };
const preview = { status: 'preview', workspace: status.workspace, delivery_enabled: true, delivery: { id: 18, review_hash: 'review-hash' },
  proposed: { state: 'published', tasks: [{ id: 'task-a', title: 'Orientation', instructions: 'Read these instructions.', assignee: 'worker', evidence_kind: 'attestation', required: true }] } };
const button = (t, label) => t.root.findAllByType('button').find(b => b.children.join('') === label);
async function mount(props = {}) { let tree; await act(async () => { tree = create(React.createElement(mod.exports.default, { value, canManage: true, onImported: async () => {}, ...props })); }); return tree; }
async function click(t, label) { await act(async () => { button(t, label).props.onClick(); }); }

test('portal status is explicitly fetched and readonly staff cannot publish', async () => {
  let calls = 0; apiImpl = { get: async () => { calls++; return status; } };
  const tree = await mount({ canManage: false }); assert.equal(calls, 0);
  await click(tree, 'Check portal and worker submissions');
  assert.equal(calls, 1); assert.equal(button(tree, 'Review publication'), undefined);
  assert.ok(JSON.stringify(tree.toJSON()).includes('No publication found')); tree.unmount();
});
test('publication shows requirements and sends only the reviewed delivery identity', async () => {
  const posts = []; apiImpl = { get: async () => status, post: async (_, body) => { posts.push(body); return { result: preview }; } };
  const tree = await mount(); await click(tree, 'Check portal and worker submissions'); await click(tree, 'Review publication');
  assert.ok(JSON.stringify(tree.toJSON()).includes('Read these instructions.'));
  await click(tree, 'Confirm publication');
  assert.deepEqual(posts[1], { action: 'send', case_id: 'case-a', delivery_id: 18, review_hash: 'review-hash' }); tree.unmount();
});
test('unconfirmed publication keeps the identical request and prevents a second preview', async () => {
  const posts = []; apiImpl = { get: async () => status, post: async (_, body) => { posts.push(body); if(body.action === 'preview')return { result: preview }; throw new Error('Lost response'); } };
  const tree = await mount(); await click(tree, 'Check portal and worker submissions'); await click(tree, 'Review publication'); await click(tree, 'Confirm publication');
  assert.equal(button(tree, 'Review publication').props.disabled, true);
  await click(tree, 'Check the same request'); assert.deepEqual(posts[1], posts[2]); tree.unmount();
});
test('import sends receipt selection and expected Core revision, never browser-supplied evidence', async () => {
  const posts = []; let imported = 0;
  apiImpl = { get: async () => ({ ...status, receipts: [{ id: 'receipt-a', task_id: 'task-a', delivery_id: 18, imported: false, submitted_at: '2026-10-22' }] }),
    post: async (_, body) => { posts.push(body); return { result: { action: 'import_quanta' } }; } };
  const tree = await mount({ onImported: async () => { imported++; } }); await click(tree, 'Check portal and worker submissions'); await click(tree, 'Import for review');
  assert.deepEqual(posts[0], { action: 'import', case_id: 'case-a', receipt_id: 'receipt-a', delivery_id: 18, expected_version: 4 }); assert.equal(imported, 1); tree.unmount();
});
test('missing publication proof cannot offer import and disabled outbound cannot send', async () => {
  apiImpl = { get: async () => ({ ...status, delivery_enabled: false, receipts: [{ id: 'r', task_id: 'task-a', imported: false }] }),
    post: async () => ({ result: { ...preview, delivery_enabled: false } }) };
  const tree = await mount(); await click(tree, 'Check portal and worker submissions'); assert.equal(button(tree, 'Import for review'), undefined);
  await click(tree, 'Review publication'); assert.equal(button(tree, 'Confirm publication').props.disabled, true); tree.unmount();
});
test('case revision change discards a prior portal review', async () => {
  apiImpl = { get: async () => status, post: async () => ({ result: preview }) };
  const tree = await mount(); await click(tree, 'Check portal and worker submissions'); await click(tree, 'Review publication');
  await act(async () => { tree.update(React.createElement(mod.exports.default, { value: { ...value, version: 5 }, canManage: true })); });
  assert.equal(button(tree, 'Confirm publication'), undefined); tree.unmount();
});
