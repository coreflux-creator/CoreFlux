import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require=createRequire(import.meta.url),React=require('react');
const {create,act}=require('react-test-renderer');
let pin,calls,post,done,closed;
const fixture={getPinnedTenantId:()=>pin,bustApiCachePrefix:()=>{},api:{post:(...args)=>{calls.push(args);return post(...args);}}};
const result=await build({entryPoints:[fileURLToPath(new URL('../../modules/time/ui/TimeDecisionReview.jsx',import.meta.url))],bundle:true,platform:'node',format:'cjs',write:false,external:['react'],plugins:[{name:'fixture',setup(b){b.onResolve({filter:/lib\/api$/},()=>({path:'fixture-api',external:true}));}}]});
const module={exports:{}};new Function('require','module','exports',result.outputFiles[0].text)(id=>id==='fixture-api'?fixture:require(id),module,module.exports);const Review=module.exports.default;
const text=n=>typeof n==='string'?n:(n.children||[]).map(text).join('');
const find=(tree,id)=>tree.root.findAllByProps({'data-testid':id});
const review=(kind='entries',decision='approve',ids=[1,2])=>({workspace_id:2002,kind,decision,ids,revision:'a'.repeat(64),entry_count:2,total_hours:10,entries:[{id:1,person_number:'P-15',worker_name:'Synthetic worker',placement_number:'PL-902',placement_title:'Test assignment',work_date:'2026-10-05',hours:8,hour_type:'regular',source:'quanta',status:'pending_review',will_change:true},{id:2,person_number:'P-15',worker_name:'Synthetic worker',placement_number:'PL-902',placement_title:'Test assignment',work_date:'2026-10-06',hours:2,category:'OT_billable',source:'manual_entry',will_change:true}]});
const props=(extra={})=>({kind:'entries',ids:[1,2],decision:'approve',onClose:()=>closed++,onDecided:value=>done.push(value),...extra});
function reset(){pin='2002';calls=[];done=[];closed=0;post=async(path,body)=>path.includes('decision_review')?review('entries',body.decision,body.ids):({workspace_id:2002,kind:'entries',decision:body.decision,ids:body.ids,succeeded:body.ids.length});}
async function open(extra={}){let tree;await act(async()=>{tree=create(React.createElement(Review,props(extra)));});return tree;}
const submit=tree=>find(tree,'time-decision-form')[0].props.onSubmit({preventDefault(){}});

test('review displays stable identities and source hours before sending a decision',async()=>{reset();const tree=await open();try{
 assert.equal(calls.length,1);assert.match(text(tree.root),/P-15/);assert.match(text(tree.root),/PL-902/);assert.match(text(tree.root),/10.00 hours/);assert.match(text(tree.root),/quanta/);assert.match(text(tree.root),/2026-10-05/);assert.equal(done.length,0);
 await act(async()=>submit(tree));assert.equal(calls.length,2);assert.equal(calls[1][0],'/modules/time/api/entries.php?action=decision_commit');assert.deepEqual(calls[1][1],{ids:[1,2],decision:'approve',review_revision:'a'.repeat(64)});assert.equal(done.length,1);
}finally{act(()=>tree.unmount());}});

test('week rejection retains prior approvals in the review and sends the entered reason',async()=>{reset();post=async(path,body)=>path.includes('decision_review')?{...review('weeks','reject',[7]),entry_count:1,entries:[{...review().entries[0],status:'approved',will_change:false},review().entries[1]]}:{workspace_id:2002,kind:'weeks',decision:'reject',ids:[7]};const tree=await open({kind:'weeks',decision:'reject',ids:[7]});try{
 assert.match(text(tree.root),/Retain earlier approval/);assert.equal(find(tree,'time-decision-confirm')[0].props.disabled,true);await act(async()=>submit(tree));assert.equal(calls.length,1);
 await act(async()=>find(tree,'time-decision-reason')[0].props.onChange({target:{value:' Correct source hours '}}));await act(async()=>submit(tree));assert.equal(calls[1][0],'/modules/staffing/api/timesheets.php?action=decision_commit');assert.equal(calls[1][1].reason,'Correct source hours');assert.equal(done.length,1);
}finally{act(()=>tree.unmount());}});

for(const field of ['workspace','kind','decision','ids','revision','empty'])test(`mismatched ${field} cannot authorize a decision`,async()=>{reset();post=async()=>{const data=review();if(field==='workspace')data.workspace_id=999;if(field==='kind')data.kind='weeks';if(field==='decision')data.decision='reject';if(field==='ids')data.ids=[1];if(field==='revision')data.revision='short';if(field==='empty')data.entries=[];return data;};const tree=await open();try{assert.equal(find(tree,'time-decision-form').length,0);assert.equal(calls.length,1);assert.match(text(tree.root),/does not match/);}finally{act(()=>tree.unmount());}});

test('workspace switching after review prevents submission',async()=>{reset();const tree=await open();try{pin='999';await act(async()=>tree.update(React.createElement(Review,props())));assert.equal(find(tree,'time-decision-form').length,0);assert.equal(calls.length,1);}finally{act(()=>tree.unmount());}});
test('workspace switch is checked again in an already-rendered confirmation handler',async()=>{reset();const tree=await open();try{pin='999';await act(async()=>submit(tree));assert.equal(calls.length,1);assert.equal(done.length,0);}finally{act(()=>tree.unmount());}});

test('a stale decision requires another displayed review and never automatically retries',async()=>{reset();post=async(path,body)=>{if(path.includes('decision_commit'))throw new Error('The reviewed time changed.');return review('entries',body.decision,body.ids);};const tree=await open();try{await act(async()=>submit(tree));assert.equal(done.length,0);assert.equal(find(tree,'time-decision-form').length,0);assert.match(text(tree.root),/reviewed time changed/);const reload=tree.root.findAllByType('button').find(button=>text(button)==='Reload review');await act(async()=>reload.props.onClick());assert.equal(calls.length,3);assert.equal(calls.filter(([url])=>url.includes('decision_commit')).length,1);assert.equal(find(tree,'time-decision-form').length,1);}finally{act(()=>tree.unmount());}});

test('double confirmation sends one request while a decision is pending',async()=>{reset();let finish;post=async(path,body)=>path.includes('decision_review')?review():new Promise(resolve=>{finish=resolve;});const tree=await open();try{let first;await act(async()=>{first=submit(tree);submit(tree);});assert.equal(calls.length,2);assert.equal(find(tree,'time-decision-cancel')[0].props.disabled,true);await act(async()=>{finish({workspace_id:2002,kind:'entries',decision:'approve',ids:[1,2]});await first;});assert.equal(done.length,1);}finally{act(()=>tree.unmount());}});

test('changing the selection discards a late response for the old selection',async()=>{reset();let finishOld;post=async(path,body)=>body.ids[0]===1?new Promise(resolve=>{finishOld=resolve;}):review('entries','approve',body.ids);const tree=await open();try{await act(async()=>tree.update(React.createElement(Review,props({ids:[3]}))));await act(async()=>finishOld(review()));await act(async()=>submit(tree));assert.deepEqual(calls[2][1].ids,[3]);}finally{act(()=>tree.unmount());}});

test('response mismatch does not announce success or allow a blind repeat',async()=>{reset();post=async(path)=>path.includes('decision_review')?review():{workspace_id:999,kind:'entries',decision:'approve',ids:[1,2]};const tree=await open();try{await act(async()=>submit(tree));assert.equal(done.length,0);assert.equal(find(tree,'time-decision-form').length,0);assert.match(text(tree.root),/Check time history/);}finally{act(()=>tree.unmount());}});

test('cancel and escape do not send a decision',async()=>{reset();const tree=await open();try{find(tree,'time-decision-cancel')[0].props.onClick();find(tree,'time-decision-review')[0].props.onKeyDown({key:'Escape',preventDefault(){}});assert.equal(closed,2);assert.equal(calls.length,1);}finally{act(()=>tree.unmount());}});
