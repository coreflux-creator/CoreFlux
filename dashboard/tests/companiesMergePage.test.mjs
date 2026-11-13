import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/people/ui/CompaniesMerge.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react', 'react-router-dom'], plugins: [{ name: 'fixture', setup(b) {
  b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
  b.onResolve({ filter: /CompanyMergeReview$/ }, () => ({ path: 'fixture-review', external: true }));
} }] });
const text = node => typeof node === 'string' ? node : (node?.children || []).map(text).join('');
const review = { review_token: 'reviewed-token', survivor: { id: 11 }, retired: { id: 22 } };
async function fixture(post) {
  const module = { exports: {} }, calls = []; let reloads = 0;
  const api = { api: { post: async (path, body) => { calls.push({ path, body }); return post(path, body); } }, useApi: () => ({ data: { can_merge: true, groups: [{ normalized: 'acme', companies: [{ id: 11, company_number: 'CO-11', name: 'Acme LLC', use_count: 5 }, { id: 22, company_number: 'CO-22', name: 'Acme Inc', use_count: 1 }] }] }, reload: () => reloads++ }) };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : id === 'fixture-review' ? { __esModule: true, default: props => React.createElement('merge-review', props) } : id === 'react-router-dom' ? { Link: props => React.createElement('a', props, props.children) } : require(id), module, module.exports);
  let tree; await act(async () => { tree = create(React.createElement(module.exports.default)); });
  return { tree, calls, reloads: () => reloads, preview: () => tree.root.findByProps({ 'data-testid': 'companies-merge-into-survivor-22' }).props.onClick(), review: () => tree.root.findByType('merge-review'), close: () => act(() => tree.unmount()) };
}
test('merge page retains the reviewed token after a lost response and accepts a replay receipt', async () => {
  let attempts = 0;
  const f = await fixture(async path => {
    if (path.endsWith('merge_preview')) return { review };
    if (++attempts === 1) throw new Error('Response lost');
    return { replayed: true, company_number: 'CO-11', retired_number: 'CO-22' };
  });
  try {
    await act(async () => f.preview());
    assert.deepEqual(f.calls[0].body, { survivor_id: 11, retired_id: 22 });
    const choices = { fields: { name: 'retired' }, custom_fields: {}, primary_contact_id: 0, primary_addresses: {} };
    await act(async () => f.review().props.onApply(choices));
    assert.equal(f.review().props.review.review_token, 'reviewed-token'); assert.equal(f.review().props.error, 'Response lost'); assert.equal(f.reloads(), 0);
    await act(async () => f.review().props.onApply(choices));
    assert.deepEqual(f.calls[1], f.calls[2]); assert.equal(f.calls[2].body.confirm_merge, true);
    assert.match(text(f.tree.toJSON()), /already applied. Later edits were preserved/); assert.equal(f.reloads(), 1);
  } finally { f.close(); }
});
test('merge completion after navigation cannot refresh another directory', async () => {
  let resolve; const pending = new Promise(done => { resolve = done; });
  const f = await fixture(async path => path.endsWith('merge_preview') ? { review } : pending);
  await act(async () => f.preview()); let saving;
  await act(async () => { saving = f.review().props.onApply({ fields: {} }); });
  assert.equal(f.review().props.busy, true); f.close();
  await act(async () => { resolve({ company_number: 'CO-11', retired_number: 'CO-22' }); await saving; });
  assert.equal(f.reloads(), 0);
});
test('refresh requests a new review and cancel performs no merge', async () => {
  let count = 0;
  const f = await fixture(async () => ({ review: { ...review, review_token: `token-${++count}` } }));
  try {
    await act(async () => f.preview()); await act(async () => f.review().props.onRefresh());
    assert.equal(f.review().props.review.review_token, 'token-2');
    await act(async () => f.review().props.onCancel());
    assert.equal(f.tree.root.findAllByType('merge-review').length, 0);
    assert.equal(f.calls.filter(c => c.path.endsWith('action=merge')).length, 0);
  } finally { f.close(); }
});
