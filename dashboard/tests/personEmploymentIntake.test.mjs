import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/people/ui/PersonCreate.jsx', import.meta.url))],
  bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react', 'react-router-dom'], plugins: [{ name: 'fixture-api', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
    b.onResolve({ filter: /EntityPicker$/ }, () => ({ path: 'fixture-entity', external: true }));
    b.onResolve({ filter: /CompanyTypeahead$/ }, () => ({ path: 'fixture-company', external: true }));
  } }] });
async function fixture() {
  const f = { calls: [], navigation: [] }, module = { exports: {} };
  const api = { api: { post: async (url, body) => { f.calls.push({ url, body }); return { person: { id: 123 } }; } } };
  const router = { useNavigate: () => url => f.navigation.push(url), Link: ({ children }) => React.createElement('span', {}, children) };
  const entity = p => React.createElement('input', { 'data-testid': 'entity-fixture', onChange: e => p.onChange(e.target.value) });
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : id === 'react-router-dom' ? router
    : id === 'fixture-entity' ? entity : id === 'fixture-company' ? () => null : require(id), module, module.exports);
  await act(async () => { f.tree = create(React.createElement(module.exports.default)); });
  f.node = id => f.tree.root.findByProps({ 'data-testid': id });
  f.set = async (id, value) => act(async () => f.node(id).props.onChange({ target: { value, type: 'text' } }));
  f.next = async () => act(async () => f.node('person-create-next').props.onClick());
  f.submit = async () => act(async () => f.node('person-create-form').props.onSubmit({ preventDefault() {} }));
  f.close = () => act(() => f.tree.unmount()); return f;
}
test('new employment intake does not invent a private address or overtime classification', async () => {
  const f = await fixture();
  try { await f.set('person-create-first-name', 'Synthetic'); await f.set('person-create-last-name', 'Operator');
    await f.set('person-create-email-primary', 'synthetic@example.invalid'); await f.set('person-create-classification', 'w2');
    await f.next(); assert.equal(f.node('person-create-home-country').props.value, ''); await f.next(); await f.submit();
    assert.equal(f.calls.length, 1); const payload = f.calls[0].body;
    assert.equal(payload.classification, 'w2'); assert.equal(payload.home_country, ''); assert.equal(payload.mailing_country, '');
    for (const field of ['dob', 'ssn_last4', 'hire_date', 'employment_type', 'flsa_class']) assert.ok(!(field in payload));
    assert.deepEqual(f.navigation, ['../123']);
  } finally { f.close(); }
});
test('explicit home entity and entered intake facts survive the wizard', async () => {
  const f = await fixture();
  try { await f.set('entity-fixture', 8); await f.next(); await f.set('person-create-employment-type', 'part_time');
    await f.set('person-create-hire-date', '2026-11-01'); await f.set('person-create-home-country', 'CA'); await f.next(); await f.submit();
    assert.equal(f.calls[0].body.entity_id, 8); assert.equal(f.calls[0].body.employment_type, 'part_time');
    assert.equal(f.calls[0].body.hire_date, '2026-11-01'); assert.equal(f.calls[0].body.mailing_country, 'CA');
  } finally { f.close(); }
});
