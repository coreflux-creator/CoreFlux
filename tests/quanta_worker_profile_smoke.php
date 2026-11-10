<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/worker_profile_contract.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed++;};
$fails=static function(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException $e){return true;}};
$person=integrationRecordReference('381e452a-5d11-4e2e-9daa-e9427501898a',1,'person','P-777');
$body=['person'=>$person,'email'=>'new.worker@example.com','full_name'=>'Synthetic Worker','phone'=>null,'worker_id'=>null,'expected_worker'=>null];
$id=quantaCoreRemoteId('a',$person);
$record=['id'=>$id,'tenant_id'=>'a','person'=>$person,'person_link_id'=>$id,'worker_id'=>'cfw_'.substr($id,4),
    'profile_contract_version'=>QUANTA_WORKER_PROFILE_VERSION,'access_changed'=>false,'invitation_sent'=>false,
    'worker'=>['email'=>$body['email'],'full_name'=>$body['full_name'],'phone'=>null,'status'=>'disabled','updated_at'=>'2026-10-22T00:00:00+00:00'],
    'delivery_id'=>'cfd_'.str_repeat('a',64)];
$transportCalls=0;
$GLOBALS['__quanta_core_write_transport']=static function($method,$url,$headers,$json)use(&$record,&$transportCalls){
    $transportCalls++;
    if($method!=='POST' || $url!==QUANTA_API_BASE.QUANTA_CORE_CONTRACT_PATH.'/worker-profiles')throw new RuntimeException('Unexpected writer route');
    return ['status'=>201,'body'=>json_encode($record)];
};
$check(quantaWriteWorkerProfile('fixture-key','a',$body,str_repeat('1',64))['worker']['status']==='disabled','Confirmed inactive creation');
foreach (['active'=>true,'roles'=>['admin'],'identity_id'=>'replace','external_id'=>'replace','employee_id'=>'replace','worksite_ids'=>['site']] as $field=>$value) {
    $check($fails(fn()=>quantaWriteWorkerProfile('fixture-key','a',$body+[$field=>$value],str_repeat('1',64))),$field.' excluded before IO');
}
$check($transportCalls===1,'Rejected field changes never call the provider');
$check($fails(fn()=>quantaWriteWorkerProfile("bad\r\nkey",'a',$body,str_repeat('1',64))),'Header injection refused');
$check($fails(fn()=>quantaWriteWorkerProfile('fixture-key','a',$body,'new-key')),'Persisted delivery identity required');
$check($fails(fn()=>quantaWorkerProfileBodyValidate(array_replace($body,['worker_id'=>'worker-1']))),'Existing worker requires observation');
$saved=$record;
foreach (['tenant_id'=>'b','worker_id'=>'someone-else','access_changed'=>true,'invitation_sent'=>true,'profile_contract_version'=>'old','delivery_id'=>'wrong'] as $field=>$value) {
    $record=array_replace($saved,[$field=>$value]);
    try{quantaWriteWorkerProfile('fixture-key','a',$body,str_repeat('1',64));$uncertain=false;}
    catch(QuantaContractWriteException $e){$uncertain=$e->uncertain;}
    $check($uncertain,'Unconfirmed '.$field.' requires reconciliation');
}
$record=$saved;$record['worker']['status']='active';
try{quantaWriteWorkerProfile('fixture-key','a',$body,str_repeat('1',64));$uncertain=false;}
catch(QuantaContractWriteException $e){$uncertain=$e->uncertain;}
$check($uncertain,'Unexpected activation cannot be accepted');
$GLOBALS['__quanta_core_write_transport']=static fn()=>['status'=>409,'body'=>'conflict'];
try{quantaWriteWorkerProfile('fixture-key','a',$body,str_repeat('1',64));$uncertain=false;}
catch(QuantaContractWriteException $e){$uncertain=$e->uncertain;}
$check($uncertain,'Conflicts reconcile instead of blindly resending');
echo json_encode(['passed'=>$passed,'scope'=>'Quanta worker-profile IO boundary']),"\n";
