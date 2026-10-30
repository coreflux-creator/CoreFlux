import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url);
const React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/treasury/ui/PurePayrollReservations.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'fixture-api', setup(b) { b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true })); } }] });
const text = node => typeof node === 'string' ? node : (node.children || []).map(text).join('');
const reservation = { id: `cpa_${'a'.repeat(64)}`, state: 'held', components: 1, created_at: '2026-10-25 20:00:00' };
const initial = { tenant_id: 999, delivery_id: 18, organization: { name: 'Demo company' }, changes_enabled: true, can_release: true, has_held: false, items: [], next_before: null };
async function fixture(value = initial, respond) {
  const f = { calls: [], pinned: '999', history: structuredClone(value) };
  const api = { getPinnedTenantId: () => f.pinned, api: { post: async (url, body, options) => {
    f.calls.push({ url, body, options });
    if (url.endsWith('_history')) return structuredClone(f.history);
    return respond ? respond(url, body, f) : { authority: reservation, provider_receipt: null };
  } } };
  const module = { exports: {} };
  new Function('require','module','exports',compiled.outputFiles[0].text)(id => id === 'fixture-api' ? api : require(id),module,module.exports);
  await act(async () => { f.tree = create(React.createElement(module.exports.default, { delivery: { id: 18, review_hash: 'b'.repeat(64) }, tenantId: 999 })); });
  f.button = label => f.tree.root.findAllByType('button').find(n => text(n) === label);
  f.click = async label => { const b = f.button(label); assert.ok(b,label); assert.ok(!b.props.disabled); await act(async () => b.props.onClick()); };
  f.confirm = async () => act(async () => f.tree.root.findByType('input').props.onChange({ target: { checked: true } }));
  f.close = () => act(() => f.tree.unmount());
  return f;
}
test('history is explicit, pinned, and does not send a reservation', async () => {
  const f = await fixture(); try { assert.equal(f.calls.length,0); await f.click('View reservations'); assert.equal(f.calls.length,1); assert.match(f.calls[0].url,/_history$/); assert.equal(f.calls[0].options.headers['X-CoreFlux-Tenant-Id'],'999'); assert.ok(text(f.tree.root).includes('Demo company')); } finally { f.close(); }
});
test('reservation requires confirmation and retains request after lost response', async () => {
  const f = await fixture(initial,async () => { throw new Error('Response lost'); });
  try { await f.click('View reservations'); await f.click('Review reserving hours'); assert.ok(f.button('Reserve hours').props.disabled);
    await f.confirm(); await f.click('Reserve hours'); assert.deepEqual(f.calls.map(c=>c.url.split('_').at(-1)),['history','reserve','history']);
    const first=f.calls[1].body; assert.match(first.request_id,/^[a-f0-9]{64}$/); assert.equal(first.confirmed,true);
    await f.click('Review reserving hours'); await f.confirm(); await f.click('Reserve hours'); assert.equal(f.calls[3].body.request_id,first.request_id);
  } finally { f.close(); }
});
test('lost response recovers committed reservation without creating another', async () => {
  const f=await fixture(initial,async (url,body,state)=>{state.history={...initial,has_held:true,items:[reservation]};throw new Error('Response lost after commit');});
  try {await f.click('View reservations');await f.click('Review reserving hours');await f.confirm();await f.click('Reserve hours');assert.ok(!f.button('Review reserving hours'));assert.ok(f.button('Check provider receipt'));assert.equal(f.calls.filter(c=>c.url.endsWith('_reserve')).length,1);}finally{f.close();}
});
test('held reservation on another page prevents a new one', async () => {
  const f=await fixture({...initial,has_held:true,items:[{...reservation,state:'released'}]});try{await f.click('View reservations');assert.ok(!f.button('Review reserving hours'));}finally{f.close();}
});
test('share and cancel require separate confirmation with cancellation consequences', async () => {
  const f=await fixture({...initial,has_held:true,items:[reservation]});
  try{await f.click('View reservations');await f.click('Review sharing reservation');await f.confirm();await f.click('Share reservation');assert.equal(f.calls[1].body.authority_id,reservation.id);assert.match(f.calls[1].url,/_send$/);
    await f.click('Review cancellation');assert.ok(text(f.tree.root).includes('draft will be voided'));assert.ok(f.button('Confirm cancellation').props.disabled);await f.confirm();await f.click('Confirm cancellation');assert.match(f.calls[3].url,/_release$/);
  }finally{f.close();}
});
test('feature and permission holds hide writes but retain receipt checks', async () => {
  const f=await fixture({...initial,changes_enabled:false,has_held:true,items:[reservation]});
  try{await f.click('View reservations');assert.ok(!f.button('Review sharing reservation'));assert.ok(!f.button('Review cancellation'));await f.click('Check provider receipt');assert.match(f.calls[1].url,/_reconcile$/);}finally{f.close();}
  const read=await fixture({...initial,can_release:false,has_held:true,items:[reservation]});try{await read.click('View reservations');assert.ok(!read.button('Review cancellation'));}finally{read.close();}
});
test('company switch cannot submit old input to another company', async () => {
  const f=await fixture();try{await f.click('View reservations');await f.click('Review reserving hours');await f.confirm();f.pinned='2';await f.click('Reserve hours');assert.equal(f.calls.length,1);assert.ok(text(f.tree.root).includes('The company changed'));}finally{f.close();}
});
