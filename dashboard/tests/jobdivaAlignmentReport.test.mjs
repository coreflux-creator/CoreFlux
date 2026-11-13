import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const { MemoryRouter } = require('react-router-dom');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../src/pages/JobDivaSettings.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react', 'react-router-dom'], plugins: [{ name: 'fixture', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
const module = { exports: {} };
new Function('require','module','exports',compiled.outputFiles[0].text)(id => id === 'fixture-api' ? {} : require(id), module, module.exports);
const text = n => typeof n === 'string' ? n : (n.children || []).map(text).join('');
const healthy = { complete: true, ok: true, issues: [], relationships: {}, generated_at: '2026-11-13T20:00:00Z' };
for (const [label, props] of [
  ['initial load', { data: null }],
  ['refresh in progress', { data: healthy, loading: true }],
  ['failed refresh with stale successful data', { data: healthy, error: 'Database check unavailable' }],
  ['incomplete response', { data: { complete: false, ok: false, issues: [{ message: 'Check unavailable' }] } }],
]) test(`${label} cannot show healthy counts or repair actions`, async () => {
  let tree, refreshed = 0;
  await act(async () => { tree = create(React.createElement(module.exports.JobDivaMappingAlignmentCard, { ...props, onRefresh: () => refreshed++ })); });
  try {
    assert.equal(tree.root.findAllByProps({ 'data-testid': 'jobdiva-mapping-alignment-health' }).length, 0);
    assert.equal(tree.root.findAllByType('button').length, 1);
    assert.doesNotMatch(text(tree.root), /checks passed|0 blockers|Repair alignment/);
    if (!props.loading) { await act(async () => tree.root.findByType('button').props.onClick()); assert.equal(refreshed, 1); }
  } finally { act(() => tree.unmount()); }
});
test('a complete report describes the covered checks without promising financial readiness', async () => {
  let tree; await act(async () => { tree = create(React.createElement(MemoryRouter, null, React.createElement(module.exports.JobDivaMappingAlignmentCard, { data: healthy }))); });
  try { assert.match(text(tree.root), /Source link and rate checks passed/); assert.match(text(tree.root), /setup and approvals/); assert.doesNotMatch(text(tree.root), /Placements can move through activation/); }
  finally { act(() => tree.unmount()); }
});
