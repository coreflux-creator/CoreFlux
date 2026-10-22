<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')throw new RuntimeException('CLI fixture only');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/purepay_payroll_results.php';
require_once __DIR__.'/purepay_payroll_result_fixture.php';
if(in_array('--verify-json-stdin',$argv,true)) {
    try {$in=json_decode(stream_get_contents(STDIN,1048577),true,64,JSON_THROW_ON_ERROR);echo json_encode(purepayPayrollResultValidate($in['result'],$in['authority'],$in['packet']),JSON_THROW_ON_ERROR);exit(0);}
    catch(Throwable $e){fwrite(STDERR,$e->getMessage());exit(2);}
}
$passed=0;$check=static function(bool $ok,string $label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed++;};
$fails=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}};
$instance='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$ref=static fn($kind,$number)=>['instance_id'=>$instance,'directory_tenant_id'=>7,'entity_type'=>$kind,'number'=>$number];
$c=['person'=>$ref('person','P-1'),'placement'=>$ref('placement','PL-2'),'employee_id'=>'emp-a','assignment_id'=>'assignment-a',
    'person_link_id'=>'cfl_'.str_repeat('a',64),'placement_link_id'=>'cfl_'.str_repeat('b',64),
    'source'=>['time_entry_id'=>1,'revision'=>str_repeat('c',64),'work_date'=>'2026-10-02','category'=>'OT_billable','hours'=>'2.25','source_system'=>'manual','source_entry_id'=>null,'source_component'=>null,'source_revision'=>null,'source_workspace_id'=>null],
    'rate'=>['snapshot_id'=>2,'revision'=>str_repeat('e',64),'pay_rate'=>'70.1250','pay_rate_unit'=>'hour','currency'=>'USD','multiplier'=>'1.75']];
$packet=['contract_version'=>'2026-10-21.1','instance_id'=>$instance,'time_tenant_id'=>1,'period_start'=>'2026-10-01','period_end'=>'2026-10-15','components'=>[$c]];
$hash=integrationDeliveryHash($packet);
$body=['contract_version'=>'2026-10-21.1','organization_id'=>'org-a','id'=>'cpa_'.str_repeat('a',64),'input_id'=>'cpi_'.integrationDeliveryHash(['org-a',$hash]),'instance_id'=>$instance,'time_tenant_id'=>1,'content_hash'=>$hash,'components'=>1,'source_time_reserved'=>true];
$r=payrollResultFixture($body,$packet);
$check(purepayPayrollResultComponent($c)===27612,'independent exact OT calculation is 276.12');
$half=$c;$half['rate']['pay_rate']='0.0100';$half['rate']['multiplier']='1.00';$half['source']['hours']='0.50';
$check(purepayPayrollResultComponent($half)===1,'half cent rounds up');
$huge=$c;$huge['source']['hours']='9999.99';$huge['rate']['pay_rate']='999999.9999';$huge['rate']['multiplier']='99.99';
$check($fails(fn()=>purepayPayrollResultComponent($huge)),'overflow is refused before integer multiplication');
$check(integrationDeliveryHash(purepayPayrollResultValidate($r,$body,$packet))===integrationDeliveryHash($r),'complete provider result validates');
foreach(['run_id'=>'wrong','input_id'=>'wrong','content_hash'=>str_repeat('0',64),'organization_id'=>'org-b','status'=>'paid','archived'=>true,
    'execution_hold'=>'false','calculation_mode'=>'unknown','currency'=>'CAD','delivery_confirmation'=>'settled','contract_version'=>'future','period_start'=>'2026-01-01','pay_date'=>'2026-02-31','amount_basis'=>'payment_confirmation']as$k=>$v) {
    $bad=$r;$bad[$k]=$v;$bad=payrollResultRehash($bad);$check($fails(fn()=>purepayPayrollResultValidate($bad,$body,$packet)),'invalid '.$k.' rejected even if rehashed');
}
foreach(['source_gross','gross','net','funding_required','garnishments']as$k){$bad=$r;$bad['lines'][0]['amounts'][$k]='1.00';$bad['totals'][$k]='1.00';$bad=payrollResultRehash($bad);$check($fails(fn()=>purepayPayrollResultValidate($bad,$body,$packet)),'unbalanced '.$k.' rejected');}
foreach([true,1,1.2,'NaN','1.001','-1.00','01.00']as$v)$check($fails(fn()=>purepayPayrollResultCents($v)),'noncanonical money refused');
$bad=$r;$bad['lines'][0]['components'][0]['input_component']['placement']['number']='PL-9';$bad=payrollResultRehash($bad);$check($fails(fn()=>purepayPayrollResultValidate($bad,$body,$packet)),'placement drift rejected');
$bad=$r;$bad['lines'][0]['components'][]=$bad['lines'][0]['components'][0];$bad=payrollResultRehash($bad);$check($fails(fn()=>purepayPayrollResultValidate($bad,$body,$packet)),'duplicate source rejected');
$bad=$r;$bad['lines'][0]['components']=[];$bad=payrollResultRehash($bad);$check($fails(fn()=>purepayPayrollResultValidate($bad,$body,$packet)),'missing source rejected');
$bad=$r;$bad['lines'][0]['bank_account']='private';$bad=payrollResultRehash($bad);$check($fails(fn()=>purepayPayrollResultValidate($bad,$body,$packet)),'unrequested sensitive fields rejected');
$bad=$r;unset($bad['lines'][0]['amounts']['sdi_pfl']);$bad=payrollResultRehash($bad);$check($fails(fn()=>purepayPayrollResultValidate($bad,$body,$packet)),'missing tax field is not silently zero');
$bad=$r;$bad['observed_at']='2026-02-31T00:00:00+00:00';$check($fails(fn()=>purepayPayrollResultValidate($bad,$body,$packet)),'invalid observation calendar date is rejected');
echo "PurePayroll result receipt: {$passed} passed\n";
