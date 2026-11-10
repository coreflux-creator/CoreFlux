<?php
declare(strict_types=1);
require_once __DIR__.'/../deploy/synthetic_time_approval_guard.php';
$entry=['id'=>1,'tenant_id'=>999,'person_id'=>15,'placement_id'=>13,'created_by_user_id'=>4,
    'work_date'=>'2026-10-19','hours'=>'8.00','source'=>'quanta','source_system'=>'quanta',
    'category'=>'regular_billable','hour_type'=>'regular','timesheet_id'=>2,'status'=>'pending_review',
    'bill_extracted_at'=>null,'ap_extracted_at'=>null,'payroll_extracted_at'=>null,'superseded_by_id'=>null];
$rate=['id'=>19,'bill_rate'=>'60.00','pay_rate'=>'30.00','currency'=>'USD','bill_rate_unit'=>'hour','pay_rate_unit'=>'hour'];
$snapshot=['payload'=>['workspace_id'=>'0598d4ef-3075-4289-9714-c93f904aa85d','body'=>[
    'assignment_id'=>'cfa_33fdaed15cffaba6f9468cba05de1ac9d39651031f2eb5bfdc7079f3a87c45ac',
    'core_tenant_id'=>999,'components'=>[['entry_id'=>1,'component'=>'regular','status'=>'pending_review']]]],
    'placement_number'=>'PL-13','entry'=>['id'=>'d9caaf9a-877b-4f59-89d9-c2b71983a676',
    'worker_id'=>'cfw_2c5e38cf6059e5d00f16b534feb7e2006c697e0e82b5b009cabcfa86eef0892c',
    'work_date'=>'2026-10-19','hours'=>8,'source_approval'=>['timesheet_id'=>'8c9e9039-109f-421c-9e7d-585adb8400a7']]];
$passed=0;$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
$fails=static function($e,$r,$s):bool{try{syntheticTimeApprovalGuard($e,$r,$s);return false;}catch(RuntimeException $error){return true;}};
syntheticTimeApprovalGuard($entry,$rate,$snapshot);$check(true,'exact existing demo is eligible for native review');
foreach(['id'=>2,'tenant_id'=>2,'person_id'=>358,'placement_id'=>864,'created_by_user_id'=>51,'work_date'=>'2026-10-20',
    'hours'=>9,'source'=>'manual','source_system'=>'manual','category'=>'OT_billable','hour_type'=>'overtime','timesheet_id'=>0,
    'status'=>'draft','bill_extracted_at'=>'2026-10-25','ap_extracted_at'=>'2026-10-25','payroll_extracted_at'=>'2026-10-25',
    'superseded_by_id'=>2,'approved_by_user_id'=>51,'approved_at'=>'2026-10-25'] as $field=>$value)
    $check($fails(array_replace($entry,[$field=>$value]),$rate,$snapshot),'changed '.$field.' cannot reach approval');
foreach(['id'=>0,'bill_rate'=>98,'pay_rate'=>65,'currency'=>'CAD','bill_rate_unit'=>'day','pay_rate_unit'=>'day'] as $field=>$value)
    $check($fails($entry,array_replace($rate,[$field=>$value]),$snapshot),'changed rate '.$field.' is held');
foreach(['workspace_id'=>'other'] as $field=>$value){$bad=$snapshot;$bad['payload'][$field]=$value;$check($fails($entry,$rate,$bad),'foreign source workspace is held');}
foreach(['assignment_id'=>'other','core_tenant_id'=>2,'components'=>[['entry_id'=>1,'component'=>'overtime','status'=>'pending_review']]] as $field=>$value){
    $bad=$snapshot;$bad['payload']['body'][$field]=$value;$check($fails($entry,$rate,$bad),'changed source '.$field.' is held');
}
foreach(['id'=>'other','worker_id'=>'other','work_date'=>'2026-10-20','hours'=>7,'source_approval'=>['timesheet_id'=>'other']] as $field=>$value){
    $bad=$snapshot;$bad['entry'][$field]=$value;$check($fails($entry,$rate,$bad),'changed source entry '.$field.' is held');
}
$approved=array_replace($entry,['status'=>'approved','approved_by_user_id'=>51,'approved_at'=>'2026-10-25 18:00:00',
    'approved_via'=>'manual','rate_snapshot_id'=>19]);
$accepted=$snapshot;$accepted['payload']['body']['components'][0]['status']='approved';
syntheticTimeApprovalGuard($approved,$rate,$accepted);$check(true,'exact prior test approval is eligible for status recovery');
foreach(['approved_by_user_id'=>4,'approved_via'=>'bulk_pre_approved','approved_at'=>null,'rate_snapshot_id'=>20] as $field=>$value)
    $check($fails(array_replace($approved,[$field=>$value]),$rate,$accepted),'foreign prior '.$field.' cannot be adopted');
echo "$passed synthetic approval boundary checks passed\n";
