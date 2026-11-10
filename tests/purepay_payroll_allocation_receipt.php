<?php
/** Pure receipt validation; no database, browser, provider or financial writes. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')throw new RuntimeException('CLI fixture only');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/purepay_payroll_authority.php';
if(in_array('--verify-json-stdin',$argv,true)) {
    try {
        $input=json_decode(stream_get_contents(STDIN,65537),true,64,JSON_THROW_ON_ERROR);
        $result=purepayPayrollAuthorityReceipt($input['receipt'],$input['authority'],($input['require_released']??false)===true);
        echo json_encode($result,JSON_THROW_ON_ERROR);exit(0);
    } catch(Throwable $e) {fwrite(STDERR,$e->getMessage());exit(2);}
}
$passed=0;$check=static function(bool $ok,string $label)use(&$passed):void {if(!$ok)throw new RuntimeException($label);$passed++;};
$fails=static function(array $r,array $body,bool $released=false):bool {try{purepayPayrollAuthorityReceipt($r,$body,$released);return false;}catch(RuntimeException $e){return true;}};
$body=['contract_version'=>'2026-10-21.1','organization_id'=>'org-a','id'=>'cpa_'.str_repeat('a',64),
    'input_id'=>'cpi_'.str_repeat('b',64),'instance_id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','time_tenant_id'=>77,'content_hash'=>str_repeat('c',64),'components'=>1,'source_time_reserved'=>true];
$run='run_core_'.integrationDeliveryHash(['org-a',$body['id']]);$request=str_repeat('d',64);
$base=['receipt_version'=>'2026-10-21.2','authority'=>$body,'state'=>'allocating','provider_usable'=>false,'payroll_created'=>false,
    'release_confirmed'=>false,'release_receipt_id'=>null,'allocation'=>['run_id'=>$run,'request_hash'=>$request,'input_id'=>$body['input_id'],'run_state'=>'not_created','archived'=>false,'fence_id'=>null]];
$check(purepayPayrollAuthorityReceipt($base,$body)['state']==='allocating','preparing grant is readable without invented payroll');
$check($fails($base,$body,true),'preparing is not release evidence');
$allocated=$base;$allocated['state']='allocated';$allocated['payroll_created']=true;$allocated['allocation']['run_state']='draft';
$check(purepayPayrollAuthorityReceipt($allocated,$body)['payroll_created'],'allocated draft remains held');
$check($fails($allocated,$body,true),'draft receipt cannot free source');
$cancelled=$allocated;$cancelled['state']='released';$cancelled['release_confirmed']=true;$cancelled['allocation']['run_state']='void';
$fence='cpf_'.integrationDeliveryHash(['org-a',$body['id'],$run,$request,'cancelled']);$cancelled['allocation']['fence_id']=$fence;
$proof=['run_id'=>$run,'request_hash'=>$request,'input_id'=>$body['input_id'],'fence_id'=>$fence];
$cancelled['release_receipt_id']='cpr_'.integrationDeliveryHash(['org-a',$body['id'],integrationDeliveryHash($body),'released',$proof]);
$check(purepayPayrollAuthorityReceipt($cancelled,$body,true)['allocation']['fence_id']===$fence,'complete permanent cancellation is accepted');
$archived=$cancelled;$archived['allocation']['archived']=true;
$check(purepayPayrollAuthorityReceipt($archived,$body,true)['release_receipt_id']===$cancelled['release_receipt_id'],'archive presentation does not change permanent release identity');
foreach(['run_id'=>'run_core_'.str_repeat('0',64),'request_hash'=>'wrong','input_id'=>'cpi_'.str_repeat('0',64),
    'run_state'=>'draft','archived'=>1,'fence_id'=>null]as$field=>$value) {
    $bad=$cancelled;$bad['allocation'][$field]=$value;$check($fails($bad,$body,true),'invalid allocation field '.$field.' is rejected');
}
foreach(['receipt_version'=>'future','state'=>'unknown','payroll_created'=>false,'provider_usable'=>true,'release_confirmed'=>false,'release_receipt_id'=>'cpr_'.str_repeat('0',64)]as$field=>$value) {
    $bad=$cancelled;$bad[$field]=$value;$check($fails($bad,$body,true),'invalid cancellation field '.$field.' is rejected');
}
$bad=$cancelled;unset($bad['allocation']);$check($fails($bad,$body,true),'allocation cannot be stripped into a legacy receipt');
$bad=$cancelled;$bad['authority']['organization_id']='org-b';$check($fails($bad,$body,true),'another company receipt is rejected');
$bad=$base;$bad['payroll_created']=true;$check($fails($bad,$body),'absent run cannot be reported as created');
echo "PurePayroll allocation receipt: {$passed} passed\n";
