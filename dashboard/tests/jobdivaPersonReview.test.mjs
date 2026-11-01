import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require=createRequire(import.meta.url),React=require('react'),{create,act}=require('react-test-renderer');
const compiled=await build({entryPoints:[fileURLToPath(new URL('../src/pages/JobDivaPersonReview.jsx',import.meta.url))],bundle:true,platform:'node',format:'cjs',write:false,external:['react'],plugins:[{name:'isolated-api',setup(b){b.onResolve({filter:/lib\/api$/},()=>({path:'fixture-api',external:true}));}}]});
const review={review_token:'sealed-review',source:{candidate_id:'1001',name:'Source Person',email:'source@example.com',phone:null},target:{id:1,person_number:'P-9001',first_name:'Core',last_name:'Person',email_primary:'core@example.com',external_id:'quanta:original'}};
const result={candidate_id:'1001',person_number:'P-9001'};
const text=n=>typeof n==='string'?n:(n.children||[]).map(text).join('');
const button=(f,label)=>f.tree.root.findAllByType('button').find(n=>text(n)===label);
async function fixture(respond=async a=>a==='preview'?structuredClone(review):result){
  const f={calls:[],pinned:'10'},module={exports:{}};
  const mock={getPinnedTenantId:()=>f.pinned,api:{post(url,body,options){const a=url.split('action=')[1];f.calls.push({action:a,body,options});return respond(a);}}};
  new Function('require','module','exports',compiled.outputFiles[0].text)(id=>id==='fixture-api'?mock:require(id),module,module.exports);
  await act(async()=>{f.tree=create(React.createElement(module.exports.default,{workspaceId:10}));});
  f.input=async(label,value)=>act(async()=>f.tree.root.findByProps({'aria-label':label}).props.onChange({target:{value}}));
  f.click=async label=>{const b=button(f,label);assert.ok(b,label);assert.equal(!!b.props.disabled,false);await act(async()=>{await b.props.onClick();});};
  f.confirm=async()=>act(async()=>f.tree.root.findByProps({type:'checkbox'}).props.onChange({target:{checked:true}}));
  f.preview=async()=>{await f.input('JobDiva candidate ID','1001');await f.input('Core person number','P-9001');await f.click('Review candidate link');};
  f.close=()=>act(()=>f.tree.unmount());return f;
}
test('review is explicit, compares both records and applies only the sealed token',async()=>{
  const f=await fixture();try{assert.equal(f.calls.length,0);await f.preview();assert.equal(f.calls.length,1);
    assert.ok(text(f.tree.root).includes('Source Person'));assert.ok(text(f.tree.root).includes('Core Person'));assert.ok(text(f.tree.root).includes('quanta:original'));
    assert.equal(button(f,'Apply reviewed link').props.disabled,true);await f.confirm();await f.click('Apply reviewed link');
    assert.deepEqual(f.calls[1].body,{review_token:'sealed-review',confirm_review:true});assert.equal(f.calls[1].options.headers['X-CoreFlux-Tenant-Id'],'10');
    assert.ok(text(f.tree.root).includes('Recorded: JobDiva candidate 1001 → P-9001'));assert.ok(!button(f,'Apply reviewed link'));
  }finally{f.close();}
});
test('lost response permits receipt recovery but no blind second write',async()=>{
  const f=await fixture(async a=>{if(a==='preview')return structuredClone(review);if(a==='apply')throw new Error('Interrupted');return {state:'applied',result};});
  try{await f.preview();await f.confirm();await f.click('Apply reviewed link');assert.ok(!button(f,'Apply reviewed link'));assert.ok(!button(f,'Review another link'));
    await f.click('Check recorded result');assert.ok(text(f.tree.root).includes('Recorded:'));assert.equal(f.calls.filter(c=>c.action==='apply').length,1);
  }finally{f.close();}
});
test('uncommitted receipt lookup requires renewed confirmation',async()=>{
  const f=await fixture(async a=>{if(a==='preview')return structuredClone(review);if(a==='apply')throw new Error('Interrupted');return {state:'not_applied',result:null};});
  try{await f.preview();await f.confirm();await f.click('Apply reviewed link');await f.click('Check recorded result');assert.equal(button(f,'Apply reviewed link').props.disabled,true);}finally{f.close();}
});
test('workspace change discards a late source review',async()=>{
  let resolve;const f=await fixture(()=>new Promise(r=>{resolve=r;}));try{await f.input('JobDiva candidate ID','1001');await f.input('Core person number','P-9001');
    let pending;act(()=>{pending=button(f,'Review candidate link').props.onClick();});f.pinned='12';await act(async()=>{resolve(structuredClone(review));await pending;});
    assert.ok(!button(f,'Apply reviewed link'));assert.ok(text(f.tree.root).includes('workspace changed'));assert.equal(f.calls.length,1);
  }finally{f.close();}
});
test('workspace change during apply hides stale completion',async()=>{
  let resolve;const f=await fixture(async a=>a==='preview'?structuredClone(review):new Promise(r=>{resolve=r;}));try{
    await f.preview();await f.confirm();let pending;act(()=>{pending=button(f,'Apply reviewed link').props.onClick();});f.pinned='12';await act(async()=>{resolve(result);await pending;});
    assert.ok(!text(f.tree.root).includes('Recorded:'));assert.ok(text(f.tree.root).includes('workspace changed'));
  }finally{f.close();}
});
test('starting a different review discards prior consent and token',async()=>{
  const f=await fixture();try{await f.preview();await f.confirm();await f.click('Review another link');await f.preview();assert.equal(button(f,'Apply reviewed link').props.disabled,true);assert.equal(f.calls.filter(c=>c.action==='apply').length,0);}finally{f.close();}
});
