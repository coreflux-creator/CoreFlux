import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const { MemoryRouter, useNavigate } = require('react-router-dom');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/people/ui/DirectoryModule.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react','react-router-dom','lucide-react'], plugins: [{ name: 'fixture', setup(b) {
  b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
  b.onResolve({ filter: /\/(ConnectedSourcesBadge|LinkedExternalSystemsPanel|IdBadge|BulkEditBar|RecordCustomFields|CustomFields|CompanySourceReview|CompanySourceLink)$/ }, () => ({ path: 'fixture-component', external: true }));
} }] });
test('directory keeps a same-record refresh visible but hides stale identities on route navigation', async () => {
  const module = { exports: {} }; let navigate;
  let state = { data: { company: { id: 10, name: 'First company', roles: [], contacts: [], addresses: [] }, can_review_source: true, can_link_source: true }, loading: false, error: null, reload() {} };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? { useApi: () => state, api: {} } : id === 'fixture-component' ? { __esModule: true, default: () => null } : require(id), module, module.exports);
  function Navigation() { navigate = useNavigate(); return null; }
  const render = () => React.createElement(MemoryRouter, { initialEntries: ['/10'] }, React.createElement(Navigation), React.createElement(module.exports.default));
  let tree; await act(async () => { tree = create(render()); });
  try {
    assert.equal(tree.root.findByProps({ 'data-testid': 'clients-detail-name' }).findByType('span').children[0], 'First company');
    state = { ...state, loading: true }; await act(async () => tree.update(render()));
    assert.equal(tree.root.findAllByProps({ 'data-testid': 'clients-detail' }).length, 1);
    await act(async () => navigate('/11'));
    assert.equal(tree.root.findAllByProps({ 'data-testid': 'clients-detail' }).length, 0);
    assert.equal(tree.root.findAllByType('button').length, 0);
    state = { ...state, loading: false, data: { ...state.data, company: { ...state.data.company, id: 11, name: 'Second company' } } };
    await act(async () => tree.update(render()));
    assert.equal(tree.root.findByProps({ 'data-testid': 'clients-detail-name' }).findByType('span').children[0], 'Second company');
  } finally { act(() => tree.unmount()); }
});
