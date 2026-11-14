import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require=createRequire(import.meta.url),React=require('react');
const { create,act }=require('react-test-renderer'),{MemoryRouter}=require('react-router-dom');
const compiled=await build({entryPoints:[fileURLToPath(new URL('../src/pages/QuantaSettings.jsx',import.meta.url))],bundle:true,platform:'node',format:'cjs',write:false,
  external:['react','react-router-dom'],plugins:[{name:'fixtures',setup(b){
    b.onResolve({filter:/lib\/api$/},()=>({path:'fixture-api',external:true}));
    b.onResolve({filter:/^\.\/Quanta/},args=>({path:args.path,namespace:'other-panel'}));
    b.onLoad({filter:/.*/,namespace:'other-panel'},()=>({contents:'export default function Panel(){return null}; export const QuantaMetadataRecovery=()=>null, QuantaTimeControlRecovery=()=>null, QuantaLegacyTimeRecovery=()=>null, QuantaTimeReviewRecovery=()=>null, QuantaTimeRevisionHistory=()=>null;'}));
  }}]});
const text=n=>typeof n==='string'?n:(n.children||[]).map(text).join('');
const row={id:'late-1',status:'supplement',worker_id:'worker-1',work_date:'2026-11-14',hours:1.25,placement_id:902,placement_number:'PL-902',person_name:'Synthetic Worker',
  destination_review_hash:'a'.repeat(64),destination:{mode:'supplement',period_start:'2026-11-09',period_end:'2026-11-15',supplement_of_id:8},message:'Separate review preserves the original approval.'};
const preview=()=>({rows:[structuredClone(row)],total:1,counts:{ready:0,supplement:1,update:0,imported:0,conflict:0},source_workspace:{id:'demo-quanta',name:'Demo'}});
async function fixture(){
  const f={calls:[],pin:'2002',status:{connected:true,workspace:{id:2002,name:'Demo Core'},connection_revision:1},preview:preview(),failure:null};
  const api={getPinnedTenantId:()=>f.pin,useApi:()=>({data:f.status,reload:async()=>{}}),api:{
    get:async url=>url.endsWith('time_access')?{available:true}:{workers:[],people:[],placements:[],routes:[],worker_links:[],worksites:[],dimensions:[]},
    post:async(url,body,options)=>{f.calls.push({url,body,options});if(url.endsWith('preview'))return structuredClone(f.preview);
      if(f.failure)throw f.failure;return {inserted:1,updated:0,timesheets:[{id:9}]};}
  }};
  const module={exports:{}};new Function('require','module','exports',compiled.outputFiles[0].text)(id=>id==='fixture-api'?api:require(id),module,module.exports);
  const Component=module.exports.default;f.render=()=>React.createElement(MemoryRouter,null,React.createElement(Component));
  await act(async()=>{f.tree=create(f.render());});
  f.button=label=>f.tree.root.findAllByType('button').find(n=>text(n).trim()===label);
  f.click=async label=>{const b=f.button(label);assert.ok(b,label);assert.equal(!!b.props.disabled,false);await act(async()=>b.props.onClick());};
  f.select=async(checked=true)=>act(async()=>f.tree.root.findByProps({'aria-label':'Select Quanta entry late-1'}).props.onChange({target:{checked}}));
  f.confirm=async()=>act(async()=>f.tree.root.findByProps({'data-testid':'confirm-supplemental-time'}).props.onChange({target:{checked:true}}));
  f.close=()=>act(()=>f.tree.unmount());await f.click('Preview time');return f;
}
test('preview shows the Core week and links the approved parent before import',async()=>{const f=await fixture();try{
  assert.match(text(f.tree.root),/Core review week: 2026-11-09 → 2026-11-15/);
  assert.ok(f.tree.root.findAllByType('a').some(a=>a.props.href==='/modules/staffing/timesheets/8'));
  assert.equal(f.calls.filter(c=>c.url.endsWith('import')).length,0);
}finally{f.close();}});
test('supplement requires explicit confirmation and submits the selected review in the original company',async()=>{const f=await fixture();try{
  await f.select();assert.equal(f.button('Import 1 selected').props.disabled,true);await f.confirm();await f.click('Import 1 selected');
  const imports=f.calls.filter(c=>c.url.endsWith('import'));assert.equal(imports.length,1);
  assert.deepEqual(imports[0].body.entry_ids,['late-1']);assert.deepEqual(imports[0].body.destination_reviews,{'late-1':row.destination_review_hash});
  assert.equal(imports[0].body.confirm_supplements,true);assert.equal(imports[0].options.headers['X-CoreFlux-Tenant-Id'],'2002');
  assert.match(text(f.tree.root),/1 weekly timesheets ready for review/);assert.equal(f.button('Import 0 selected').props.disabled,true);
}finally{f.close();}});
test('selection changes discard earlier supplement consent',async()=>{const f=await fixture();try{
  await f.select();await f.confirm();await f.select(false);await f.select();
  assert.equal(f.tree.root.findByProps({'data-testid':'confirm-supplemental-time'}).props.checked,false);assert.equal(f.button('Import 1 selected').props.disabled,true);
}finally{f.close();}});
test('stale source is not automatically reimported and a fresh preview clears the old decision',async()=>{const f=await fixture();try{
  f.failure=new Error('Review and confirm the current supplemental destination');await f.select();await f.confirm();await f.click('Import 1 selected');
  assert.match(text(f.tree.root),/Review and confirm the current supplemental destination/);assert.equal(f.calls.filter(c=>c.url.endsWith('import')).length,1);
  f.preview.rows[0].destination_review_hash='b'.repeat(64);await f.click('Preview time');await f.select();assert.equal(f.button('Import 1 selected').props.disabled,true);
}finally{f.close();}});
test('changed company is refused before a stale page can import',async()=>{const f=await fixture();try{
  await f.select();await f.confirm();f.pin='2003';await f.click('Import 1 selected');
  assert.equal(f.calls.filter(c=>c.url.endsWith('import')).length,0);assert.match(text(f.tree.root),/workspace changed/);
}finally{f.close();}});
test('connection replacement removes old source review and selection',async()=>{const f=await fixture();try{
  await f.select();await f.confirm();f.status={...f.status,connection_revision:2};await act(async()=>f.tree.update(f.render()));
  assert.equal(f.tree.root.findAllByProps({'data-testid':'confirm-supplemental-time'}).length,0);
  assert.equal(f.tree.root.findAllByProps({'aria-label':'Select Quanta entry late-1'}).length,0);
}finally{f.close();}});
