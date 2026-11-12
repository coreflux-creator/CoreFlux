import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require=createRequire(import.meta.url),React=require('react'),{create,act}=require('react-test-renderer');
const compiled=await build({entryPoints:[fileURLToPath(new URL('../../modules/placements/ui/CommercialOwnershipReview.jsx',import.meta.url))],bundle:true,platform:'node',format:'cjs',write:false,external:['react'],plugins:[{name:'isolated-review',setup(b){
  b.onResolve({filter:/lib\/api$/},()=>({path:'fixture-api',external:true}));
}}]});
const review={placement_id:15,permissions:{release:true},review_token:'a'.repeat(64),groups:[
  {key:'vendor_terms',label:'Contractor terms and paid when paid',selectable:true,placement_values:{vendor_payment_terms_override:'NET30',vendor_pwp_enabled:0},participants:[{id:2,name:'Synthetic contractor',values:{payment_terms:'NET30',pwp_enabled:0}}]},
  {key:'client_cycle',label:'Client billing schedule',selectable:false,blocked_reason:'Review the participant source link first.',placement_values:{client_bill_cycle:'weekly'},participants:[{id:3,name:'Synthetic client',values:{operating_cycle_id:70},cycle:{name:'Weekly billing',cadence:'weekly',anchor_date:'2026-11-09'}}]},
]};
const text=n=>typeof n==='string'?n:(n.children||[]).map(text).join('');
const button=(f,label)=>f.tree.root.findAllByType('button').find(n=>text(n)===label);
const deferred=()=>{let resolve;const promise=new Promise(r=>{resolve=r;});return {promise,resolve};};
async function fixture(respond=async method=>method==='get'?structuredClone(review):{changed:true,values_applied:false},props={}){
  const f={calls:[],confirmations:[],confirm:true},module={exports:{}};
  global.window={confirm(message){f.confirmations.push(message);return f.confirm;}};
  const api={api:Object.fromEntries(['get','post'].map(method=>[method,(url,body)=>{f.calls.push({method,url,body});return respond(method,url,body);}]))};
  new Function('require','module','exports',compiled.outputFiles[0].text)(id=>id==='fixture-api'?api:require(id),module,module.exports);
  f.render=id=>React.createElement(module.exports.default,{placementId:id,...props});
  await act(async()=>{f.tree=create(f.render(15));});
  f.click=async label=>{const b=button(f,label);assert.ok(b,label);assert.equal(!!b.props.disabled,false);await act(async()=>b.props.onClick());};
  f.select=async()=>act(async()=>f.tree.root.findAllByType('input')[0].props.onChange());
  f.close=()=>{act(()=>f.tree.unmount());delete global.window;};return f;
}
test('review is read-only and displays false values, schedule names and blocked links',async()=>{
  const f=await fixture();try{await f.click('Review JobDiva ownership');const t=text(f.tree.root);
    assert.ok(t.includes('NET30'));assert.ok(t.includes('Paid when paidNo'));assert.ok(t.includes('Weekly billing'));
    assert.ok(t.includes('Review the participant source link first.'));assert.equal(f.tree.root.findAllByType('input')[1].props.disabled,true);
    assert.equal(f.calls.filter(c=>c.method==='post').length,0);assert.equal(button(f,'Return selected fields to JobDiva').props.disabled,true);
  }finally{f.close();}
});
test('cancelled confirmation preserves selection without a write',async()=>{
  const f=await fixture();try{await f.click('Review JobDiva ownership');await f.select();f.confirm=false;await f.click('Return selected fields to JobDiva');
    assert.equal(f.calls.filter(c=>c.method==='post').length,0);assert.equal(f.tree.root.findAllByType('input')[0].props.checked,true);
    assert.ok(f.confirmations[0].includes('Contractor terms and paid when paid'));assert.ok(f.confirmations[0].includes('Current values stay unchanged now.'));
  }finally{f.close();}
});
test('release submits the reviewed groups and seal once and consumes its selection',async()=>{
  let refreshed=0;const f=await fixture(undefined,{onReleased:()=>{refreshed++;}});try{await f.click('Review JobDiva ownership');await f.select();await f.click('Return selected fields to JobDiva');
    assert.deepEqual(f.calls.find(c=>c.method==='post'),{method:'post',url:'/modules/placements/api/economics.php?placement_id=15&action=release_ownership',body:{groups:['vendor_terms'],review_token:review.review_token,confirm:'RELEASE_COMMERCIAL_OWNERSHIP'}});
    assert.equal(refreshed,1);assert.ok(text(f.tree.root).includes('Ownership released.'));assert.equal(button(f,'Return selected fields to JobDiva'),undefined);
  }finally{f.close();}
});
test('failed or uncertain submission requires a new successful review',async()=>{
  let failGet=false;const f=await fixture(async method=>{if(method==='post')throw new Error('Contract changed');if(failGet)throw new Error('Unavailable');return structuredClone(review);});
  try{await f.click('Review JobDiva ownership');await f.select();await f.click('Return selected fields to JobDiva');
    assert.ok(text(f.tree.root).includes('Load a fresh ownership review'));assert.equal(button(f,'Return selected fields to JobDiva'),undefined);
    failGet=true;await f.click('Review JobDiva ownership');assert.equal(button(f,'Return selected fields to JobDiva'),undefined);
    failGet=false;await f.click('Review JobDiva ownership');assert.equal(button(f,'Return selected fields to JobDiva').props.disabled,true);
    assert.equal(f.calls.filter(c=>c.method==='post').length,1);
  }finally{f.close();}
});
test('failed parent refresh retains known release success and cannot replay it',async()=>{
  const f=await fixture(undefined,{onReleased:()=>{throw new Error('Reload unavailable');}});try{await f.click('Review JobDiva ownership');await f.select();await f.click('Return selected fields to JobDiva');
    assert.ok(text(f.tree.root).includes('Ownership released. Refresh the Contract tab'));assert.equal(button(f,'Return selected fields to JobDiva'),undefined);assert.equal(f.calls.filter(c=>c.method==='post').length,1);
  }finally{f.close();}
});
test('navigation discards an in-flight review from the previous placement',async()=>{
  const pending=deferred(),f=await fixture(()=>pending.promise);try{
    let request;act(()=>{request=button(f,'Review JobDiva ownership').props.onClick();});
    await act(async()=>f.tree.update(f.render(16)));await act(async()=>{pending.resolve(structuredClone(review));await request;});
    assert.equal(button(f,'Return selected fields to JobDiva'),undefined);assert.equal(button(f,'Review JobDiva ownership').props.disabled,false);
  }finally{f.close();}
});
test('navigation discards an in-flight release notification for the previous placement',async()=>{
  const pending=deferred();let refreshed=0;const f=await fixture(method=>method==='get'?Promise.resolve(structuredClone(review)):pending.promise,{onReleased:()=>{refreshed++;}});
  try{await f.click('Review JobDiva ownership');await f.select();let request;act(()=>{request=button(f,'Return selected fields to JobDiva').props.onClick();});
    await act(async()=>f.tree.update(f.render(16)));await act(async()=>{pending.resolve({changed:true});await request;});
    assert.equal(refreshed,0);assert.ok(!text(f.tree.root).includes('Ownership released.'));assert.equal(button(f,'Review JobDiva ownership').props.disabled,false);
  }finally{f.close();}
});

test('readers and responses without permissions expose evidence but no release action',async()=>{
  for(const permissions of [{release:false},undefined]){
    const f=await fixture(async()=>({...structuredClone(review),permissions}));
    try{await f.click('Review JobDiva ownership');assert.ok(text(f.tree.root).includes('NET30'));
      assert.ok(text(f.tree.root).includes('requires placement edit access'));
      assert.equal(f.tree.root.findAllByType('input').length,0);
      assert.equal(button(f,'Return selected fields to JobDiva'),undefined);
      assert.equal(f.calls.filter(c=>c.method==='post').length,0);
    }finally{f.close();}
  }
});

test('refreshing after a permission downgrade removes prior release selections',async()=>{
  let allowed=true;const f=await fixture(async()=>({...structuredClone(review),permissions:{release:allowed}}));
  try{await f.click('Review JobDiva ownership');await f.select();allowed=false;
    await f.click('Refresh ownership review');assert.equal(button(f,'Return selected fields to JobDiva'),undefined);
    assert.equal(f.tree.root.findAllByType('input').length,0);assert.equal(f.calls.filter(c=>c.method==='post').length,0);
  }finally{f.close();}
});
