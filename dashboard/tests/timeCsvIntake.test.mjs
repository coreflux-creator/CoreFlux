import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require=createRequire(import.meta.url),React=require('react');
const {create,act}=require('react-test-renderer');
let pin,calls,post,readerDone;
globalThis.FileReader=class {readAsText(file){this.result=file.text;readerDone=this.onload();}};
const api={api:{post:(...args)=>{calls.push(args);return post(...args);}},getPinnedTenantId:()=>pin};
const bundled=await build({entryPoints:[fileURLToPath(new URL('../src/components/CsvImportPage.jsx',import.meta.url))],bundle:true,platform:'node',format:'cjs',write:false,external:['react'],plugins:[{name:'fixtures',setup(b){
 b.onResolve({filter:/lib\/api$/},()=>({path:'fixture-api',external:true}));
 b.onResolve({filter:/lib\/csvImportAudit$/},()=>({path:'fixture-audit',external:true}));
 b.onResolve({filter:/CsvImportRecoveryNotice$/},()=>({path:'fixture-notice',external:true}));
 b.onResolve({filter:/^react-router-dom$/},()=>({path:'fixture-router',external:true}));
}}]});
const module={exports:{}};new Function('require','module','exports',bundled.outputFiles[0].text)(id=>id==='fixture-api'?api:id==='fixture-audit'?{}:id==='fixture-notice'?()=>null:id==='fixture-router'?{Link:props=>React.createElement('a',props,props.children)}:require(id),module,module.exports);
const Import=module.exports.default;
const value=(revision='a'.repeat(64))=>({workspace_id:2002,review_revision:revision,row_count:1,error_count:0,rows:{2:{hours:8}},errors:{}});
const find=(tree,id)=>tree.root.findAllByProps({'data-testid':`csv-import-${id}`});
const text=node=>typeof node==='string'?node:(node.children||[]).map(text).join('');
function reset(){pin='2002';calls=[];post=async path=>path.includes('inspect')?{headers:['Hours'],auto_map:['hours'],fields:[{key:'hours',label:'Hours'}]}:path.includes('dry_run')?value():{imported_count:1,skipped_count:0,errors:{}};}
async function open(){let tree;await act(async()=>{tree=create(React.createElement(Import,{endpoint:'/modules/time/api/csv_import.php',entityLabel:'Time',previewColumns:[{key:'hours',label:'Hours'}],reviewRequired:true,defaultUpdateExisting:true,extraToggles:[{key:'external',label:'External approval evidence',commitParam:'already_approved=1'}]}));});await act(async()=>{find(tree,'file-input')[0].props.onChange({target:{files:[{name:'time.csv',text:'Hours\n8\n'}]}});await readerDone;});await act(async()=>find(tree,'dry-run')[0].props.onClick());return tree;}

test('CSV option changes are previewed and final confirmation carries that exact revision',async()=>{reset();const tree=await open();try{
 assert.match(calls[1][0],/action=dry_run&update_existing=1/);
 const defaultPost=post;post=async(path,...args)=>path.includes('dry_run')?{...value(),error_count:1,errors:{3:['Invalid source row']}}:defaultPost(path,...args);
 await act(async()=>find(tree,'toggle-external')[0].props.onChange({target:{checked:true}}));assert.match(calls[2][0],/already_approved=1/);
 await act(async()=>find(tree,'skip-invalid')[0].props.onChange({target:{checked:true}}));assert.match(calls[3][0],/skip_invalid=1/);assert.match(calls[3][0],/already_approved=1/);
 await act(async()=>find(tree,'commit')[0].props.onClick());const request=calls.find(([url])=>url.includes('action=commit'));assert.equal(request[1].review_revision,'a'.repeat(64));assert.match(request[0],/skip_invalid=1/);assert.match(request[0],/already_approved=1/);assert.match(text(tree.root),/Import complete/);
}finally{act(()=>tree.unmount());}});

test('failed re-preview removes the earlier confirmation',async()=>{reset();const tree=await open();try{
 post=async()=>{throw new Error('External reviewer details are missing');};await act(async()=>find(tree,'toggle-external')[0].props.onChange({target:{checked:true}}));assert.equal(find(tree,'commit').length,0);assert.match(text(tree.root),/External reviewer details are missing/);
}finally{act(()=>tree.unmount());}});

test('workspace changes cannot submit an earlier CSV review',async()=>{reset();const tree=await open();try{
 pin='999';await act(async()=>find(tree,'commit')[0].props.onClick());assert.equal(calls.filter(([url])=>url.includes('action=commit')).length,0);assert.equal(find(tree,'commit').length,0);assert.match(text(tree.root),/workspace changed/);
}finally{act(()=>tree.unmount());}});

test('a late preview cannot restore a review discarded by a mapping change',async()=>{reset();const tree=await open();try{
 let finish;post=()=>new Promise(resolve=>{finish=resolve;});let pending;await act(async()=>{pending=find(tree,'dry-run')[0].props.onClick();});
 const mapping=tree.root.findAllByType('select').find(node=>node.props.value==='hours');await act(async()=>mapping.props.onChange({target:{value:''}}));
 await act(async()=>{finish(value('b'.repeat(64)));await pending;});assert.equal(find(tree,'commit').length,0);
}finally{act(()=>tree.unmount());}});

test('a workspace change while preview is running discards its response',async()=>{reset();const tree=await open();try{
 let finish;post=()=>new Promise(resolve=>{finish=resolve;});let pending;await act(async()=>{pending=find(tree,'dry-run')[0].props.onClick();});pin='999';await act(async()=>{finish(value());await pending;});assert.equal(find(tree,'commit').length,0);assert.match(text(tree.root),/workspace changed/);
}finally{act(()=>tree.unmount());}});

let reservations=0;
const bulkBundle=await build({entryPoints:[fileURLToPath(new URL('../src/pages/CsvBulkImport.jsx',import.meta.url))],bundle:true,platform:'node',format:'cjs',write:false,external:['react'],plugins:[{name:'bulk-fixtures',setup(b){
 b.onResolve({filter:/lib\/api$/},()=>({path:'fixture-api',external:true}));
 b.onResolve({filter:/lib\/csvImportAudit$/},()=>({path:'fixture-audit',external:true}));
 b.onResolve({filter:/CsvImportRecoveryNotice$/},()=>({path:'fixture-notice',external:true}));
 b.onResolve({filter:/^react-router-dom$/},()=>({path:'fixture-router',external:true}));
 b.onResolve({filter:/^lucide-react$/},()=>({path:'fixture-icons',external:true}));
}}]});
const bulkModule={exports:{}};new Function('require','module','exports',bulkBundle.outputFiles[0].text)(id=>id==='fixture-api'?api:id==='fixture-audit'?{reserveCsvImportAudit:async()=>++reservations,finalizeCsvImportAudit:async()=>({id:reservations,sourceAttached:true})}:id==='fixture-notice'?()=>null:id==='fixture-icons'?{Download:()=>null}:id==='fixture-router'?{Link:props=>React.createElement('a',props,props.children)}:require(id),bulkModule,bulkModule.exports);
const bulkFind=(tree,id)=>tree.root.findAllByProps({'data-testid':`csv-bulk-${id}`});
async function openBulk(){let tree;reservations=0;await act(async()=>{tree=create(React.createElement(bulkModule.exports.default));});await act(async()=>bulkFind(tree,'file-input')[0].props.onChange({target:{files:[{name:'time.csv',text:'Placement ID,Work date,Hours\nPL-1,2026-10-05,8\n'}]}}));await act(async()=>bulkFind(tree,'validate')[0].props.onClick());return tree;}
test('bulk time intake carries the displayed revision and rechecks changed skip options',async()=>{reset();const tree=await openBulk();try{
 assert.equal(bulkFind(tree,'commit').length,1);await act(async()=>bulkFind(tree,'skip-invalid')[0].props.onChange({target:{checked:true}}));assert.equal(bulkFind(tree,'commit').length,0);
 await act(async()=>bulkFind(tree,'validate')[0].props.onClick());assert.match(calls.at(-1)[0],/skip_invalid=1/);await act(async()=>bulkFind(tree,'commit')[0].props.onClick());const commit=calls.find(([path])=>path.includes('action=commit'));assert.equal(commit[1].review_revision,'a'.repeat(64));assert.match(commit[0],/skip_invalid=1/);assert.equal(reservations,1);
}finally{act(()=>tree.unmount());}});
test('bulk time intake refuses a switched workspace before reserving an import',async()=>{reset();const tree=await openBulk();try{
 pin='999';await act(async()=>bulkFind(tree,'commit')[0].props.onClick());assert.equal(reservations,0);assert.equal(calls.filter(([path])=>path.includes('action=commit')).length,0);assert.match(text(tree.root),/current workspace/);
}finally{act(()=>tree.unmount());}});
