import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { build } from 'esbuild';
const require=createRequire(import.meta.url), React=require('react'), {create,act}=require('react-test-renderer');
const source=await readFile(new URL('../../modules/people/ui/PersonDetail.jsx',import.meta.url),'utf8');
const start=source.indexOf('function CustomFieldsTab(');
assert.ok(start>=0);
const tab=source.slice(start,source.indexOf('const Item =',start));
const compiled=await build({stdin:{contents:"import React, {useState} from 'react';\nimport {api,useApi} from 'fixture-api';\n"+tab+'\nexport default CustomFieldsTab;',loader:'jsx'},bundle:true,platform:'node',format:'cjs',write:false,external:['react','fixture-api']});
const definitions=[
  {id:1,field_key:'precise',field_label:'Exact number',field_type:'number'},
  {id:2,field_key:'flag',field_label:'Optional flag',field_type:'boolean'},
  {id:3,field_key:'choices',field_label:'Choices',field_type:'multiselect',options_json:'["A","B"]'},
  {id:4,field_key:'restricted',field_label:'Restricted',field_type:'text',field_access:{visible:true,editable:false}},
  {id:5,field_key:'private',field_label:'Private',field_type:'text',sensitive_hidden:true},
];
const text=n=>typeof n==='string'?n:(n.children||[]).map(text).join('');
async function fixture({readError=null,saveError=null}={}){
  const f={calls:[],reloads:0}, module={exports:{}};
  const api={api:{post:async(url,body)=>{f.calls.push({url,body});if(saveError)throw new Error(saveError);return{ok:true};}},useApi:url=>({
    loading:false,error:url.includes('custom-field-values')?readError:null,reload:()=>f.reloads++,
    data:url.includes('definitions')?{definitions}:url.includes('values')?{values:[{field_key:'precise',value:'99999999999999.999999'},{field_key:'flag',value:null}]}:{layout:{layout:{}}},
  })};
  new Function('require','module','exports',compiled.outputFiles[0].text)(id=>id==='fixture-api'?api:require(id),module,module.exports);
  await act(async()=>{f.tree=create(React.createElement(module.exports.default,{personId:15}));});
  f.input=key=>f.tree.root.findByProps({'data-testid':'custom-values-input-'+key});
  f.change=async(key,target)=>act(async()=>f.input(key).props.onChange({target}));
  f.save=async()=>act(async()=>f.tree.root.findByProps({'data-testid':'custom-values-save'}).props.onClick());
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
