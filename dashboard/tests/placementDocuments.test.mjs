import assert from 'node:assert/strict';
import test from 'node:test';
import React from 'react';
import { act, create } from 'react-test-renderer';
import { build } from 'esbuild';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { webcrypto } from 'node:crypto';

const require = createRequire(import.meta.url);
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/placements/ui/PlacementDocumentsTab.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react', 'lucide-react'], plugins: [{ name: 'document-api', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
const text = n => typeof n === 'string' ? n : (n.children || []).map(text).join('');
const original = () => new File(['Synthetic agreement only'], 'synthetic.txt', { type: 'text/plain' });
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };
async function fixture(settings = {}) {
  const storage = settings.storage || new Map();
  const f = { storage, pin: '2002', calls: [], transfers: [], rows: [], present: false, context: { tenant_id: 2002, placements_tenant_id: 2002, actor_user_id: 95, placement_id: 902, permissions: { manage: settings.manage ?? true } }, props: { pid: 902 } };
  if (!globalThis.crypto) Object.defineProperty(globalThis, 'crypto', { value: webcrypto, configurable: true });
  globalThis.window = { sessionStorage: { getItem: key => storage.get(key) || null, setItem: (key, value) => { if (settings.storageFailure) throw new Error('Session storage unavailable'); storage.set(key, value); }, removeItem: key => storage.delete(key) }, open: (...args) => { f.opened = args; } };
  globalThis.fetch = async (url, init) => { f.transfers.push({ url, init }); if (settings.transfer) return settings.transfer(f); f.present = true; return { ok: true, status: 204 }; };
  const api = {
    get: async (url, options) => {
      f.calls.push({ method: 'GET', url, options });
      const override = await settings.get?.(url, f);
      if (override !== undefined) return override;
      const query = new URL(url, 'https://core.invalid').searchParams;
      if (query.get('action') === 'upload_status') return { present: f.present, context: structuredClone(f.context) };
      if (query.get('action') === 'upload_url') return { context: structuredClone(f.context), storage_key: query.get('storage_key') || 'placements/2002/document/902/synthetic-key.txt', upload: { url: 'https://private-storage.invalid', fields: { key: 'synthetic-key.txt', policy: 'signed-fixture-do-not-persist' } } };
      if (query.get('id')) return { signed_url: 'https://private-storage.invalid/download', document: f.rows.find(row => String(row.id) === query.get('id')) };
      return { context: structuredClone(f.context), documents: structuredClone(f.rows) };
    },
    post: async (url, body, options) => {
      f.calls.push({ method: 'POST', url, body: structuredClone(body), options });
      const count = f.calls.filter(call => call.method === 'POST').length;
      const row = { id: 3, placement_id: 902, storage_object_id: 5, file_name: body.filename, doc_type: body.doc_type, effective_from: body.effective_from, effective_to: body.effective_to, created_at: '2026-11-12' };
      f.rows = [row];
      if (settings.post) await settings.post(count, f);
      return { id: 3, storage_object_id: 5, replayed: count > 1, document: row, context: structuredClone(f.context) };
    },
  };
  const module = { exports: {} };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? { api, getPinnedTenantId: () => f.pin } : require(id), module, module.exports);
  f.render = () => React.createElement(module.exports.default, f.props);
  await act(async () => { f.tree = create(f.render()); });
  f.choose = async (file = original(), resume = false) => act(async () => f.tree.root.findByProps({ 'data-testid': resume ? 'placement-document-resume-file' : 'placement-document-file' }).props.onChange({ target: { files: [file] } }));
  f.submit = () => act(async () => f.tree.root.findByType('form').props.onSubmit({ preventDefault() {} }));
  f.close = () => act(() => f.tree.unmount());
  f.posts = () => f.calls.filter(call => call.method === 'POST');
  return f;
}

test('a lost registration response retries one exact file and metadata without transferring again', async () => {
  const f = await fixture({ post: async count => { if (count === 1) throw new Error('Response lost after commit'); } });
  try {
    await f.choose(); await f.submit();
    assert.equal(f.tree.root.findByType('fieldset').props.disabled, true);
    assert.match(text(f.tree.root), /Check original upload/);
    assert.equal(f.storage.size, 1);
    assert.ok(![...f.storage.values()][0].includes('signed-fixture'));
    await f.submit();
    assert.deepEqual(f.posts()[0].body, f.posts()[1].body);
    assert.equal(f.transfers.length, 1);
    assert.equal(f.storage.size, 0);
    assert.match(text(f.tree.root), /no duplicate was added/);
    assert.equal(f.tree.root.findAllByProps({ 'data-testid': 'document-row-3' }).length, 1);
    assert.ok(f.posts().every(call => call.options.headers['X-CoreFlux-Tenant-Id'] === '2002'));
  } finally { f.close(); }
});

test('page reload resumes the saved reference under the same operator and company', async () => {
  const storage = new Map(); const first = await fixture({ storage, post: async () => { throw new Error('Connection lost'); } });
  await first.choose(); await first.submit(); const body = first.posts()[0].body; first.close();
  const next = await fixture({ storage });
  try { next.present = true; await next.submit(); assert.deepEqual(next.posts()[0].body, body); assert.equal(next.transfers.length, 0); assert.equal(storage.size, 0); } finally { next.close(); }
});

test('an unreadable storage response can be reconciled when the file actually arrived', async () => {
  const f = await fixture({ transfer: async f => { f.present = true; throw new Error('Failed to fetch'); } });
  try { await f.choose(); await f.submit(); assert.equal(f.posts().length, 0); await f.submit(); assert.equal(f.transfers.length, 1); assert.equal(f.posts().length, 1); assert.match(text(f.tree.root), /synthetic.txt saved/); } finally { f.close(); }
});

test('an absent file after reload requires the identical original bytes and resumes the same storage key', async () => {
  const storage = new Map(); const first = await fixture({ storage, transfer: async () => { throw new Error('Upload never arrived'); } });
  await first.choose(); await first.submit(); const receipt = JSON.parse([...storage.values()][0]); first.close();
  const next = await fixture({ storage });
  try {
    await next.submit(); assert.match(text(next.tree.root), /Select that same file/);
    await next.choose(new File(['different bytes same name'], 'synthetic.txt'), true); await next.submit();
    assert.equal(next.transfers.length, 0); assert.equal(next.posts().length, 0);
    await next.choose(original(), true); await next.submit();
    assert.equal(next.transfers.length, 1); assert.deepEqual(next.posts()[0].body, receipt.body);
    assert.ok(next.calls.some(call => call.url.includes('action=upload_url') && call.url.includes(encodeURIComponent(receipt.body.storage_key))));
  } finally { next.close(); }
});

test('read-only users see documents without an upload form', async () => {
  const f = await fixture({ manage: false }); try { assert.equal(f.tree.root.findAllByType('form').length, 0); assert.match(text(f.tree.root), /Read-only/); } finally { f.close(); }
});

test('permission removed while storage is uploading prevents registration and preserves the receipt', async () => {
  const f = await fixture({ transfer: async f => { f.present = true; f.context.permissions.manage = false; return { ok: true }; } });
  try { await f.choose(); await f.submit(); assert.equal(f.posts().length, 0); assert.equal(f.tree.root.findAllByType('form').length, 0); assert.equal(f.storage.size, 1); } finally { f.close(); }
});

test('a company switch during transfer prevents a follow-up registration even before rerender', async () => {
  const f = await fixture({ transfer: async f => { f.pin = '999'; return { ok: true }; } });
  try { await f.choose(); await f.submit(); assert.equal(f.posts().length, 0); assert.equal(f.storage.size, 1); } finally { f.close(); }
});

test('a changed directory cannot receive the previous directory upload', async () => {
  const f = await fixture({ transfer: async f => { f.context.placements_tenant_id = 2100; return { ok: true }; } });
  try { await f.choose(); await f.submit(); assert.equal(f.posts().length, 0); assert.equal(f.tree.root.findAllByType('form').length, 0); assert.match(text(f.tree.root), /workspace changed/); } finally { f.close(); }
});

test('two immediate submit events transfer and register only once', async () => {
  const f = await fixture(); try { await f.choose(); const save = f.tree.root.findByType('form').props.onSubmit; await act(async () => Promise.all([save({ preventDefault() {} }), save({ preventDefault() {} })])); assert.equal(f.transfers.length, 1); assert.equal(f.posts().length, 1); } finally { f.close(); }
});

test('unmounting during transfer leaves a resumable receipt and never registers late', async () => {
  const wait = deferred(); const started = deferred(); const f = await fixture({ transfer: async () => { started.resolve(); return wait.promise; } });
  await f.choose(); let running;
  await act(async () => { running = f.tree.root.findByType('form').props.onSubmit({ preventDefault() {} }); await started.promise; });
  assert.equal(f.transfers.length, 1); f.close(); await act(async () => { wait.resolve({ ok: true }); await running; });
  assert.equal(f.posts().length, 0); assert.equal(f.storage.size, 1);
});

test('browser storage refusal stops before any physical upload or registration', async () => {
  const f = await fixture({ storageFailure: true }); try { await f.choose(); await f.submit(); assert.equal(f.transfers.length, 0); assert.equal(f.posts().length, 0); assert.match(text(f.tree.root), /storage unavailable/); } finally { f.close(); }
});

test('an unconfirmed registration response remains pending rather than claiming success', async () => {
  const f = await fixture({ post: async (_count, f) => { f.context.actor_user_id = 99; } });
  try { await f.choose(); await f.submit(); assert.equal(f.storage.size, 1); assert.doesNotMatch(text(f.tree.root), /synthetic.txt saved/); } finally { f.close(); }
});
