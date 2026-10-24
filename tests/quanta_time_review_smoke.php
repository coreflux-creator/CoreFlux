<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/time_review_delivery.php';
$passed=0;$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
$fails=static function(callable $f):bool{try{$f();return false;}catch(Throwable $e){return true;}};
$body=['contract_version'=>QUANTA_TIME_REVIEW_VERSION,'assignment_id'=>'cfa_'.str_repeat('a',64),'assignment_version'=>1,
    'source_entry_id'=>'entry-1','source_revision'=>str_repeat('b',64),'core_tenant_id'=>1,'core_revision'=>str_repeat('c',64),
    'expected_version'=>0,'components'=>[['entry_id'=>10,'component'=>'regular','status'=>'approved']]];
$expected=quantaTimeReviewExpected('workspace-a',$body);
$check($expected['state']==='accepted' && $expected['version']===1,'only fully approved components report accepted');
$mixed=$body;$mixed['components'][]=['entry_id'=>11,'component'=>'overtime','status'=>'pending_review'];
$check(quantaTimeReviewExpected('workspace-a',$mixed)['state']==='pending_review','any pending component keeps Core review pending');
$mixed['components'][1]['status']='rejected';
$check(quantaTimeReviewExpected('workspace-a',$mixed)['state']==='needs_correction','a rejected component reports needs correction');
$check($expected['id']!==quantaTimeReviewExpected('workspace-b',$body)['id'],'different provider workspaces cannot share review identities');
foreach(['expected_version'=>-1,'core_tenant_id'=>true,'assignment_version'=>'1','source_revision'=>'bad','source_entry_id'=>'../entry','assignment_id'=>'foreign'] as $field=>$value)
    $check($fails(fn()=>quantaTimeReviewBody(array_replace($body,[$field=>$value]))),'invalid '.$field.' rejected before delivery');
foreach(['rates','approved_by','notes','payroll','roles'] as $field)
    $check($fails(fn()=>quantaTimeReviewBody($body+[$field=>'private'])),'private or executable '.$field.' excluded');
$duplicate=$body;$duplicate['components'][]=$duplicate['components'][0];
$check($fails(fn()=>quantaTimeReviewBody($duplicate)),'repeated components rejected');
$response=$expected+['delivery_id'=>'cfd_'.str_repeat('d',64)];$calls=[];$http=201;
$GLOBALS['__quanta_core_write_transport']=static function($method,$url,$headers,$json)use(&$calls,&$response,&$http){
    $calls[]=[$method,$url,$headers,json_decode($json,true)];return ['status'=>$http,'body'=>json_encode($response)];};
$idem=str_repeat('e',64);
$check(quantaWriteContract('fixture-key','workspace-a','time.review',$body,$expected,$idem)['state']==='accepted','confirmed review receipt accepted');
$check(str_ends_with($calls[0][1],'/integrations/coreflux/time-reviews') && $calls[0][0]==='POST','time review uses its own observation route');
$before=count($calls);
$check($fails(fn()=>quantaWriteContract('fixture-key','workspace-a','time.review',$body,$expected+['bank'=>'private'],$idem)),'unexpected acknowledgement fields fail before IO');
$check(count($calls)===$before,'invalid envelope made no provider call');
foreach([['state'=>'pending_review'],['tenant_id'=>'workspace-b'],['version'=>2],['source_entry_id'=>'another'],['delivery_id'=>'bad']] as $bad){
    $response=array_replace($expected,['delivery_id'=>'cfd_'.str_repeat('d',64)],$bad);
    try{quantaWriteContract('fixture-key','workspace-a','time.review',$body,$expected,$idem);$uncertain=false;}
    catch(QuantaContractWriteException $e){$uncertain=$e->uncertain;}
    $check($uncertain,'mismatched acknowledgement stays uncertain');
}
foreach([409,500,302] as $http){
    try{quantaWriteContract('fixture-key','workspace-a','time.review',$body,$expected,$idem);$uncertain=false;}
    catch(QuantaContractWriteException $e){$uncertain=$e->uncertain;}
    $check($uncertain,'ambiguous response cannot authorize a retry');
}
unset($GLOBALS['__quanta_core_write_transport']);
$raw=['id'=>'entry-1','worker_id'=>'worker-1','worksite_id'=>'site-1','work_date'=>'2026-10-05','timesheet_status'=>'approved',
    'regular_minutes'=>480,'overtime_minutes'=>0,'doubletime_minutes'=>0,'pto_minutes'=>0,'duration_minutes'=>480,
    'timesheet_id'=>'source-sheet','approved_at'=>'2026-10-06T00:00:00Z','approved_by_email'=>'reviewer@example.test','updated_at'=>'2026-10-05T20:00:00Z'];
$entry=quantaNormalizeEntry($raw,[],'approved');$route=['placement_id'=>42,'person_id'=>19,'engagement_type'=>'w2'];
[$type,$category,$billable]=quantaComponentCategory('regular');
$prior=['time_entry_id'=>10,'source_workspace_id'=>'workspace-a','worker_id'=>'worker-1','source_worksite_id'=>'site-1',
    'placement_id'=>42,'person_id'=>19,'work_date'=>'2026-10-05','entry_status'=>'approved','current_hours'=>8,
    'current_hour_type'=>$type,'current_category'=>$category,'current_billable'=>$billable,'current_source'=>'quanta','current_source_system'=>'quanta',
    'current_description'=>$entry['description'],'superseded_by_id'=>null,'source_hash'=>quantaComponentHash($entry,$route,'regular',8),
    'approved_by_user_id'=>7,'approved_at'=>'2026-10-06 12:00:00','rate_snapshot_id'=>81,...quantaTimeApprovalFields($entry)];
$imports=['entry-1'=>['regular'=>$prior]];
$proof=quantaTimeReviewProof($entry,$route,$imports,'workspace-a');
$check($proof['components']===[['entry_id'=>10,'component'=>'regular','status'=>'approved']],'exact imported components preserve native Core approval status');
foreach(['source_workspace_id'=>'workspace-b','current_hours'=>7,'entry_status'=>'superseded','rate_snapshot_id'=>null,'approved_by_user_id'=>null,'approved_at'=>null,'source_approval_hash'=>'changed','person_id'=>20,'current_source'=>'manual_entry'] as $field=>$value){
    $changed=$imports;$changed['entry-1']['regular'][$field]=$value;
    $check($fails(fn()=>quantaTimeReviewProof($entry,$route,$changed,'workspace-a')),'changed '.$field.' blocks publication');
}
$check($fails(fn()=>quantaTimeReviewProof($entry,$route,[],'workspace-a')),'missing imported components cannot report review status');
$check(!quantaTimeReviewEnabled(),'time review writes are disabled by default');
require_once __DIR__.'/fixtures/staffing_dictionary_checks.php';
staffingDictionaryAssertPayload('quanta_time_review','quanta',$body);
$check(true,'field dictionary covers every transmitted observation field');
echo "Quanta time review: $passed passed\n";
