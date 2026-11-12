import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require=createRequire(import.meta.url),React=require('react'),{create,act}=require('react-test-renderer');
const compiled=await build({entryPoints:[fileURLToPath(new URL('../../modules/placements/ui/JobDivaReconciliation.jsx',import.meta.url))],bundle:true,platform:'node',format:'cjs',write:false,external:['react','lucide-react'],plugins:[{name:'isolated-review',setup(b){
  b.onResolve({filter:/lib\/api$/},()=>({path:'fixture-api',external:true}));
  b.onResolve({filter:/^react-router-dom$/},()=>({path:'fixture-router',external:true}));
}}]});
const retained={group:'Settlement',field:'client_payment_terms',label:'Client payment terms',current:'NET10',proposed:'NET60',changes:true,core_owned:true,handling:'retain_core'};
const row={start_id:'1001',placement_id:15,outcome:'update',selectable:true,errors:[],warnings:[],source:{candidate_id:'2001',title:'Synthetic review'},contract:{fields:[retained,
  {group:'Placement',field:'worksite_city',label:'City',current:'Newark',proposed:'Hamilton',changes:true,core_owned:false,handling:'source_proposal'},
  {group:'Settlement',field:'paid_when_paid',label:'Paid when paid',current:false,proposed:false,changes:false,core_owned:true,handling:'core_unchanged'}],ownership_conflicts:[retained]}};
const preview={permissions:{apply:true},dry_run_token:'review-token',rows:[row],summary:{assignments:1,update:1,retained_core_fields:1},pagination:{page:1,per_page:20,total:1}};
const applied={projected:1,updated:1,field_map_writes:0,retained_core_fields:1,rows:[{start_id:'1001',placement_id:15,ownership_conflicts:[retained]}]};
const text=n=>typeof n==='string'?n:(n.children||[]).map(text).join('');
const button=(f,label)=>f.tree.root.findAllByType('button').find(n=>text(n)===label);
async function fixture(respond=async action=>action==='stored_preview'?structuredClone(preview):structuredClone(applied)){
  const f={calls:[],confirmations:[],confirm:true},module={exports:{}};
  global.window={confirm(message){f.confirmations.push(message);return f.confirm;}};
  const api={api:{post(url,body){const action=url.split('action=')[1];f.calls.push({action,body});return respond(action);}}};
  new Function('require','module','exports',compiled.outputFiles[0].text)(id=>id==='fixture-api'?api:id==='fixture-router'?{Link:({children,to})=>React.createElement('a',{href:to},children)}:require(id),module,module.exports);
  await act(async()=>{f.tree=create(React.createElement(module.exports.default));});
  f.click=async label=>{const b=button(f,label);assert.ok(b,label);assert.equal(!!b.props.disabled,false);await act(async()=>b.props.onClick());};
  f.select=async()=>act(async()=>f.tree.root.findByProps({'aria-label':'Select stored Start ID 1001'}).props.onChange());
  f.close=()=>{act(()=>f.tree.unmount());delete global.window;};return f;
}
test('review shows retained and source-owned values, preserving false values',async()=>{
  const f=await fixture();try{await f.click('Verified');const t=text(f.tree.root);
    assert.ok(t.includes('Retain Core value'));assert.ok(t.includes('NET10'));assert.ok(t.includes('NET60'));
    assert.ok(t.includes('Source proposal'));assert.ok(t.includes('Core-managed · unchanged'));
    const fields=f.tree.root.findByProps({'data-testid':'jobdiva-contract-fields-1001'});
    assert.ok(text(fields).includes('NoNo'));assert.equal(f.calls.filter(c=>c.action==='stored_apply').length,0);
  }finally{f.close();}
});
test('confirmation counts only selected retained values and result exposes retained details',async()=>{
  const f=await fixture();try{await f.select();await f.click('Project selected assignments');
    assert.ok(f.confirmations[0].includes('For 1 field, Core values will be retained where JobDiva differs.'));
    assert.deepEqual(f.calls.find(c=>c.action==='stored_apply').body,{dry_run_token:'review-token',selected_start_ids:['1001'],page:1,per_page:20,confirm:'APPLY_STORED_JOBDIVA_ASSIGNMENTS'});
    assert.ok(text(f.tree.root).includes('1 Core value retained where JobDiva differed.'));
    assert.ok(text(f.tree.root.findByProps({'data-testid':'jobdiva-retained-result-1001'})).includes('NET10NET60'));
  }finally{f.close();}
});
test('cancelling review sends no apply request',async()=>{
  const f=await fixture();try{f.confirm=false;await f.select();await f.click('Project selected assignments');assert.equal(f.calls.filter(c=>c.action==='stored_apply').length,0);}finally{f.close();}
});
test('a stale or uncertain result requires a successful new preview before another apply',async()=>{
  let failPreview=false;const f=await fixture(async action=>{
    if(action==='stored_apply')throw new Error('Records changed after preview.');
    if(failPreview)throw new Error('Preview unavailable');return structuredClone(preview);
  });
  try{await f.select();await f.click('Project selected assignments');assert.ok(text(f.tree.root).includes('Refresh the source preview before applying again.'));
    assert.ok(!text(f.tree.root).includes('Canonical projection complete.'));await f.select();assert.equal(button(f,'Project selected assignments').props.disabled,true);
    failPreview=true;await f.click('Refresh source preview');assert.equal(button(f,'Project selected assignments').props.disabled,true);
    failPreview=false;await f.click('Refresh source preview');await f.select();assert.equal(button(f,'Project selected assignments').props.disabled,false);
    assert.equal(f.calls.filter(c=>c.action==='stored_apply').length,1);
  }finally{f.close();}
});
test('a failed refresh after a successful apply does not allow a blind replay',async()=>{
  let completed=false;const f=await fixture(async action=>{
    if(action==='stored_apply'){completed=true;return structuredClone(applied);}
    if(completed)throw new Error('Preview unavailable');return structuredClone(preview);
  });
  try{await f.select();await f.click('Project selected assignments');assert.ok(text(f.tree.root).includes('Canonical projection complete.'));
    assert.equal(button(f,'Project selected assignments').props.disabled,true);assert.equal(f.calls.filter(c=>c.action==='stored_apply').length,1);
  }finally{f.close();}
});

test('preview remains readable without apply permission and defaults to no writes',async()=>{
  for(const permissions of [{apply:false},undefined]){
    const f=await fixture(async()=>({...structuredClone(preview),permissions}));
    try{await f.click('Verified');assert.ok(text(f.tree.root).includes('NET10'));
      assert.equal(f.tree.root.findByProps({'aria-label':'Select stored Start ID 1001'}).props.disabled,true);
      await f.select();assert.equal(button(f,'Project selected assignments').props.disabled,true);
      assert.equal(f.calls.filter(c=>c.action==='stored_apply').length,0);
    }finally{f.close();}
  }
});

test('a refreshed permission downgrade clears previously selected source writes',async()=>{
  let allowed=true;const f=await fixture(async()=>({...structuredClone(preview),permissions:{apply:allowed}}));
  try{await f.select();assert.equal(button(f,'Project selected assignments').props.disabled,false);allowed=false;
    await f.click('Refresh source preview');assert.equal(button(f,'Project selected assignments').props.disabled,true);
    const checkbox=f.tree.root.findByProps({'aria-label':'Select stored Start ID 1001'});assert.equal(checkbox.props.checked,false);assert.equal(checkbox.props.disabled,true);
  }finally{f.close();}
});

const csvPreview={...preview,rows:[{...row,row_number:1,person:{label:'Synthetic worker'},changes:[]}]};
const csvInspect={headers:['Start ID'],auto_map:{0:'start_id'},fields:[{key:'start_id',label:'Start ID'}],sample_rows:[['1001']],row_count:1};
async function loadCsv(f){
  await act(async()=>f.tree.root.findByProps({'data-testid':'jobdiva-reconciliation-file'}).props.onChange({target:{files:[{name:'synthetic.csv',text:async()=> 'Start ID\n1001\n'}]}}));
  await f.click('Run dry-run');
}
test('CSV review without apply permission permits inspection but disables selection',async()=>{
  const f=await fixture(async action=>action==='stored_preview'?preview:action==='inspect'?csvInspect:{...csvPreview,permissions:{apply:false}});
  try{await loadCsv(f);const checkbox=f.tree.root.findByProps({'aria-label':'Select Start ID 1001'});
    assert.equal(checkbox.props.disabled,true);await act(async()=>checkbox.props.onChange());
    assert.equal(button(f,'Apply selected changes').props.disabled,true);assert.equal(f.calls.filter(c=>c.action==='apply').length,0);
  }finally{f.close();}
});
test('failed CSV preview refresh cannot reuse a prior authorized preview',async()=>{
  let fail=false;const f=await fixture(async action=>{
    if(action==='stored_preview')return preview;if(action==='inspect')return csvInspect;
    if(fail)throw new Error('Preview unavailable');return csvPreview;
  });
  try{await loadCsv(f);await act(async()=>f.tree.root.findByProps({'aria-label':'Select Start ID 1001'}).props.onChange());
    assert.equal(button(f,'Apply selected changes').props.disabled,false);fail=true;await f.click('Run dry-run');
    assert.equal(button(f,'Apply selected changes'),undefined);assert.equal(f.calls.filter(c=>c.action==='apply').length,0);
  }finally{f.close();}
});
test('uncertain CSV submission consumes its review and requires a fresh dry-run',async()=>{
  const f=await fixture(async action=>{
    if(action==='stored_preview')return preview;if(action==='inspect')return csvInspect;
    if(action==='apply')throw new Error('Response lost');return csvPreview;
  });
  try{await loadCsv(f);await act(async()=>f.tree.root.findByProps({'aria-label':'Select Start ID 1001'}).props.onChange());await f.click('Apply selected changes');
    assert.equal(button(f,'Apply selected changes'),undefined);assert.ok(text(f.tree.root).includes('Run a fresh dry-run before applying again.'));
    assert.ok(!text(f.tree.root).includes('No changes were made.'));assert.equal(f.calls.filter(c=>c.action==='apply').length,1);
  }finally{f.close();}
});
