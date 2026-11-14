import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/staffing/ui/Jobs.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', jsx: 'automatic', write: false, external: ['react', 'react/jsx-runtime', 'react-router-dom'], plugins: [{ name: 'fixture', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
const text = n => typeof n === 'string' ? n : (n.children || []).map(text).join('');
async function fixture({ canManage = true, error = null } = {}) {
  const job = { id: 17, title: 'Reviewed Core role', status: 'closed', location_city: 'Hamilton', source_system: 'jobdiva', external_id: 'jd-job-1', edit_revision: 'observed-job-version' };
  const review = { job_id: 17, source: { system: 'jobdiva', external_id: 'jd-job-1' }, current: { title: job.title }, proposed: { title: 'Incoming source role' }, owned_fields: ['title'], review_token: 'observed-source-version' };
  const calls = [], module = { exports: {} }; let reloads = 0;
  const api = { api: { get: async () => ({ job, placements: [], can_manage: canManage, source_review: canManage ? review : null }), post: async (path, body) => { calls.push({ path, body }); if (error) throw new Error(error); return { replayed: false }; } }, useApi: () => ({ data: { rows: [job], can_manage: canManage }, loading: false, reload: () => reloads++ }) };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : id === 'react-router-dom' ? { Link: p => React.createElement('a', p, p.children) } : require(id), module, module.exports);
  let tree; await act(async () => { tree = create(React.createElement(module.exports.default)); });
  const find = id => tree.root.findByProps({ 'data-testid': id });
  await act(async () => find('staffing-job-row-17').props.onClick());
  return { tree, calls, find, reloads: () => reloads, field: label => tree.root.findByProps({ 'aria-label': label }), decision: () => tree.root.findAllByType('button').find(n => text(n) === 'Save source decision'), close: () => act(() => tree.unmount()) };
}
test('job editor sends only changed fields and the reviewed version, preserving intentional clears', async () => {
  const f = await fixture(); try {
    await act(async () => f.find('staffing-job-city').props.onChange({ target: { value: '' } }));
    await act(async () => f.tree.root.findByType('form').props.onSubmit({ preventDefault() {} }));
    assert.deepEqual(f.calls[0].body, { id: 17, edit_revision: 'observed-job-version', location_city: '' });
    assert.equal(f.reloads(), 1);
  } finally { f.close(); }
});
test('source decision displays both values, resets confirmation on mode changes and sends the observed token', async () => {
  const f = await fixture(); try {
    assert.match(text(f.tree.root), /Core: Reviewed Core role/); assert.match(text(f.tree.root), /JobDiva: Incoming source role/);
    assert.ok(f.decision().props.disabled);
    await act(async () => f.field('Select Title').props.onChange({ target: { checked: true } }));
    await act(async () => f.field('Confirm job source decision').props.onChange({ target: { checked: true } }));
    await act(async () => f.field('Job source decision').props.onChange({ target: { value: 'follow_source' } }));
    assert.ok(f.decision().props.disabled); assert.match(text(f.tree.root), /Editing a field in Core takes control again/);
    await act(async () => f.field('Confirm job source decision').props.onChange({ target: { checked: true } }));
    await act(async () => f.decision().props.onClick());
    assert.deepEqual(f.calls[0].body, { id: 17, fields: ['title'], mode: 'follow_source', review_token: 'observed-source-version', confirm_source: true });
    assert.equal(f.reloads(), 1);
  } finally { f.close(); }
});
test('an outdated source review shows the refusal and retains the selected field without reporting success', async () => {
  const f = await fixture({ error: 'The source changed. Refresh the review.' }); try {
    await act(async () => f.field('Select Title').props.onChange({ target: { checked: true } }));
    await act(async () => f.field('Confirm job source decision').props.onChange({ target: { checked: true } }));
    await act(async () => f.decision().props.onClick());
    assert.match(text(f.tree.root), /Refresh the review/); assert.equal(f.reloads(), 0); assert.equal(f.field('Select Title').props.checked, true);
  } finally { f.close(); }
});
test('unsaved native edits prevent a source decision from replacing the open form', async () => {
  const f = await fixture(); try {
    await act(async () => f.field('Select Title').props.onChange({ target: { checked: true } }));
    await act(async () => f.field('Confirm job source decision').props.onChange({ target: { checked: true } }));
    await act(async () => f.find('staffing-job-title').props.onChange({ target: { value: 'Unsaved title' } }));
    assert.ok(f.decision().props.disabled); assert.match(text(f.tree.root), /Save or discard your job edits/); assert.equal(f.calls.length, 0);
  } finally { f.close(); }
});
test('job viewers have read-only fields and no native or source mutation controls', async () => {
  const f = await fixture({ canManage: false }); try {
    for (const id of ['staffing-jobs-new', 'staffing-job-save', 'staffing-job-close', 'job-source-review']) assert.equal(f.tree.root.findAllByProps({ 'data-testid': id }).length, 0);
    assert.equal(f.tree.root.findByType('fieldset').props.disabled, true);
  } finally { f.close(); }
});
