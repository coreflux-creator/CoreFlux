import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/placements/ui/PartyCompanyReview.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture', setup(b) {
  b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
  b.onResolve({ filter: /CompanyTypeahead$/ }, () => ({ path: 'fixture-company', external: true }));
} }] });
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
async function fixture(kind = 'chain', error = false, pending = null) {
  const module = { exports: {} }, calls = []; let saved = 0;
  const api = { api: { async patch(path, body) { calls.push({ path, body }); if (pending) await pending; if (error) throw new Error('Company is no longer available'); } } };
  const Choice = props => React.createElement('company-choice', props);
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : id === 'fixture-company' ? { __esModule: true, default: Choice } : require(id), module, module.exports);
  let tree; await act(async () => { tree = create(React.createElement(module.exports.default, { kind, id: 27, onSaved: () => saved++ })); });
  const button = label => tree.root.findAllByType('button').find(n => text(n) === label);
  await act(async () => button('Choose company').props.onClick());
  return { tree, calls, saved: () => saved, button, choice: () => tree.root.findByType('company-choice'), close: () => act(() => tree.unmount()) };
}
for (const [kind, endpoint, field] of [['chain', 'chain', 'company_id'], ['referral', 'referrals', 'referrer_company_id']]) {
  test(`${kind} selection explicitly saves only the existing company identity`, async () => {
    const f = await fixture(kind); try {
      assert.equal(f.calls.length, 0); assert.ok(f.button('Save company').props.disabled);
      assert.match(text(f.tree.toJSON()), /rule set managed in Core/);
      await act(async () => f.choice().props.onChange({ id: 71, name: 'Reviewed company' }));
      assert.equal(f.calls.length, 0); assert.equal(f.choice().props.role, undefined);
      await act(async () => f.button('Save company').props.onClick());
      assert.deepEqual(f.calls, [{ path: `/modules/placements/api/${endpoint}.php?id=27`, body: { [field]: 71 } }]);
      assert.equal(f.saved(), 1);
    } finally { f.close(); }
  });
}
test('editing a selected company or entering a name cannot submit the stale identity', async () => {
  const f = await fixture(); try {
    await act(async () => f.choice().props.onChange({ id: 71, name: 'Reviewed company' }));
    await act(async () => f.choice().props.onQueryChange('different'));
    assert.ok(f.button('Save company').props.disabled);
    await act(async () => f.button('Save company').props.onClick());
    await act(async () => f.choice().props.onChange({ id: null, name: 'Free text' }));
    await act(async () => f.button('Save company').props.onClick());
    assert.equal(f.calls.length, 0);
    await act(async () => f.button('Cancel').props.onClick());
    await act(async () => f.button('Choose company').props.onClick());
    assert.equal(f.choice().props.value, null);
  } finally { f.close(); }
});
test('a rejected company selection stays open and does not report success', async () => {
  const f = await fixture('referral', true); try {
    await act(async () => f.choice().props.onChange({ id: 71, name: 'Retired company' }));
    await act(async () => f.button('Save company').props.onClick());
    assert.match(text(f.tree.toJSON()), /Company is no longer available/);
    assert.equal(f.saved(), 0); assert.equal(f.choice().props.value.id, 71);
  } finally { f.close(); }
});
test('a completed save does not refresh a different placement after navigation', async () => {
  let resolve; const pending = new Promise(done => { resolve = done; });
  const f = await fixture('chain', false, pending);
  await act(async () => f.choice().props.onChange({ id: 71, name: 'Reviewed company' }));
  let saving; await act(async () => { saving = f.button('Save company').props.onClick(); });
  f.close(); await act(async () => { resolve(); await saving; });
  assert.equal(f.saved(), 0); assert.equal(f.calls.length, 1);
});
