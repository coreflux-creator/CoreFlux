<?php
/** Complete synthetic PHP export, also consumed by the real provider route tests. */
declare(strict_types=1);
require_once __DIR__.'/purepay_assignment_fixture.php';

function purepayAssignmentCommissionFixtureRows(): array
{
    $rows=purepayAssignmentFixtureRows();
    $commission=array_replace($rows['parties'][1],['id'=>55,'source_ref'=>'commission:55','source_type'=>'commission',
        'role'=>'commission_recruiter','display_name'=>'Synthetic commission recipient','user_id'=>77,'person_id'=>9002,
        'fee_basis'=>'flat','fee_pct'=>null,'fee_flat'=>'8000.00','currency'=>'GBP','payment_frequency'=>'per_pay_period','due_month'=>null]);
    $rows['parties'][]=$commission;
    foreach($rows['rates'] as &$rate) {
        $snapshot=json_decode($rate['economics_snapshot_json'],true,64,JSON_THROW_ON_ERROR);
        $snapshot['contract_parties'][]=array_replace($commission,['fee_flat'=>'7500.00','currency'=>'INR',
            'payment_frequency'=>'one_time','due_month'=>'2026-08','cycle_name'=>'Approved payroll',
            'cycle_purpose'=>'payroll','cycle_cadence'=>'biweekly','resolved_payment_terms'=>null,'resolved_pwp'=>false]);
        $rate['economics_snapshot_json']=json_encode($snapshot,JSON_THROW_ON_ERROR);
    }
    unset($rate);
    return $rows;
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    $rows=purepayAssignmentCommissionFixtureRows();$packet=purepayAssignmentFixtureBuild($rows);
    if(($argv[1]??'')==='--emit'){echo integrationDeliveryJson($packet);exit;}
    $count=0;$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;};
    $check($packet['contract_version']==='2026-11-12.1','Commission version is explicit');
    foreach($packet['terms'] as $term) {
        $party=$term['economics']['parties'][4];
        $check($party['currency']==='INR'&&$party['payment_frequency']==='one_time'&&$party['due_month']==='2026-08'&&$party['fee_amount']==='7500.0000','Approved independent terms survive exactly');
        $check($party['roots']['person']['number']==='P-9002'&&$party['source_user_id']===77&&$party['id']===55,'Commission recipient/source identities survive');
    }
    $current=$packet['current_commercial_context']['parties'][4];
    $check($current['currency']==='GBP'&&$current['payment_frequency']==='per_pay_period'&&$current['due_month']===null&&$current['fee_amount']==='8000.0000','Current terms remain separate from approval');
    $check($packet['terms'][0]['currency']==='USD'&&$packet['terms'][0]['pay_rates']['regular']==='42.1250','Placement wages remain unchanged');
    $unknown=purepayAssignmentCommissionTerms(['source_type'=>'commission','fee_basis'=>'flat']);
    $check($unknown===['currency'=>null,'payment_frequency'=>null,'due_month'=>null],'Legacy missing terms stay unknown');
    foreach([['currency'=>'USD'],['payment_frequency'=>'one_time','due_month'=>'2026-08'],['currency'=>'EUR']] as $change) {
        $changed=$rows;$changed['parties'][4]=array_replace($changed['parties'][4],$change);
        $next=purepayAssignmentFixtureBuild($changed);
        $check($next['source_revision']!==$packet['source_revision']&&$next['terms']===$packet['terms'],'Each current term edit changes revision without rewriting approval');
    }
    foreach([['currency'=>'XYZ'],['currency'=>'' ],['currency'=>true],['payment_frequency'=>'monthly'],['due_month'=>'2026-13'],
        ['due_month'=>'2026-08-01'],['due_month'=>202608],['due_month'=>'2026-08'],['fee_basis'=>'net_margin','currency'=>'INR']] as $change) {
        try {purepayAssignmentCommissionTerms(array_replace($rows['parties'][4],$change));$rejected=false;} catch(RuntimeException) {$rejected=true;}
        $check($rejected,'Invalid or conflicting terms rejected');
    }
    echo "PASS $count commission exchange projection checks\n";
}
