<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/publication.php';
require_once __DIR__.'/../core/quanta/worker_writer.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS {$label}\n";};
$p=['id'=>1,'person_number'=>'P-1','first_name'=>'Test','last_name'=>'Worker','email_primary'=>'Worker@example.test','phone_primary'=>'555-0101','status'=>'active'];
$w=['id'=>'worker-1','full_name'=>'Prior Name','email'=>'worker@example.test','phone'=>'555-0100','active'=>true,'external_id'=>'purepay-employee-9','employee_id'=>'legacy-4','roles'=>['worker','approver'],'worksite_ids'=>['site-1']];
$new=quantaWorkerPublicationPlan($p,null,[]);
$check($new['status']==='preview' && $new['payload']['active']===false,'new worker is an inactive onboarding proposal');
$check($new['payload']['email']==='worker@example.test','email normalized');
$check(!array_intersect(['external_id','employee_id','password','roles','worksite_ids','approver_user_id'],array_keys($new['payload'])),'new proposal does not claim provider identities or access');
$linked=quantaWorkerPublicationPlan($p,$w,[$w]);
$check($linked['operation']==='worker.update' && array_keys($linked['payload'])===['full_name','phone'],'linked worker proposes only name and phone');
$check(!array_key_exists('external_id',$linked['payload']) && $w['external_id']==='purepay-employee-9','existing PurePayroll identity preserved');
$check(quantaWorkerPublicationPlan($p,null,[$w])['status']==='blocked','email match requires reviewed link, never duplicate create');
$check(quantaWorkerPublicationPlan($p,$w,[$w,array_replace($w,['id'=>'worker-2'])])['status']==='blocked','ambiguous source emails blocked');
$check(quantaWorkerPublicationPlan($p,array_replace($w,['email'=>'different@example.test']),[])['status']==='blocked','linked identity email drift blocked');
$check(quantaWorkerPublicationPlan($p,array_replace($w,['active'=>false]),[])['status']==='blocked','inactive worker requires onboarding review');
$check(quantaWorkerPublicationPlan(array_replace($p,['status'=>'inactive']),null,[])['status']==='blocked','inactive Core person excluded');
$check(quantaWorkerPublicationPlan(array_replace($p,['email_primary'=>'bad']),null,[])['status']==='blocked','invalid email excluded');
$check(quantaWorkerPublicationPlan(array_replace($p,['first_name'=>str_repeat('x',121)]),null,[])['status']==='blocked','provider name limit enforced');
$noPhone=quantaWorkerPublicationPlan(array_replace($p,['phone_primary'=>'']),$w,[$w]);
$check(!array_key_exists('phone',$noPhone['payload']),'missing Core phone preserves self-service phone');
$same=quantaWorkerPublicationPlan($p,array_replace($w,['full_name'=>'Test Worker','phone'=>'555-0101']),[]);
$check($same['status']==='unchanged' && $same['payload']===[],'unchanged source creates no delivery');
$check(integrationDeliveryHash(['b'=>2,'a'=>1])===integrationDeliveryHash(['a'=>1,'b'=>2]),'hash ignores object key ordering');
$check(integrationDeliveryHash(['v'=>null])!==integrationDeliveryHash([]),'hash distinguishes null from missing');
$check(integrationDeliveryHash(['v'=>0])!==integrationDeliveryHash(['v'=>false]),'hash distinguishes zero from false');
$check(integrationDeliveryHash(['v'=>1])!==integrationDeliveryHash(['v'=>1.0]),'hash preserves numeric types');
$check(integrationDeliveryHash(['v'=>[1,2]])!==integrationDeliveryHash(['v'=>[2,1]]),'hash preserves list order');
$calls=[];$response=['id'=>'worker-new','email'=>'worker@example.test','full_name'=>'Test Worker','active'=>false];
$GLOBALS['__quanta_write_transport']=static function($method,$url,$headers,$body)use(&$calls,&$response){$calls[]=[$method,$url,$headers,$body];return ['status'=>201,'body'=>json_encode($response)];};
$idem=str_repeat('a',64);$fields=['email'=>'worker@example.test','full_name'=>'Test Worker','active'=>false];
$check(quantaWriteWorker('fixture-key',null,$fields,$idem)['id']==='worker-new','documented create response checked');
$check($calls[0][0]==='POST' && $calls[0][1]==='https://helloquanta.app/api/v1/workers','write pinned to documented provider host');
$check(in_array('Idempotency-Key: '.$idem,$calls[0][2],true),'durable idempotency key transmitted');
$fails=static function(callable $f):bool{try{$f();return false;}catch(Throwable $e){return true;}};
$before=count($calls);
foreach(['external_id','employee_id','password','roles','worksite_ids','approver_user_id']as$protected){
    $check($fails(fn()=>quantaWriteWorker('fixture-key','worker-existing',[$protected=>'override'],$idem)), $protected.' cannot be overwritten');
}
$check($fails(fn()=>quantaWriteWorker("bad\r\nkey",null,$fields,$idem)),'header injection blocked');
$check($fails(fn()=>quantaWriteWorker('fixture-key','../evil',['full_name'=>'Test'],$idem)),'worker path traversal blocked');
$check($fails(fn()=>quantaWriteWorker('fixture-key',null,array_replace($fields,['active'=>true]),$idem)),'creation cannot activate access');
$check(count($calls)===$before,'invalid requests never reach provider');
$GLOBALS['__quanta_write_transport']=static fn()=>['status'=>200,'body'=>'{"id":"worker-existing","full_name":"Test Worker"}'];
$check(quantaWriteWorker('fixture-key','worker-existing',['full_name'=>'Test Worker'],$idem)['id']==='worker-existing','documented update response checked');
$response=['id'=>'wrong-worker','full_name'=>'Test Worker'];
$GLOBALS['__quanta_write_transport']=static fn()=>['status'=>200,'body'=>json_encode($response)];
try{quantaWriteWorker('fixture-key','worker-existing',['full_name'=>'Test Worker'],$idem);$uncertain=false;}catch(QuantaWorkerWriteException $e){$uncertain=$e->uncertain;}
$check($uncertain,'wrong remote identity is uncertain, never accepted');
foreach([0,202,302,409,429,500]as$status){
    $GLOBALS['__quanta_write_transport']=static fn()=>['status'=>$status,'body'=>'{"detail":"fixture-key private payload"}'];
    try{quantaWriteWorker('fixture-key',null,$fields,$idem);$safe=false;}catch(QuantaWorkerWriteException $e){$safe=$e->uncertain && !str_contains($e->getMessage(),'fixture-key');}
    $check($safe,'HTTP '.$status.' held for reconciliation without leaking provider body');
}
foreach(['not-json','{"id":"worker-new","email":"worker@example.test","full_name":"Different Name","active":false}','{"id":"worker-new","email":"worker@example.test","full_name":"Test Worker","active":true}']as$body){
    $GLOBALS['__quanta_write_transport']=static fn()=>['status'=>201,'body'=>$body];
    try{quantaWriteWorker('fixture-key',null,$fields,$idem);$safe=false;}catch(QuantaWorkerWriteException $e){$safe=$e->uncertain;}
    $check($safe,'malformed or mismatched success stays uncertain');
}
$GLOBALS['__quanta_write_transport']=static function(){throw new RuntimeException('private transport details');};
try{quantaWriteWorker('fixture-key',null,$fields,$idem);$safe=false;}catch(QuantaWorkerWriteException $e){$safe=$e->uncertain && !str_contains($e->getMessage(),'private');}
$check($safe,'transport exception stays uncertain and sanitized');
$GLOBALS['__quanta_write_transport']=static fn()=>['status'=>422,'body'=>'{"detail":"bad field"}'];
try{quantaWriteWorker('fixture-key',null,$fields,$idem);$rejected=false;}catch(QuantaWorkerWriteException $e){$rejected=!$e->uncertain;}
$check($rejected,'validation rejection distinguished from uncertain outcome');
unset($GLOBALS['__quanta_write_transport']);
echo "Quanta publication: {$passed} passed\n";
