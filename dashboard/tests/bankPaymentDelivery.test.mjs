import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url);
const React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/ap/ui/BankPaymentDelivery.jsx', import.meta.url))],
  bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture-api', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
  } }] });
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
const delivery = { tenant_id: 1, dispatch_id: 'synthetic-original', rail: 'nacha', state: 'recorded', created_at: '2026-10-25 12:00:00', can_download: true,
  payments: [{ payment_id: 42, vendor_name: 'Synthetic supplier', amount_cents: 12500, result_status: 'queued' },
    { payment_id: 43, vendor_name: 'Second supplier', amount_cents: 5000, result_status: 'queued' }] };
async function fixture(initial = delivery) {
  const f = { pinned: '1', calls: [], switchDuringDownload: false };
  const module = { exports: {} };
  const api = { getPinnedTenantId: () => f.pinned, api: { get: async (url, options) => {
    f.calls.push({ url, options });
    if (url.includes('action=bank_file')) {
      if (f.switchDuringDownload) f.pinned = '2';
      return { delivery: { ...initial, nacha_file_b64: btoa('ORIGINAL BANK FILE'), nacha_filename: 'original-synthetic-batch.ach' } };
    }
    return { delivery: initial };
  } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id), module, module.exports);
  await act(async () => { f.tree = create(React.createElement(module.exports.default, { paymentId: 42, onClose() {} })); });
  f.button = () => f.tree.root.findAllByType('button').find(n => text(n) === 'Download original bank file');
  f.click = () => act(async () => f.button().props.onClick());
  f.close = () => act(() => f.tree.unmount());
  return f;
}
test('review reads saved evidence only and shows the whole original batch without bank details', async () => {
  const f = await fixture();
  try {
    assert.equal(f.calls.length, 1); assert.ok(f.calls[0].url.includes('action=bank_delivery'));
    assert.equal(f.calls[0].options.headers['X-CoreFlux-Tenant-Id'], '1');
    for (const value of ['Synthetic supplier', 'Second supplier', '$125.00', '$50.00', 'entire original batch']) assert.ok(text(f.tree.root).includes(value));
    assert.equal(f.tree.root.findAllByType('input').length, 0);
  } finally { f.close(); }
});
test('unknown online outcome stays reserved and offers no retry or file download', async () => {
  const f = await fixture({ ...delivery, state: 'needs_review', rail: 'plaid_transfer', can_download: false });
  try {
    assert.ok(text(f.tree.root).includes('Keep these payments reserved')); assert.equal(f.button(), undefined);
    assert.deepEqual(f.tree.root.findAllByType('button').map(text), ['Close']); assert.equal(f.calls.length, 1);
  } finally { f.close(); }
});
test('view-only operator cannot download the original bank file', async () => {
  const f = await fixture({ ...delivery, can_download: false });
  try { assert.equal(f.button(), undefined); assert.equal(f.calls.length, 1); } finally { f.close(); }
});
test('company switch blocks bank-file retrieval before the request', async () => {
  const f = await fixture();
  try {
    f.pinned = '2'; await f.click(); assert.equal(f.calls.length, 1);
    assert.ok(text(f.tree.root).includes('The company changed'));
  } finally { f.close(); }
});
test('download fetches the original bytes with the reviewed company and original filename', async () => {
  const f = await fixture(); const oldDocument = globalThis.document;
  const oldCreate = URL.createObjectURL, oldRevoke = URL.revokeObjectURL;
  let blob, link, clicked = 0, revoked;
  URL.createObjectURL = value => { blob = value; return 'blob:synthetic'; };
  URL.revokeObjectURL = value => { revoked = value; };
  globalThis.document = { createElement: () => (link = { click: () => clicked++ }) };
  try {
    await f.click(); assert.equal(f.calls.length, 2); assert.ok(f.calls[1].url.includes('action=bank_file'));
    assert.equal(f.calls[1].options.headers['X-CoreFlux-Tenant-Id'], '1');
    assert.equal(await blob.text(), 'ORIGINAL BANK FILE'); assert.equal(link.download, 'original-synthetic-batch.ach');
    assert.equal(clicked, 1); assert.equal(revoked, 'blob:synthetic');
  } finally { globalThis.document = oldDocument; URL.createObjectURL = oldCreate; URL.revokeObjectURL = oldRevoke; f.close(); }
});
test('company switch while retrieval is pending prevents the download', async () => {
  const f = await fixture();
  try {
    f.switchDuringDownload = true; await f.click(); assert.equal(f.calls.length, 2);
    assert.ok(text(f.tree.root).includes('The company changed. Reopen'));
  } finally { f.close(); }
});
