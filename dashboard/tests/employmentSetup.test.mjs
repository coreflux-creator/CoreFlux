import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/people/ui/EmploymentSetupPanel.jsx', import.meta.url))],
  bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture-api', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
  } }] });
const text = node => typeof node === 'string' ? node : (node?.children || []).map(text).join('');
const before = { status: 'pending', employment_type: null, flsa_class: null, hire_date: null, start_date: null, job_title: null, department: null, location: null };
const context = { available: true, linked: true, can_manage: true, person: { person_number: 'P-9001' }, employee: { id: 20, employee_number: 'LEGACY-20', terminated_at: null, ...before } };
const review = { before, after: { ...before, job_title: 'Analyst' }, person_number: 'P-9001', employee_number: 'LEGACY-20', reason: 'Reviewed source employment', review_token: 'encrypted-setup-review' };
async function fixture(respond = async action => action === 'read' ? structuredClone(context) : action === 'preview' ? structuredClone(review) : { id: 3, employee_id: 20 }) {
  const f = { pinned: '10', calls: [], saved: 0 }, module = { exports: {} };
  const call = async (action, url, body, options) => { f.calls.push({ action, url, body, options }); return respond(action, body, url); };
  const api = { getPinnedTenantId: () => f.pinned, api: {
    get: (url, options) => call(url.includes('action=history') ? 'history' : 'read', url, null, options),
    post: (url, body, options) => call(url.split('action=')[1], url, body, options) } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id), module, module.exports);
  f.Component = module.exports.default; f.props = { employeeId: 20, onSaved: () => { f.saved++; } };
  await act(async () => { f.tree = create(React.createElement(f.Component, f.props)); });
  f.button = label => f.tree.root.findAllByType('button').find(n => text(n) === label);
  f.click = async label => { const b = f.button(label); assert.ok(b, label); assert.equal(!!b.props.disabled, false, label); await act(async () => b.props.onClick()); };
  f.set = async (label, value) => act(async () => f.tree.root.findByProps({ 'aria-label': label }).props.onChange({ target: { value } }));
  f.edit = async () => { await f.set('Job title', 'Analyst'); await f.set('Reason for these changes', review.reason); };
  f.confirm = async () => act(async () => f.tree.root.findAllByType('input').find(n => n.props.type === 'checkbox').props.onChange({ target: { checked: true } }));
  f.close = () => act(() => f.tree.unmount()); return f;
}
test('employment change is reviewed and explicitly confirmed with the company pinned', async () => {
  const f = await fixture();
  try { assert.ok(f.button('Review employment changes').props.disabled); await f.edit(); await f.click('Review employment changes');
    assert.deepEqual(f.calls[1].body, { employee_id: 20, changes: { job_title: 'Analyst' }, reason: review.reason });
    assert.ok(text(f.tree.toJSON()).includes('Not set')); assert.ok(text(f.tree.toJSON()).includes('LEGACY-20'));
    assert.ok(f.button('Save employment setup').props.disabled); await f.confirm(); await f.click('Save employment setup');
    assert.deepEqual(f.calls[2].body, { review_token: review.review_token, confirm_setup: true });
    assert.ok(f.calls.every(c => c.options.headers['X-CoreFlux-Tenant-Id'] === '10')); assert.equal(f.saved, 1);
    assert.ok(text(f.tree.toJSON()).includes('Review receipt #3'));
  } finally { f.close(); }
});
test('unset employment facts stay unset and saving a partial setup does not request activation', async () => {
  const f = await fixture();
  try { assert.equal(f.tree.root.findByProps({ 'aria-label': 'Employment type' }).props.value, '');
    assert.equal(f.tree.root.findByProps({ 'aria-label': 'Overtime classification' }).props.value, '');
    await f.edit(); await f.click('Review employment changes'); assert.ok(!('status' in f.calls[1].body.changes));
  } finally { f.close(); }
});
test('lost response freezes changes and retries the original save', async () => {
  let applies = 0;
  const f = await fixture(async a => { if (a === 'read') return structuredClone(context); if (a === 'preview') return review;
    if (!applies++) throw new Error('Response lost'); return { id: 3, replayed: true }; });
  try { await f.edit(); await f.click('Review employment changes'); await f.confirm(); await f.click('Save employment setup');
    assert.ok(f.button('Change review').props.disabled); assert.ok(f.tree.root.findByProps({ 'aria-label': 'Job title' }).props.disabled);
    assert.equal(f.saved, 0); await f.click('Check original save'); assert.deepEqual(f.calls[2].body, f.calls[3].body); assert.equal(f.saved, 1);
  } finally { f.close(); }
});
test('a conflict requires a new review and never silently reapplies', async () => {
  const f = await fixture(async a => { if (a === 'read') return structuredClone(context); if (a === 'preview') return review;
    const e = new Error('Employment changed'); e.status = 409; throw e; });
  try { await f.edit(); await f.click('Review employment changes'); await f.confirm(); await f.click('Save employment setup');
    assert.equal(f.calls.length, 3); assert.equal(f.saved, 0); assert.ok(!f.button('Save employment setup'));
    assert.ok(text(f.tree.toJSON()).includes('Employment changed')); assert.equal(f.button('Review employment changes').props.disabled, false);
  } finally { f.close(); }
});
test('switching company prevents an old reviewed save', async () => {
  const f = await fixture();
  try { await f.edit(); await f.click('Review employment changes'); await f.confirm(); f.pinned = '12'; await f.click('Save employment setup');
    assert.equal(f.calls.length, 2); assert.equal(f.saved, 0); assert.ok(text(f.tree.toJSON()).includes('Company changed'));
  } finally { f.close(); }
});
test('switching employment discards an in-flight review', async () => {
  let resolve;
  const f = await fixture(a => a === 'read' ? Promise.resolve(structuredClone(context)) : new Promise(r => { resolve = r; }));
  try { await f.edit(); let request; await act(async () => { request = f.button('Review employment changes').props.onClick(); });
    await act(async () => f.tree.update(React.createElement(f.Component, { ...f.props, employeeId: 21 })));
    await act(async () => { resolve(review); await request; }); assert.ok(!f.button('Save employment setup'));
  } finally { f.close(); }
});
test('read-only, unlinked and terminated records have no setup mutation controls', async () => {
  for (const [patch, message] of [[{ can_manage: false }, 'People manager'], [{ linked: false }, 'person-to-employment link'],
    [{ employee: { ...context.employee, status: 'terminated', terminated_at: '2026-11-01' } }, 'lifecycle review']]) {
    const f = await fixture(async () => ({ ...context, ...patch }));
    try { assert.equal(f.tree.root.findAllByType('select').length, 0); assert.ok(text(f.tree.toJSON()).includes(message)); }
    finally { f.close(); }
  }
});
test('saved review history shows original before and after facts', async () => {
  const f = await fixture(async a => a === 'read' ? structuredClone(context) : { rows: [{ id: 3, created_at: '2026-11-12', kind: 'setup_update', reason: review.reason, actor_user_id: 7, before, after: review.after }] });
  try { await f.click('Show recent setup reviews'); assert.ok(text(f.tree.toJSON()).includes('Job title: Not set → Analyst'));
    assert.ok(text(f.tree.toJSON()).includes('Operator #7')); assert.ok(f.calls[1].url.includes('employee_id=20'));
  } finally { f.close(); }
});
test('known person intake fills empty employment fields for explicit review', async () => {
  const f = await fixture(async a => a === 'read' ? { ...context, person: { ...context.person, employment_type: 'part_time', hire_date: '2026-11-01' } } : review);
  try { assert.equal(f.tree.root.findByProps({ 'aria-label': 'Employment type' }).props.value, 'part_time');
    assert.equal(f.tree.root.findByProps({ 'aria-label': 'Employment status' }).props.value, 'pending');
    await f.set('Reason for these changes', 'Confirm intake facts'); await f.click('Review employment changes');
    assert.deepEqual(f.calls[1].body.changes, { employment_type: 'part_time', hire_date: '2026-11-01' });
  } finally { f.close(); }
});
test('profile-only disagreement can be reviewed with both originals visible', async () => {
  const c = { ...context, person: { ...context.person, employment_type: 'part_time' }, employee: { ...context.employee, employment_type: 'full_time' } };
  const f = await fixture(async a => a === 'read' ? c : { ...review, before: { ...before, employment_type: 'full_time' }, person_before: { employment_type: 'part_time', hire_date: null }, after: { ...before, employment_type: 'full_time' } });
  try { await f.set('Reason for these changes', 'Align reviewed employment'); await f.click('Review employment changes');
    assert.deepEqual(f.calls[1].body.changes, { employment_type: 'full_time' });
    const rendered = text(f.tree.toJSON()); assert.ok(rendered.includes('Part time')); assert.ok(rendered.includes('Person profile'));
  } finally { f.close(); }
});
