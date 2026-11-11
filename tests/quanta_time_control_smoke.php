<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/time_control_delivery.php';
$passed=0;$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
$fails=static function(callable $f):bool{try{$f();return false;}catch(Throwable $e){return true;}};
$instance='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$person=integrationRecordReference($instance,1,'person','P-19');$placement=integrationRecordReference($instance,1,'placement','PL-42');
$identity=['id'=>quantaCoreRemoteId('workspace-a',$placement),'tenant_id'=>'workspace-a','person'=>$person,'placement'=>$placement,
    'worker_id'=>'worker-1','person_link_id'=>quantaCoreRemoteId('workspace-a',$person),'engagement_type'=>'w2','start_date'=>'2026-10-01',
    'client'=>null,'vendor'=>null,'end_client'=>null];
$body=['contract_version'=>QUANTA_TIME_CONTROL_VERSION,'worker_id'=>'worker-1','worksite_id'=>'site-1','expected_version'=>0,
    'expected_context_hash'=>str_repeat('a',64),'assignments'=>[['id'=>$identity['id'],'version'=>1]],'confirm_core_control'=>true];
$context=['native_membership_id'=>'grant-1','assignments'=>[['identity'=>$identity,'version'=>1]]];
$expected=quantaTimeControlExpected('workspace-a',$body,$context);$payload=['body'=>$body,'expected'=>$expected];
$check($expected['version']===1&&$expected['assignments'][0]['identity']===$identity,'typed identities survive the reviewed policy');
foreach(['confirm_core_control'=>1,'expected_version'=>'0','worker_id'=>'../worker','expected_context_hash'=>'bad','assignments'=>[['id'=>$identity['id'],'version'=>'1']]] as $field=>$value)
    $check($fails(fn()=>quantaTimeControlBody(array_replace($body,[$field=>$value]))),'reject malformed '.$field);
$check($fails(fn()=>quantaTimeControlBody($body+['hours'=>8])),'hours cannot enter policy publication');
$check($fails(fn()=>quantaTimeControlBody(array_replace($body,['assignments'=>[$body['assignments'][0],$body['assignments'][0]]]))),'duplicate choices fail');
$check($fails(fn()=>quantaTimeControlExpected('foreign',$body,$context)),'foreign workspace fails');
$owners=['people'=>1,'placements'=>1,'companies'=>1,'ap'=>1];
$check(quantaTimeControlOwned($identity,'workspace-a','worker-1',$instance,$owners,$person)===$identity,'current Core directory owns the selection');
$check($fails(fn()=>quantaTimeControlOwned($identity,'workspace-a','worker-1',$instance,array_replace($owners,['placements'=>2]),$person)),'different directory cannot take control');
$check($fails(fn()=>quantaTimeControlOwned($identity,'workspace-a','worker-1',$instance,$owners,integrationRecordReference($instance,1,'person','P-20'))),'different person fails');
$check($fails(fn()=>quantaTimeControlExpectedValidate('workspace-a',$body,array_replace($expected,['version'=>8]))),'unreviewed acknowledgement fails');
$check($fails(fn()=>quantaTimeControlPlacementIds([42,42]))&&$fails(fn()=>quantaTimeControlPlacementIds(['42'])),'local selection is typed and distinct');
$rid='cfd_'.str_repeat('b',64);$response=$expected+['last_delivery_id'=>$rid,'delivery_id'=>$rid,'updated_at'=>'2026-11-11T12:00:00Z'];
$calls=[];$mode='ok';
$GLOBALS['__quanta_core_write_transport']=static function($method,$url,$headers,$json)use(&$calls,&$mode,$response):array{
    $calls[]=[$method,$url,$headers,$json];
    return match($mode){'lost'=>throw new RuntimeException('lost'),'mismatch'=>['status'=>201,'body'=>json_encode(array_replace($response,['last_delivery_id'=>'cfd_'.str_repeat('c',64)]))],
        'redirect'=>['status'=>302,'body'=>'{}'],default=>['status'=>201,'body'=>json_encode($response)]};
};
$key=str_repeat('d',64);$write=static fn()=>quantaWriteContract('fixture-key','workspace-a','time.access',$body,$expected,$key);
$check($write()===$response&&count($calls)===1&&str_ends_with($calls[0][1],'/time-access'),'only the policy endpoint receives the reviewed write');
$check(json_decode($calls[0][3],true)===$body,'publication contains no extra authority');
foreach(['lost','mismatch','redirect'] as $mode){try{$write();$check(false,'must reject');}catch(QuantaContractWriteException $e){$check($e->uncertain,$mode.' retains uncertain delivery');}}
$receipt=['id'=>$rid,'state'=>'complete','request_hash'=>quantaTimeControlHash($body),'status_code'=>201,'response'=>$response];
$check(quantaTimeControlReceiptOutcome($payload,$receipt)==='confirmed','original complete receipt confirms exact setup');
$check($fails(fn()=>quantaTimeControlReceiptOutcome($payload,array_replace($receipt,['request_hash'=>str_repeat('e',64)]))),'different original request cannot release hold');
$bad=$receipt;$bad['response']['assignments']=[];$check($fails(fn()=>quantaTimeControlReceiptOutcome($payload,$bad)),'different committed selection cannot release hold');
$check(quantaTimeControlReceiptOutcome($payload,array_replace($receipt,['state'=>'uncertain','response'=>null]))==='unconfirmed','uncertain receipt stays held');
$check(quantaTimeControlReceiptOutcome($payload,array_replace($receipt,['status_code'=>409,'response'=>['delivery_id'=>$rid]]))==='rejected','durable terminal conflict releases only as rejected');
$check($fails(fn()=>quantaTimeControlReceiptOutcome($payload,array_replace($receipt,['status_code'=>500]))),'server failure remains uncertain');
$GLOBALS['__quanta_core_write_transport']=static function($method,$url,$headers,$json)use(&$calls,$receipt):array{$calls[]=[$method,$url,$headers,$json];return ['status'=>200,'body'=>json_encode($receipt)];};
$check(quantaTimeControlRecoverRemote('fixture-key',$key)===$receipt&&str_ends_with(end($calls)[1],'/time-access-deliveries/'.$key.'/reconcile')&&end($calls)[3]==='{}','recovery targets only the original receipt with an empty body');
$check($fails(fn()=>quantaTimeControlRecoverRemote('fixture-key','../time-access')),'recovery cannot target another route');
unset($GLOBALS['__quanta_core_write_transport']);echo "$passed time-control contract checks passed\n";
