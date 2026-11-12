import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { build } from 'esbuild';
const require=createRequire(import.meta.url), React=require('react'), {create,act}=require('react-test-renderer');
const source=await readFile(new URL('../src/components/RecordCustomFields.jsx',import.meta.url),'utf8');
const compiled=await build({stdin:{contents:source.replace("'../lib/api'","'fixture-api'"),loader:'jsx'},bundle:true,platform:'node',format:'cjs',write:false,external:['react','fixture-api']});
const definitions=[
  {id:1,field_key:'precise',field_label:'Exact number',field_type:'number'},
  {id:2,field_key:'flag',field_label:'Optional flag',field_type:'boolean'},
  {id:3,field_key:'choices',field_label:'Choices',field_type:'multiselect',options_json:'["A","B"]'},
  {id:4,field_key:'restricted',field_label:'Restricted',field_type:'text',field_access:{visible:true,editable:false}},
  {id:5,field_key:'private',field_label:'Private',field_type:'text',sensitive_hidden:true},
].map(d=>({field_access:{visible:true,editable:true},...d}));
const text=n=>typeof n==='string'?n:(n.children||[]).map(text).join('');
async function fixture({readError=null,saveError=null,canManage=true,scopeMismatch=false,entity='people'}={}){
  const f={calls:[],reloads:0,tenant:'2002',props:{entityType:entity,recordId:15,definitionsUrl:'/definitions'}}, module={exports:{}};
  const saved={};
  const api={getPinnedTenantId:()=>f.tenant,api:{post:async(url,body)=>{f.calls.push({url,body});if(saveError)throw new Error(saveError);saved[url]={...(saved[url]||{}),...body.values};return{ok:true};}},useApi:url=>{
    const q=new URL(url,'https://example.invalid').searchParams;
    const valuesUrl='/api/custom_field_values.php?entity_type='+q.get('entity_type')+'&record_id='+q.get('record_id');
    return {loading:false,error:url.includes('custom_field_values')?readError:null,reload:()=>f.reloads++,
      data:url.includes('definitions')?{entity_type:q.get('entity_type'),owner_tenant_id:10,can_manage_definitions:canManage,definitions}:
        url.includes('values')?{entity_type:q.get('entity_type'),record_id:Number(q.get('record_id')),owner_tenant_id:scopeMismatch?20:10,can_manage_values:canManage,
          values:Object.entries({precise:'99999999999999.999999',flag:null,...saved[valuesUrl]}).map(([field_key,value])=>({field_key,value}))}:{layout:{layout:{}}},
    };
  }};
  new Function('require','module','exports',compiled.outputFiles[0].text)(id=>id==='fixture-api'?api:require(id),module,module.exports);
  await act(async()=>{f.tree=create(React.createElement(module.exports.default,f.props));});
  f.input=key=>f.tree.root.findByProps({'data-testid':'custom-values-input-'+key});
  f.change=async(key,target)=>act(async()=>f.input(key).props.onChange({target}));
  f.save=async()=>act(async()=>f.tree.root.findByProps({'data-testid':'custom-values-save'}).props.onClick());
  f.render=async(props)=>act(async()=>{f.props={...f.props,...props};f.tree.update(React.createElement(module.exports.default,f.props));});
  f.close=()=>act(()=>f.tree.unmount());return f;
}
test('exact decimal survives display and submission without a floating point conversion',async()=>{
  const f=await fixture();try{assert.equal(f.input('precise').props.value,'99999999999999.999999');await f.change('precise',{value:'99999999999999.999998'});await f.save();assert.equal(f.calls[0].body.values.precise,'99999999999999.999998');}finally{f.close();}
});
test('unset, false and clear remain distinct operator choices',async()=>{
  const f=await fixture();try{assert.equal(f.input('flag').props.value,'');await f.change('flag',{value:'false'});await f.save();assert.equal(f.calls[0].body.values.flag,false);await f.change('flag',{value:''});await f.save();assert.equal(f.calls[1].body.values.flag,null);}finally{f.close();}
});
test('multiple selections reach the service as a list',async()=>{
  const f=await fixture();try{assert.equal(f.input('choices').props.multiple,true);await f.change('choices',{selectedOptions:[{value:'A'},{value:'B'}]});await f.save();assert.deepEqual(f.calls[0].body.values.choices,['A','B']);}finally{f.close();}
});
test('hidden sensitive fields and restricted edits are not offered as blank writable inputs',async()=>{
  const f=await fixture();try{assert.equal(f.input('restricted').props.disabled,true);assert.equal(f.tree.root.findAllByProps({'data-testid':'custom-values-input-private'}).length,0);assert.equal(f.calls.length,0);}finally{f.close();}
});
test('failed read does not render an empty editable form',async()=>{
  const f=await fixture({readError:new Error('Directory unavailable')});try{assert.ok(text(f.tree.root).includes('Directory unavailable'));assert.equal(f.tree.root.findAllByType('input').length,0);assert.equal(f.calls.length,0);}finally{f.close();}
});
test('failed atomic save retains draft and reports the problem without claiming success',async()=>{
  const f=await fixture({saveError:'Unknown custom field'});try{await f.change('precise',{value:'12.5'});await f.save();assert.equal(f.input('precise').props.value,'12.5');assert.ok(text(f.tree.root).includes('Unknown custom field'));assert.equal(f.reloads,0);}finally{f.close();}
});

for(const entity of ['people','companies','placements']) test(`${entity} saves only changed fields to its own entity and original directory`,async()=>{
  const f=await fixture({entity});try{await f.change('flag',{value:'false'});await f.save();assert.equal(f.calls[0].url,`/api/custom_field_values.php?entity_type=${entity}&record_id=15`);assert.deepEqual(f.calls[0].body,{values:{flag:false},expected_values:{flag:null},expected_owner_tenant_id:10});}finally{f.close();}
});
test('view-only operator sees values without save, definition management or editable inputs',async()=>{
  const f=await fixture({canManage:false});try{assert.equal(f.input('precise').props.disabled,true);assert.equal(f.tree.root.findAllByProps({'data-testid':'custom-values-save'}).length,0);assert.equal(f.tree.root.findAllByType('a').length,0);}finally{f.close();}
});
test('mismatched definition and value directories fail closed',async()=>{
  const f=await fixture({scopeMismatch:true});try{assert.ok(text(f.tree.root).includes('directory changed'));assert.equal(f.tree.root.findAllByType('input').length,0);}finally{f.close();}
});
test('changing entity or record discards the prior record draft',async()=>{
  const f=await fixture();try{
    await f.change('precise',{value:'12'});await f.render({entityType:'placements'});assert.equal(f.input('precise').props.value,'99999999999999.999999');
    await f.change('precise',{value:'14'});await f.render({recordId:16});assert.equal(f.input('precise').props.value,'99999999999999.999999');
    await f.change('flag',{value:'true'});await f.save();assert.ok(f.calls[0].url.endsWith('entity_type=placements&record_id=16'));assert.deepEqual(f.calls[0].body.values,{flag:true});
  }finally{f.close();}
});
test('changing the active company discards the prior draft even for the same record number',async()=>{
  const f=await fixture();try{await f.change('precise',{value:'12'});f.tenant='2003';await f.render({});assert.equal(f.input('precise').props.value,'99999999999999.999999');assert.equal(f.calls.length,0);}finally{f.close();}
});
test('conflicting save retains changes and never claims success',async()=>{
  const f=await fixture({saveError:'Custom fields changed since you opened this record.'});try{await f.change('precise',{value:'12'});await f.save();assert.equal(f.input('precise').props.value,'12');assert.ok(text(f.tree.root).includes('changed since'));assert.equal(f.tree.root.findAllByProps({'data-testid':'custom-values-saved'}).length,0);}finally{f.close();}
});
