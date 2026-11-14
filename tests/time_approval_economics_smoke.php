<?php
declare(strict_types=1);
require_once __DIR__.'/../modules/staffing/lib/dimension_snapshot.php';
require_once __DIR__.'/../modules/time/lib/approval_economics.php';
$count=0;
$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;};
$entry=['tenant_id'=>10,'id'=>20,'person_id'=>30,'placement_id'=>40,'period_id'=>50,'timesheet_id'=>60,'work_date'=>'2026-10-05','hours'=>8,'category'=>'regular_billable','hour_type'=>'regular','billable'=>1,'payable'=>1];
$rate=['bill_rate'=>98,'pay_rate'=>65,'ot_multiplier'=>1.5,'dt_multiplier'=>2,'adder_pct'=>0.1,'workers_comp_pct'=>0.02,'benefits_load_pct'=>0.03,'other_cost_per_hour'=>2,'c2c_overhead_pct'=>0.05];
$w2=['engagement_type'=>'w2'];
$regular=timeApprovedEntryAmounts($entry,$rate,$w2);
foreach(['revenue'=>784,'wage_cost'=>520,'employer_load_cost'=>52,'workers_comp_cost'=>10.4,'benefits_cost'=>15.6,'other_direct_cost'=>16,'cost'=>614] as $field=>$expected) $check(abs($regular[$field]-$expected)<0.000001,'Eight-hour W2 '.$field);
$ot=timeApprovedEntryAmounts(array_replace($entry,['hour_type'=>'overtime']),$rate,$w2);
$check($ot['revenue']===1176.0&&$ot['wage_cost']===780.0&&$ot['other_direct_cost']===16.0,'Overtime multiplies hourly bill/pay but not flat costs');
$nonbill=timeApprovedEntryAmounts(array_replace($entry,['billable'=>0]),$rate,$w2);
$check($nonbill['revenue']===0.0&&$nonbill['cost']===$regular['cost'],'Nonbillable payable time retains labor costs');
$unpaid=timeApprovedEntryAmounts(array_replace($entry,['payable'=>0]),$rate,$w2);
$check($unpaid['cost']===0.0&&$unpaid['revenue']===784.0,'Payability and billability are independent');
$referral=timeApprovedEntryAmounts(array_replace($entry,['hour_type'=>'overtime']),$rate,['engagement_type'=>'referral','referral_fee_per_hour'=>'20.0000']);
$check($referral['cost']===160.0&&$referral['revenue']===1176.0,'Referral payout remains flat per hour during overtime');
$c2c=timeApprovedEntryAmounts($entry,$rate,['engagement_type'=>'c2c']);
$check($c2c['cost']===562.0&&$c2c['wage_cost']===0.0,'C2C includes overhead without creating employee wages');
$hash=timeApprovedEntryFactsHash($entry);
foreach(['hours'=>9,'tenant_id'=>11,'id'=>21,'person_id'=>31,'placement_id'=>41,'period_id'=>51,'timesheet_id'=>61,'work_date'=>'2026-10-06','category'=>'holiday','hour_type'=>'overtime','billable'=>0,'payable'=>0,'approved_at'=>'2026-10-10 12:00:00','approved_by_user_id'=>99,'approved_via'=>'manual','client_approver_email'=>'different@example.invalid'] as $field=>$value) $check($hash!==timeApprovedEntryFactsHash(array_replace($entry,[$field=>$value])),'Approved evidence binds '.$field);
$check($hash===timeApprovedEntryFactsHash($entry+['status'=>'locked','bill_extracted_ref'=>'invoice:1']),'Extraction markers do not rewrite approved facts');
require_once __DIR__.'/../modules/time/lib/time.php';
$entries=[
    array_replace($entry,['id'=>1,'hours'=>2,'rate_snapshot_id'=>90]),
    array_replace($entry,['id'=>2,'hours'=>3,'rate_snapshot_id'=>90,'billable'=>0,'category'=>'regular_nonbillable']),
    array_replace($entry,['id'=>3,'hours'=>1,'rate_snapshot_id'=>90,'billable'=>0,'category'=>'vacation','hour_type'=>'pto']),
    array_replace($entry,['id'=>4,'hours'=>4,'rate_snapshot_id'=>90,'billable'=>0,'payable'=>0,'category'=>'unpaid_leave','hour_type'=>'unpaid']),
];
$rates=[1=>$rate,2=>array_replace($rate,['pay_rate'=>70]),3=>$rate,4=>$rate];
$totals=timeEntrySnapshotFinancialTotals($entries,$rates);
$check($totals['amount_bill']===196.0 && $totals['amount_pay']===405.0,'period amounts honor billability, paid nonbillable time, PTO and unpaid flags separately');
$check($totals['totals']===['billable'=>2.0,'nonbillable'=>3.0,'pto'=>1.0,'unpaid'=>4.0,'custom'=>0.0],'period hour buckets remain visible independently of financial flags');
$referralEntry=array_replace($entry,['id'=>5,'rate_snapshot_id'=>90,'hours'=>2,'hour_type'=>'overtime','dimension_snapshot_json'=>json_encode(['engagement_type'=>'referral','referral_fee_per_hour'=>20])]);
$referralTotal=timeEntrySnapshotFinancialTotals([$referralEntry],[5=>$rate]);
$check($referralTotal['amount_pay']===40.0 && $referralTotal['amount_bill']===294.0,'period referral uses approved flat hourly payout without live contract reads');
$missing=timeEntrySnapshotFinancialTotals([$entries[0]],[]);
$check($missing['missing_rate_entry_ids']===[1] && $missing['amount_bill']===0.0,'missing per-entry rates cannot fall back to a shared rate ID');
echo $count." approved-economics checks passed.\n";
