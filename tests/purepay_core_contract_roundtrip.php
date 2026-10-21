<?php
/** Optional real PHP client -> isolated provider ASGI app over a loopback-only bridge. */
declare(strict_types=1);
$origin=(string)getenv('COREFLUX_PUREPAY_FIXTURE_ORIGIN');
if(getenv('COREFLUX_PUREPAY_CONTRACT_FIXTURE')!=='1' || !preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{0,4}$~D',$origin))throw new RuntimeException('Explicit loopback contract fixture required');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/purepay_links.php';
require_once __DIR__.'/../core/integrations/purepay_payroll_inputs.php';
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$body)use($origin) {
    $prefix='https://purepay.online/api/v1/integrations/coreflux/';
    if(!str_starts_with($url,$prefix))throw new RuntimeException('Unexpected provider route');
    $fixture=$origin.substr($url,strlen('https://purepay.online'));
    $options=['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body??'','ignore_errors'=>true,'follow_location'=>0,'timeout'=>10]];
    $raw=file_get_contents($fixture,false,stream_context_create($options));
    if($raw===false)throw new RuntimeException('Fixture transport failed');
    preg_match('/^HTTP\/\S+ ([0-9]{3})/',$http_response_header[0]??'',$match);
    return ['status'=>(int)($match[1]??0),'body'=>$raw];
};
$passed=0;$check=static function($ok,$label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed++;};
$key='pk_'.hash('sha256','full');$instance='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$context=purepayCoreContextValidate(purepayCoreGet($key,'/context'));
$check($context['organization']['id']==='org-a' && $context['link_contract_version']===PUREPAY_CORE_LINK_VERSION,'Authenticated company and current link protocol');
foreach([['person','P-9001','emp-a'],['placement','PL-9001','assignment-a'],['client','CL-9001','client-a'],['vendor','V-9001','vendor-a']]as[$kind,$number,$native]) {
    $record=purepayCoreRecordView($kind,purepayCoreGet($key,'/records/'.$kind.'/'.$native));
    $body=['reference'=>integrationRecordReference($instance,1,$kind,$number),'provider_id'=>$native,'expected_record_version'=>$record['record_version']];
    $check(purepayCoreLinkResolve($key,'org-a',$body,true)===null,'Both identities start unlinked');
    $result=purepayCall($key,'POST',PUREPAY_CORE_PATH.'/links',$body,25,str_repeat('a',64),purepayCoreApiBase());
    $receipt=purepayCoreLinkReceipt($result,'org-a',$body);
    $check(purepayCoreLinkResolve($key,'org-a',$body,true)===$receipt,'PHP validates provider receipt and both identity lookups');
    $again=purepayCall($key,'POST',PUREPAY_CORE_PATH.'/links',$body,25,str_repeat('a',64),purepayCoreApiBase());
    $check(purepayCoreLinkReceipt($again,'org-a',$body)===$receipt,'Replay returns original provider receipt');
}
$record=purepayCoreGet($key,'/records/person/emp-a2');
$bad=['reference'=>integrationRecordReference($instance,1,'person','P-9002'),'provider_id'=>'emp-a2','expected_record_version'=>str_repeat('0',64)];
try {purepayCall($key,'POST',PUREPAY_CORE_PATH.'/links',$bad,25,str_repeat('b',64),purepayCoreApiBase());$rejected=false;}
catch(PurePayApiException $e){$rejected=$e->httpStatus===409;}
$check($rejected,'Provider refuses stale reviewed version');
$bad['expected_record_version']=$record['record_version'];
try {purepayCall('pk_'.hash('sha256','reader'),'POST',PUREPAY_CORE_PATH.'/links',$bad,25,str_repeat('c',64),purepayCoreApiBase());$rejected=false;}
catch(PurePayApiException $e){$rejected=$e->httpStatus===403;}
$check($rejected,'Read-only provider key cannot link');
$check(purepayCoreLinkResolve('pk_'.hash('sha256','other'),'org-b',$body,true)===null,'Provider companies cannot see each other links');

$person=integrationRecordReference($instance,1,'person','P-9001');$placement=integrationRecordReference($instance,1,'placement','PL-9001');
$input=['contract_version'=>PUREPAY_PAYROLL_INPUT_VERSION,'instance_id'=>$instance,'time_tenant_id'=>77,'period_start'=>'2026-10-01','period_end'=>'2026-10-15','components'=>[[
    'person'=>$person,'placement'=>$placement,'employee_id'=>'emp-a','assignment_id'=>'assignment-a',
    'person_link_id'=>'cfl_'.integrationDeliveryHash(['org-a',$person]),'placement_link_id'=>'cfl_'.integrationDeliveryHash(['org-a',$placement]),
    'source'=>['time_entry_id'=>51,'revision'=>str_repeat('a',64),'work_date'=>'2026-10-02','category'=>'OT_billable','hours'=>'2.25',
        'source_system'=>'manual','source_entry_id'=>null,'source_component'=>null,'source_revision'=>null,'source_workspace_id'=>null],
    'rate'=>['snapshot_id'=>3,'revision'=>str_repeat('b',64),'pay_rate'=>'52.5000','pay_rate_unit'=>'hour','currency'=>'USD','multiplier'=>'1.50']]]];
$result=purepayCall($key,'POST',PUREPAY_CORE_PATH.'/payroll/inputs',$input,25,integrationDeliveryHash($input),purepayCoreApiBase());
$inputReceipt=purepayPayrollInputReceipt($result,'org-a',$input);
$check($inputReceipt['payroll_created']===false,'Real PHP payload and content hash accepted without payroll creation');
$again=purepayCall($key,'POST',PUREPAY_CORE_PATH.'/payroll/inputs',$input,25,integrationDeliveryHash($input),purepayCoreApiBase());
$check($again===$inputReceipt,'Identical PHP input replay returns original receipt');
$read=purepayCoreGet($key,'/payroll/inputs/'.$inputReceipt['id']);
$check(purepayPayrollInputReceipt($read,'org-a',$input)['id']===$inputReceipt['id'] && integrationDeliveryHash($read['packet'])===integrationDeliveryHash($input),'PHP reconciles stored provider packet and immutable source content');
try {purepayCall('pk_'.hash('sha256','reader'),'POST',PUREPAY_CORE_PATH.'/payroll/inputs',$input,25,integrationDeliveryHash($input),purepayCoreApiBase());$rejected=false;}
catch(PurePayApiException $e){$rejected=$e->httpStatus===403;}
$check($rejected,'Payroll read access cannot send input packets');
echo json_encode(['passed'=>$passed,'scope'=>'synthetic Core PHP to PurePay ASGI HTTP contract']),"\n";
