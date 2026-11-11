import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url);
const React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../src/pages/QuantaLegacyTime.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false,
  external: ['react'], plugins: [{ name: 'fixture-api', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
const text = n => typeof n === 'string' ? n : (n.children || []).map(text).join('');
const button = (f, label) => f.tree.root.findAllByType('button').find(n => text(n) === label);
const delivery = { id: 31, review_hash: 'original', state: 'preview' };
const inventory = { rows: [{ source_entry_id: 'original-entry', work_date: '2026-10-07', hours: 8, imported: true }], remaining: 1, complete: true };
const preview = { status: 'preview', person_number: 'P-19', placement_number: 'PL-42', work_date: '2026-10-07', hours: 8,
  workspace: { name: 'Synthetic Quanta' }, source_approved_at: '2026-10-10', components: [{ component: 'regular', status: 'pending_review' }], delivery_enabled: true, delivery, receipt_only: false };
const pending = { rows: [{ delivery: { ...delivery, state: 'uncertain' } }], next_before: null };
async function fixture(name = 'default', respond = async action => ({ inventory, preview, pending, send: { ...delivery, state: 'delivered' }, receipt: { delivery: { ...delivery, state: 'delivered' }, receipt_state: 'confirmed' } })[action]) {
  const f = { pinned: '11', calls: [] }; const module = { exports: {} };
  const api = { getPinnedTenantId: () => f.pinned, api: { async post(url, body, options) { const action = url.split('legacy_time_')[1]; f.calls.push({ action, body, options }); return structuredClone(await respond(action, body)); } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id), module, module.exports);
  f.Component = module.exports[name]; f.props = { tenantId: 11, connectionRevision: 1, personId: 19, worksiteId: 'site-1' };
  await act(async () => { f.tree = create(React.createElement(f.Component, f.props)); });
  f.click = async label => { const b = button(f, label); assert.ok(b, label); assert.equal(!!b.props.disabled, false, label); await act(async () => b.props.onClick()); };
  f.confirm = async () => act(async () => f.tree.root.findByProps({ 'aria-label': 'Confirm historical time link' }).props.onChange({ target: { checked: true } }));
  f.review = async () => { await f.click('Find existing time to link'); await f.click('Review existing entry 1'); };
  f.close = () => act(() => f.tree.unmount()); return f;
}

test('reviews original imported evidence and requires confirmation before one tenant-pinned link', async () => {
  const f = await fixture(); try {
    await f.review(); assert.deepEqual(f.calls[0].body, { person_id: 19, worksite_id: 'site-1' });
    assert.deepEqual(f.calls[1].body, { source_entry_id: 'original-entry' });
    assert.ok(text(f.tree.root).includes('P-19 · PL-42')); assert.ok(text(f.tree.root).includes('pending_review'));
    assert.equal(button(f, 'Link reviewed historical time').props.disabled, true);
    await f.confirm(); await f.click('Link reviewed historical time');
    assert.deepEqual(f.calls[2].body, { delivery_id: 31, review_hash: 'original', confirm_existing_time: true });
    assert.equal(f.calls[2].options.headers['X-CoreFlux-Tenant-Id'], '11');
    assert.ok(!button(f, 'Link reviewed historical time')); assert.ok(text(f.tree.root).includes('Confirmed by Quanta'));
  } finally { f.close(); }
});

test('lost response retains original envelope, blocks new previews and recovers without re-sending', async () => {
  const f = await fixture('default', async action => { if (action === 'send') throw Error('Response lost');
    return { inventory, preview, receipt: { delivery: { ...delivery, state: 'delivered' }, receipt_state: 'confirmed' } }[action]; });
  try { await f.review(); await f.confirm(); await f.click('Link reviewed historical time');
    assert.equal(button(f, 'Find existing time to link').props.disabled, true);
    assert.equal(button(f, 'Review existing entry 1').props.disabled, true);
    assert.ok(!button(f, 'Link reviewed historical time'));
    await f.click('Check original historical link receipt');
    assert.deepEqual(f.calls.at(-1).body, { delivery_id: 31, review_hash: 'original' });
    assert.equal(f.calls.filter(c => c.action === 'send').length, 1);
  } finally { f.close(); }
});

test('no send action while rollout is disabled or an association already agrees', async () => {
  for (const change of [{ delivery_enabled: false }, { status: 'unchanged', delivery: undefined }]) {
    const f = await fixture('default', async action => action === 'inventory' ? inventory : { ...preview, ...change });
    try { await f.review(); assert.ok(!button(f, 'Link reviewed historical time')); } finally { f.close(); }
  }
});

test('unimported source cannot be reviewed as if it were in Core', async () => {
  const f = await fixture('default', async () => ({ ...inventory, rows: [{ ...inventory.rows[0], imported: false }] }));
  try { await f.click('Find existing time to link'); assert.equal(button(f, 'Review existing entry 1').props.disabled, true); } finally { f.close(); }
});

for (const name of ['default', 'QuantaLegacyTimeRecovery']) {
  const label = name === 'default' ? 'Find existing time to link' : 'Find unconfirmed historical links';
  test(`${name} discards late responses after a pinned company change`, async () => {
    let finish; const f = await fixture(name, () => new Promise(resolve => { finish = resolve; }));
    try { let request; act(() => { request = button(f, label).props.onClick(); }); f.pinned = '22';
      await act(async () => { finish(name === 'default' ? inventory : pending); await request; });
      assert.ok(text(f.tree.root).includes('Workspace changed')); assert.ok(!text(f.tree.root).includes('2026-10-07'));
    } finally { f.close(); }
  });
  test(`${name} aborts and ignores a stale connection response`, async () => {
    let finish; const f = await fixture(name, () => new Promise(resolve => { finish = resolve; }));
    try { let request; act(() => { request = button(f, label).props.onClick(); });
      await act(async () => f.tree.update(React.createElement(f.Component, { ...f.props, connectionRevision: 2 })));
      assert.equal(f.calls[0].options.fetch.signal.aborted, true);
      await act(async () => { finish(name === 'default' ? inventory : pending); await request; });
      assert.ok(!text(f.tree.root).includes('2026-10-07')); assert.ok(!text(f.tree.root).includes('Delivery #31'));
    } finally { f.close(); }
  });
}

test('recovery after reload needs no source feed, and a missing receipt stays held', async () => {
  const f = await fixture('QuantaLegacyTimeRecovery', async action => action === 'pending' ? pending
    : { delivery: { ...delivery, state: 'uncertain' }, receipt_state: 'not_found' });
  try { await f.click('Find unconfirmed historical links'); await f.click('Check historical link receipt #31');
    assert.deepEqual(f.calls.map(c => c.action), ['pending', 'receipt']);
    assert.ok(text(f.tree.root).includes('remains unconfirmed')); assert.ok(button(f, 'Check historical link receipt #31'));
  } finally { f.close(); }
});

test('two immediate send clicks claim only one request', async () => {
  let finish; const f = await fixture('default', async action => action === 'send' ? new Promise(resolve => { finish = resolve; }) : { inventory, preview }[action]);
  try { await f.review(); await f.confirm(); const handler = button(f, 'Link reviewed historical time').props.onClick;
    let first; act(() => { first = handler(); handler(); }); assert.equal(f.calls.filter(c => c.action === 'send').length, 1);
    await act(async () => { finish({ ...delivery, state: 'delivered' }); await first; });
  } finally { f.close(); }
});
