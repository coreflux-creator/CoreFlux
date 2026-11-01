<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/workspace.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS {$label}\n";};
$fails=static function(callable $f):bool{try{$f();return false;}catch(Throwable $e){return true;}};
$instance='381e452a-5d11-4e2e-9daa-e9427501898a';
$oldEnv=getenv('COREFLUX_INTEGRATION_INSTANCE_ID');
putenv('COREFLUX_INTEGRATION_INSTANCE_ID');
$check(integrationInstanceId()===null,'missing namespace never silently allocates a new identity');
putenv('COREFLUX_INTEGRATION_INSTANCE_ID='.$instance);
$check(integrationInstanceId()===$instance,'configured installation namespace is stable');
putenv('COREFLUX_INTEGRATION_INSTANCE_ID=bad');
$check($fails(fn()=>integrationInstanceId()),'malformed namespace fails closed');
putenv('COREFLUX_INTEGRATION_INSTANCE_ID'.($oldEnv===false?'':'='.$oldEnv));
foreach(['person'=>'P-333','placement'=>'PL-810','company'=>'CO-1','client'=>'CL-2','vendor'=>'V-3'] as $kind=>$number){
    $r=integrationRecordReference($instance,7,$kind,$number);
    $check($r['directory_tenant_id']===7 && $r['number']===$number && $r['entity_type']===$kind,'canonical '.$kind.' preserves its directory owner and number');
}
$check(integrationRecordReference($instance,7,'person','P-1')!==integrationRecordReference($instance,8,'person','P-1'),'same display number in different directories has different identity');
foreach([['person','PL-1',7],['person','P-01',7],['person','P-0',7],['worker','P-1',7],['person','P-1',0],['person',"P-1\n",7]] as [$kind,$number,$owner]){
    $check($fails(fn()=>integrationRecordReference($instance,$owner,$kind,$number)),'invalid typed reference is rejected');
}
$context=['workspace'=>['id'=>'workspace-a','name'=>'Synthetic A'],'contract_version'=>QUANTA_CORE_CONTRACT_VERSION,
    'capabilities'=>['person_links','assignment_metadata','conditional_assignment_updates','durable_delivery_receipts'],'execution_side_effects'=>false];
$check(quantaContractContextValidate($context)===$context,'reviewed provider contract accepted');
$reordered=$context;$reordered['capabilities']=array_reverse([...$context['capabilities'],'unrelated_new_feature']);
$check(quantaContractContextValidate($reordered)===$context,'extra capability never expands reviewed authority');
foreach([['contract_version'=>'next'],['execution_side_effects'=>true],['execution_side_effects'=>'false'],['capabilities'=>['person_links']],['capabilities'=>[[]]],['workspace'=>['id'=>'../foreign','name'=>'A']],['workspace'=>['id'=>'a','name'=>"A\nB"]],['workspace'=>['id'=>'a','name'=>'']]] as $bad){
    $check($fails(fn()=>quantaContractContextValidate(array_replace($context,$bad))),'incomplete or unsafe remote context rejected');
}
$hash=quantaWorkspaceBindingHash(1,2,'fixture-key',$instance,$context);
$check($hash===quantaWorkspaceBindingHash(1,2,'fixture-key',$instance,$reordered),'binding ignores unrelated capability order');
foreach([[2,2,'fixture-key',$instance,$context],[1,3,'fixture-key',$instance,$context],[1,2,'rotated-key',$instance,$context],
    [1,2,'fixture-key','481e452a-5d11-4e2e-9daa-e9427501898a',$context],[1,2,'fixture-key',$instance,array_replace($context,['workspace'=>['id'=>'b','name'=>'Synthetic A']])]] as $inputs){
    $check($hash!==quantaWorkspaceBindingHash(...$inputs),'changed organization, installation or connection invalidates binding');
}
$check(quantaWorkspaceBindingMatches(['state'=>'verified','binding_hash'=>$hash],$hash),'reviewed binding matches exact context');
$check(!quantaWorkspaceBindingMatches(['state'=>'revoked','binding_hash'=>$hash],$hash),'revoked binding cannot authorize publication');
$check(!quantaWorkspaceBindingMatches(null,$hash),'missing binding cannot authorize publication');
$calls=[];
$GLOBALS['__quanta_transport']=static function($method,$url,$headers)use(&$calls,$context){$calls[]=[$method,$url];return ['status'=>200,'body'=>json_encode($context)];};
$check(quantaContractContext('fixture-key')===$context,'context uses real read client and strict response validation');
$check($calls===[['GET','https://helloquanta.app/api/v1/integrations/coreflux/context']],'context lookup stays on the pinned provider host');
foreach([401,403,404,500]as$status){
    $GLOBALS['__quanta_transport']=static fn()=>['status'=>$status,'body'=>'{"detail":"fixture-key private provider data"}'];
    try{quantaContractContext('fixture-key');$safe=false;}catch(QuantaApiException $e){$safe=$e->httpStatus===$status&&!str_contains($e->getMessage(),'private')&&!str_contains($e->getMessage(),'fixture-key');}
    $check($safe,'context error '.$status.' is actionable without exposing provider data');
}
unset($GLOBALS['__quanta_transport']);
echo "Quanta workspace: {$passed} passed\n";
