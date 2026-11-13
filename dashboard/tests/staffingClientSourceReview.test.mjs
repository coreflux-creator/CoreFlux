import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const text = n => typeof n === 'string' ? n : (n.children || []).map(text).join('');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/staffing/ui/ClientSourceReview.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
async function fixture(error = null) {
  const calls = [], module = { exports: {} }; let saved = 0;
  const api = { api: { get: async () => ({ review: { review_token: 'observed-version', current: { primary_contact_phone: '' }, observations: { qb: { source: { system: 'quickbooks_online', external_id: 'source-2' }, proposed: { primary_contact_phone: '123' } }, jd: { source: { system: 'jobdiva', external_id: 'source-1' }, proposed: { primary_contact_phone: '456' } } } } }), post: async (path, body) => { calls.push({ path, body }); if (error) throw new Error(error); return { replayed: false }; } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id), module, module.exports);
  let tree; await act(async () => { tree = create(React.createElement(module.exports.default, { client: { id: 17, name: 'Synthetic client' }, onSaved: () => saved++ })); });
  return { tree, calls, saved: () => saved, field: label => tree.root.findByProps({ 'aria-label': label }), button: label => tree.root.findAllByType('button').find(n => text(n) === label), close: () => act(() => tree.unmount()) };
}
test('client review identifies each provider and submits only the confirmed observed values', async () => {
  const f = await fixture(); try {
    assert.match(text(f.tree.root), /QuickBooks · source-2/); assert.match(text(f.tree.root), /JobDiva · source-1/);
    assert.ok(f.button('Apply selected values').props.disabled);
    await act(async () => f.field('Use incoming Primary contact phone').props.onChange({ target: { checked: true } }));
    await act(async () => f.field('Confirm selected client values').props.onChange({ target: { checked: true } }));
    await act(async () => f.field('Client source').props.onChange({ target: { value: 'jd' } }));
    assert.ok(f.button('Apply selected values').props.disabled);
    await act(async () => f.field('Use incoming Primary contact phone').props.onChange({ target: { checked: true } }));
    await act(async () => f.field('Confirm selected client values').props.onChange({ target: { checked: true } }));
    await act(async () => f.button('Apply selected values').props.onClick());
    assert.deepEqual(f.calls[0].body, { source_key: 'jd', fields: ['primary_contact_phone'], review_token: 'observed-version', confirm_source: true });
    assert.equal(f.saved(), 1); assert.match(text(f.tree.root), /Core keeps control/);
  } finally { f.close(); }
});
test('a stale source review retains the selected values without reporting success', async () => {
  const f = await fixture('The source changed. Refresh the review.'); try {
    await act(async () => f.field('Use incoming Primary contact phone').props.onChange({ target: { checked: true } }));
    await act(async () => f.field('Confirm selected client values').props.onChange({ target: { checked: true } }));
    await act(async () => f.button('Apply selected values').props.onClick());
    assert.equal(f.saved(), 0); assert.equal(f.field('Use incoming Primary contact phone').props.checked, true);
    assert.match(text(f.tree.root), /Refresh the review/);
  } finally { f.close(); }
});
