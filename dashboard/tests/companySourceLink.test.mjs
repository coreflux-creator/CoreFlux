import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/people/ui/CompanySourceLink.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
async function fixture({ error = null, saved = false, recordType = 'companies' } = {}) {
  const module = { exports: {} }, calls = []; let linked = 0;
  const mock = { api: { async post(path, body) {
    calls.push({ path, body });
    if (path.endsWith('preview')) return { review_token: 'opaque-review', source: { id: body.source_id, name: 'Source name', phone: 'Source phone', email: 'source@example.invalid', company_id: '1001' }, target: { company_number: 'CO-10', company_id: 10, company_name: 'Core company', name: 'Core name', phone: 'Core phone', email: 'core@example.invalid' } };
    const result = { source_id: '1001', record_id: 10 };
    if (path.endsWith('status')) return { state: saved ? 'applied' : 'not_applied', result: saved ? result : null };
    if (error) throw new Error(error);
    return result;
  } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? mock : require(id), module, module.exports);
  let tree; await act(async () => { tree = create(React.createElement(module.exports.default, { id: 10, recordType, onLinked: () => { linked++; } })); });
  const button = label => tree.root.findAllByType('button').find(n => text(n) === label);
  const input = () => tree.root.findByProps({ inputMode: 'numeric' });
  const confirm = () => tree.root.findByProps({ type: 'checkbox' });
  const preview = async () => { await act(async () => button('Link JobDiva record').props.onClick()); await act(async () => input().props.onChange({ target: { value: '1001' } })); await act(async () => button('Preview identity link').props.onClick()); };
  return { tree, calls, linked: () => linked, button, input, confirm, preview, close: () => act(() => tree.unmount()) };
}
test('linking requires exact identity preview and explicit confirmation, preserving the opaque review', async () => {
  const f = await fixture(); try {
    assert.equal(f.calls.length, 0); await f.preview();
    assert.deepEqual(f.calls[0].body, { record_type: 'companies', record_id: 10, source_id: '1001' });
    assert.match(text(f.tree.toJSON()), /Core name/); assert.match(text(f.tree.toJSON()), /Source name/);
    assert.ok(f.button('Confirm identity link').props.disabled);
    await act(async () => f.button('Confirm identity link').props.onClick()); assert.equal(f.calls.length, 1);
    await act(async () => f.confirm().props.onChange({ target: { checked: true } }));
    await act(async () => f.button('Confirm identity link').props.onClick());
    assert.deepEqual(f.calls[1].body, { review_token: 'opaque-review', confirm_review: true });
    assert.equal(f.linked(), 1); assert.match(text(f.tree.toJSON()), /is linked/);
  } finally { f.close(); }
});
test('changing the source ID clears the old review and confirmation', async () => {
  const f = await fixture(); try {
    await f.preview(); await act(async () => f.confirm().props.onChange({ target: { checked: true } }));
    await act(async () => f.input().props.onChange({ target: { value: '2001' } }));
    assert.equal(f.button('Confirm identity link'), undefined);
    await act(async () => f.button('Preview identity link').props.onClick());
    assert.equal(f.calls.at(-1).body.source_id, '2001'); assert.equal(f.confirm().props.checked, false);
    await act(async () => f.input().props.onChange({ target: { value: 'name-only' } }));
    assert.ok(f.button('Preview identity link').props.disabled);
  } finally { f.close(); }
});
test('contact review displays parent proof and uses the contact record type', async () => {
  const f = await fixture({ recordType: 'company_contacts' }); try {
    await f.preview(); assert.equal(f.calls[0].body.record_type, 'company_contacts');
    assert.match(text(f.tree.toJSON()), /1001 · already linked to this Core company/);
    assert.match(text(f.tree.toJSON()), /core@example.invalid/); assert.match(text(f.tree.toJSON()), /source@example.invalid/);
  } finally { f.close(); }
});
test('failed or stale commit remains reviewable and does not claim completion', async () => {
  const f = await fixture({ error: 'The Core record changed. Preview again.' }); try {
    await f.preview(); await act(async () => f.confirm().props.onChange({ target: { checked: true } }));
    await act(async () => f.button('Confirm identity link').props.onClick());
    assert.equal(f.linked(), 0); assert.match(text(f.tree.root.findByProps({ role: 'alert' })), /Preview again/);
    await act(async () => f.button('Check saved result').props.onClick());
    assert.match(text(f.tree.root.findByProps({ role: 'alert' })), /not been applied/); assert.equal(f.linked(), 0);
  } finally { f.close(); }
});
test('lost response can recover the saved result without sending another link request', async () => {
  const f = await fixture({ error: 'Response lost', saved: true }); try {
    await f.preview(); await act(async () => f.confirm().props.onChange({ target: { checked: true } }));
    await act(async () => f.button('Confirm identity link').props.onClick());
    await act(async () => f.button('Check saved result').props.onClick());
    assert.equal(f.calls.filter(c => c.path.endsWith('apply')).length, 1);
    assert.deepEqual(f.calls.at(-1).body, { review_token: 'opaque-review' });
    assert.equal(f.linked(), 1); assert.match(text(f.tree.toJSON()), /is linked/);
  } finally { f.close(); }
});
