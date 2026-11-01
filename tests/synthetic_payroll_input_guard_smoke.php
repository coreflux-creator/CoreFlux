<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/purepay_payroll_inputs.php';
require_once __DIR__.'/../deploy/synthetic_payroll_input_guard.php';
$person=integrationRecordReference(SYNTHETIC_PAYROLL_INSTANCE,999,'person','P-15');
$placement=integrationRecordReference(SYNTHETIC_PAYROLL_INSTANCE,999,'placement','PL-13');
$body=['contract_version'=>PUREPAY_PAYROLL_INPUT_VERSION,'instance_id'=>SYNTHETIC_PAYROLL_INSTANCE,'time_tenant_id'=>999,
    'period_start'=>'2026-10-19','period_end'=>'2026-10-19','components'=>[['person'=>$person,'placement'=>$placement,
    'employee_id'=>SYNTHETIC_PAYROLL_EMPLOYEE,'assignment_id'=>SYNTHETIC_PAYROLL_ASSIGNMENT,
    'person_link_id'=>'cfl_'.integrationDeliveryHash([SYNTHETIC_PAYROLL_ORG,$person]),
    'placement_link_id'=>'cfl_'.integrationDeliveryHash([SYNTHETIC_PAYROLL_ORG,$placement]),
    'source'=>['time_entry_id'=>1,'revision'=>str_repeat('a',64),'work_date'=>'2026-10-19','category'=>'regular_billable','hours'=>'8.00',
        'source_system'=>'quanta','source_entry_id'=>'d9caaf9a-877b-4f59-89d9-c2b71983a676','source_component'=>'regular',
        'source_revision'=>str_repeat('b',64),'source_workspace_id'=>'0598d4ef-3075-4289-9714-c93f904aa85d'],
    'rate'=>['snapshot_id'=>1,'revision'=>str_repeat('c',64),'pay_rate'=>'30.0000','pay_rate_unit'=>'hour','currency'=>'USD','multiplier'=>'1.00']]]];
$payload=['organization_id'=>SYNTHETIC_PAYROLL_ORG,'owners'=>['time'=>999,'people'=>999,'placements'=>999],'body'=>$body];
$passed=0;$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
$reject=static function(callable $call):bool{try{$call();return false;}catch(RuntimeException $e){return true;}};
syntheticPayrollInputGuard($payload);$check(true,'exact demo packet passes without mutating it');
foreach(['organization_id'=>'thunderhawk','owners'=>['people'=>999,'placements'=>999,'time'=>2]] as $field=>$value){
    $p=$payload;$p[$field]=$value;$check($reject(fn()=>syntheticPayrollInputGuard($p)),'foreign '.$field.' held');
}
foreach(['instance_id'=>'other','time_tenant_id'=>2,'period_start'=>'2026-10-18','period_end'=>'2026-10-20','contract_version'=>'future','components'=>[$body['components'][0],$body['components'][0]]] as $field=>$value){
    $p=$payload;$p['body'][$field]=$value;$check($reject(fn()=>syntheticPayrollInputGuard($p)),'changed '.$field.' held');
}
foreach(['person'=>integrationRecordReference(SYNTHETIC_PAYROLL_INSTANCE,2,'person','P-358'),
    'placement'=>integrationRecordReference(SYNTHETIC_PAYROLL_INSTANCE,2,'placement','PL-864'),
    'employee_id'=>'other','assignment_id'=>'other','person_link_id'=>'other','placement_link_id'=>'other'] as $field=>$value){
    $p=$payload;$p['body']['components'][0][$field]=$value;$check($reject(fn()=>syntheticPayrollInputGuard($p)),'changed '.$field.' held');
}
foreach(['time_entry_id'=>2,'work_date'=>'2026-10-20','category'=>'OT_billable','hours'=>'9.00','source_system'=>'manual',
    'source_entry_id'=>'other','source_component'=>'overtime','source_workspace_id'=>'other','revision'=>'','source_revision'=>''] as $field=>$value){
    $p=$payload;$p['body']['components'][0]['source'][$field]=$value;$check($reject(fn()=>syntheticPayrollInputGuard($p)),'changed source '.$field.' held');
}
foreach(['snapshot_id'=>2,'pay_rate'=>'65.0000','pay_rate_unit'=>'day','currency'=>'CAD','multiplier'=>'1.50','revision'=>''] as $field=>$value){
    $p=$payload;$p['body']['components'][0]['rate'][$field]=$value;$check($reject(fn()=>syntheticPayrollInputGuard($p)),'changed rate '.$field.' held');
}
$hash=integrationDeliveryHash($body);
$remote=['id'=>'cpi_'.integrationDeliveryHash([SYNTHETIC_PAYROLL_ORG,$hash]),'organization_id'=>SYNTHETIC_PAYROLL_ORG,'content_hash'=>$hash,
    'state'=>'received_for_review','payroll_created'=>false,'source_time_reserved'=>false,'components'=>1,
    'packet'=>$body,'can_apply'=>false,'source_approval'=>'snapshot_only','earnings_preview'=>['version'=>PUREPAY_PAYROLL_INPUT_VERSION,
    'complete'=>true,'currency'=>'USD','gross'=>'240.00','issues'=>[],'components'=>[['time_entry_id'=>1,'employee_id'=>SYNTHETIC_PAYROLL_EMPLOYEE,
    'assignment_id'=>SYNTHETIC_PAYROLL_ASSIGNMENT,'currency'=>'USD','amount'=>'240.00','issue'=>null]]]];
syntheticPayrollInputReadbackGuard($remote,$body);$check(true,'exact immutable held receipt and earnings pass');
foreach(['id'=>'other','organization_id'=>'thunderhawk','content_hash'=>str_repeat('d',64),'state'=>'applied','payroll_created'=>true,
    'source_time_reserved'=>true,'components'=>2,'packet'=>[],'can_apply'=>true,'source_approval'=>'current'] as $field=>$value){
    $r=$remote;$r[$field]=$value;$check($reject(fn()=>syntheticPayrollInputReadbackGuard($r,$body)),'changed receipt '.$field.' held');
}
foreach(['gross'=>'520.00','complete'=>false,'currency'=>'CAD','issues'=>['missing source'],'components'=>[]] as $field=>$value){
    $r=$remote;$r['earnings_preview'][$field]=$value;$check($reject(fn()=>syntheticPayrollInputReadbackGuard($r,$body)),'changed earnings '.$field.' held');
}
$r=$remote;$r['earnings_preview']['components'][0]['employee_id']='another-worker';
$check($reject(fn()=>syntheticPayrollInputReadbackGuard($r,$body)),'correct amount attributed to another employee is held');
echo "$passed synthetic payroll input boundary checks passed\n";
