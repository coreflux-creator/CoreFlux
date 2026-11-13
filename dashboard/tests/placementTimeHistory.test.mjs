import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/placements/ui/PlacementTimeHistory.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'history-api', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
const content = n => typeof n === 'string' ? n : (n.children || []).map(content).join('');
const row = (id, status = 'approved') => ({ id, work_date: '2026-10-05', hours: '8.00', category: 'regular_billable', status, approved_at: '2026-10-08 12:00:00', description: `Original time ${id}` });
async function fixture(respond) {
  const f = { pin: '2002', calls: [] }, module = { exports: {} };
  const api = { get: async (url, options) => { f.calls.push({ url, options }); return respond(url, f); } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? { api, getPinnedTenantId: () => f.pin } : require(id), module, module.exports);
  await act(async () => { f.tree = create(React.createElement(module.exports.default, { pid: 902 })); });
  f.close = () => act(() => f.tree.unmount()); return f;
}
const page = (entries, next = null) => ({ workspace_id: 2002, placement_id: 902, entries, next_before_id: next });
test('original time includes corrections and actual approval evidence without editing or invoice actions', async () => {
  const f = await fixture(() => page([row(2, 'superseded'), row(1)]));
  try { assert.match(content(f.tree.root), /superseded/); assert.match(content(f.tree.root), /2026-10-08 12:00:00/); assert.equal(f.tree.root.findAllByType('button').length, 0); assert.equal(f.tree.root.findAllByType('a').length, 0); }
  finally { f.close(); }
});
test('older pages retain their original placement and workspace while appending exactly once', async () => {
  const f = await fixture(url => url.includes('before_id=2') ? page([row(1)]) : page([row(2)], 2));
  try {
    await act(async () => f.tree.root.findByType('button').props.onClick());
    assert.equal(f.tree.root.findAllByType('tbody')[0].findAllByType('tr').length, 2);
    assert.equal(f.tree.root.findAllByType('button').length, 0);
    assert.match(f.calls[1].url, /placement_id=902&before_id=2/);
    assert.ok(f.calls.every(call => call.options.headers['X-CoreFlux-Tenant-Id'] === '2002'));
  } finally { f.close(); }
});
test('a wrong-placement response is refused without displaying another record', async () => {
  const f = await fixture(() => ({ ...page([row(1)]), placement_id: 903 }));
  try { assert.match(content(f.tree.root), /different placement or workspace/); assert.doesNotMatch(content(f.tree.root), /Original time 1/); }
  finally { f.close(); }
});
test('a permission failure on an older page removes stale time data', async () => {
  const f = await fixture(url => { if (url.includes('before_id=2')) throw new Error('Time access was removed'); return page([row(2)], 2); });
  try { await act(async () => f.tree.root.findByType('button').props.onClick()); assert.match(content(f.tree.root), /access was removed/); assert.doesNotMatch(content(f.tree.root), /Original time 2/); }
  finally { f.close(); }
});
test('an in-flight response is ignored after the selected workspace changes', async () => {
  let resolve; const pending = new Promise(r => { resolve = r; });
  const f = await fixture(() => pending);
  try { f.pin = '2003'; await act(async () => { resolve(page([row(1)])); await pending; }); assert.doesNotMatch(content(f.tree.root), /Original time 1/); }
  finally { f.close(); }
});
