import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require=createRequire(import.meta.url),React=require('react'),{create,act}=require('react-test-renderer');
const compiled=await build({entryPoints:[fileURLToPath(new URL('../src/pages/QuantaIdentityReview.jsx',import.meta.url))],bundle:true,platform:'node',format:'cjs',write:false,external:['react'],plugins:[{name:'isolated-api',setup(b){b.onResolve({filter:/lib\/api$/},()=>({path:'fixture-api',external:true}));}}]});
const review={review_token:'encrypted-review',mode:'create',workspace:{name:'Synthetic Quanta'},people_tenant_id:10,people_directory_name:'Shared directory',rows:[{
  worker:{id:'Worker-1',full_name:'Source Worker',email:'source@example.com',phone:null,status:'disabled',employee_id:'payroll-77',external_id:'external-88'},
  target:{first_name:'Reviewed',last_name:'Worker',email_primary:'source@example.com',phone_primary:null,status:'inactive',classification:'candidate'},
}]};
const result={mode:'create',people:[{person_id:333,person_number:'P-9001',worker_id:'Worker-1'}]};
const text=n=>typeof n==='string'?n:(n.children||[]).map(text).join('');
const button=(f,label)=>f.tree.root.findAllByType('button').find(n=>text(n)===label);
async function fixture(respond=async action=>action==='identity_preview'?structuredClone(review):result,mode='create'){
  const f={calls:[],pinned:'11',completed:[],closed:0};const module={exports:{}};
  const mock={getPinnedTenantId:()=>f.pinned,api:{post(url,body,options){const action=url.split('action=')[1];f.calls.push({action,body,options});return respond(action,body,options);}}};
  new Function('require','module','exports',compiled.outputFiles[0].text)(id=>id==='fixture-api'?mock:require(id),module,module.exports);
  f.Component=module.exports.default;f.props={request:mode==='create'?{mode,worker_id:'Worker-1',first_name:'Reviewed',last_name:'Worker',confirm_distinct_person:false}:{mode,links:[{worker_id:'Worker-1',person_id:333}]},workspaceId:11,connectionRevision:1,onClose:()=>f.closed++,onComplete:r=>f.completed.push(r)};
  await act(async()=>{f.tree=create(React.createElement(f.Component,f.props));});
  f.click=async label=>{const b=button(f,label);assert.ok(b,label);assert.equal(!!b.props.disabled,false,label+' enabled');await act(async()=>{await b.props.onClick();});};
  f.confirm=async()=>{await act(async()=>f.tree.root.findByType('input').props.onChange({target:{checked:true}}));};
  f.close=()=>act(()=>f.tree.unmount());return f;
}
test('opening intake only previews, shows both records and requires confirmation',async()=>{
  const f=await fixture();try{
    assert.deepEqual(f.calls.map(c=>c.action),['identity_preview']);assert.ok(text(f.tree.root).includes('Source Worker'));assert.ok(text(f.tree.root).includes('Reviewed Worker'));
    assert.ok(text(f.tree.root).includes('Shared directory'));assert.ok(text(f.tree.root).includes('payroll-77'));assert.ok(text(f.tree.root).includes('external-88'));
    assert.equal(button(f,'Create and link').props.disabled,true);await f.confirm();await f.click('Create and link');
    assert.deepEqual(f.calls[1].body,{review_token:'encrypted-review',confirm_review:true});assert.equal(f.calls[1].options.headers['X-CoreFlux-Tenant-Id'],'11');
    assert.ok(text(f.tree.root).includes('P-9001'));assert.ok(!text(f.tree.root).includes('P-333'));assert.ok(!button(f,'Create and link'));
    assert.equal(f.completed.length,0);await f.click('Refresh workers');assert.deepEqual(f.completed,[result]);
  }finally{f.close();}
});
test('lost apply response exposes receipt lookup without an automatic second apply',async()=>{
  const f=await fixture(async action=>{if(action==='identity_preview')return structuredClone(review);if(action==='create_worker_person')throw new Error('Response lost');return {state:'applied',result};});
  try{await f.confirm();await f.click('Create and link');assert.ok(!button(f,'Create and link'));await f.click('Check recorded result');
    assert.ok(text(f.tree.root).includes('P-9001'));assert.equal(f.calls.filter(c=>c.action==='create_worker_person').length,1);
  }finally{f.close();}
});
test('uncommitted receipt result requires a new confirmation',async()=>{
  const f=await fixture(async action=>{if(action==='identity_preview')return structuredClone(review);if(action==='create_worker_person')throw new Error('Interrupted');return {state:'not_applied',result:null};});
  try{await f.confirm();await f.click('Create and link');await f.click('Check recorded result');assert.equal(button(f,'Create and link').props.disabled,true);assert.equal(f.calls.filter(c=>c.action==='create_worker_person').length,1);
  }finally{f.close();}
});
test('link review sends only its sealed token and leaves contact fields unchanged',async()=>{
  const f=await fixture(async action=>action==='identity_preview'?{...structuredClone(review),mode:'link',rows:[{...review.rows[0],target:{...review.rows[0].target,person_number:'P-9001'}}]}:{...result,mode:'link'},'link');
  try{assert.ok(text(f.tree.root).includes('contact fields and provider IDs will be preserved'));await f.confirm();await f.click('Apply reviewed links');assert.equal(f.calls[1].action,'save_worker_links');assert.deepEqual(f.calls[1].body,{review_token:'encrypted-review',confirm_review:true});}finally{f.close();}
});
test('workspace switch discards a late preview and prevents application',async()=>{
  let resolve;const f=await fixture(()=>new Promise(r=>{resolve=r;}));try{f.pinned='12';await act(async()=>resolve(structuredClone(review)));assert.ok(!button(f,'Create and link'));assert.ok(text(f.tree.root).includes('workspace changed'));assert.equal(f.calls.length,1);}finally{f.close();}
});
test('workspace switch during apply never presents old completion or refresh callback',async()=>{
  let resolve;const f=await fixture(async action=>action==='identity_preview'?structuredClone(review):new Promise(r=>{resolve=r;}));try{
    await f.confirm();let pending;act(()=>{pending=button(f,'Create and link').props.onClick();});f.pinned='12';await act(async()=>{resolve(result);await pending;});
    assert.ok(!text(f.tree.root).includes('P-9001'));assert.ok(!button(f,'Refresh workers'));assert.equal(button(f,'Close review').props.disabled,false);assert.equal(f.completed.length,0);
  }finally{f.close();}
});
test('closing during preview discards the result',async()=>{
  let resolve;const f=await fixture(()=>new Promise(r=>{resolve=r;}));f.close();await act(async()=>resolve(structuredClone(review)));assert.equal(f.calls.length,1);assert.equal(f.completed.length,0);
});
