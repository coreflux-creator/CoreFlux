import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/treasury/ui/StaffingReconciliation.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
const report = { tenant_id: 11, placement: { number: 'PL-1' }, person: { number: 'P-1' }, person_name: 'Synthetic worker', period: { start: '2026-10-05', end: '2026-10-18' }, read_at: '2026-11-12',
  time: { approved_hours: '8.00', payable_hours: '8.00', billable_hours: '8.00', currency: 'USD', pay_amount: '520.00', bill_amount: '784.00', entries: [] },
  financial_lines: [{ association_id: 9, kind: 'invoice', document_id: 'invoice-a', line_id: 'line-a', description: "Sep'26", period_relation: 'unknown', currency: null, amount: '784.00', hours: '8.00000000',
    capture_id: 4, captured_at: '2026-10-31', reviewed_at: '2026-11-01', service_start: null, service_end: null, core_amount: null, core_approved_hours: null, core_currency: null,
    comparison: { numeric_difference: null, monetary_difference: null, currency_comparison: 'unknown', allocation_proven: false },
    document_payment_evidence: { scope: 'whole_document_not_allocated_to_this_line', outgoing_payments: [], collections: [], recorded_receipts: [], quickbooks_receipts: [], invoice_line_splits: [] } }],
  payroll: { status: 'not_selected', lines: [] }, next_before: null };
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
const button = (f, label) => f.tree.root.findAllByType('button').find(n => text(n) === label);
async function fixture(respond = async () => structuredClone(report)) {
  const f = { calls: [], pinned: '11' }, module = { exports: {} };
  const mock = { getPinnedTenantId: () => f.pinned, api: { post(url, body, options) { f.calls.push({ url, body, options }); return respond(body); } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? mock : require(id), module, module.exports);
  f.Component = module.exports.default; f.props = { tenantId: 11, connectionVersion: 'original' };
  await act(async () => { f.tree = create(React.createElement(f.Component, f.props)); });
  f.input = async (index, value) => act(async () => f.tree.root.findAllByType('input')[index].props.onChange({ target: { value } }));
  f.ready = async () => { await f.input(0, ' pl-1 '); await f.input(1, '2026-10-05'); await f.input(2, '2026-10-18'); };
  f.click = async label => { const b = button(f, label); assert.ok(b && !b.props.disabled); await act(async () => { await b.props.onClick(); }); };
  f.close = () => act(() => f.tree.unmount()); return f;
}
test('comparison requires an explicit valid period and never automatically fetches data', async () => {
  const f = await fixture(); try {
    assert.equal(f.calls.length, 0); assert.ok(button(f, 'Compare saved evidence').props.disabled);
    await f.ready(); await f.input(2, '2026-10-01'); assert.ok(button(f, 'Compare saved evidence').props.disabled);
    await f.input(2, '2026-10-18'); await f.click('Compare saved evidence');
    assert.deepEqual(f.calls[0].body, { placement_number: 'PL-1', start: '2026-10-05', end: '2026-10-18', payroll_run_id: null, before: null });
    assert.equal(f.calls[0].options.headers['X-CoreFlux-Tenant-Id'], '11');
    assert.ok(text(f.tree.root).includes('Service dates unavailable')); assert.ok(text(f.tree.root).includes('Source service dates: Unknown'));
    assert.ok(text(f.tree.root).includes('unlinked documents are not included')); assert.ok(text(f.tree.root).includes('No payroll run selected'));
  } finally { f.close(); }
});
test('changing inputs clears old comparison before another review', async () => {
  const f = await fixture(); try { await f.ready(); await f.click('Compare saved evidence'); await f.input(0, 'PL-2'); assert.ok(!text(f.tree.root).includes('Synthetic worker')); } finally { f.close(); }
});
test('workspace switch aborts and discards a pending response', async () => {
  let resolve; const f = await fixture(() => new Promise(done => { resolve = done; }));
  try { await f.ready(); let pending; await act(async () => { pending = button(f, 'Compare saved evidence').props.onClick(); });
    await act(async () => f.tree.update(React.createElement(f.Component, { ...f.props, tenantId: 12 })));
    assert.ok(f.calls[0].options.fetch.signal.aborted); await act(async () => { resolve(report); await pending; });
    assert.ok(!text(f.tree.root).includes('Synthetic worker'));
  } finally { f.close(); }
});
test('foreign tenant results cannot disclose the selected person', async () => {
  const f = await fixture(async () => ({ ...report, tenant_id: 12 })); try { await f.ready(); await f.click('Compare saved evidence'); assert.ok(text(f.tree.root).includes('Workspace changed')); assert.ok(!text(f.tree.root).includes('Synthetic worker')); } finally { f.close(); }
});
test('older association page retains selected period and optional payroll ID', async () => {
  const f = await fixture(async () => ({ ...report, next_before: 9 })); try { await f.ready(); await f.input(3, 'run_saved'); await f.click('Compare saved evidence'); await f.click('Older placement associations'); assert.equal(f.calls[1].body.before, 9); assert.equal(f.calls[1].body.payroll_run_id, 'run_saved'); assert.equal(f.calls[1].body.start, '2026-10-05'); } finally { f.close(); }
});
test('numeric equality with unknown currency remains explicitly incomplete', async () => {
  const f = await fixture(async () => ({ ...report, payroll: { status: 'saved_person_evidence', period_relation: 'exact', period_start: '2026-10-05', period_end: '2026-10-18', currency: null,
    lines: [{ line_index: 0, employee_id: 'employee-a', hours: '8.0000', ot_hours: null, amounts: { gross: '520.00', net: '400.00' }, core_pay_amount: '520.00',
      comparison: { numeric_difference: '0.00', monetary_difference: null, currency_comparison: 'unknown' }, source_time_lineage: { status: 'not_recorded', entries: [], unavailable_entries: [] } }] } }));
  try { await f.ready(); await f.click('Compare saved evidence'); const visible = text(f.tree.root); assert.ok(visible.includes('Numeric difference: 0.00. Currency: unknown')); assert.ok(visible.includes('Difference in the same currency: Unknown')); assert.ok(visible.includes('Allocation to these time entries has not been established')); } finally { f.close(); }
});
test('failed read does not retain stale financial evidence or retry automatically', async () => {
  let reads = 0; const f = await fixture(async () => { if (++reads > 1) throw new Error('Unavailable'); return report; });
  try { await f.ready(); await f.click('Compare saved evidence'); await f.click('Compare saved evidence'); assert.ok(!text(f.tree.root).includes('Synthetic worker')); assert.ok(text(f.tree.root).includes('Unavailable')); assert.equal(f.calls.length, 2); } finally { f.close(); }
});
