<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/sync.php';
$passed=0;
$check=static function(bool $ok,string $message)use(&$passed):void { if(!$ok)throw new RuntimeException($message); $passed++; echo "PASS {$message}\n"; };
$fails=static function(callable $f):bool {try{$f();return false;}catch(InvalidArgumentException $e){return true;}};
$raw=['id'=>'entry-1','worker_id'=>'worker-1','worksite_id'=>'site-1','work_date'=>'2026-10-02',
    'timesheet_status'=>'approved','regular_hours'=>8,'timesheet_id'=>'sheet-1',
    'approved_at'=>'2026-10-03T10:30:00.123456Z','approved_by_email'=>'approver@example.test','updated_at'=>'2026-10-03T10:31:00Z'];
$sites=['site-1'=>['timezone'=>'America/New_York']];
$entry=quantaNormalizeEntry($raw,$sites,'approved');
$proof=$entry['source_approval'];
$check($proof['timesheet_id']==='sheet-1' && $proof['approved_by_email']==='approver@example.test','source sheet and approver survive normalization');
$check($proof['approved_at']==='2026-10-03T10:30:00.123456Z' && $proof['entry_updated_at']==='2026-10-03T10:31:00.000000Z','source timestamps retain microseconds and explicit UTC');
$offset=array_replace($raw,['approved_at'=>'2026-10-03T06:30:00.123456-04:00']);
$check(quantaTimeApprovalFields(quantaNormalizeEntry($offset,$sites,'approved'))===quantaTimeApprovalFields($entry),'equivalent offsets produce the same evidence hash');
$check(!array_key_exists('approved_by_user_id',$proof),'missing stable source actor ID is not invented');
$check(quantaTimeApprovalNormalize([],'approved')===null,'old sources remain explicitly without evidence');
$partial=quantaTimeApprovalNormalize(['timesheet_id'=>'legacy-sheet'],'approved');
$check($partial['approved_at']===null && $partial['approved_by_email']===null,'partial source evidence is retained without invented approval');
foreach(['approved_at'=>'2026-02-30T10:00:00Z','updated_at'=>'tomorrow','timesheet_id'=>['bad'],'approved_by_email'=>'bad'] as $key=>$value) {
    $check($fails(fn()=>quantaNormalizeEntry(array_replace($raw,[$key=>$value]),$sites,'approved')),"invalid {$key} blocks import");
}
$check($fails(fn()=>quantaApprovalTimestamp('2026-10-03T10:00:00+25:00','approved_at')),'invalid UTC offset is rejected');
$route=['placement_id'=>42,'person_id'=>19,'engagement_type'=>'w2'];
$prior=['entry-1'=>['regular'=>['source_hash'=>quantaComponentHash($entry,$route,'regular',8.0),
    ...quantaTimeApprovalFields($entry),'source_worksite_id'=>'site-1','entry_status'=>'pending_review',
    'placement_id'=>42,'person_id'=>19,'work_date'=>'2026-10-02','bill_extracted_at'=>null,'ap_extracted_at'=>null,'payroll_extracted_at'=>null]]];
$check(quantaEntryDecision($entry,$route,$prior)[0]==='imported','unchanged source approval and hours replay without changes');
foreach(['approved_by_email'=>'other@example.test','approved_at'=>'2026-10-04T10:00:00Z','timesheet_id'=>'sheet-2','updated_at'=>'2026-10-04T10:31:00Z']as$key=>$value) {
    $changed=quantaNormalizeEntry(array_replace($raw,[$key=>$value]),$sites,'approved');
    $check(quantaEntryDecision($changed,$route,$prior)[0]==='update',"changed {$key} requires new review despite unchanged hours");
    $approved=$prior;$approved['entry-1']['regular']['entry_status']='approved';
    $check(quantaEntryDecision($changed,$route,$approved)[0]==='conflict',"changed {$key} cannot rewrite Core-approved source evidence");
}
$used=$prior;$used['entry-1']['regular']['payroll_extracted_at']='2026-10-03 12:00:00';
$missing=quantaNormalizeEntry(array_diff_key($raw,['approved_by_email'=>true]),$sites,'approved');
$check(quantaEntryDecision($missing,$route,$used)[0]==='conflict','lost approval details cannot rewrite downstream-used time');
$legacy=$prior;unset($legacy['entry-1']['regular']['source_approval_hash']);
$legacy['entry-1']['regular']['entry_status']='approved';
$check(quantaEntryDecision($entry,$route,$legacy)[0]==='conflict','historic approved imports do not silently acquire current approval evidence');
echo "Quanta approval evidence: {$passed} passed\n";
