import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/people/ui/EmploymentLinkPanel.jsx', import.meta.url))],
  bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture-api', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
  } }] });
const text = node => typeof node === 'string' ? node : (node?.children || []).map(text).join('');
const employee = { id: 20, employee_number: 'LEGACY-20', legal_first_name: 'Same', legal_last_name: 'Name', work_email: 'legacy@example.invalid', status: 'active' };
const context = { available: true, can_manage: true, person: { id: 1, person_number: 'P-9001', first_name: 'Same', last_name: 'Name', email_primary: 'legacy@example.invalid' }, link: null, employee: null, choices: [employee], choices_complete: true };
const review = { person: context.person, employee, reason: 'Reviewed source employment', creates_employee: false, review_token: 'encrypted-original-review' };
async function fixture(respond = async action => action === 'read' ? structuredClone(context) : action === 'preview' ? structuredClone(review) : { link: { employee_id: 20 } }) {
  const f = { pinned: '10', calls: [], props: { personId: 1 } }, module = { exports: {} };
  const call = async (action, url, body, options) => { f.calls.push({ action, url, body, options }); return respond(action, body, url); };
  const api = { getPinnedTenantId: () => f.pinned, api: { get: (url, options) => call('read', url, null, options), post: (url, body, options) => call(url.split('action=')[1], url, body, options) } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id), module, module.exports);
  f.Component = module.exports.default;
  await act(async () => { f.tree = create(React.createElement(f.Component, f.props)); });
  f.button = label => f.tree.root.findAllByType('button').find(n => text(n) === label);
  f.click = async label => { const b = f.button(label); assert.ok(b, label); assert.equal(!!b.props.disabled, false, label); await act(async () => b.props.onClick()); };
  f.choose = async (value = '20') => act(async () => {
    f.tree.root.findByType('select').props.onChange({ target: { value } });
    f.tree.root.findByType('textarea').props.onChange({ target: { value: 'Reviewed source employment' } });
  });
  f.confirm = async () => act(async () => f.tree.root.findAllByType('input').find(n => n.props.type === 'checkbox').props.onChange({ target: { checked: true } }));
  f.close = () => act(() => f.tree.unmount()); return f;
}
test('existing employment is explicitly selected, reviewed and confirmed with company pinned', async () => {
  const f = await fixture();
  try {
    assert.ok(f.button('Review employment link').props.disabled); await f.choose(); await f.click('Review employment link');
    assert.ok(text(f.tree.toJSON()).includes('P-9001')); assert.ok(text(f.tree.toJSON()).includes('LEGACY-20'));
    assert.ok(f.button('Save employment link').props.disabled); await f.confirm(); await f.click('Save employment link');
    assert.deepEqual(f.calls[1].body, { person_id: 1, employee_id: 20, reason: 'Reviewed source employment' });
    assert.deepEqual(f.calls[2].body, { review_token: review.review_token, confirm_link: true });
    assert.ok(f.calls.every(c => c.options.headers['X-CoreFlux-Tenant-Id'] === '10'));
    assert.ok(text(f.tree.toJSON()).includes('Employment link saved'));
    assert.equal(f.tree.root.findByType('a').props.href, '/modules/people/employment/20');
  } finally { f.close(); }
});
test('new employment preview preserves unset setup and sends no guessed classifications', async () => {
  const f = await fixture(async a => a === 'read' ? structuredClone(context) : { ...review, employee: null, creates_employee: true });
  try { await f.choose('new'); await f.click('Review employment link'); assert.equal(f.calls[1].body.employee_id, null);
    assert.ok(text(f.tree.toJSON()).includes('pending employment record')); assert.ok(!('flsa_class' in f.calls[1].body));
  } finally { f.close(); }
});
test('uncertain response freezes selection and retries the exact saved request', async () => {
  let applies = 0;
  const f = await fixture(async a => { if (a === 'read') return structuredClone(context); if (a === 'preview') return review;
    if (!applies++) throw new Error('Response lost'); return { link: { employee_id: 20 }, replayed: true }; });
  try { await f.choose(); await f.click('Review employment link'); await f.confirm(); await f.click('Save employment link');
    assert.ok(f.tree.root.findByType('select').props.disabled); assert.ok(f.button('Change selection').props.disabled);
    await f.click('Check original link'); assert.deepEqual(f.calls[2].body, f.calls[3].body);
    assert.ok(text(f.tree.toJSON()).includes('Employment link saved'));
  } finally { f.close(); }
});
test('stale review returns to explicit review without automatically applying again', async () => {
  const f = await fixture(async a => { if (a === 'read') return structuredClone(context); if (a === 'preview') return review;
    const e = new Error('Person changed'); e.status = 409; throw e; });
  try { await f.choose(); await f.click('Review employment link'); await f.confirm(); await f.click('Save employment link');
    assert.equal(f.calls.length, 3); assert.ok(!f.button('Save employment link')); assert.equal(f.button('Review employment link').props.disabled, false);
  } finally { f.close(); }
});
test('company changes prevent applying the old company review', async () => {
  const f = await fixture();
  try { await f.choose(); await f.click('Review employment link'); await f.confirm(); f.pinned = '12'; await f.click('Save employment link');
    assert.equal(f.calls.length, 2); assert.ok(text(f.tree.toJSON()).includes('Company changed'));
  } finally { f.close(); }
});
test('record changes discard an old in-flight preview', async () => {
  let resolve;
  const f = await fixture(a => a === 'read' ? Promise.resolve(structuredClone(context)) : new Promise(r => { resolve = r; }));
  try { await f.choose(); let request; await act(async () => { request = f.button('Review employment link').props.onClick(); });
    await act(async () => f.tree.update(React.createElement(f.Component, { personId: 2 })));
    await act(async () => { resolve(review); await request; });
    assert.ok(!f.button('Save employment link')); assert.equal(f.tree.root.findByType('select').props.value, '');
  } finally { f.close(); }
});
test('read-only operator sees status without a mutation form', async () => {
  const f = await fixture(async () => ({ ...context, can_manage: false }));
  try { assert.equal(f.tree.root.findAllByType('select').length, 0); assert.ok(text(f.tree.toJSON()).includes('People administrator')); }
  finally { f.close(); }
});
test('existing link is displayed without a relinking action', async () => {
  const f = await fixture(async () => ({ ...context, link: { employee_id: 20, reason: 'Verified identity' }, employee }));
  try { assert.equal(f.tree.root.findAllByType('select').length, 0); assert.ok(text(f.tree.toJSON()).includes('Verified identity')); }
  finally { f.close(); }
});
