import assert from 'node:assert/strict';
import test from 'node:test';
import React from 'react';
import { act, create } from 'react-test-renderer';
import { build } from 'esbuild';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
const require=createRequire(import.meta.url);
const compiled=await build({entryPoints:[fileURLToPath(new URL('../../modules/placements/ui/OneTimeItems.jsx',import.meta.url))],bundle:true,platform:'node',format:'cjs',write:false,external:['react'],plugins:[{name:'item-api',setup(b){b.onResolve({filter:/lib\/api$/},()=>({path:'fixture-api',external:true}));}}]});
const text=n=>typeof n==='string'?n:(n.children||[]).map(text).join('');
async function fixture(options={}){
  const storage=options.storage || new Map();
  globalThis.window={confirm:()=>true,sessionStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)}};
  const f={calls:[],refreshes:0},module={exports:{}};
  const api=Object.fromEntries(['post','delete'].map(method=>[method,async(url,body)=>{f.calls.push({method,url,body});return options.respond?.(method,url,body,f.calls.length)??{id:91,changed:true,item:{...body,id:91,placement_id:15,status:'active',source_managed:0,source_external_id:`native-item:${body.request_id}`}};} ]));
  new Function('require','module','exports',compiled.outputFiles[0].text)(id=>id==='fixture-api'?{api}:require(id),module,module.exports);
  f.props={placementId:15,startDate:'2026-10-01',currency:'USD',canManage:true,items:[],parties:[{id:2,settlement_channel:'ar',display_name:'Synthetic client'}],refresh:async()=>{f.refreshes++;if(options.failRefresh)throw new Error('Refresh unavailable');}};
  f.render=()=>React.createElement(module.exports.default,f.props);
  await act(async()=>{f.tree=create(f.render());});
  f.update=()=>act(async()=>f.tree.update(f.render()));
  f.fill=async(label,value)=>act(async()=>f.tree.root.findByProps({'aria-label':label}).props.onChange({target:{value}}));
  f.submit=()=>act(async()=>f.tree.root.findByType('form').props.onSubmit({preventDefault(){}}));
  f.close=()=>act(()=>f.tree.unmount());
  await f.fill('Item description','Background check');await f.fill('Item recipient','2');await f.fill('Item amount','25.00');return f;
}
test('an interrupted save retries the exact request and prevents editing its pending amount',async()=>{
  const f=await fixture({respond:async(method,url,body,count)=>{if(count===1)throw new Error('Connection lost');return {id:91,changed:false,item:{...body,id:91,placement_id:15,status:'active',source_external_id:`native-item:${body.request_id}`}};}});
  try{await f.submit();assert.equal(f.tree.root.findByType('fieldset').props.disabled,true);assert.ok(text(f.tree.root).includes('Retry same save'));await f.submit();assert.deepEqual(f.calls[0].body,f.calls[1].body);assert.match(f.calls[0].body.request_id,/^[a-f0-9-]{36}$/);assert.ok(text(f.tree.root).includes('No duplicate item was added'));assert.equal(f.tree.root.findAllByProps({'data-testid':'economic-item-91'}).length,1);assert.equal(f.tree.root.findByType('fieldset').props.disabled,false);}finally{f.close();}
});
test('a confirmed save stays successful when refreshing the surrounding contract fails',async()=>{
  const f=await fixture({failRefresh:true});try{await f.submit();assert.ok(text(f.tree.root).includes('One-time item added'));assert.equal(f.tree.root.findAllByProps({'data-testid':'economic-item-91'}).length,1);assert.equal(f.tree.root.findByProps({'aria-label':'Item amount'}).props.value,'');assert.equal(f.calls.length,1);}finally{f.close();}
});
test('validation rejection preserves editable input while an unconfirmed response keeps the same request',async()=>{
  const options={respond:async()=>{const error=new Error('Invalid amount');error.status=422;throw error;}};
  const f=await fixture(options);try{await f.submit();assert.equal(f.tree.root.findByType('fieldset').props.disabled,false);assert.equal(f.tree.root.findByProps({'aria-label':'Item amount'}).props.value,'25.00');options.respond=async()=>({});await f.submit();assert.equal(f.tree.root.findByType('fieldset').props.disabled,true);await f.submit();assert.deepEqual(f.calls[1].body,f.calls[2].body);}finally{f.close();}
});
test('a newly applied server item replaces the pending receipt and removes its remove action',async()=>{
  const f=await fixture();try{await f.submit();f.props.items=[{...f.calls[0].body,id:91,placement_id:15,status:'active',settlement_status:'applied',ar_invoice_id:18}];await f.update();assert.equal(f.tree.root.findAllByProps({'data-testid':'economic-item-91'}).length,1);assert.ok(text(f.tree.root).includes('Applied'));assert.equal(f.tree.root.findAllByType('button').filter(b=>text(b)==='Remove').length,0);}finally{f.close();}
});
test('a permission downgrade removes the form and prevents replay of a pending save',async()=>{
  const f=await fixture({respond:async()=>{throw new Error('Connection lost');}});try{await f.submit();f.props.canManage=false;await f.update();assert.equal(f.tree.root.findAllByType('form').length,0);assert.equal(f.tree.root.findAllByType('button').length,0);assert.equal(f.calls.length,1);}finally{f.close();}
});
test('reopening the form after a page reload resumes the original uncertain save',async()=>{
  const storage=new Map();const first=await fixture({storage,respond:async()=>{throw new Error('Connection lost');}});
  await first.submit();const original=first.calls[0].body;first.close();
  const reopened=await fixture({storage});try{assert.equal(reopened.tree.root.findByType('fieldset').props.disabled,true);assert.ok(text(reopened.tree.root).includes('Retry same save'));await reopened.submit();assert.deepEqual(reopened.calls[0].body,original);assert.equal(storage.size,0);}finally{reopened.close();}
});
