<?php
declare(strict_types=1);
require_once __DIR__.'/../deploy/staffing_pilot_commercial_alignment.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
$contract=['start_id'=>'start001','engagement_type'=>'w2','bill_rate'=>98,'pay_rate'=>65,
    'bill_rate_unit'=>'USD/Hour','pay_rate_unit'=>'hour','currency'=>'USD',
    'private_sentinel'=>'DO-NOT-EMIT-COMPENSATION'];
$rate=['id'=>1,'approved_at'=>null,'bill_rate'=>'98.0000','pay_rate'=>'65.0000',
    'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD','adjusted_bill_rate'=>'98.0000'];
$comparison=fullCorePilotRateComparison($rate,$contract,'start001');
$check($comparison['status']==='compared'&&$comparison['approval']==='draft','draft rate remains explicitly unapproved');
foreach(['bill_rate','pay_rate','bill_rate_unit','pay_rate_unit','currency','invoice_bill_rate'] as $field) {
    $check($comparison['fields'][$field]==='matches_fresh_source',"canonical comparison matches $field without raw values");
}
$check(!str_contains(json_encode($comparison),'98')&&!str_contains(json_encode($comparison),'65')
    &&!str_contains(json_encode($comparison),'DO-NOT-EMIT'),'commercial output omits source and current compensation');
$check(fullCorePilotRateComparison(array_replace($rate,['approved_at'=>'2026-01-01']),$contract,'start001')['approval']==='approved',
    'approved state is taken from the specific rate version');
$check(fullCorePilotRateComparison(array_replace($rate,['pay_rate'=>'64.9999']),$contract,'start001')['fields']['pay_rate']==='differs_from_fresh_source',
    'a four-decimal rate difference is retained');
$check(fullCorePilotRateComparison($rate,array_replace($contract,['pay_currency'=>'CAD']),'start001')['fields']['pay_rate']==='source_rate_basis_needs_review',
    'equal numbers with conflicting bill/pay currencies are not equivalent rates');
$missing=$contract; unset($missing['currency']);$missing['bill_rate_unit']='hour';
$check(!fullCorePilotRateComparison($rate,$missing,'start001')['source_rate_basis_resolved'],'no default USD is inferred');
$missing=$contract;unset($missing['pay_rate_unit']);
$check(fullCorePilotRateComparison($rate,$missing,'start001')['fields']['pay_rate']==='source_rate_basis_needs_review','missing pay unit cannot match an hourly Core amount');
$check(fullCorePilotRateComparison($rate,array_replace($contract,['classification_review'=>['status'=>'conflict']]),'start001')['fields']['pay_rate']==='source_classification_needs_review',
    'explicit unresolved source classification is preserved');
$monthly=array_replace($contract,['bill_rate_unit'=>'month','pay_rate_unit'=>'month']);
$check(fullCorePilotRateComparison($rate,$monthly,'start001')['fields']['pay_rate']==='source_rate_model_needs_review',
    'hourly projection does not certify a monthly source amount');
$missing=$contract;unset($missing['pay_rate']);
$check(fullCorePilotRateComparison($rate,$missing,'start001')['fields']['pay_rate']==='source_value_missing','missing source pay never inherits bill rate');
$check(fullCorePilotRateComparison(array_replace($rate,['pay_rate'=>null]),$contract,'start001')['fields']['pay_rate']==='core_value_missing','null Core pay stays missing');
$check($comparison['fields']['workers_comp_pct']==='core_column_unavailable','absent optional schema is reported separately');
$check(fullCorePilotRateComparison($rate,$contract,'START001')['status']==='contract_assignment_mismatch','assignment identity is exact and case sensitive');
$vms=array_replace($contract,['bill_rate_in_vms'=>100,'net_bill_rate'=>98]);
$vmsRate=array_replace($rate,['bill_rate'=>'100.0000','bill_discount_pct'=>'0.020000']);
$vmsComparison=fullCorePilotRateComparison($vmsRate,$vms,'start001');
$check($vmsComparison['fields']['bill_rate']==='matches_fresh_source'
    &&$vmsComparison['fields']['invoice_bill_rate']==='matches_fresh_source'
    &&$vmsComparison['fields']['bill_discount_pct']==='matches_fresh_source','gross, discount and net invoice terms remain distinct');
echo "$passed passed\n";
