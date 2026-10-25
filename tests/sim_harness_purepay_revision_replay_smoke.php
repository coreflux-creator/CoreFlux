<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/delivery.php';
require_once __DIR__.'/../deploy/verify_purepay_preview_replay.php';
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;};
$old=['contract_version'=>'2026-10-22.2','source_revision'=>str_repeat('a',64),'previous_revision'=>null,
    'placement'=>['number'=>'PL-13','directory_tenant_id'=>999],'person'=>['number'=>'P-15'],'client'=>['number'=>'CL-1'],
    'terms'=>[['pay_rates'=>['regular'=>'30.0000'],'bill_rates'=>['regular'=>'60.0000']]],
    'work_context'=>['worksite_state'=>'NJ','worksite_country'=>'US']];
$review=['state'=>'created','assignment_id'=>'same-native','assignment_status'=>'draft','accepted_revision'=>$old['source_revision']];
$check(purepayReplayAssignmentReview($review,$old,'same-native'),'original accepted packet remains verifiable');
$new=$old;$new['contract_version']='2026-10-25.1';$new['work_context']['worksite_city']=null;
unset($new['source_revision'],$new['previous_revision']);$new['source_revision']=integrationDeliveryHash($new);$new['previous_revision']=$old['source_revision'];
$successor=['packet'=>$new,'provider_review'=>array_replace($review,['accepted_revision'=>$new['source_revision']])];
$history=array_replace($review,['state'=>'superseded','accepted_revision'=>$new['source_revision']]);
$check(purepayReplayAssignmentReview($history,$old,'same-native',$successor),'original receipt can be historical through the exact accepted successor');
$check(!purepayReplayAssignmentReview($history,$old,'same-native'),'superseded claim without successor evidence is rejected');
foreach(['assignment_id'=>'another','assignment_status'=>'active','accepted_revision'=>str_repeat('b',64),'state'=>'conflict'] as $field=>$value)
    $check(!purepayReplayAssignmentReview(array_replace($history,[$field=>$value]),$old,'same-native',$successor),'changed historical '.$field.' rejected');
foreach(['assignment_id'=>'another','assignment_status'=>'active','accepted_revision'=>str_repeat('b',64),'state'=>'revision_ready'] as $field=>$value){
    $bad=$successor;$bad['provider_review'][$field]=$value;
    $check(!purepayReplayAssignmentReview($history,$old,'same-native',$bad),'unconfirmed successor '.$field.' rejected');
}
foreach(['rate','person','client','directory','city','previous','version'] as $case){
    $bad=$successor;
    if($case==='rate')$bad['packet']['terms'][0]['pay_rates']['regular']='99';
    if($case==='person')$bad['packet']['person']['number']='P-358';
    if($case==='client')$bad['packet']['client']['number']='CL-2';
    if($case==='directory')$bad['packet']['placement']['directory_tenant_id']=2;
    if($case==='city')$bad['packet']['work_context']['worksite_city']='Unreviewed';
    if($case==='previous')$bad['packet']['previous_revision']=str_repeat('b',64);
    if($case==='version')$bad['packet']['contract_version']='2026-10-22.1';
    $check(!purepayReplayAssignmentReview($history,$old,'same-native',$bad),'successor cannot alter '.$case);
}
$bad=$old;$bad['contract_version']='2026-10-22.1';
$check(!purepayReplayAssignmentReview($history,$bad,'same-native',$successor),'incomplete legacy contract cannot qualify as this successor');
echo "PurePay historical revision replay: {$checks} passed\n";
