import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require=createRequire(import.meta.url),React=require('react');
const {create,act}=require('react-test-renderer');
const {MemoryRouter,Routes,Route}=require('react-router-dom');
let view,failure,pin,calls,reloads,post;
const apiFixture={useApi:()=>({data:view,error:failure,loading:false,reload:()=>reloads++}),getPinnedTenantId:()=>pin,
  bustApiCachePrefix:()=>{},api:{post:(...args)=>{calls.push(args);return post(...args);}}};
async function component(path){const result=await build({entryPoints:[fileURLToPath(new URL(path,import.meta.url))],bundle:true,platform:'node',format:'cjs',write:false,
  external:['react','react-router-dom'],plugins:[{name:'fixture',setup(b){b.onResolve({filter:/lib\/api$/},()=>({path:'fixture-api',external:true}));}}]});
  const module={exports:{}};new Function('require','module','exports',result.outputFiles[0].text)(id=>id==='fixture-api'?apiFixture:require(id),module,module.exports);return module.exports.default;}
const Correction=await component('../../modules/time/ui/TimeEntryCorrection.jsx'),History=await component('../../modules/time/ui/TimeEntryHistory.jsx');
const text=n=>typeof n==='string'?n:(n.children||[]).map(text).join('');
const ids=(tree,id)=>tree.root.findAllByProps({'data-testid':id});
const data=()=>({workspace_id:2002,revision:'a'.repeat(64),entry:{id:51,work_date:'2026-10-05',hours:'8.00',category:'regular_billable',description:'Original hours',status:'approved',source:'ai_inbox',source_ref_id:17,approved_at:'2026-10-12 12:00:00',approved_via:'manual'},worker:{person_number:'P-15',first_name:'Synthetic',last_name:'Worker'},placement:{id:902,placement_number:'PL-902',title:'Invented assignment'},custom_categories:[{id:3,label:'Training'}]});
function reset(){view=data();failure=null;pin='2002';calls=[];reloads=0;post=async()=>({ok:true,workspace_id:2002,superseded_entry_id:51,new_entry_id:52,timesheet_id:43});}
const render=Component=>React.createElement(MemoryRouter,{initialEntries:['/51']},React.createElement(Routes,null,React.createElement(Route,{path:'/:entryId',element:React.createElement(Component)})));
async function open(Component=Correction){let tree;await act(async()=>{tree=create(render(Component));});return tree;}
async function field(tree,key,value){await act(async()=>ids(tree,`time-correction-${key}`)[0].props.onChange({target:{value}}));}
const submit=tree=>ids(tree,'time-correction-form')[0].props.onSubmit({preventDefault(){}});
test('correction review displays original approval and source without permitting identity edits',async()=>{reset();const tree=await open();try{
  assert.match(text(ids(tree,'time-correction-original')[0]),/8.00 hours/);assert.match(text(tree.root),/source record 17/);assert.match(text(tree.root),/2026-10-12 12:00:00/);
  assert.match(text(tree.root),/P-15/);assert.match(text(tree.root),/PL-902/);
  assert.equal(ids(tree,'time-correction-submit')[0].props.disabled,true);assert.equal(tree.root.findAll(n=>n.props.name==='placement_id'||n.props.name==='person_id').length,0);assert.equal(calls.length,0);
}finally{act(()=>tree.unmount());}});
test('saving sends the reviewed replacement and offers the resulting week and history',async()=>{reset();const tree=await open();try{
  await field(tree,'hours','6.25');await field(tree,'date','2026-10-13');await field(tree,'category','OT_nonbillable');await field(tree,'custom','3');await field(tree,'description','Corrected paper hours');await field(tree,'reason','Reviewed original paper');
  await act(async()=>submit(tree));assert.equal(calls.length,1);assert.equal(calls[0][0],'/modules/time/api/entries.php?action=correct&id=51');
  assert.deepEqual(calls[0][1],{work_date:'2026-10-13',hours:6.25,category:'OT_nonbillable',custom_category_id:3,description:'Corrected paper hours',correction_reason:'Reviewed original paper',review_revision:'a'.repeat(64)});
  assert.equal(ids(tree,'time-correction-form').length,0);assert.match(text(tree.root),/Draft replacement created/);
  assert.ok(tree.root.findAllByType('a').some(a=>a.props.href==='/modules/staffing/timesheets/43'));assert.ok(tree.root.findAllByType('a').some(a=>a.props.href==='/modules/time/entries/52/history'));
}finally{act(()=>tree.unmount());}});
for(const value of ['','0','25','NaN'])test(`invalid hours ${JSON.stringify(value)} never submits`,async()=>{reset();const tree=await open();try{await field(tree,'hours',value);await field(tree,'reason','Reviewed');await act(async()=>submit(tree));assert.equal(calls.length,0);assert.match(text(tree.root),/Enter hours/);}finally{act(()=>tree.unmount());}});
test('blank reason never submits',async()=>{reset();const tree=await open();try{await act(async()=>submit(tree));assert.equal(calls.length,0);assert.match(text(tree.root),/Explain why/);}finally{act(()=>tree.unmount());}});
for(const mode of ['wrong-entry','wrong-workspace','denied','missing'])test(`review hides fields for ${mode}`,async()=>{reset();if(mode==='wrong-entry')view.entry.id=52;if(mode==='wrong-workspace')pin='2003';if(mode==='denied')failure=new Error('Access denied');if(mode==='missing')view=null;const tree=await open();try{assert.equal(ids(tree,'time-correction-form').length,0);assert.equal(tree.root.findAllByType('input').length,0);assert.equal(calls.length,0);}finally{act(()=>tree.unmount());}});
test('changed review resets replacement fields and requires a fresh reason',async()=>{reset();const tree=await open();try{
  await field(tree,'hours','5');await field(tree,'reason','Previous review');view={...view,revision:'b'.repeat(64),entry:{...view.entry,hours:'7.00'}};
  await act(async()=>tree.update(render(Correction)));assert.equal(ids(tree,'time-correction-hours')[0].props.value,'7.00');assert.equal(ids(tree,'time-correction-reason')[0].props.value,'');assert.equal(ids(tree,'time-correction-submit')[0].props.disabled,true);
}finally{act(()=>tree.unmount());}});
test('a stale-review refusal preserves the draft and offers reload',async()=>{reset();post=async()=>{throw new Error('Reload the correction review');};const tree=await open();try{
  await field(tree,'hours','6');await field(tree,'reason','Reviewed');await act(async()=>submit(tree));assert.match(text(ids(tree,'time-correction-error')[0]),/Reload the correction review/);assert.equal(ids(tree,'time-correction-hours')[0].props.value,'6');assert.equal(ids(tree,'time-correction-saved').length,0);
  act(()=>ids(tree,'time-correction-error')[0].findByType('button').props.onClick());assert.equal(reloads,1);
}finally{act(()=>tree.unmount());}});
test('double submit sends one request while the original is in flight',async()=>{reset();let resolve;post=()=>new Promise(r=>resolve=r);const tree=await open();try{
  await field(tree,'reason','Reviewed');await act(async()=>{const pending=submit(tree);await submit(tree);assert.equal(calls.length,1);resolve({ok:true,workspace_id:2002,superseded_entry_id:51,new_entry_id:52,timesheet_id:43});await pending;});assert.equal(ids(tree,'time-correction-saved').length,1);
}finally{act(()=>tree.unmount());}});
test('a workspace switch during submission conceals the old response',async()=>{reset();let resolve;post=()=>new Promise(r=>resolve=r);const tree=await open();try{
  await field(tree,'reason','Reviewed');await act(async()=>{const pending=submit(tree);pin='2003';resolve({ok:true,workspace_id:2002,superseded_entry_id:51,new_entry_id:52,timesheet_id:43});await pending;});assert.equal(ids(tree,'time-correction-saved').length,0);assert.equal(ids(tree,'time-correction-form').length,0);assert.match(text(tree.root),/original workspace/);
}finally{act(()=>tree.unmount());}});
test('an unexpected response cannot claim a saved correction',async()=>{reset();post=async()=>({ok:true,workspace_id:2002,superseded_entry_id:99,new_entry_id:52,timesheet_id:43});const tree=await open();try{await field(tree,'reason','Reviewed');await act(async()=>submit(tree));assert.equal(ids(tree,'time-correction-saved').length,0);assert.match(text(tree.root),/Check time history/);}finally{act(()=>tree.unmount());}});
test('history renders the original and each replacement with separate approvals and weeks',async()=>{reset();view={workspace_id:2002,entry_id:51,entries:[{...data().entry,status:'superseded',timesheet_id:42},{...data().entry,id:52,hours:6,status:'draft',source:'manual_entry',source_ref_id:null,approved_at:null,correction_reason:'Corrected paper',timesheet_id:43}]};const tree=await open(History);try{assert.match(text(ids(tree,'time-history-entry-51')[0]),/8.00 hours/);assert.match(text(ids(tree,'time-history-entry-51')[0]),/Approved 2026-10-12/);assert.match(text(ids(tree,'time-history-entry-52')[0]),/Awaiting approval/);assert.match(text(ids(tree,'time-history-entry-52')[0]),/Corrected paper/);assert.equal(calls.length,0);}finally{act(()=>tree.unmount());}});
for(const mode of ['wrong-entry','wrong-workspace','denied'])test(`history conceals mismatched records for ${mode}`,async()=>{reset();view={workspace_id:2002,entry_id:51,entries:[data().entry]};if(mode==='wrong-entry')view.entry_id=52;if(mode==='wrong-workspace')pin='2003';if(mode==='denied')failure=new Error('History unavailable');const tree=await open(History);try{assert.equal(ids(tree,'time-history-entry-51').length,0);}finally{act(()=>tree.unmount());}});
