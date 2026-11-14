<?php
/** Exact approved-source arithmetic, categories, provenance and override boundaries. */
declare(strict_types=1);
require_once __DIR__.'/../modules/time/lib/approval_economics.php';
require_once __DIR__.'/../modules/payroll/lib/source_earnings.php';
require_once __DIR__.'/../modules/payroll/lib/compute.php';
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS {$label}\n";};
$refuses=static function(callable $fn):bool{try{$fn();return false;}catch(RuntimeException|InvalidArgumentException $e){return true;}};
$source=static function(array $specs):array{
    $result=['entries'=>[],'rates'=>[]];
    foreach($specs as $i=>$spec){
        [$hours,$base,$type,$category,$payable]=$spec+[null,null,'regular','regular_billable',1];
        $id=$i+1;
        $rate=['id'=>7,'pay_rate'=>$base,'pay_rate_unit'=>'hour','currency'=>'USD','ot_multiplier'=>'1.7500','dt_multiplier'=>'2.2500'];
        $json=json_encode(['engagement_type'=>'w2','source_rate_hash'=>hash('sha256',json_encode($rate))],JSON_THROW_ON_ERROR);
        $result['entries'][]=['id'=>$id,'tenant_id'=>1,'person_id'=>2,'placement_id'=>3,'timesheet_id'=>4,'period_id'=>5,
            'work_date'=>'2026-10-06','hours'=>$hours,'hour_type'=>$type,'category'=>$category,'payable'=>$payable,'billable'=>1,
            'dimension_snapshot_json'=>$json,'dimension_snapshot_hash'=>hash('sha256',$json),'approved_at'=>'2026-10-12 12:00:00',
            'approved_by_user_id'=>10,'approved_via'=>'manual'];
        $result['rates'][$id]=$rate;
    }
    return $result;
};
$s=$source([['8','65.0050'],['2','65.0050','overtime','OT_billable'],['1','80.1250','doubletime','custom'],['9','65.0050','regular','unpaid_leave',0]]);
$p=payrollApprovedHourlyEarnings($s);
$check($p['gross_cents']===92784,'mixed original rates and custom overtime/double-time preserve exact cents');
$check(count($p['earnings'])===3 && count(json_decode($p['receipt_json'],true)['sources'])===4,'nonpayable source retained in receipt without pay');
$check(hash('sha256',$p['receipt_json'])===$p['receipt_sha256'],'receipt fingerprint covers original approval facts');
$check(count(array_filter($p['earnings'],static fn($e)=>$e['rate_cents']===null))===3,'fractional-cent rates are not reported as rounded whole-cent rates');
$reordered=$s;$reordered['entries']=array_reverse($s['entries']);
$check(payrollApprovedHourlyEarnings($reordered)===$p,'input order never changes source receipts or allocated cents');
$tiny=payrollApprovedHourlyEarnings($source([['0.5','0.0100'],['0.5','0.0100','regular','holiday']]));
$check($tiny['gross_cents']===1 && array_sum(array_column($tiny['earnings'],'amount_cents'))===1,'component allocation cannot create a second half-cent rounding gain');
$single=payrollApprovedHourlyEarnings($source([['0.5','0.0100']]));
$check($single['gross_cents']===1,'a single half-cent rounds up once');
$leave=payrollApprovedHourlyEarnings($source([['1','10','regular','holiday'],['1','10','regular','sick'],['1','10','regular','vacation'],['1','10','regular','bereavement']]));
$codes=array_column($leave['earnings'],'code');sort($codes);
$check($codes===['holiday','pto','sick'] && $leave['gross_cents']===4000,'approved leave maps to distinct payroll earnings');
$none=payrollApprovedHourlyEarnings($source([['8','10','regular','unpaid_leave',0]]));
$check($none['earnings']===[] && $none['gross_cents']===0,'nonpayable-only source yields zero, not default profile hours');
foreach(['-1','0.00001',1.00001,INF,'1e3','999999999999999999999'] as $bad)$check($refuses(fn()=>payrollSourceDecimal4($bad,'Hours')),'invalid decimal is refused: '.(string)$bad);
$check(payrollSourceDecimal4(0.1,'Hours')===1000 && payrollSourceDecimal4('65.0050','Rate')===650050,'representable decimals retain four-place precision');
$check($refuses(fn()=>payrollApprovedHourlyEarnings($source([['99999999','999999999']]))),'overflow refuses instead of changing the pay amount');
foreach([['pay_rate_unit','day'],['currency','CAD']] as [$field,$value]){
    $bad=$s;$bad['rates'][1][$field]=$value;
    $check($refuses(fn()=>payrollApprovedHourlyEarnings($bad)),'unsupported source '.$field.' is refused');
}
$bad=$s;$bad['entries'][]=$bad['entries'][0];$check($refuses(fn()=>payrollApprovedHourlyEarnings($bad)),'repeated source cannot double gross');
$bad=$s;unset($bad['rates'][1]);$check($refuses(fn()=>payrollApprovedHourlyEarnings($bad)),'missing original rate cannot become zero pay');
$check($refuses(fn()=>payrollApprovedHourlyEarnings($source([['1','10','regular','OT_billable']]))),'conflicting approved category and hour type require review');
$check($refuses(fn()=>payrollApprovedHourlyEarnings($source([['1','10','regular','unpaid_leave',1]]))),'payable unpaid leave requires review');
$roster=[['employee_id'=>9]];
foreach([['hours_regular'=>1],['approved_time_earnings'=>[]],['suppress_regular_earnings'=>true],['bonus_cents'=>-1]] as $values){
    $check($refuses(fn()=>payrollComputeOverrides([9=>$values],$roster,[9=>$s],[])),'source hours and internal compute values cannot be injected');
}
$check($refuses(fn()=>payrollComputeOverrides([10=>['bonus_cents'=>10]],$roster,[],[])),'overrides cannot add an employee outside the run');
$check(payrollComputeOverrides([9=>['bonus_cents'=>'123','commission_cents'=>45]],$roster,[9=>$s],[])===[9=>['bonus_cents'=>123,'commission_cents'=>45]],'reviewed-source runs allow explicit supplemental whole-cent earnings');
$check(payrollComputeOverrides([9=>['hours_regular'=>'1.2345']],$roster,[],[])===[9=>['hours_regular'=>'1.2345']],'compensation-only hours retain precision');
$ctx=['pay_type'=>'hourly','pay_rate_cents'=>99999,'pay_frequency'=>'weekly','work_state'=>'CA','fed_filing_status'=>'single',
    'approved_time_earnings'=>$p['earnings'],'approved_time_gross_cents'=>$p['gross_cents'],'hours_regular'=>999,'bonus_cents'=>100,'commission_cents'=>50];
$result=payrollComputeLine($ctx);
$check($result['gross_cents']===92934 && array_slice($result['earnings'],0,3)===$p['earnings'],'actual gross-to-net engine consumes retained earnings plus supplements without repricing');
$bad=$ctx;$bad['approved_time_gross_cents']++;$check($refuses(fn()=>payrollComputeLine($bad)),'component total mismatch cannot enter tax calculation');
$salary=$ctx;$salary['pay_type']='salary';$salary['pay_rate_cents']=5200000;
$check(payrollComputeLine($salary)['gross_cents']===100150,'explicit salary treatment is unchanged');
$legacy=$ctx;unset($legacy['approved_time_earnings'],$legacy['approved_time_gross_cents']);$legacy['pay_rate_cents']=3000;$legacy['hours_regular']=40;
$check(payrollComputeLine($legacy)['gross_cents']===120150,'compensation-only hourly calculation remains available');
$check($refuses(fn()=>payrollAssertHoursOnlyExport([['earnings_basis'=>'approved_time']])),'hours-only provider cannot silently reprice approved source earnings');
payrollAssertHoursOnlyExport([['earnings_basis'=>'compensation']]);$check(true,'ordinary compensation exports retain their existing path');
echo "{$checks} approved source earnings checks passed\n";
