import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url);
const React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/treasury/ui/PurePayrollInputReview.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture-api', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
const row = (id, available = true) => ({ id, available, reason: available ? null : 'Already reserved for payroll.', placement_number: 'PL-902', work_date: '2026-11-14', category: 'regular_billable', hours: '1.25' });
const page = { tenant_id: 2002, person: { number: 'P-387' }, period_start: '2026-11-09', period_end: '2026-11-22', rows: [row(17, false), row(24)], next_after: 24 };
const review = ids => ({ tenant_id: 2002, person: { number: 'P-387' }, organization: { name: 'Synthetic employer' }, selected_entry_ids: ids, delivery_enabled: true,
  delivery: { id: 118, review_hash: 'a'.repeat(64), state: 'preview' }, packet: { period_start: page.period_start, period_end: page.period_end,
    components: ids.map(id => ({ placement: { number: 'PL-902' }, assignment_id: 'assignment-existing', source: { time_entry_id: id, work_date: '2026-11-14', category: 'regular_billable', hours: '1.25' }, rate: { snapshot_id: 23, currency: 'USD', pay_rate: '50.0000', pay_rate_unit: 'hour', multiplier: '1.00' } })) } });
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };

async function fixture(respond, props = {}) {
  const f = { pinned: '2002', calls: [], props: { tenantId: 2002, canSend: true, ...props } };
  const api = { getPinnedTenantId: () => f.pinned, api: { post: async (url, body, options) => {
    const action = url.split('payroll_input_')[1]; f.calls.push({ action, body, options });
    if (respond) { const custom = await respond(action, body, f); if (custom !== undefined) return custom; }
    if (action === 'candidates') return structuredClone(body.after ? { ...page, rows: [row(38)], next_after: null } : page);
    if (action === 'preview') return review(body.entry_ids);
    if (action === 'history') return { tenant_id: 2002, items: [review([24])], next_before: null };
    if (['send', 'reconcile'].includes(action)) return { ...review([24]).delivery, state: 'delivered', remote_receipt_id: 'receipt-existing' };
    throw new Error(`Unexpected ${action}`);
  } } };
  const module = { exports: {} };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id), module, module.exports);
  f.Component = module.exports.default;
  await act(async () => { f.tree = create(React.createElement(f.Component, f.props)); });
  f.button = label => f.tree.root.findAllByType('button').find(n => text(n) === label);
  f.input = label => f.tree.root.findAllByType('input').find(n => n.props['aria-label'] === label);
  f.click = async label => { const b = f.button(label); assert.ok(b, label); assert.ok(!b.props.disabled, label); await act(async () => b.props.onClick()); };
  f.set = async (label, value) => act(async () => f.input(label).props.onChange({ target: { value, checked: value } }));
  f.find = async () => act(async () => f.tree.root.findByType('form').props.onSubmit({ preventDefault() {} }));
  f.fill = async () => { await f.set('Person number', 'P-387'); await f.set('Period start', page.period_start); await f.set('Period end', page.period_end); };
  f.ready = async () => { await f.fill(); await f.find(); await f.set('Include time 24', true); await f.click('Review selected time'); };
  f.close = () => act(() => f.tree.unmount());
  return f;
}

test('discovery is explicit, company-pinned, and starts with no selected time', async () => {
  const f = await fixture(); try {
    assert.equal(f.calls.length, 0); await f.fill(); await f.find();
    assert.equal(f.calls[0].action, 'candidates'); assert.equal(f.calls[0].options.headers['X-CoreFlux-Tenant-Id'], '2002');
    assert.equal(f.input('Include time 17').props.disabled, true); assert.equal(f.input('Include time 24').props.checked, false);
    assert.equal(f.button('Review selected time').props.disabled, true); assert.ok(text(f.tree.root).includes('Already reserved for payroll.'));
  } finally { f.close(); }
});

test('paging keeps explicit selection and the actual payroll period', async () => {
  const f = await fixture(); try {
    await f.fill(); await f.find(); await f.set('Include time 24', true); await f.click('More approved time'); await f.set('Include time 38', true);
    await f.click('Review selected time');
    assert.deepEqual(f.calls.at(-1).body, { person_number: 'P-387', period_start: page.period_start, period_end: page.period_end, entry_ids: [24, 38] });
    assert.equal(f.calls[1].body.after, 24); assert.equal(f.input('Include time 24').props.checked, true);
    assert.ok(text(f.tree.root).includes('2 explicitly selected time entries.')); assert.ok(f.button('Send for input review').props.disabled);
  } finally { f.close(); }
});

test('selection and date changes discard review and consent', async () => {
  const f = await fixture(); try {
    await f.ready(); await f.set('Confirm payroll input review', true); assert.ok(!f.button('Send for input review').props.disabled);
    await f.set('Include time 24', false); assert.ok(!f.button('Send for input review'));
    await f.set('Include time 24', true); await f.click('Review selected time'); assert.equal(f.input('Confirm payroll input review').props.checked, false);
    await f.set('Period end', '2026-11-23'); assert.ok(!f.input('Include time 24')); assert.ok(!f.button('Send for input review'));
  } finally { f.close(); }
});

test('duplicate discovery clicks issue one request and late responses cannot restore an edited form', async () => {
  const wait = deferred(); const f = await fixture(action => action === 'candidates' ? wait.promise : undefined);
  try {
    await f.fill(); const submit = f.tree.root.findByType('form').props.onSubmit;
    let first, second; act(() => { first = submit({ preventDefault() {} }); second = submit({ preventDefault() {} }); });
    assert.equal(f.calls.length, 1); await f.set('Person number', 'P-388');
    await act(async () => { wait.resolve(page); await Promise.all([first, second]); });
    assert.ok(!f.input('Include time 24')); assert.equal(f.input('Person number').props.value, 'P-388');
  } finally { f.close(); }
});

test('company change before submission blocks provider requests', async () => {
  const f = await fixture(); try {
    await f.ready(); await f.set('Confirm payroll input review', true); const before = f.calls.length; f.pinned = '2';
    await f.click('Send for input review'); assert.equal(f.calls.length, before); assert.ok(text(f.tree.root).includes('The company changed'));
    assert.ok(!f.button('Send for input review'));
  } finally { f.close(); }
});

test('company prop change remounts the form and ignores old responses', async () => {
  const wait = deferred(); const f = await fixture(action => action === 'preview' ? wait.promise : undefined);
  try {
    await f.fill(); await f.find(); await f.set('Include time 24', true);
    let pending; act(() => { pending = f.button('Review selected time').props.onClick(); });
    f.pinned = '2'; await act(async () => f.tree.update(React.createElement(f.Component, { ...f.props, tenantId: 2 })));
    await act(async () => { wait.resolve(review([24])); await pending; });
    assert.equal(f.input('Person number').props.value, ''); assert.ok(!text(f.tree.root).includes('Synthetic employer'));
  } finally { f.close(); }
});

test('a response from another company or another selected packet is rejected', async () => {
  const foreign = await fixture(action => action === 'candidates' ? { ...page, tenant_id: 2 } : undefined);
  try { await foreign.fill(); await foreign.find(); assert.ok(!foreign.input('Include time 24')); assert.ok(text(foreign.tree.root).includes('another company')); } finally { foreign.close(); }
  const wrong = await fixture(action => action === 'preview' ? review([17]) : undefined);
  try { await wrong.ready(); assert.ok(!wrong.button('Send for input review')); assert.ok(text(wrong.tree.root).includes('does not match the selected time')); } finally { wrong.close(); }
});

test('lost send response requires receipt recovery and never automatically resends', async () => {
  const f = await fixture(action => { if (action === 'send') throw new Error('Connection lost'); });
  try {
    await f.ready(); await f.set('Confirm payroll input review', true); await f.click('Send for input review');
    assert.ok(!f.button('Send for input review')); assert.ok(f.button('Check input receipt'));
    await f.click('Check input receipt'); assert.equal(f.calls.filter(c => c.action === 'send').length, 1);
    assert.deepEqual(f.calls.at(-1).body, { delivery_id: 118, review_hash: 'a'.repeat(64), confirmed: false });
    assert.ok(text(f.tree.root).includes('Inputs received for review in PurePayroll.'));
  } finally { f.close(); }
});

test('history remains accessible without write capability and preserves legacy reviews', async () => {
  const saved = { ...review([24]), selected_entry_ids: null };
  const f = await fixture(action => action === 'history' ? { tenant_id: 2002, items: [saved], next_before: null } : undefined, { canSend: false });
  try {
    await f.fill(); assert.ok(f.button('Find approved time').props.disabled); await f.click('Input history');
    const item = f.tree.root.findAllByType('button').find(n => text(n).includes('Review 118'));
    await act(async () => item.props.onClick()); assert.ok(text(f.tree.root).includes('Saved review of all approved time in this period.'));
    assert.ok(f.button('Send for input review').props.disabled); assert.equal(f.calls.length, 1);
  } finally { f.close(); }
});

test('selection cap retains 200 deliberate choices without silently choosing the next page', async () => {
  const many = { ...page, rows: Array.from({ length: 201 }, (_, i) => row(i + 1)), next_after: null };
  const f = await fixture(action => action === 'candidates' ? many : undefined);
  try {
    await f.fill(); await f.find();
    for (let id = 1; id <= 200; id++) await f.set(`Include time ${id}`, true);
    assert.equal(f.input('Include time 201').props.disabled, true); assert.equal(f.input('Include time 201').props.checked, false);
    await f.set('Include time 1', false); assert.equal(f.input('Include time 201').props.disabled, false);
  } finally { f.close(); }
});
