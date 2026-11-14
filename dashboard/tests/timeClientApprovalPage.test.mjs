import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../../time_approve.php',import.meta.url),'utf8');
const script=source.match(/<script>\s*([\s\S]*?)<\/script>/)[1];
function setup(action='approve',transport=async()=>({ok:true,json:async()=>({ok:true,response:action==='approve'?'approved':'rejected'})})){
  const calls=[],nodes=[],button={disabled:false};let focused=false;
  const form={dataset:{},action:'/modules/time/api/approval_tokens.php?action=respond',elements:{t:{value:'synthetic-link'},decision:{value:action},note:{value:'Reviewed correction',focus(){focused=true;}},review_revision:{value:'a'.repeat(64)}},querySelectorAll:()=>[button]};
  const card={replaceChildren(...items){nodes.splice(0,nodes.length,...items);},append(item){nodes.push(item);}};
  const context={location:{href:'https://fixture.invalid/review'},document:{querySelector:()=>card,createElement:tag=>({tag,dataset:{},textContent:''})},fetch:async(...args)=>{calls.push(args);return transport(...args);}};
  vm.createContext(context);vm.runInContext(script,context);
  return {calls,nodes,button,form,focused:()=>focused,submit:()=>context.cfSubmit({preventDefault(){},target:form})};
}
for(const action of ['approve','reject'])test(`client ${action} sends only the displayed review and reports matching success`,async()=>{
  const f=setup(action);await f.submit();const body=JSON.parse(f.calls[0][1].body);
  assert.equal(body.action,action);assert.equal(body.review_revision,'a'.repeat(64));assert.equal(body.t,'synthetic-link');
  assert.equal(f.nodes[0].dataset.testid,'time-approve-success');assert.equal(f.button.disabled,true);
});
test('client rejection requires a reason before sending',async()=>{const f=setup('reject');f.form.elements.note.value='  ';await f.submit();assert.equal(f.calls.length,0);assert.equal(f.focused(),true);});
test('a second click does not send a second pending client decision',async()=>{let release;const wait=new Promise(r=>{release=r;});const f=setup('approve',()=>wait);const first=f.submit();await f.submit();assert.equal(f.calls.length,1);release({ok:true,json:async()=>({ok:true,response:'approved'})});await first;});
test('server refusal is text and requires a new review',async()=>{const f=setup('approve',async()=>({ok:false,json:async()=>({error:'<img src=x onerror=bad()>Changed time'})}));await f.submit();assert.equal(f.nodes[0].textContent,'<img src=x onerror=bad()>Changed time');assert.equal(f.nodes[0].innerHTML,undefined);assert.equal(f.nodes[1].textContent,'Reload the time review');await f.submit();assert.equal(f.calls.length,1);});
test('a mismatched success result is never presented as confirmed',async()=>{const f=setup('approve',async()=>({ok:true,json:async()=>({ok:true,response:'rejected'})}));await f.submit();assert.equal(f.nodes[0].dataset.testid,'time-approve-error');assert.equal(f.nodes[1].textContent,'Reload the time review');});
test('an uncertain network result requires checking status without automatic retry',async()=>{const f=setup('approve',async()=>{throw Error('Disconnected');});await f.submit();await f.submit();assert.equal(f.calls.length,1);assert.equal(f.nodes[0].dataset.testid,'time-approve-error');assert.match(f.nodes[0].textContent,/check its status/);});
