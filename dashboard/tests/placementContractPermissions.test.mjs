import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require=createRequire(import.meta.url), React=require('react'), {create,act}=require('react-test-renderer');
const compiled=await build({entryPoints:[fileURLToPath(new URL('../../modules/placements/ui/PlacementDetail.jsx',import.meta.url))],bundle:true,platform:'node',format:'cjs',write:false,external:['react','lucide-react'],plugins:[{name:'contract-screen-fixture',setup(b){
  b.onResolve({filter:/lib\/api$/},()=>({path:'fixture-api',external:true}));
  b.onResolve({filter:/^react-router-dom$/},()=>({path:'fixture-router',external:true}));
  b.onResolve({filter:/\/(LinkedExternalSystemsPanel|SyncHistoryDrawer|EntityPicker|PlacementTimesheetsTab|CompanyTypeahead|BillToClientPicker)$/},()=>({path:'fixture-picker',external:true}));
}}]});
const placement={id:15,external_id:'jd:123',engagement_type:'c2c',start_date:'2026-10-01'};
const party={id:1,display_name:'Synthetic client',role:'client',source_type:'placement',source_managed:1,settlement_channel:'ar',money_flow:'receivable',fee_basis:'none',payment_terms:'NET30',cycle_cadence:'monthly'};
const text=n=>typeof n==='string'?n:(n.children||[]).map(text).join('');
const button=(f,label)=>f.tree.root.findAllByType('button').find(n=>text(n)===label);
const byId=(f,id)=>f.tree.root.findAllByProps({'data-testid':id});
async function fixture(permissions={},options={}){
  const f={calls:[],reads:[],reloads:0,parentReloads:0,context:{data:{placement,permissions,parties:[party],readiness:{ready:false}},loading:false,error:null}},module={exports:{}};
  f.corp={corp:{corp_legal_name:'Synthetic vendor',company_id:71,ap_vendor_id:82,corp_contact_phone:'555-0100',corp_ein_last4:'6789'},review_token:'a'.repeat(64)};
  f.corpReloads=0;
  const api={useApi(path){f.reads.push(path);return path?.includes('/economics.php')?{...f.context,reload:async()=>{f.reloads++;if(options.failReload)throw new Error('Reload unavailable');}}:{data:path?.includes('/corp.php')?f.corp:{users:[{id:2,name:'Synthetic recruiter'}]},reload:async()=>{throw new Error('Corporate review must use a confirmed response');}};},api:Object.fromEntries(['get','post','put','patch','delete'].map(method=>[method,async(url,body)=>{
    f.calls.push({method,url,body});
    if(method==='get'&&url.includes('/corp.php')){f.corpReloads++;if(options.failCorpReload)throw new Error('Corporate reload unavailable');return structuredClone(f.corp);}
    if(options.respond)return options.respond(method,url,body);
    if(method==='put'&&url.includes('/corp.php')){const {review_token,...fields}=body;return {ok:true,changed:true,corp:{...f.corp.corp,...fields},review_token:'b'.repeat(64)};}
    return {};
  }]))};
  new Function('require','module','exports',compiled.outputFiles[0].text)(id=>id==='fixture-api'?api:id==='fixture-picker'?()=>null:id==='fixture-router'?{Link:({children,to})=>React.createElement('a',{href:to},children)}:require(id),module,module.exports);
  const props={placement,chain:[{id:5,position:0,party_role:'end_client',party_name:'Synthetic client'}],rates:[{id:3,bill_rate:100,pay_rate:50,currency:'USD',effective_from:'2026-10-01'}],commissions:[{id:7,user_id:2,recipient_name:'Synthetic recruiter',role:'recruiter',basis:'flat',flat_amount:25}],referrals:[],reload:async()=>{f.parentReloads++;}};
  f.render=()=>React.createElement(module.exports.EconomicsTab,props);
  await act(async()=>{f.tree=create(f.render());});
  f.update=async()=>act(async()=>f.tree.update(f.render()));
  f.click=async label=>{const b=button(f,label);assert.ok(b,label);assert.equal(!!b.props.disabled,false);await act(async()=>b.props.onClick());};
  f.close=()=>act(()=>f.tree.unmount());return f;
}

test('contract readers retain evidence and recipient names without write or upload controls',async()=>{
  const f=await fixture();try{
    assert.ok(text(f.tree.root).includes('Synthetic recruiter'));
    assert.equal(f.tree.root.findAllByType('form').length,0);
    assert.equal(f.tree.root.findAllByProps({type:'file'}).length,0);
    assert.equal(button(f,'Refresh contract participants'),undefined);
    assert.ok(button(f,'Review JobDiva ownership'));
    assert.equal(f.tree.root.findByProps({'aria-label':'Payment terms for Synthetic client'}).props.disabled,true);
    assert.equal(f.tree.root.findByProps({'data-testid':'corp-corp-legal-name'}).props.readOnly,true);
    assert.ok(!f.reads.includes('/api/users.php'));assert.equal(f.calls.length,0);
  }finally{f.close();}
});

test('placement edit access does not grant rate, approval, commission or referral actions',async()=>{
  const f=await fixture({manage:true});try{
    assert.ok(button(f,'Refresh contract participants'));assert.ok(button(f,'Add participant'));
    assert.equal(f.tree.root.findAllByProps({type:'file'}).length,1);
    assert.equal(button(f,'Add one-time item'),undefined);
    assert.equal(byId(f,'commissions-add-form').length,0);assert.equal(byId(f,'referrals-add-form').length,0);
    assert.equal(f.tree.root.findAllByType('button').filter(b=>text(b).includes('Approve')).length,0);
    assert.equal(f.calls.length,0);
  }finally{f.close();}
});

test('financial, approval and recipient permissions are separate from general placement editing',async()=>{
  const f=await fixture({financials_manage:true,financials_approve:true,commissions_manage:true,referrals_manage:true,corp_manage:true});try{
    assert.ok(button(f,'Add one-time item'));assert.ok(f.tree.root.findAllByType('button').some(b=>text(b).includes('Approve')));
    assert.equal(byId(f,'commissions-add-form').length,1);assert.equal(byId(f,'referrals-add-form').length,1);
    assert.equal(f.tree.root.findByProps({'data-testid':'corp-corp-legal-name'}).props.readOnly,false);
    assert.equal(button(f,'Add participant'),undefined);assert.equal(button(f,'Refresh contract participants'),undefined);
    assert.equal(f.tree.root.findAllByProps({type:'file'}).length,0);assert.equal(f.calls.length,0);
  }finally{f.close();}
});

test('permission downgrades, missing permissions and a different placement remove write controls',async()=>{
  const f=await fixture({manage:true,financials_manage:true});try{
    assert.ok(button(f,'Refresh contract participants'));
    for(const update of [{permissions:{manage:false,financials_manage:false}},{permissions:undefined},{permissions:{manage:true,financials_manage:true},placement:{id:16}}]){
      f.context.data={...f.context.data,...update};await f.update();
      assert.equal(f.tree.root.findAllByType('form').length,0);assert.equal(button(f,'Refresh contract participants'),undefined);
    }
    assert.equal(f.calls.length,0);
  }finally{f.close();}
});

test('only an explicit editor refresh posts reconciliation and reloads both contract sources',async()=>{
  const f=await fixture({manage:true});try{
    assert.equal(f.calls.length,0);await f.click('Refresh contract participants');
    assert.deepEqual(f.calls,[{method:'post',url:'/modules/placements/api/economics.php?placement_id=15&action=reconcile',body:{}}]);
    assert.equal(f.reloads,1);assert.equal(f.parentReloads,1);assert.ok(text(f.tree.root).includes('Contract participants refreshed.'));
  }finally{f.close();}
});

test('a successful participant refresh is not reported as failed when reloading is unavailable',async()=>{
  const f=await fixture({manage:true},{failReload:true});try{
    await f.click('Refresh contract participants');assert.ok(text(f.tree.root).includes('Reload the Contract tab to see the saved result.'));
    assert.ok(!text(f.tree.root).includes('Refresh failed:'));assert.equal(f.calls.length,1);
  }finally{f.close();}
});

test('corporate save submits only edited values and the reviewed identity token',async()=>{
  const f=await fixture({corp_manage:true});try{
    await act(async()=>f.tree.root.findByProps({'data-testid':'corp-corp-contact-phone'}).props.onChange({target:{value:'555-0111'}}));
    await f.click('Save');
    assert.deepEqual(f.calls,[{method:'put',url:'/modules/placements/api/corp.php?placement_id=15',body:{corp_contact_phone:'555-0111',corp_legal_name:'Synthetic vendor',review_token:'a'.repeat(64)}}]);
    assert.equal(f.corpReloads,0);assert.ok(text(f.tree.root).includes('Corporate details saved.'));
    assert.equal(f.tree.root.findByProps({'data-testid':'corp-corp-contact-phone'}).props.value,'555-0111');
    assert.equal(f.tree.root.findByProps({'data-testid':'corp-corp-ein'}).props.value,'');
  }finally{f.close();}
});

test('an uncertain corporate save cannot be retried until a successful reload',async()=>{
  const options={respond:async()=>{throw new Error('Connection lost');}};
  const f=await fixture({corp_manage:true},options);try{
    await act(async()=>f.tree.root.findByProps({'data-testid':'corp-corp-city'}).props.onChange({target:{value:'Hamilton'}}));
    await f.click('Save');assert.equal(button(f,'Save').props.disabled,true);
    assert.equal(f.tree.root.findByProps({'data-testid':'corp-corp-city'}).props.value,'Hamilton');
    options.failCorpReload=true;await f.click('Reload corporate details');assert.equal(button(f,'Save').props.disabled,true);
    options.failCorpReload=false;await f.click('Reload corporate details');
    assert.equal(button(f,'Save').props.disabled,false);assert.equal(f.tree.root.findByProps({'data-testid':'corp-corp-city'}).props.value,'');
    assert.equal(f.calls.filter(c=>c.method==='put').length,1);
  }finally{f.close();}
});

test('the canonical save response updates the review without depending on another read',async()=>{
  const f=await fixture({corp_manage:true},{failCorpReload:true});try{
    await f.click('Save');assert.ok(text(f.tree.root).includes('Corporate details saved.'));
    assert.equal(button(f,'Save').props.disabled,false);assert.equal(byId(f,'corp-save-error').length,0);assert.equal(f.calls.length,1);
    await f.click('Save');assert.equal(f.calls[1].body.review_token,'b'.repeat(64));
  }finally{f.close();}
});

test('a rejected corporate field can be corrected without discarding the rest of the form',async()=>{
  const f=await fixture({corp_manage:true},{respond:async()=>{const error=new Error('Enter a valid corporate email.');error.status=422;throw error;}});
  try{
    await act(async()=>f.tree.root.findByProps({'data-testid':'corp-corp-contact-email'}).props.onChange({target:{value:'invalid'}}));
    await f.click('Save');assert.equal(button(f,'Save').props.disabled,false);
    assert.equal(f.tree.root.findByProps({'data-testid':'corp-corp-contact-email'}).props.value,'invalid');
  }finally{f.close();}
});

test('corporate save is unavailable without reviewed data or after a permission downgrade',async()=>{
  const f=await fixture({corp_manage:true});try{
    f.corp={corp:f.corp.corp};await f.update();assert.equal(button(f,'Save').props.disabled,true);
    f.context.data.permissions={};await f.update();assert.equal(button(f,'Save'),undefined);
    assert.equal(button(f,'Reload corporate details'),undefined);assert.equal(f.calls.length,0);
  }finally{f.close();}
});
