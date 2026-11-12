import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/treasury/ui/StaffingFinancialEvidence.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
const workbook = { file_name: 'synthetic.xlsx', file_sha256: 'a'.repeat(64), file_bytes: 4, selection: { sheet: 'Hours' }, row_identity: 'Synthetic worker', period: { start: '2026-10-01', end: '2026-10-31' }, stated_hours: '16.00', numeric_hours_sum: '16.00', hours_difference: '0.00', stated_bill_amount: '1568.00', calculated_bill_amount: '1568.00', bill_amount_difference: '0.00', currency: null, blank_days: 28, cells: { A6: { cell: 'A6', value: '16', formula: 'SUM(C5:AG5)' } } };
const review = { tenant_id: 11, review_hash: 'b'.repeat(64), action: 'record', workbook, association: { target: { placement: { number: 'PL-1' }, person: { number: 'P-1' }, person_name: 'Synthetic worker', start_date: '2026-01-01', end_date: null } } };
const item = { id: 3, reviewed_at: '2026-11-12', saved: { action: 'record', reason: 'Synthetic source', reviewed_by: 7, workbook } };
const receipt = { tenant_id: 11, replayed: false, is_current: true, current_action: 'record', receipt: item };
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
const button = (f, label) => f.tree.root.findAllByType('button').find(n => text(n) === label);
async function fixture(respond = async url => url.endsWith('preview') ? structuredClone(review) : structuredClone(receipt)) {
  const f = { calls: [], pinned: '11', saved: 0 }, module = { exports: {} };
  const mock = { getPinnedTenantId: () => f.pinned, api: { post(url, body, options) { f.calls.push({ url, body, options }); return respond(url, body); } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? mock : require(id), module, module.exports);
  f.Component = module.exports.default; f.props = { tenantId: 11, associationId: 9, onSaved: () => f.saved++ };
  await act(async () => { f.tree = create(React.createElement(f.Component, f.props)); });
  f.click = async label => { const b = button(f, label); assert.ok(b && !b.props.disabled, label); await act(async () => { await b.props.onClick(); }); };
  f.ready = async () => {
    await act(async () => f.tree.root.findAllByType('input').find(n => n.props.type === 'file').props.onChange({ target: { files: [{ name: 'synthetic.xlsx', size: 4, arrayBuffer: async () => Uint8Array.from([80, 75, 3, 4]).buffer }] } }));
    const values = ['Hours', 'A1', 'A2', 'C4:AG4', 'C5:AG5', 'A6', 'A7', 'A8', ''];
    for (let i = 0; i < values.length; i++) await act(async () => f.tree.root.findAllByType('input').filter(n => !n.props.type)[i].props.onChange({ target: { value: values[i] } }));
  };
  f.confirm = async () => act(async () => f.tree.root.findAllByType('input').find(n => n.props.type === 'checkbox').props.onChange({ target: { checked: true } }));
  f.close = () => act(() => f.tree.unmount()); return f;
}

test('workbook review is explicit and sends original bytes and cell selection with company scope', async () => {
  const f = await fixture(); try { assert.equal(f.calls.length, 0); await f.ready(); await f.click('Review workbook evidence');
    const call = f.calls[0]; assert.equal(call.body.association_id, 9); assert.equal(call.body.file_base64, 'UEsDBA=='); assert.equal(call.body.selection.hours_range, 'C5:AG5'); assert.equal(call.body.selection.currency_cell, null); assert.equal(call.options.headers['X-CoreFlux-Tenant-Id'], '11');
    assert.ok(!Object.hasOwn(call.body, 'file_sha256')); assert.ok(text(f.tree.root).includes('blank days remain unknown')); assert.ok(text(f.tree.root).includes('SUM(C5:AG5)'));
  } finally { f.close(); }
});
test('save requires review confirmation and a receipt marks the comparison as needing refresh', async () => {
  const f = await fixture(); try { await f.ready(); await f.click('Review workbook evidence'); assert.equal(button(f, 'Save workbook review').props.disabled, true); await f.confirm(); await f.click('Save workbook review');
    assert.equal(f.calls[1].body.confirmed, true); assert.match(f.calls[1].body.request_id, /^[a-f0-9-]{36}$/); assert.equal(f.saved, 1); assert.ok(text(f.tree.root).includes('Workbook review saved.'));
  } finally { f.close(); }
});
test('editing source cells invalidates a previous review and confirmation', async () => {
  const f = await fixture(); try { await f.ready(); await f.click('Review workbook evidence'); await f.confirm(); await act(async () => f.tree.root.findAllByType('input').filter(n => !n.props.type)[1].props.onChange({ target: { value: 'B1' } })); assert.equal(button(f, 'Save workbook review'), undefined); assert.ok(!text(f.tree.root).includes('Synthetic worker')); } finally { f.close(); }
});
test('uncertain save has no automatic retry and explicit retry preserves its exact request', async () => {
  let saves = 0; const f = await fixture(async url => { if (url.endsWith('preview')) return structuredClone(review); if (++saves === 1) throw Object.assign(new Error('Timed out'), { status: 408 }); return { ...structuredClone(receipt), replayed: true }; });
  try { await f.ready(); await f.click('Review workbook evidence'); await f.confirm(); await f.click('Save workbook review'); assert.equal(f.calls.length, 2); assert.equal(button(f, 'Review workbook evidence').props.disabled, true); await f.click('Retry this saved review'); assert.deepEqual(f.calls[2].body, f.calls[1].body); assert.equal(f.saved, 1); assert.ok(text(f.tree.root).includes('no duplicate review was created')); } finally { f.close(); }
});
test('workspace switch cancels pending preview and discards its source detail', async () => {
  let finish; const f = await fixture(() => new Promise(resolve => { finish = resolve; }));
  try { await f.ready(); let pending; await act(async () => { pending = button(f, 'Review workbook evidence').props.onClick(); await Promise.resolve(); });
    await act(async () => { f.pinned = '12'; f.props = { ...f.props, tenantId: 12 }; f.tree.update(React.createElement(f.Component, f.props)); }); assert.equal(f.calls[0].options.fetch.signal.aborted, true);
    await act(async () => { finish(review); await pending; }); assert.ok(!text(f.tree.root).includes('Synthetic worker')); assert.equal(f.tree.root.findAllByType('input').filter(n => !n.props.type)[0].props.value, '');
  } finally { f.close(); }
});
test('foreign company response never displays workbook content', async () => {
  const f = await fixture(async () => ({ ...review, tenant_id: 12 })); try { await f.ready(); await f.click('Review workbook evidence'); assert.ok(text(f.tree.root).includes('Workspace changed')); assert.ok(!text(f.tree.root).includes('Synthetic worker')); } finally { f.close(); }
});
test('withdrawal requires an explanation and sends no replacement workbook', async () => {
  const f = await fixture(async () => ({ ...review, action: 'withdraw', workbook: null }));
  try { await act(async () => f.tree.root.findByType('select').props.onChange({ target: { value: 'withdraw' } })); assert.equal(button(f, 'Review workbook evidence').props.disabled, true);
    await act(async () => f.tree.root.findByType('textarea').props.onChange({ target: { value: 'Wrong row' } })); await f.click('Review workbook evidence');
    assert.equal(f.calls[0].body.decision, 'withdraw'); assert.equal(f.calls[0].body.file_base64, null); assert.equal(f.calls[0].body.selection, null); assert.ok(text(f.tree.root).includes('original file and review history will remain'));
  } finally { f.close(); }
});
test('history retains superseded source evidence and paginates the selected association', async () => {
  const f = await fixture(async () => ({ tenant_id: 11, current_id: 4, current_action: 'withdraw', items: [{ id: 4, reviewed_at: '2026-11-12', saved: { action: 'withdraw', reviewed_by: 7, reason: 'Correction', workbook: null } }, item], next_before: 3 }));
  try { await f.click('Workbook review history'); assert.ok(text(f.tree.root).includes('Download original workbook')); assert.ok(text(f.tree.root).includes('Withdrawn')); await f.click('Older workbook reviews'); assert.equal(f.calls[1].body.before, 3); assert.equal(f.calls[1].body.association_id, 9); } finally { f.close(); }
});
test('replaying an older receipt does not present it as newly current', async () => {
  const f = await fixture(async url => url.endsWith('preview') ? review : ({ ...receipt, replayed: true, is_current: false, current_action: 'withdraw' }));
  try { await f.ready(); await f.click('Review workbook evidence'); await f.confirm(); await f.click('Save workbook review'); assert.ok(text(f.tree.root).includes('does not reactivate the earlier evidence')); } finally { f.close(); }
});
test('original download refuses a response from another company before creating a file', async () => {
  const beforeFetch = globalThis.fetch; let downloads = 0;
  globalThis.fetch = async () => { downloads++; return new Response('wrong source', { status: 200, headers: { 'X-CoreFlux-Tenant-Id': '12', 'Content-Type': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' } }); };
  const f = await fixture(async () => ({ tenant_id: 11, current_id: 3, items: [item], next_before: null }));
  try { await f.click('Workbook review history'); await f.click('Download original workbook'); assert.equal(downloads, 1); assert.ok(text(f.tree.root).includes('could not be downloaded')); } finally { f.close(); globalThis.fetch = beforeFetch; }
});

test('original download checks exact bytes before making the retained file available', async () => {
  const originalFetch = globalThis.fetch, originalDocument = globalThis.document;
  const originalCreate = URL.createObjectURL, originalRevoke = URL.revokeObjectURL;
  const bytes = Uint8Array.from([80, 75, 3, 4]), hash = createHash('sha256').update(bytes).digest('hex');
  const source = structuredClone(item); source.saved.workbook.file_sha256 = hash;
  let altered = true, created = 0, clicked = 0, request;
  const link = { click() { clicked++; } };
  globalThis.document = { createElement(tag) { assert.equal(tag, 'a'); return link; } };
  URL.createObjectURL = blob => { assert.equal(blob.size, 4); created++; return 'blob:verified'; };
  URL.revokeObjectURL = () => {};
  globalThis.fetch = async (url, options) => { request = { url, options }; return new Response(altered ? Uint8Array.from([80, 75, 3, 5]) : bytes,
    { status: 200, headers: { 'X-CoreFlux-Tenant-Id': '11', 'Content-Type': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' } }); };
  const f = await fixture(async () => ({ tenant_id: 11, current_id: 3, items: [source], next_before: null }));
  try {
    await f.click('Workbook review history'); await f.click('Download original workbook');
    assert.equal(created, 0); assert.ok(text(f.tree.root).includes('differs from the saved source'));
    altered = false; await f.click('Download original workbook');
    assert.equal(created, 1); assert.equal(clicked, 1); assert.equal(link.download, 'synthetic.xlsx');
    assert.equal(request.options.headers['X-CoreFlux-Tenant-Id'], '11');
    assert.deepEqual(JSON.parse(request.options.body), { association_id: 9, evidence_id: 3 });
  } finally { f.close(); globalThis.fetch = originalFetch; globalThis.document = originalDocument; URL.createObjectURL = originalCreate; URL.revokeObjectURL = originalRevoke; }
});
