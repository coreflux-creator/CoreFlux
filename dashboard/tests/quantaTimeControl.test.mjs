import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url);
const React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../src/pages/QuantaTimeControl.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false,
  external: ['react'], plugins: [{ name: 'fixture-api', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
const text = n => typeof n === 'string' ? n : (n.children || []).map(text).join('');
const button = (f, label) => f.tree.root.findAllByType('button').find(n => text(n) === label);
const input = (f, label) => f.tree.root.findAllByType('input').find(n => n.props['aria-label'] === label);
const delivery = { id: 31, review_hash: 'original', state: 'preview' };
const context = { workspace: { name: 'Synthetic Quanta' }, person_number: 'P-19', worker_name: 'Demo Worker', worksite_name: 'Demo site',
  context_hash: 'reviewed-context', current_version: 1, delivery_enabled: true, unbound_time: { count: 0, complete: true },
  choices: [{ assignment_id: 'assignment-42', placement_id: 42, placement_number: 'PL-42', title: 'Demo assignment', status: 'active', start_date: '2026-10-01', end_date: null, selected: true }] };
const preview = { ...context, selected_placements: ['PL-42'], delivery, receipt_only: false };
const pending = { rows: [{ delivery: { ...delivery, state: 'uncertain' } }], next_before: null };
async function fixture(name = 'default', respond = async action => ({ context, preview, pending, send: { ...delivery, state: 'delivered' }, receipt: { delivery: { ...delivery, state: 'delivered' }, receipt_state: 'confirmed' } })[action]) {
  const f = { pinned: '11', calls: [] }; const module = { exports: {} };
  const api = { getPinnedTenantId: () => f.pinned, api: { async post(url, body, options) { const action = url.split('time_control_')[1]; f.calls.push({ action, body, options }); return structuredClone(await respond(action, body)); } } };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id), module, module.exports);
  f.Component = module.exports[name]; f.props = { tenantId: 11, connectionRevision: 1, people: [{ id: 19, person_number: 'P-19', name: 'Demo Worker' }], worksites: [{ id: 'site-1', name: 'Demo site', active: true }] };
  await act(async () => { f.tree = create(React.createElement(f.Component, f.props)); });
  f.click = async label => { const b = button(f, label); assert.ok(b, label); assert.equal(!!b.props.disabled, false, label); await act(async () => b.props.onClick()); };
  f.check = async (label, checked = true) => { const i = input(f, label); assert.ok(i, label); assert.equal(!!i.props.disabled, false); await act(async () => i.props.onChange({ target: { checked } })); };
  f.choose = async () => { await act(async () => f.tree.root.findByProps({ 'aria-label': 'Time setup person' }).props.onChange({ target: { value: '19' } }));
    await act(async () => f.tree.root.findByProps({ 'aria-label': 'Time setup worksite' }).props.onChange({ target: { value: 'site-1' } })); };
  f.review = async () => { await f.choose(); await f.click('Review assignment time setup'); await f.click('Preview time setup change'); };
  f.close = () => act(() => f.tree.unmount()); return f;
}

test('requires reviewed local placement selection and explicit confirmation before sending once', async () => {
  const f = await fixture(); try {
    assert.equal(f.calls.length, 0); await f.review();
    assert.deepEqual(f.calls[1].body, { person_id: 19, worksite_id: 'site-1', placement_ids: [42], context_hash: 'reviewed-context' });
    assert.equal(button(f, 'Apply reviewed time setup').props.disabled, true);
    await f.check('Confirm time setup'); await f.click('Apply reviewed time setup');
    assert.deepEqual(f.calls[2].body, { delivery_id: 31, review_hash: 'original', confirm_time_control: true });
    assert.equal(f.calls[2].options.headers['X-CoreFlux-Tenant-Id'], '11'); assert.ok(!button(f, 'Apply reviewed time setup'));
  } finally { f.close(); }
});

test('changed choices discard the old preview and confirmation', async () => {
  const f = await fixture(); try { await f.review(); await f.check('Confirm time setup'); await f.check('Select PL-42', false);
    assert.ok(!button(f, 'Apply reviewed time setup')); assert.ok(text(f.tree.root).includes('holds new time'));
    await f.click('Preview time setup change'); assert.deepEqual(f.calls.at(-1).body.placement_ids, []);
    assert.equal(button(f, 'Apply reviewed time setup').props.disabled, true);
  } finally { f.close(); }
});

for (const current_version of [0, 1]) test(`legacy time prevents enabling assignments; initial policy=${!current_version}`, async () => {
  const f = await fixture('default', async () => ({ ...context, current_version, unbound_time: { count: 3, complete: true } }));
  try { await f.choose(); await f.click('Review assignment time setup'); assert.equal(button(f, 'Preview time setup change').props.disabled, true);
    await f.check('Select PL-42', false); assert.equal(!!button(f, 'Preview time setup change').props.disabled, !current_version);
  } finally { f.close(); }
});

test('disabled rollout displays review but offers no send action', async () => {
  const f = await fixture('default', async action => action === 'context' ? { ...context, delivery_enabled: false } : { ...preview, delivery_enabled: false });
  try { await f.review(); assert.ok(!button(f, 'Apply reviewed time setup')); assert.ok(text(f.tree.root).includes('not enabled')); } finally { f.close(); }
});

test('lost response retains original envelope and only checks its receipt', async () => {
  const f = await fixture('default', async action => { if (action === 'send') throw new Error('lost'); return ({ context, preview, receipt: { delivery: { ...delivery, state: 'delivered' }, receipt_state: 'confirmed' } })[action]; });
  try { await f.review(); await f.check('Confirm time setup'); await f.click('Apply reviewed time setup');
    assert.ok(!button(f, 'Apply reviewed time setup')); assert.equal(button(f, 'Review assignment time setup').props.disabled, true);
    await f.click('Check original time setup receipt'); assert.equal(f.calls.filter(c => c.action === 'send').length, 1);
    assert.deepEqual(f.calls.at(-1).body, { delivery_id: 31, review_hash: 'original' });
  } finally { f.close(); }
});

test('two clicks during one render cannot send twice', async () => {
  let resolve; const f = await fixture('default', async action => action === 'send' ? new Promise(done => { resolve = done; }) : ({ context, preview })[action]);
  try { await f.review(); await f.check('Confirm time setup'); let waiting; const b = button(f, 'Apply reviewed time setup');
    await act(async () => { waiting = b.props.onClick(); await b.props.onClick(); }); assert.equal(f.calls.filter(c => c.action === 'send').length, 1);
    await act(async () => { resolve({ ...delivery, state: 'delivered' }); await waiting; });
  } finally { f.close(); }
});

for (const name of ['default', 'QuantaTimeControlRecovery']) {
  const label = name === 'default' ? 'Review assignment time setup' : 'Find unconfirmed time setup';
  test(name + ' discards responses from a previous tenant', async () => {
    let resolve; const f = await fixture(name, () => new Promise(done => { resolve = done; }));
    try { if (name === 'default') await f.choose(); let waiting;
      await act(async () => { waiting = button(f, label).props.onClick(); }); f.pinned = '12';
      await act(async () => { resolve(name === 'default' ? context : pending); await waiting; });
      assert.ok(text(f.tree.root).includes('Workspace changed')); assert.ok(!text(f.tree.root).includes('PL-42')); assert.ok(!text(f.tree.root).includes('Delivery #31'));
    } finally { f.close(); }
  });
  test(name + ' aborts stale connection responses', async () => {
    let resolve; const f = await fixture(name, () => new Promise(done => { resolve = done; }));
    try { if (name === 'default') await f.choose(); let waiting;
      await act(async () => { waiting = button(f, label).props.onClick(); });
      await act(async () => f.tree.update(React.createElement(f.Component, { ...f.props, connectionRevision: 2 })));
      assert.equal(f.calls[0].options.fetch.signal.aborted, true);
      await act(async () => { resolve(name === 'default' ? context : pending); await waiting; });
      assert.ok(!text(f.tree.root).includes('PL-42')); assert.ok(!text(f.tree.root).includes('Delivery #31'));
    } finally { f.close(); }
  });
}

test('recovery survives reload without person or placement catalogs and never prepares a new change', async () => {
  const f = await fixture('QuantaTimeControlRecovery'); try {
    await f.click('Find unconfirmed time setup');
    await act(async () => f.tree.update(React.createElement(f.Component, { ...f.props, key: 'reload', people: [], worksites: [] })));
    await f.click('Find unconfirmed time setup'); await f.click('Check time setup receipt #31');
    assert.ok(text(f.tree.root).includes('Original change confirmed')); assert.equal(f.calls.filter(c => ['send', 'context', 'preview'].includes(c.action)).length, 0);
  } finally { f.close(); }
});

test('missing receipts stay held and recovery pages deduplicate the same delivery', async () => {
  const f = await fixture('QuantaTimeControlRecovery', async (action, body) => action === 'receipt'
    ? { delivery: pending.rows[0].delivery, receipt_state: 'not_found' } : body.before
      ? { rows: [...pending.rows, { delivery: { ...delivery, id: 30, state: 'uncertain' } }], next_before: null } : { ...pending, next_before: 31 });
  try { await f.click('Find unconfirmed time setup'); await f.click('Earlier time setup changes');
    assert.equal(f.tree.root.findAllByType('button').filter(n => text(n) === 'Check time setup receipt #31').length, 1);
    await f.click('Check time setup receipt #31'); assert.ok(text(f.tree.root).includes('This attempt remains unconfirmed')); assert.ok(button(f, 'Check time setup receipt #31'));
  } finally { f.close(); }
});
