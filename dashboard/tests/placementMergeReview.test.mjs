import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const { MemoryRouter, Routes, Route } = require('react-router-dom');
let post, get, view;
const api = { get: (...args) => get(...args), post: (...args) => post(...args) };
const useApi = url => ({ data: url?.includes('mappings.php') ? { mappings: [{ id: 1, external_id: 'jd:991', source_system: 'jobdiva', sync_status: 'ok' }] } : url?.includes('sync_history.php') ? { migration_pending: true, rows: [] } : view, loading: false, reload: () => {} });
const picker = ({ testId, onChange, value }) => React.createElement('select', { 'data-testid': testId, value, onChange: e => onChange(e.target.value ? { id: e.target.value } : null) }, React.createElement('option', { value: '' }, 'Choose'), ...[10, 20].map(id => React.createElement('option', { key: id, value: id }, id)));
async function component(path) {
  const compiled = await build({ entryPoints: [fileURLToPath(new URL(path, import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react', 'react-router-dom', 'lucide-react'], plugins: [{ name: 'fixture', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
    b.onResolve({ filter: /PlacementPicker$/ }, () => ({ path: 'fixture-picker', external: true }));
  } }] });
  const module = { exports: {} };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? { api, useApi } : id === 'fixture-picker' ? picker : require(id), module, module.exports);
  return module.exports.default;
}
const Merge = await component('../../modules/placements/ui/PlacementMergeReview.jsx');
const Detail = await component('../../modules/placements/ui/PlacementDetail.jsx');
const content = n => typeof n === 'string' ? n : (n.children || []).map(content).join('');
const button = (tree, label) => tree.root.findAllByType('button').find(n => content(n) === label);
const review = () => ({ survivor_id: 10, retired_id: 20, survivor_number: 'PL-801', retired_number: 'PL-802', review_token: 'a'.repeat(64), fields: { title: { survivor: 'Current', retired: 'Historical' }, notes: { survivor: '', retired: '' } }, source_options: [{ mapping_id: 81, physical_placement_number: 'PL-802', external_id: 'jd:991', eligible: true }], current_configuration: { status: { survivor: 'active', retired: 'active' }, start_date: {}, end_date: {} }, rates: { survivor: [], retired: [] }, provider_pairs_retained: 2, custom_values_retained: 0 });
async function open() {
  get = async () => ({ total_groups: 0, candidates: [], truncated: false });
  let tree; await act(async () => { tree = create(React.createElement(MemoryRouter, null, React.createElement(Merge))); });
  for (const [id, value] of [['merge-survivor', '10'], ['merge-retired', '20']]) await act(async () => tree.root.findByProps({ 'data-testid': id }).props.onChange({ target: { value } }));
  await act(async () => button(tree, 'Compare selected placements').props.onClick());
  return tree;
}
async function complete(tree) {
  for (const label of ['Keep Title from', 'Keep Notes from']) await act(async () => tree.root.findByProps({ 'aria-label': label }).props.onChange({ target: { value: 'survivor' } }));
  await act(async () => tree.root.findByProps({ 'aria-label': 'Future JobDiva source' }).props.onChange({ target: { value: '81' } }));
  await act(async () => tree.root.findByProps({ 'aria-label': 'Reason for placement merge' }).props.onChange({ target: { value: 'Reviewed assignment' } }));
  await act(async () => tree.root.findByProps({ type: 'checkbox' }).props.onChange({ target: { checked: true } }));
}
test('review requires all field choices, an exact source, reason and explicit confirmation', async () => {
  const calls = []; post = async (url, body) => { calls.push([url, body]); return review(); };
  const tree = await open();
  try {
    assert.deepEqual(calls[0][1], { survivor_id: 10, retired_id: 20 });
    assert.equal(button(tree, 'Confirm reviewed merge').props.disabled, true);
    assert.equal(tree.root.findByProps({ 'aria-label': 'Keep Title from' }).props.value, '');
    assert.equal(tree.root.findByProps({ 'aria-label': 'Keep Notes from' }).props.value, '');
    assert.equal(tree.root.findByProps({ 'aria-label': 'Future JobDiva source' }).props.value, '');
    await complete(tree);
    assert.equal(button(tree, 'Confirm reviewed merge').props.disabled, false);
    assert.match(content(tree.root), /stay with their original placement numbers/);
    assert.equal(calls.length, 1);
  } finally { act(() => tree.unmount()); }
});
test('lost-response retry submits the same reviewed request and recovers its receipt', async () => {
  const bodies = []; post = async (url, body) => {
    if (url.endsWith('preview')) return review();
    bodies.push(structuredClone(body)); if (bodies.length === 1) throw new Error('Response lost');
    return { survivor_id: 10, retired_id: 20, survivor_number: 'PL-801', retained_number: 'PL-802', replayed: true };
  };
  const tree = await open();
  try {
    await complete(tree); await act(async () => button(tree, 'Confirm reviewed merge').props.onClick());
    assert.match(content(tree.root), /Response lost/);
    assert.equal(tree.root.findByProps({ 'aria-label': 'Keep Title from' }).props.disabled, true);
    await act(async () => button(tree, 'Retry original merge').props.onClick());
    assert.deepEqual(bodies[1], bodies[0]);
    assert.match(bodies[0].request_id, /^[0-9a-f-]{36}$/);
    assert.equal(bodies[0].primary_jobdiva_mapping_id, 81);
    assert.equal(bodies[0].history_policy, 'retain_original_rows');
    assert.match(content(tree.root), /original merge is confirmed/);
    assert.ok(tree.root.findAllByType('a').some(n => n.props.href === '/modules/placements/20/history'));
    assert.equal(button(tree, 'Confirm reviewed merge'), undefined);
  } finally { act(() => tree.unmount()); }
});
test('stale review errors require fresh choices before a new merge request', async () => {
  let previews = 0; post = async url => { if (url.endsWith('preview')) { previews++; return { ...review(), review_token: String(previews).repeat(64) }; } throw new Error('Placement changed. Refresh review.'); };
  const tree = await open();
  try {
    await complete(tree); await act(async () => button(tree, 'Confirm reviewed merge').props.onClick());
    assert.match(content(tree.root), /Placement changed/);
    await act(async () => button(tree, 'Refresh review').props.onClick());
    assert.equal(previews, 2);
    assert.equal(button(tree, 'Confirm reviewed merge').props.disabled, true);
    assert.equal(tree.root.findByProps({ type: 'checkbox' }).props.checked, false);
    assert.equal(tree.root.findByProps({ 'aria-label': 'Future JobDiva source' }).props.value, '');
  } finally { act(() => tree.unmount()); }
});
test('a permission failure never exposes merge controls', async () => {
  post = async () => { throw new Error('Placement management access required'); };
  const tree = await open();
  try { assert.match(content(tree.root), /management access required/); assert.equal(button(tree, 'Confirm reviewed merge'), undefined); }
  finally { act(() => tree.unmount()); }
});
function identity(financial = true) {
  const source = { id: 20, placement_number: 'PL-802', title: 'Historical assignment', start_date: '2026-10-01', status: 'active', engagement_type: 'w2' };
  const current = { ...source, id: 10, placement_number: 'PL-801', title: 'Current assignment' };
  return { requested: '20', merged: true, source, current, family: [current, source], can_view_financial_history: financial, can_view_source_history: true,
    source_rates: financial ? [{ id: 31, effective_from: '2026-10-01', currency: 'USD', bill_rate: '100.00', pay_rate: '50.00', bill_rate_unit: 'hour', pay_rate_unit: 'hour', approved_at: '2026-10-01' }] : [] };
}
for (const financial of [true, false]) test(`old placement route is read-only with financial access ${financial}`, async () => {
  view = { placement: null, identity: identity(financial) };
  let tree; await act(async () => { tree = create(React.createElement(MemoryRouter, { initialEntries: ['/modules/placements/20/overview'] }, React.createElement(Routes, null, React.createElement(Route, { path: '/modules/placements/:pid/*', element: React.createElement(Detail) })))); });
  try {
    assert.equal(tree.root.findAllByProps({ 'data-testid': 'placement-original-history' }).length, 1);
    assert.equal(tree.root.findAllByProps({ 'data-testid': 'placement-detail-tabs' }).length, 0);
    assert.ok(tree.root.findAllByType('a').some(n => n.props.href === '/modules/placements/10/overview'));
    assert.equal(tree.root.findAllByProps({ 'data-testid': 'linked-systems-expand-jobdiva' }).length, 0);
    assert.match(content(tree.root), /jd:991/);
    if (financial) assert.match(content(tree.root), /USD 100.00/); else assert.doesNotMatch(content(tree.root), /100.00|50.00/);
    await act(async () => tree.root.findByProps({ 'data-testid': 'sync-history-open-placement-20' }).props.onClick());
    assert.equal(tree.root.findAllByProps({ 'data-testid': 'sync-history-run-migration' }).length, 0);
  } finally { act(() => tree.unmount()); }
});
