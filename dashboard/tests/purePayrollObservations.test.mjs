import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { readFile } from 'node:fs/promises';
import { build } from 'esbuild';

const require = createRequire(import.meta.url);
const React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/treasury/ui/PurePayrollObservations.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture-api', setup(b) {
  b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
} }] });
const observation = JSON.parse(await readFile(new URL('../../tests/fixtures/purepay_observations/full.json', import.meta.url), 'utf8'));
const capture = { tenant_id: 11, organization: { name: 'Demo payroll', id: observation.organization_id }, observation,
  review_hash: 'reviewed-original', comparison: { people: [{ employee_id: observation.lines[0].employee_id, person: { number: 'P-123' }, link_status: 'linked' }],
    totals: { gross: { provider_total: '100.00', line_total: '100.00', difference: '0.00', missing_lines: 0, status: 'matched' }, net: { provider_total: null, line_total: '87.35', difference: null, missing_lines: 0, status: 'missing_provider_total' } } } };
const page = { tenant_id: 11, organization: capture.organization, items: [observation], next_cursor: null };
const history = { tenant_id: 11, run_id: observation.run_id, items: [{ id: 8, saved_at: '2026-10-24 12:00:00', saved: capture }], next_before: null };
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
const button = (f, label) => f.tree.root.findAllByType('button').find(n => text(n) === label);
async function fixture(respond) {
  const f = { calls: [], pinned: '11' }; const module = { exports: {} };
  const mock = { getPinnedTenantId: () => f.pinned, api: { post(url, body, options) {
    const action = url.split('action=payroll_observation_')[1]; f.calls.push({ action, body, options }); return respond(action, body);
  } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? mock : require(id), module, module.exports);
  f.Component = module.exports.default; f.props = { tenantId: 11, connectionRevision: 'binding-a' };
  await act(async () => { f.tree = create(React.createElement(f.Component, f.props)); });
  f.click = async label => { const b = button(f, label); assert.ok(b, label); assert.equal(!!b.props.disabled, false); await act(async () => { await b.props.onClick(); }); };
  f.open = async () => { await f.click('Find provider payroll'); await f.click(`Review ${observation.run_id}`); };
  f.confirm = async () => act(async () => f.tree.root.findByType('input').props.onChange({ target: { checked: true } }));
  f.close = () => act(() => f.tree.unmount()); return f;
}
const standard = async action => structuredClone(action === 'page' ? page : action === 'history' ? history : action === 'save' ? { id: 8, replayed: false, saved: capture } : capture);

test('explicit review preserves unknown amounts and requires confirmation before saving', async () => {
  const f = await fixture(standard);
  try {
    assert.equal(f.calls.length, 0); await f.open();
    assert.ok(text(f.tree.root).includes('P-123')); assert.ok(text(f.tree.root).includes('Unknown'));
    assert.ok(text(f.tree.root).includes('matching against Core time and rates have not been verified'));
    assert.equal(button(f, 'Save reviewed capture').props.disabled, true);
    await f.confirm(); await f.click('Save reviewed capture');
    assert.deepEqual(f.calls.at(-1).body, { run_id: observation.run_id, review_hash: 'reviewed-original', confirmed: true });
    assert.ok(f.calls.every(c => c.options.headers['X-CoreFlux-Tenant-Id'] === '11'));
    assert.ok(!button(f, 'Save reviewed capture')); assert.ok(text(f.tree.root).includes('Review captured in Core'));
    assert.ok(f.calls.every(c => ['page', 'read', 'save'].includes(c.action)));
  } finally { f.close(); }
});

test('lost save acknowledgement clears confirmation and never retries automatically', async () => {
  const f = await fixture(async action => { if (action === 'save') throw new Error('Lost acknowledgement'); return standard(action); });
  try {
    await f.open(); await f.confirm(); await f.click('Save reviewed capture');
    assert.ok(!button(f, 'Save reviewed capture')); assert.equal(f.calls.filter(c => c.action === 'save').length, 1);
    await f.click('View saved captures'); await f.click('Capture 8 · 2026-10-24 12:00:00');
    assert.ok(text(f.tree.root).includes('records reviewed then')); assert.ok(!button(f, 'Save reviewed capture'));
  } finally { f.close(); }
});

test('provider paid status does not claim bank settlement and unknown hold remains unknown', async () => {
  const variant = structuredClone(capture); variant.observation.status = 'paid'; variant.observation.execution_hold = null;
  const f = await fixture(async action => action === 'read' ? variant : standard(action));
  try { await f.open(); assert.ok(text(f.tree.root).includes('Recorded paid')); assert.ok(text(f.tree.root).includes('Provider execution hold is unknown.')); assert.ok(text(f.tree.root).includes('Payment delivery, tax remittance')); }
  finally { f.close(); }
});

test('unlinked employees never receive an invented Core number', async () => {
  const variant = structuredClone(capture); variant.comparison.people[0].person = null; variant.comparison.people[0].link_status = 'unlinked';
  const f = await fixture(async action => action === 'read' ? variant : standard(action));
  try { await f.open(); assert.ok(text(f.tree.root).includes('No Core link')); assert.ok(!text(f.tree.root).includes('P-123')); }
  finally { f.close(); }
});

test('repeated listing cursor is rejected and smaller page size resets the old list', async () => {
  const f = await fixture(async action => action === 'page' ? { ...structuredClone(page), next_cursor: 'same' } : standard(action));
  try {
    await f.click('Find provider payroll'); await f.click('Next payroll page');
    assert.ok(text(f.tree.root).includes('repeated a payroll page')); assert.ok(!button(f, `Review ${observation.run_id}`));
    await act(async () => f.tree.root.findByType('select').props.onChange({ target: { value: '1' } }));
    await f.click('Find provider payroll'); assert.equal(f.calls.at(-1).body.limit, 1); assert.equal(f.calls.at(-1).body.cursor, null);
  } finally { f.close(); }
});

test('switching workspace before an action sends no request', async () => {
  const f = await fixture(standard);
  try { f.pinned = '12'; await f.click('Find provider payroll'); assert.equal(f.calls.length, 0); assert.ok(text(f.tree.root).includes('Workspace changed')); }
  finally { f.close(); }
});

test('switching workspace during a request discards its payroll figures', async () => {
  let resolve; const f = await fixture(() => new Promise(done => { resolve = done; }));
  try {
    let waiting; await act(async () => { waiting = button(f, 'Find provider payroll').props.onClick(); });
    f.pinned = '12'; await act(async () => { resolve(structuredClone(page)); await waiting; });
    assert.ok(!text(f.tree.root).includes(observation.run_id)); assert.ok(text(f.tree.root).includes('Workspace changed'));
  } finally { f.close(); }
});

test('connection changes abort stale requests and clear selected payroll', async () => {
  let resolve; const f = await fixture(() => new Promise(done => { resolve = done; }));
  try {
    let waiting; await act(async () => { waiting = button(f, 'Find provider payroll').props.onClick(); });
    await act(async () => f.tree.update(React.createElement(f.Component, { ...f.props, connectionRevision: 'binding-b' })));
    assert.equal(f.calls[0].options.fetch.signal.aborted, true);
    await act(async () => { resolve(structuredClone(page)); await waiting; });
    assert.ok(!text(f.tree.root).includes(observation.run_id)); assert.equal(button(f, 'Find provider payroll').props.disabled, false);
  } finally { f.close(); }
});

test('another company response is rejected before payroll is displayed', async () => {
  const f = await fixture(async () => ({ ...structuredClone(page), tenant_id: 12 }));
  try { await f.click('Find provider payroll'); assert.ok(!text(f.tree.root).includes(observation.run_id)); assert.ok(text(f.tree.root).includes('another workspace')); }
  finally { f.close(); }
});

test('overlapping clicks issue only one payroll request', async () => {
  let resolve; const f = await fixture(() => new Promise(done => { resolve = done; }));
  try {
    const click = button(f, 'Find provider payroll').props.onClick; let a; let b;
    await act(async () => { a = click(); b = click(); }); assert.equal(f.calls.length, 1);
    await act(async () => { resolve(structuredClone(page)); await Promise.all([a, b]); });
  } finally { f.close(); }
});
