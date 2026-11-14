import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require=createRequire(import.meta.url),React=require('react');
const {create,act}=require('react-test-renderer');
let pin,calls,get,post;
const fixture={getPinnedTenantId:()=>pin,api:{get:(...args)=>{calls.push(['GET',...args]);return get(...args);},post:(...args)=>{calls.push(['POST',...args]);return post(...args);}}};
const built=await build({entryPoints:[fileURLToPath(new URL('../../modules/time/ui/AccountingHandoff.jsx',import.meta.url))],bundle:true,platform:'node',format:'cjs',write:false,external:['react'],plugins:[{name:'fixture',setup(b){b.onResolve({filter:/lib\/api$/},()=>({path:'fixture-api',external:true}));}}]});
const module={exports:{}};new Function('require','module','exports',built.outputFiles[0].text)(id=>id==='fixture-api'?fixture:require(id),module,module.exports);
const Handoff=module.exports.default;
const data=(extra={})=>({id:19,timesheet_id:42,workspace_id:2002,status:'pending',attempts:0,review_revision:'a'.repeat(64),can_retry:true,...extra});
const text=n=>typeof n==='string'?n:(n.children||[]).map(text).join('');
const find=(tree,id)=>tree.root.findAllByProps({'data-testid':id});
const props=(extra={})=>({timesheetId:42,workspaceId:2002,refreshKey:'approved',...extra});
function reset(){pin='2002';calls=[];get=async()=>data();post=async()=>data({status:'posted',attempts:1,can_retry:false,review_revision:'b'.repeat(64)});}
async function open(extra={}){let tree;await act(async()=>{tree=create(React.createElement(Handoff,props(extra)));});return tree;}
async function review(tree){await act(async()=>find(tree,'handoff-review-post')[0].props.onClick());}
const submit=tree=>find(tree,'handoff-confirm-form')[0].props.onSubmit({preventDefault(){}});

test('opening accounting reads status only and confirms the whole approved week before posting',async()=>{reset();const tree=await open();try{
  assert.equal(calls.length,1);assert.match(text(tree.root),/Awaiting accounting/);assert.equal(find(tree,'handoff-confirm-form').length,0);
  await review(tree);assert.match(text(tree.root),/complete approved week TS-42/);assert.equal(calls.length,1);
  await act(async()=>submit(tree));assert.deepEqual(calls[1],['POST','/modules/time/api/accounting_handoffs.php?timesheet_id=42',{review_revision:'a'.repeat(64)}]);
  assert.match(text(tree.root),/Posted to ledger/);assert.equal(find(tree,'handoff-review-post').length,0);
}finally{act(()=>tree.unmount());}});

for(const status of ['posted','cancelled'])test(`${status} cannot post again`,async()=>{reset();get=async()=>data({status});const tree=await open();try{assert.equal(find(tree,'handoff-review-post').length,0);assert.equal(calls.length,1);}finally{act(()=>tree.unmount());}});
for(const options of [{can_retry:false},{can_retry:undefined}])test(`server permission ${options.can_retry} withholds posting`,async()=>{reset();get=async()=>data(options);const tree=await open();try{assert.equal(find(tree,'handoff-review-post').length,0);}finally{act(()=>tree.unmount());}});
test('placement-scoped view cannot post a whole week',async()=>{reset();const tree=await open({allowActions:false});try{assert.match(text(tree.root),/Awaiting accounting/);assert.equal(find(tree,'handoff-review-post').length,0);}finally{act(()=>tree.unmount());}});
for(const mismatch of [{workspace_id:999},{timesheet_id:99},{review_revision:'bad'},{status:'unknown'}])test(`refuses mismatched review ${JSON.stringify(mismatch)}`,async()=>{reset();get=async()=>data(mismatch);const tree=await open();try{assert.match(text(tree.root),/does not match/);assert.equal(find(tree,'handoff-review-post').length,0);assert.equal(calls.length,1);}finally{act(()=>tree.unmount());}});
test('missing request is visible and cannot be manufactured from the status card',async()=>{reset();get=async()=>{throw Object.assign(new Error('missing'),{status:404});};const tree=await open();try{assert.match(text(tree.root),/No accounting handoff is recorded/);assert.equal(find(tree,'handoff-review-post').length,0);}finally{act(()=>tree.unmount());}});
test('cancel does not post',async()=>{reset();const tree=await open();try{await review(tree);await act(async()=>tree.root.findAllByType('button').find(b=>text(b)==='Cancel').props.onClick());assert.equal(calls.length,1);assert.equal(find(tree,'handoff-confirm-form').length,0);}finally{act(()=>tree.unmount());}});
test('workspace change is rechecked by an already-rendered confirmation',async()=>{reset();const tree=await open();try{await review(tree);pin='999';await act(async()=>submit(tree));assert.equal(calls.length,1);}finally{act(()=>tree.unmount());}});
test('stale or uncertain posting requires a fresh status read and never automatically retries',async()=>{reset();post=async()=>{throw new Error('Reviewed request changed.');};const tree=await open();try{await review(tree);await act(async()=>submit(tree));assert.equal(find(tree,'handoff-review-post').length,0);assert.match(text(tree.root),/Reviewed request changed/);assert.equal(calls.length,2);await act(async()=>find(tree,'handoff-refresh')[0].props.onClick());assert.equal(calls.length,3);assert.equal(calls[2][0],'GET');assert.equal(find(tree,'handoff-confirm-form').length,0);}finally{act(()=>tree.unmount());}});
test('double confirmation submits once and keeps refresh disabled while pending',async()=>{reset();let finish;post=()=>new Promise(resolve=>{finish=resolve;});const tree=await open();try{await review(tree);let request;await act(async()=>{request=submit(tree);submit(tree);});assert.equal(calls.length,2);assert.equal(find(tree,'handoff-refresh')[0].props.disabled,true);await act(async()=>{finish(data({status:'posted'}));await request;});assert.match(text(tree.root),/Posted to ledger/);}finally{act(()=>tree.unmount());}});
test('response from a different handoff is not announced as posted',async()=>{reset();post=async()=>data({id:21,status:'posted'});const tree=await open();try{await review(tree);await act(async()=>submit(tree));assert.match(text(tree.root),/could not be verified/);assert.equal(find(tree,'handoff-review-post').length,0);}finally{act(()=>tree.unmount());}});
test('a late read cannot replace the newly selected week',async()=>{reset();let finish;get=path=>path.endsWith('42')?new Promise(resolve=>{finish=resolve;}):Promise.resolve(data({timesheet_id:43,status:'cancelled'}));const tree=await open();try{await act(async()=>tree.update(React.createElement(Handoff,props({timesheetId:43}))));await act(async()=>finish(data()));assert.match(text(tree.root),/Withdrawn after a time correction/);assert.equal(find(tree,'handoff-review-post').length,0);}finally{act(()=>tree.unmount());}});
