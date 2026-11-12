<?php
declare(strict_types=1);
require_once __DIR__.'/../core/integrations/staffing_reconciliation_contract.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
$fails=static function(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException $e){return true;}};
$period=staffingReconciliationPeriod('2026-10-05','2026-10-18');
foreach([['2026-02-30','2026-03-01'],['2026-10-18','2026-10-05'],['2025-01-01','2026-10-01']]as[$s,$e])
    $check($fails(fn()=>staffingReconciliationPeriod($s,$e)),'invalid or excessive work period refused');
foreach([
    [null,null,'unknown'],['2026-10-01',null,'unknown'],['2026-11-05','2026-10-04','unknown'],
    ['2026-09-01','2026-09-30','outside'],['2026-10-05','2026-10-18','exact'],
    ['2026-10-06','2026-10-16','within'],['2026-10-01','2026-10-31','contains'],['2026-10-01','2026-10-06','overlap']
]as[$s,$e,$expected])$check(staffingReconciliationRelation($s,$e,$period)===$expected,'period relation '.$expected);
$entry=['id'=>1,'placement_id'=>8,'work_date'=>'2026-10-05','hours'=>'8.00','category'=>'regular_billable','hour_type'=>'regular',
    'status'=>'approved','approved_at'=>'2026-10-19 00:00:00','approved_by_user_id'=>7,'superseded_by_id'=>null,
    'rate_snapshot_id'=>3,'payable'=>1,'billable'=>1,'source'=>'quanta','source_system'=>'quanta','external_id'=>'source-1'];
$rate=['id'=>3,'placement_id'=>8,'currency'=>'USD','approved_at'=>'2026-10-01 00:00:00',
    'pay_rate'=>'65.0000','pay_rate_unit'=>'hour','bill_rate'=>'98.0000','bill_rate_unit'=>'hour',
    'adjusted_bill_rate'=>null,'ot_multiplier'=>'1.50','dt_multiplier'=>'2.00'];
$read=static fn(array $e=[],array $r=[])=>staffingReconciliationTime([array_replace($entry,$e)],[3=>array_replace($rate,$r)],$period);
$time=$read();
$check($time['approved_hours']==='8.00'&&$time['pay_amount']==='520.00'&&$time['bill_amount']==='784.00','approved frozen rates give exact hourly comparison');
$check($time['original_client_approval_proven']===false,'Core approval is not promoted to original client approval');
$check($read(['status'=>'submitted'])['pay_amount']===null,'unapproved time is not priced');
$check($read(['approved_by_user_id'=>null])['approved_entries']===0,'approval date without actor is not accepted');
$check($read(['superseded_by_id'=>9])['approved_entries']===0,'superseded time is excluded without disappearing from detail');
$check($read(['hours'=>null])['approved_hours']===null&&$read(['hours'=>null])['pay_amount']===null,'missing hours do not turn into zero or an amount');
$check($read(['payable'=>null])['payable_hours']===null&&$read(['payable'=>null])['bill_amount']==='784.00','missing pay eligibility does not hide independently valid billing');
$check($read([],['pay_rate_unit'=>'month'])['pay_amount']===null&&$read([],['pay_rate_unit'=>'month'])['bill_amount']==='784.00','non-hourly pay remains unavailable while bill amount is retained');
$check($read([],['approved_at'=>null])['pay_amount']===null,'unapproved rate cannot price approved time');
$check($read([],['placement_id'=>9])['pay_amount']===null,'rate from another placement is unavailable');
$check($read([],['currency'=>null])['pay_amount']===null,'currency absence has no default');
$check($read([],['adjusted_bill_rate'=>'100.0000'])['bill_amount']==='800.00','billing uses approved adjusted rate when present');
$check($read(['category'=>'OT_billable','hour_type'=>'overtime'],['ot_multiplier'=>null])['bill_amount']===null,'missing overtime multiplier is not defaulted');
$check($read(['category'=>'OT_billable','hour_type'=>'overtime'])['pay_amount']==='780.00','explicit overtime multiplier is retained');
$check($read(['category'=>'custom','hour_type'=>'nonbillable'])['pay_amount']===null,'unknown earnings need a rule instead of a regular-rate default');
$conflict=$read(['hour_type'=>'overtime']);
$check($conflict['pay_amount']===null&&$conflict['bill_amount']===null&&in_array('earning_category_conflict',$conflict['entries'][0]['issues'],true),'contradictory payroll category and billing hour type require review');
$check($read(['category'=>'vacation','hour_type'=>'pto','billable'=>0])['pay_amount']==='520.00','canonical paid leave remains supported');
$check($read(['category'=>'regular_nonbillable','hour_type'=>'nonbillable','billable'=>0])['pay_amount']==='520.00','canonical nonbillable work retains its payable amount');
$check($read(['category'=>'OT_billable','hour_type'=>null])['pay_amount']==='780.00','absent legacy hour type uses the explicit atomic overtime category');
$check(staffingReconciliationCharge('1000.00','0.1234','1.23')==='151.80'
    &&staffingReconciliationCharge('1000.00','0.1234','1.23',false)==='151.78','billing and payroll preserve their different fractional-rate rounding rules');
$check($fails(fn()=>staffingReconciliationScaled('1.234',2,4)),'unrepresented source precision is refused');
$check(staffingReconciliationScaled('0.00000000',2,4)===0,'explicit numeric zero is retained');
$unknown=staffingReconciliationDifference('520.00','520.00',null,'USD',true);
$check($unknown['numeric_difference']==='0.00'&&$unknown['monetary_difference']===null&&$unknown['currency_comparison']==='unknown','numeric equality with missing currency is not monetary agreement');
$different=staffingReconciliationDifference('520.00','520.00','EUR','USD',true);
$check($different['monetary_difference']===null&&$different['currency_comparison']==='different','different currencies are never subtracted as money');
$outside=staffingReconciliationDifference('520.00','520.00','USD','USD',false);
$check($outside['numeric_difference']===null,'unlike work periods do not produce a numeric comparison');
$match=staffingReconciliationDifference('520.01','520.00','USD','USD',true);
$check($match['monetary_difference']==='0.01'&&$match['allocation_proven']===false,'exact arithmetic does not establish allocation');
$mixed=staffingReconciliationTime([$entry,array_replace($entry,['id'=>2,'rate_snapshot_id'=>4])],[3=>$rate,4=>array_replace($rate,['currency'=>'EUR'])],$period);
$check($mixed['approved_hours']==='16.00'&&$mixed['currency']===null&&$mixed['pay_amount']===null,'mixed currencies preserve hours without adding monetary totals');
echo "Staffing reconciliation: $passed passed\n";
