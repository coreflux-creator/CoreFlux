<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/metadata_delivery.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS {$label}\n";};
$fails=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}};
$instance='381e452a-5d11-4e2e-9daa-e9427501898a';
$ref=static fn($kind,$number)=>integrationRecordReference($instance,11,$kind,$number);
$person=$ref('person','P-15');$placement=$ref('placement','PL-13');$workspace='demo-workspace';
$body=['expected_version'=>3,'title'=>'Montréal / Support 🧪','work_location'=>['city'=>'Montréal','state'=>'QC','country'=>'CA']];
$check(quantaMetadataRequestHash($body)==='c5b775ed0e7aba07cb063589e930b7173fadc6e93f714ec0c6369d17fb02a45c','receipt hash agrees with Python sorted ASCII JSON for Unicode, emoji and slashes');
$assignment=['placement'=>$placement,'person'=>$person,'client'=>null,'vendor'=>null,'end_client'=>null,
    'worksite_id'=>null,'title'=>$body['title'],'engagement_type'=>'w2','start_date'=>'2026-10-01','end_date'=>null,
    'status'=>'active','work_location'=>$body['work_location']];
$receiptId='cfd_'.str_repeat('a',64);
foreach(['person.link','assignment.create','assignment.update'] as $operation){
    $row=['operation'=>$operation];$kind=$operation==='person.link'?'person':'placement';
    $expected=$kind==='person'?['person'=>$person,'worker_id'=>'worker-1']:$assignment;
    if($operation==='assignment.update')$expected['version']=4;
    $payload=['body'=>$operation==='assignment.update'?$body:$expected,'expected'=>$expected];
    $remote=quantaCoreRemoteId($workspace,$expected[$kind]);
    $path='/api/v1/integrations/coreflux/'.($kind==='person'?'person-links':'assignments');
    if($operation==='assignment.update')$path.='/'.$remote;
    $response=$expected+['id'=>$remote,'tenant_id'=>$workspace,'delivery_id'=>$receiptId];
    if($operation==='assignment.create')$response['version']=1;
    $receipt=['id'=>$receiptId,'request_path'=>$path,'request_hash'=>quantaMetadataRequestHash($payload['body']),
        'state'=>'complete','status_code'=>$operation==='assignment.update'?200:201,'response'=>$response];
    $check(quantaMetadataReceiptOutcome($row,$payload,$workspace,$receipt)['state']==='confirmed',$operation.' verifies the original typed response');
    foreach(['tenant_id'=>'other','id'=>'foreign','delivery_id'=>'different',$kind=>$ref($kind,$kind==='person'?'P-16':'PL-14')] as $field=>$value){
        $bad=$receipt;$bad['response'][$field]=$value;
        $check($fails(fn()=>quantaMetadataReceiptOutcome($row,$payload,$workspace,$bad)),$operation.' rejects mismatched '.$field);
    }
    foreach(['request_path'=>$path.'/foreign','request_hash'=>str_repeat('f',64),'id'=>'cfd_invalid','state'=>'unknown'] as $field=>$value){
        $check($fails(fn()=>quantaMetadataReceiptOutcome($row,$payload,$workspace,array_replace($receipt,[$field=>$value]))),$operation.' rejects invalid '.$field);
    }
    foreach(['in_progress','uncertain'] as $state){
        $check(quantaMetadataReceiptOutcome($row,$payload,$workspace,array_replace($receipt,['state'=>$state]))['state']==='unconfirmed',$operation.' holds '.$state);
    }
    $rejected=array_replace($receipt,['status_code'=>409,'response'=>['delivery_id'=>$receiptId,'detail'=>['error'=>'version_conflict']]]);
    $check(quantaMetadataReceiptOutcome($row,$payload,$workspace,$rejected)['state']==='rejected',$operation.' records an original deterministic rejection');
    $check($fails(fn()=>quantaMetadataReceiptOutcome($row,$payload,$workspace,array_replace($rejected,['status_code'=>503]))),$operation.' cannot release an unknown server outcome');
    if($kind==='placement'){
        $bad=$receipt;$bad['response']['version']++;
        $check($fails(fn()=>quantaMetadataReceiptOutcome($row,$payload,$workspace,$bad)),$operation.' rejects a later record version in place of the original response');
    }
}
echo "Quanta metadata receipt contract: {$passed} passed\n";
