<?php
/** Synthetic provider result builder for storage/transport tests only. */
declare(strict_types=1);
function payrollResultFixture(array $body,array $packet): array
{
    $request=str_repeat('d',64);$run='run_core_'.integrationDeliveryHash([$body['organization_id'],$body['id']]);
    $receipt=['receipt_version'=>'2026-10-21.2','authority'=>$body,'state'=>'allocated','provider_usable'=>false,'payroll_created'=>true,
        'release_confirmed'=>false,'release_receipt_id'=>null,'allocation'=>['run_id'=>$run,'request_hash'=>$request,'input_id'=>$body['input_id'],'run_state'=>'draft','archived'=>false,'fence_id'=>null]];
    $byEmployee=[];foreach($packet['components']as$c)$byEmployee[$c['employee_id']][]=$c;
    $lines=[];$totals=array_fill_keys(PUREPAY_PAYROLL_RESULT_AMOUNTS,0);
    foreach($byEmployee as$eid=>$components) {
        $gross=0;$out=[];
        foreach($components as$c){$n=purepayPayrollResultComponent($c);$gross+=$n;$out[]=['input_component'=>$c,'gross'=>purepayPayrollResultAmount($n)];}
        $a=array_fill_keys(PUREPAY_PAYROLL_RESULT_AMOUNTS,'0.00');
        foreach(['source_gross','gross','net','funding_required']as$k)$a[$k]=purepayPayrollResultAmount($gross);
        foreach($a as$k=>$v)$totals[$k]+=purepayPayrollResultCents($v);
        $lines[]=['employee_id'=>$eid,'components'=>$out,'amounts'=>$a];
    }
    $r=['contract_version'=>PUREPAY_PAYROLL_RESULT_VERSION,'organization_id'=>$body['organization_id'],'authority_receipt'=>$receipt,
        'run_id'=>$run,'input_id'=>$body['input_id'],'content_hash'=>$body['content_hash'],'period_start'=>$packet['period_start'],'period_end'=>$packet['period_end'],
        'pay_date'=>$packet['period_end'],'status'=>'draft','archived'=>false,'execution_hold'=>true,'calculation_mode'=>'manual','currency'=>'USD',
        'amount_basis'=>'provider_payroll_ledger','delivery_confirmation'=>'not_evaluated','lines'=>$lines,'totals'=>array_map('purepayPayrollResultAmount',$totals)];
    return $r+['result_hash'=>integrationDeliveryHash($r),'observed_at'=>'2026-10-22T00:00:00+00:00'];
}
function payrollResultRehash(array $r): array
{
    $copy=$r;unset($copy['result_hash'],$copy['observed_at']);$r['result_hash']=integrationDeliveryHash($copy);return $r;
}
