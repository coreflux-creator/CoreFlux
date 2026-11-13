import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/people/ui/CompanySourceReview.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
async function fixture({ error = null, recordType = 'companies' } = {}) {
  const module = { exports: {} }, calls = [];
  let revision = 1, accepted = 0;
  const mock = { api: {
    async get(path) { calls.push({ method: 'GET', path }); return { review: { source: { external_id: 'provider-10' },
      current: { phone: null, name: 'Core name' }, proposed: { phone: 'Source phone', name: 'Source name' }, owned_fields: ['phone','name'],
      legacy_unreviewed: true, review_token: `review-${revision++}` } }; },
    async post(path, body) { calls.push({ method: 'POST', path, body }); if (error) throw new Error(error); return { replayed: false }; },
  } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? mock : require(id), module, module.exports);
  let tree; await act(async () => { tree = create(React.createElement(module.exports.default, { id: 10, recordType, onAccepted: () => { accepted++; } })); });
  return { tree, calls, accepted: () => accepted, button: label => tree.root.findAllByType('button').find(n => text(n) === label),
    field: label => tree.root.findByProps({ 'aria-label': label }), confirmation: () => tree.root.findAllByType('input').find(n => !n.props['aria-label']),
    close: () => act(() => tree.unmount()) };
}

test('source review requires a field selection and explicit confirmation, and sends the observed token', async () => {
  const f = await fixture();
  try {
    assert.equal(f.calls.length, 0);
    await act(async () => f.button('Review source values').props.onClick());
    assert.ok(text(f.tree.root).includes('Blank'));
    assert.ok(text(f.tree.root).includes('Source phone'));
    assert.ok(f.button('Use selected JobDiva values').props.disabled);
    await act(async () => f.field('Use JobDiva for Phone').props.onChange({ target: { checked: true } }));
    assert.ok(f.button('Use selected JobDiva values').props.disabled);
    await act(async () => f.confirmation().props.onChange({ target: { checked: true } }));
    await act(async () => f.button('Use selected JobDiva values').props.onClick());
    const sent = f.calls.find(c => c.method === 'POST');
    assert.deepEqual(sent.body, { fields: ['phone'], review_token: 'review-1', confirm_source: true });
    assert.match(sent.path, /record_type=companies.*action=source_accept/);
    assert.equal(f.accepted(), 1);
  } finally { f.close(); }
});

test('refresh clears prior choices and confirmation before presenting a new review', async () => {
  const f = await fixture({ recordType: 'company_contacts' });
  try {
    await act(async () => f.button('Review source values').props.onClick());
    await act(async () => f.field('Use JobDiva for Phone').props.onChange({ target: { checked: true } }));
    await act(async () => f.confirmation().props.onChange({ target: { checked: true } }));
    await act(async () => f.button('Refresh review').props.onClick());
    assert.equal(f.field('Use JobDiva for Phone').props.checked, false);
    assert.equal(f.confirmation().props.checked, false);
    assert.ok(f.button('Use selected JobDiva values').props.disabled);
    assert.ok(f.calls.every(c => c.method === 'GET' && c.path.includes('record_type=company_contacts')));
  } finally { f.close(); }
});

test('a rejected stale review remains visible and never reports success or refreshes silently', async () => {
  const f = await fixture({ error: 'Company or source values changed. Refresh and review again.' });
  try {
    await act(async () => f.button('Review source values').props.onClick());
    await act(async () => f.field('Use JobDiva for Phone').props.onChange({ target: { checked: true } }));
    await act(async () => f.confirmation().props.onChange({ target: { checked: true } }));
    await act(async () => f.button('Use selected JobDiva values').props.onClick());
    assert.match(text(f.tree.root.findByProps({ role: 'alert' })), /Refresh and review again/);
    assert.equal(f.accepted(), 0);
    assert.equal(f.calls.filter(c => c.method === 'GET').length, 1);
  } finally { f.close(); }
});
