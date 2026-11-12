import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require=createRequire(import.meta.url),React=require('react'),{create,act}=require('react-test-renderer');
const compiled=await build({entryPoints:[fileURLToPath(new URL('../../modules/placements/ui/SourceRuleReview.jsx',import.meta.url))],bundle:true,platform:'node',format:'cjs',write:false,external:['react'],plugins:[{name:'review-fixture',setup(b){b.onResolve({filter:/lib\/api$/},()=>({path:'fixture-api',external:true}));}}]});
const review={label:'Vendor chain',source_start_id:'9919',source_updated_at:'2026-11-12 12:00:00',review_token:'a'.repeat(64),selectable:true,can_apply:true,issues:[],references:{'company:17':{name:'Synthetic MSP'}},
  current:[{id:44,identity:'company:17',has_linked_records:true,retained_participant_settings:['payment_terms_overridden'],retained_participant_values:{payment_terms:'NET10'},values:{position:4,party_name:'Synthetic MSP',portal_fee_pct:'0.0300',is_payable:0}}],
  proposed:[{key:'proposal-1',identity:'company:17',values:{position:1,party_name:'Synthetic MSP',portal_fee_pct:'0.0200',is_payable:1}}]};
const text=n=>typeof n==='string'?n:(n.children||[]).map(text).join('');
const button=(f,label)=>f.tree.root.findAllByType('button').find(n=>text(n)===label);
const applyLabel='Apply reviewed rules and return to JobDiva';
const deferred=()=>{let resolve;const promise=new Promise(r=>{resolve=r;});return{promise,resolve};};
async function fixture(respond=async method=>method==='get'?structuredClone(review):{source_control_released:true},props={}){
  const f={calls:[],confirmations:[],confirm:true},module={exports:{}};
  global.window={confirm(message){f.confirmations.push(message);return f.confirm;}};
  const api={api:Object.fromEntries(['get','post'].map(method=>[method,(url,body)=>{f.calls.push({method,url,body});return respond(method,url,body);}]))};
  new Function('require','module','exports',compiled.outputFiles[0].text)(id=>id==='fixture-api'?api:require(id),module,module.exports);
  f.render=(id=15,kind='chain')=>React.createElement(module.exports.default,{placementId:id,kind,...props});
  await act(async()=>{f.tree=create(f.render());});
  f.click=async label=>{const b=button(f,label);assert.ok(b,label);assert.equal(!!b.props.disabled,false);await act(async()=>b.props.onClick());};
  f.select=async(value='44',index=0)=>act(async()=>f.tree.root.findAllByType('select')[index].props.onChange({target:{value}}));
  f.close=()=>{act(()=>f.tree.unmount());delete global.window;};return f;
}
test('review requires an explicit compatible match and shows changed values and retained settings',async()=>{
  const f=await fixture();try{
    await f.click('Review JobDiva replacement');assert.equal(button(f,applyLabel).props.disabled,true);
    assert.equal(f.tree.root.findByType('select').props.value,'');assert.ok(text(f.tree.root).includes('must be preserved'));
    await f.select();const t=text(f.tree.root);assert.ok(t.includes('3.00%'));assert.ok(t.includes('2.00%'));assert.ok(t.includes('No'));assert.ok(t.includes('Yes'));assert.ok(t.includes('payment terms'));assert.ok(t.includes('Contract participant retains Payment terms: NET10'));
    assert.equal(button(f,applyLabel).props.disabled,false);assert.equal(f.calls.filter(c=>c.method==='post').length,0);
  }finally{f.close();}
});
test('new choice cannot silently remove a row with linked documents or separately owned settings',async()=>{
  const f=await fixture();try{await f.click('Review JobDiva replacement');await f.select('0');assert.equal(button(f,applyLabel).props.disabled,true);assert.ok(text(f.tree.root).includes('Some unmatched rules have linked records'));}finally{f.close();}
});
test('cancel is read-only; apply sends the exact reviewed token and retained ID',async()=>{
  let reloaded=0;const f=await fixture(undefined,{onApplied:()=>{reloaded++;}});try{
    await f.click('Review JobDiva replacement');await f.select();f.confirm=false;await f.click(applyLabel);assert.equal(f.calls.length,1);
    f.confirm=true;await f.click(applyLabel);const call=f.calls.find(c=>c.method==='post');
    assert.equal(call.url,'/modules/placements/api/source_rules.php?placement_id=15&kind=chain');assert.equal(call.body.review_token,review.review_token);
    assert.deepEqual(call.body.matches,{'proposal-1':44});assert.match(call.body.request_id,/^[a-f0-9-]{36}$/);assert.equal(reloaded,1);
    assert.equal(button(f,applyLabel),undefined);assert.ok(text(f.tree.root).includes('Reviewed rules saved'));
  }finally{f.close();}
});
test('uncertain result retries identical request without reinterpreting matches',async()=>{
  let posts=0;const f=await fixture(async method=>{if(method==='get')return structuredClone(review);if(++posts===1)throw new Error('Connection lost');return {replayed:true};});
  try{await f.click('Review JobDiva replacement');await f.select();await f.click(applyLabel);
    assert.equal(f.tree.root.findByType('select').props.disabled,true);await f.click('Retry same replacement');
    const writes=f.calls.filter(c=>c.method==='post');assert.deepEqual(writes[0].body,writes[1].body);assert.equal(f.confirmations.length,1);assert.ok(text(f.tree.root).includes('Reviewed rules saved'));
  }finally{f.close();}
});
test('read access and source issues prevent apply',async()=>{
  for(const changes of [{can_apply:false},{can_apply:undefined},{selectable:false,issues:['Resolve source recipient.']}]){
    const f=await fixture(async()=>({...structuredClone(review),...changes}));try{await f.click('Review JobDiva replacement');assert.equal(f.tree.root.findByType('select').props.disabled,true);assert.ok(!button(f,applyLabel)||button(f,applyLabel).props.disabled);assert.equal(f.calls.length,1);}finally{f.close();}
  }
});
test('empty source review explicitly discloses removal and confirms the exact retired IDs',async()=>{
  const f=await fixture(async method=>method==='get'?{...structuredClone(review),proposed:[],current:[{...review.current[0],has_linked_records:false,retained_participant_settings:[]}]}:{});
  try{await f.click('Review JobDiva replacement');assert.ok(text(f.tree.root).includes('retire every current rule'));await f.click(applyLabel);
    assert.ok(f.confirmations[0].includes('Retire current rules: #44'));assert.deepEqual(f.calls.find(c=>c.method==='post').body.matches,{});
  }finally{f.close();}
});
test('another recipient is never offered as a reusable rule and a rule cannot be chosen twice',async()=>{
  const f=await fixture(async()=>({...structuredClone(review),current:[...review.current,{id:45,identity:'company:18',values:{party_name:'Different MSP'}}],proposed:[review.proposed[0],{...review.proposed[0],key:'proposal-2'}]}));
  try{await f.click('Review JobDiva replacement');await f.select();const selects=f.tree.root.findAllByType('select');assert.equal(selects[0].findAllByType('option').some(n=>n.props.value==='45'),false);assert.equal(selects[1].findAllByType('option').find(n=>n.props.value==='44').props.disabled,true);}finally{f.close();}
});
test('placement or rule-kind navigation discards stale review responses',async()=>{
  for(const next of [[16,'chain'],[15,'commission']]){const pending=deferred(),f=await fixture(()=>pending.promise);try{let request;act(()=>{request=button(f,'Review JobDiva replacement').props.onClick();});await act(async()=>f.tree.update(f.render(...next)));await act(async()=>{pending.resolve(structuredClone(review));await request;});assert.equal(button(f,applyLabel),undefined);assert.equal(button(f,'Review JobDiva replacement').props.disabled,false);}finally{f.close();}}
});
test('parent refresh failure does not misreport an applied replacement as failed',async()=>{
  const f=await fixture(undefined,{onApplied:()=>{throw new Error('Unavailable');}});try{await f.click('Review JobDiva replacement');await f.select();await f.click(applyLabel);assert.ok(text(f.tree.root).includes('Reviewed rules saved. Refresh'));assert.equal(button(f,applyLabel),undefined);}finally{f.close();}
});
test('retry receipt discloses when a later edit reclaimed Core ownership',async()=>{
  const f=await fixture(async method=>method==='get'?structuredClone(review):{replayed:true,currently_follows_source:false});
  try{await f.click('Review JobDiva replacement');await f.select();await f.click(applyLabel);assert.ok(text(f.tree.root).includes('A later Core edit has taken ownership again'));assert.equal(text(f.tree.root).includes('now follows JobDiva'),false);}finally{f.close();}
});
