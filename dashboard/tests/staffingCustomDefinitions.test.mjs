import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react'), { create, act } = require('react-test-renderer');
const source = await readFile(new URL('../../modules/people/ui/CustomFields.jsx', import.meta.url), 'utf8');
const compiled = await build({ stdin: { contents: source.replace("'../../../dashboard/src/lib/api'", "'fixture-api'"), loader: 'jsx' }, bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react', 'fixture-api'] });
const text = n => typeof n === 'string' ? n : (n.children || []).map(text).join('');
async function fixture({ entityType = 'people', manage = true, sensitive = false, archiveError = false } = {}) {
  const f = { calls: [], reloads: 0 }, module = { exports: {} };
  const adapter = { getPinnedTenantId: () => '2002', useApi: () => ({ loading: false, error: null, reload: () => f.reloads++, data: {
    entity_type: entityType, can_manage_definitions: manage, can_manage_sensitive: sensitive,
    fields: [{ id: 1, field_key: 'existing', field_label: 'Existing', field_type: 'boolean', required: '0', pii: '0' }, { id: 2, field_key: 'private', field_label: 'Sensitive field', field_type: 'text', pii: 1 }],
  } }), api: { post: async (url, body) => { f.calls.push({ url, body }); }, delete: async url => { f.calls.push({ url }); if (archiveError) throw new Error('Archive was refused'); } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? adapter : require(id), module, module.exports);
  await act(async () => { f.tree = create(React.createElement(module.exports.default, { entityType })); });
  f.control = key => f.tree.root.findByProps({ 'data-testid': `custom-fields-${key}` });
  f.change = async (key, target) => act(async () => f.control(key).props.onChange({ target }));
  f.close = () => act(() => f.tree.unmount());
  return f;
}
for (const entityType of ['people', 'companies', 'placements']) test(`${entityType} creates an explicit choice definition in the right directory`, async () => {
  const f = await fixture({ entityType });
  try {
    await f.change('key', { value: 'contract_type' }); await f.change('label', { value: 'Contract type' });
    await f.change('type', { value: 'multiselect' }); await f.change('options', { value: 'Alpha\nBeta' });
    await act(async () => f.control('add-form').props.onSubmit({ preventDefault() {} }));
    assert.equal(f.calls[0].url, `/api/custom_field_definitions.php?entity_type=${entityType}`);
    assert.deepEqual(f.calls[0].body.options, ['Alpha', 'Beta']); assert.equal(f.calls[0].body.pii, false);
    assert.equal(f.reloads, 1);
  } finally { f.close(); }
});
test('reader has no definition creation or archive controls', async () => {
  const f = await fixture({ manage: false }); try { assert.equal(f.tree.root.findAllByType('form').length, 0); assert.equal(f.tree.root.findAllByType('button').length, 0); } finally { f.close(); }
});
test('ordinary field manager cannot create sensitive fields or archive existing sensitive definitions', async () => {
  const f = await fixture(); try { assert.equal(f.tree.root.findAllByProps({ 'data-testid': 'custom-fields-pii' }).length, 0); assert.equal(f.tree.root.findAllByProps({ 'data-testid': 'custom-fields-delete-2' }).length, 0); } finally { f.close(); }
});
test('archive failure remains visible without reloading or claiming removal', async () => {
  const f = await fixture({ entityType: 'companies', archiveError: true });
  const previous = globalThis.window; globalThis.window = { confirm: () => true };
  try {
    await act(async () => f.control('delete-1').props.onClick());
    assert.equal(f.calls[0].url, '/api/custom_field_definitions.php?entity_type=companies&id=1');
    assert.ok(text(f.tree.root).includes('Archive was refused')); assert.equal(f.reloads, 0);
    assert.equal(f.tree.root.findAllByProps({ 'data-testid': 'custom-fields-row-1' }).length, 1);
  } finally { globalThis.window = previous; f.close(); }
});
