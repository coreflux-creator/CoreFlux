<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/core_contract.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS {$label}\n";};
$fails=static function(callable $f):bool{try{$f();return false;}catch(Throwable $e){return true;}};
$instance='381e452a-5d11-4e2e-9daa-e9427501898a';
$ref=static fn($type,$number,$owner=1)=>integrationRecordReference($instance,$owner,$type,$number);
$person=$ref('person','P-333'); $placement=$ref('placement','PL-810');
$personId=quantaCoreRemoteId('a',$person);
$check($personId==='cfl_71c672ffc3411d0705bc4e7e294464c77e16eb5d81504f9129a589648d248362','canonical identity agrees with Quanta Python reference_id');
$check($personId!==quantaCoreRemoteId('b',$person),'remote organizations have separate identities');
$check($personId!==quantaCoreRemoteId('a',$ref('person','P-333',2)),'shared numbers in different Core directories remain separate');
$check($fails(fn()=>quantaCoreReferenceValidate($person+['email'=>'private@example.test'],'person')),'reference cannot carry extra personal data');
$desired=['placement'=>$placement,'person'=>$person,'client'=>$ref('client','CL-11'),'vendor'=>$ref('vendor','V-12'),
    'end_client'=>$ref('company','CO-13'),'worksite_id'=>'site-1','title'=>'Synthetic assignment','engagement_type'=>'c2c',
    'start_date'=>'2026-10-01','end_date'=>null,'status'=>'draft'];
$plan=quantaAssignmentPlan('a',$desired,null);
$check($plan['operation']==='assignment.create' && $plan['body']===$desired,'new assignment carries typed relationships and exact dates');
foreach (['external_id','employee_id','pay_rate','bill_rate','roles','password','approver_user_id'] as $field) {
    $check($fails(fn()=>quantaAssignmentPayloadValidate($desired+[$field=>'not-allowed'])),$field.' cannot enter metadata exchange');
}
foreach ([['status'=>'on_hold'],['status'=>'ended'],['engagement_type'=>'internal'],['start_date'=>'2026-02-30'],['end_date'=>'2026-09-01'],['title'=>"bad\nname"],['worksite_id'=>'../foreign']] as $bad) {
    $check($fails(fn()=>quantaAssignmentPlan('a',array_replace($desired,$bad),null)),'invalid dates, lifecycle or identity fail before IO');
}
$otherInstance=$person;$otherInstance['instance_id']='481e452a-5d11-4e2e-9daa-e9427501898a';
$check($fails(fn()=>quantaAssignmentPlan('a',array_replace($desired,['person'=>$otherInstance]),null)),'mixed installations are rejected');
$remote=$desired+['id'=>$plan['remote_id'],'tenant_id'=>'a','version'=>1];
$check(quantaAssignmentPlan('a',$desired,$remote)['status']==='unchanged','unchanged assignment produces no write');
$changed=array_replace($desired,['title'=>'Reviewed title','status'=>'active']);
$update=quantaAssignmentPlan('a',$changed,$remote);
$check($update['body']===['expected_version'=>1,'title'=>'Reviewed title','status'=>'active'],'update transmits only conditional reviewed fields');
foreach (['client'=>null,'vendor'=>null,'worksite_id'=>null,'person'=>$ref('person','P-334'),'start_date'=>'2026-10-02'] as $field=>$value) {
    $check($fails(fn()=>quantaAssignmentPlan('a',array_replace($desired,[$field=>$value]),$remote)),'immutable '.$field.' cannot be silently replaced');
}
$check($fails(fn()=>quantaAssignmentPlan('a',$changed,array_replace($remote,['tenant_id'=>'b']))),'wrong provider organization rejected');
$check($fails(fn()=>quantaAssignmentPlan('a',$changed,array_replace($remote,['version'=>'1']))),'non-integer remote version rejected');
$check($fails(fn()=>quantaAssignmentPlan('a',$desired,array_replace($remote,['status'=>'ended','end_date'=>'2026-10-10']))),'closed assignment cannot reopen');
$active=array_replace($remote,['status'=>'active']);
$ended=quantaAssignmentPlan('a',array_replace($desired,['status'=>'ended','end_date'=>'2026-10-10']),$active);
$check($ended['body']['status']==='ended' && $ended['body']['expected_version']===1,'end transition requires a dated conditional update');
$check($fails(fn()=>quantaAssignmentPlan('a',array_replace($desired,['status'=>'ended']),$active)),'ending without a date rejected');
$calls=[];$response=[];$http=201;
$GLOBALS['__quanta_core_write_transport']=static function($method,$url,$headers,$body)use(&$calls,&$response,&$http){$calls[]=[$method,$url,$headers,json_decode($body,true)];return ['status'=>$http,'body'=>json_encode($response)];};
$idem=str_repeat('a',64);$receipt='cfd_'.str_repeat('b',64);
$link=['person'=>$person,'worker_id'=>'worker-1'];
$response=$link+['id'=>$personId,'tenant_id'=>'a','delivery_id'=>$receipt];
$check(quantaWriteContract('fixture-key','a','person.link',$link,$link,$idem)['id']===$personId,'confirmed person link accepted');
$check($calls[0][1]==='https://helloquanta.app/api/v1/integrations/coreflux/person-links' && $calls[0][0]==='POST','identity write uses pinned namespaced route');
$check(in_array('Idempotency-Key: '.$idem,$calls[0][2],true),'persisted idempotency key reaches provider');
$http=200;$check(quantaWriteContract('fixture-key','a','person.link',$link,$link,$idem)['id']===$personId,'existing identical link is accepted');
$http=201;$response=$remote+['delivery_id'=>$receipt];
$check(quantaWriteContract('fixture-key','a','assignment.create',$desired,$desired,$idem)['version']===1,'assignment create requires version one');
$http=200;$response=array_replace($remote,$changed,['version'=>2,'delivery_id'=>$receipt]);
$check(quantaWriteContract('fixture-key','a','assignment.update',$update['body'],$update['expected'],$idem)['version']===2,'conditional update response verifies full assignment');
$last=$calls[count($calls)-1];$check($last[0]==='PATCH' && str_ends_with($last[1],'/assignments/'.$plan['remote_id']),'update route cannot target another assignment');
$before=count($calls);
foreach (['payroll.execute','worker.create','assignment.delete'] as $operation) $check($fails(fn()=>quantaWriteContract('fixture-key','a',$operation,$desired,$desired,$idem)),'unimplemented operation cannot write');
$check($fails(fn()=>quantaWriteContract("bad\nkey",'a','person.link',$link,$link,$idem)),'header injection rejected');
$check($fails(fn()=>quantaWriteContract('fixture-key','a','person.link',$link+['roles'=>['admin']],$link,$idem)),'link cannot change access');
$check($fails(fn()=>quantaWriteContract('fixture-key','a','assignment.update',$update['body']+['client'=>null],$update['expected'],$idem)),'patch cannot replace financial party identity');
$check(count($calls)===$before,'all invalid requests blocked before provider IO');
foreach ([['tenant_id'=>'b'],['version'=>1],['delivery_id'=>'missing'],['person'=>$ref('person','P-334')],['title'=>'Unexpected title']] as $bad) {
    $response=array_replace($remote,$changed,['version'=>2,'delivery_id'=>$receipt],$bad);
    try{quantaWriteContract('fixture-key','a','assignment.update',$update['body'],$update['expected'],$idem);$unknown=false;}
    catch(QuantaContractWriteException $e){$unknown=$e->uncertain;}
    $check($unknown,'unconfirmed success remains uncertain');
}
foreach ([0,302,409,429,500,503] as $http) {
    try{quantaWriteContract('fixture-key','a','person.link',$link,$link,$idem);$unknown=false;}
    catch(QuantaContractWriteException $e){$unknown=$e->uncertain;}
    $check($unknown,'ambiguous HTTP '.$http.' cannot authorize retry');
}
foreach ([400,401,403,404,422] as $http) {
    try{quantaWriteContract('fixture-key','a','person.link',$link,$link,$idem);$rejected=false;}
    catch(QuantaContractWriteException $e){$rejected=!$e->uncertain;}
    $check($rejected,'definite HTTP '.$http.' rejection recorded without private response');
}
unset($GLOBALS['__quanta_core_write_transport']);
echo "Quanta shared contract: {$passed} passed\n";
