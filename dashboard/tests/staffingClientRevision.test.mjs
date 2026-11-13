import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require=createRequire(import.meta.url), React=require('react');
const {create,act}=require('react-test-renderer');
const compiled=await build({entryPoints:[fileURLToPath(new URL('../../modules/staffing/ui/Clients.jsx',import.meta.url))],bundle:true,platform:'node',format:'cjs',jsx:'automatic',write:false,external:['react','react/jsx-runtime','react-router-dom','lucide-react'],plugins:[{name:'fixture',setup(b){
  b.onResolve({filter:/lib\/api$/},()=>({path:'fixture-api',external:true}));
  b.onResolve({filter:/components\/BulkEditBar$/},()=>({path:'fixture-bulk',external:true}));
  b.onResolve({filter:/components\/ExportTemplatePicker$/},()=>({path:'fixture-export',external:true}));
}}]});
const client={id:17,company_id:25,name:'Synthetic client',status:'active',payment_terms_days:30,edit_revision:'reviewed-client-version'};
async function fixture(post){
  const calls=[];let reloads=0;const module={exports:{}};
  const api={api:{get:async()=>({client}),post:async(path,body)=>{calls.push({path,body});return post(path,body);}},useApi:()=>({data:{rows:[client]},loading:false,reload:()=>reloads++})};
  const router={Link:props=>React.createElement('a',props,props.children),useSearchParams:()=>React.useState(()=>new URLSearchParams('client_id=17'))};
  new Function('require','module','exports',compiled.outputFiles[0].text)(id=>id==='fixture-api'?api:id==='react-router-dom'?router:id==='fixture-bulk'?{__esModule:true,default:props=>React.createElement('bulk-edit',props)}:id==='fixture-export'?{__esModule:true,default:()=>null}:require(id),module,module.exports);
  let tree;await act(async()=>{tree=create(React.createElement(module.exports.default));});
  return{tree,calls,reloads:()=>reloads,find:id=>tree.root.findByProps({'data-testid':id}),close:()=>act(()=>tree.unmount())};
}
test('stale client save retains entered values and sends the original revision',async()=>{
  const f=await fixture(async()=>{throw new Error('The client changed. Refresh before saving.');});
  try{
    await act(async()=>f.find('staffing-client-name').props.onChange({target:{value:'Entered name'}}));
    await act(async()=>f.tree.root.findByType('form').props.onSubmit({preventDefault(){}}));
    assert.equal(f.calls[0].body.edit_revision,client.edit_revision);assert.equal(f.calls[0].body.name,'Entered name');
    assert.equal(f.find('staffing-client-name').props.value,'Entered name');assert.equal(f.reloads(),0);
    assert.match(f.find('staffing-client-save-error').children.join(''),/Refresh before saving/);
  }finally{f.close();}
});
test('bulk edit and closure submit the versions actually displayed',async()=>{
  const previous=globalThis.confirm;globalThis.confirm=()=>true;
  const f=await fixture(async()=>({updated:1,failed:0}));
  try{
    const boxes=f.tree.root.findAllByType('input').filter(n=>n.props.type==='checkbox');
    await act(async()=>boxes.at(-1).props.onChange());
    await act(async()=>f.tree.root.findByType('bulk-edit').props.onApply('payment_terms_days',45,'Payment terms'));
    assert.deepEqual(f.calls[0].body.edit_revisions,{'17':client.edit_revision});assert.deepEqual(f.calls[0].body.ids,[17]);
    await act(async()=>f.find('staffing-client-close').props.onClick());
    assert.deepEqual(f.calls[1].body,{id:17,edit_revision:client.edit_revision});
  }finally{f.close();globalThis.confirm=previous;}
});
