<?php
/** Optional real PHP client -> isolated provider ASGI app over a loopback-only bridge. */
declare(strict_types=1);
$origin=(string)getenv('COREFLUX_PUREPAY_FIXTURE_ORIGIN');
if(getenv('COREFLUX_PUREPAY_CONTRACT_FIXTURE')!=='1' || !preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{0,4}$~D',$origin))throw new RuntimeException('Explicit loopback contract fixture required');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/purepay_links.php';
require_once __DIR__.'/../core/integrations/purepay_payroll_inputs.php';
require_once __DIR__.'/../core/integrations/purepay_payroll_authority.php';
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
$authority=['contract_version'=>PUREPAY_PAYROLL_AUTHORITY_VERSION,'id'=>'cpa_'.str_repeat('d',64),'instance_id'=>$instance,
    'organization_id'=>'org-a','time_tenant_id'=>77,'input_id'=>$inputReceipt['id'],'content_hash'=>integrationDeliveryHash($input),'components'=>1,'source_time_reserved'=>true];
$authorityPost=static fn(string $path):array=>purepayCall($key,'POST',PUREPAY_CORE_PATH.$path,$authority,25,integrationDeliveryHash($authority),purepayCoreApiBase());
$receipt=purepayPayrollAuthorityReceipt($authorityPost('/payroll/authorities'),$authority);
$check($receipt['state']==='available' && !$receipt['payroll_created'],'PHP grant binds the exact provider input without creating payroll');
$check(purepayPayrollAuthorityReceipt($authorityPost('/payroll/authorities'),$authority)===$receipt,'Grant retry reuses the provider receipt');
$check(purepayPayrollAuthorityReceipt(purepayCoreGet($key,'/payroll/authorities/'.$authority['id']),$authority)===$receipt,'Grant receipt recovered with actual PHP client');
$cancelled=purepayPayrollAuthorityReceipt($authorityPost('/payroll/authorities/'.$authority['id'].'/release'),$authority,true);
$check($cancelled['release_confirmed'] && !$cancelled['provider_usable'],'PHP verifies deterministic permanent cancellation receipt');
$check(purepayPayrollAuthorityReceipt($authorityPost('/payroll/authorities'),$authority,true)===$cancelled,'A delayed grant remains cancelled');
$check(purepayPayrollAuthorityReceipt($authorityPost('/payroll/authorities/'.$authority['id'].'/release'),$authority,true)===$cancelled,'Repeated cancellation returns the original receipt');
try {purepayPayrollAuthorityReceipt($receipt,$authority,true);$rejected=false;}catch(RuntimeException $e){$rejected=true;}
$check($rejected,'An available receipt cannot unlock Core source');
try {purepayCall('pk_'.hash('sha256','reader'),'POST',PUREPAY_CORE_PATH.'/payroll/authorities',$authority,25,integrationDeliveryHash($authority),purepayCoreApiBase());$rejected=false;}
catch(PurePayApiException $e){$rejected=$e->httpStatus===403;}
$check($rejected,'Payroll read access cannot grant source authority');
require_once __DIR__.'/../core/integrations/purepay_people_inputs.php';
$context=purepayCoreContextValidate(purepayCoreGet($key,'/context'));
$check(($context['people_input_contract_version']??null)===PUREPAY_PEOPLE_INPUT_VERSION
    && in_array('write:people_inputs',$context['capabilities'],true),'PHP discovers the separate people-review capability');
$profile=['contract_version'=>PUREPAY_PEOPLE_INPUT_VERSION,'person'=>integrationRecordReference($instance,10,'person','P-9005'),
    'source_revision'=>str_repeat('d',64),'employment_type'=>'w2','first_name'=>'Zoë / 李','last_name'=>"O’Neil\u{2028}Test",
    'email'=>'worker/profile@example.test','phone'=>null];
$profilePost=static fn():array=>purepayCall($key,'POST',PUREPAY_CORE_PATH.'/people/inputs',$profile,25,integrationDeliveryHash($profile),purepayCoreApiBase());
$profileReceipt=purepayPeopleInputReceipt($profilePost(),'org-a',$profile);
$check(str_starts_with($profileReceipt['id'],'cpp_'),'Actual PHP and Python agree on Unicode/contact snapshot identity');
$check(purepayPeopleInputReceipt($profilePost(),'org-a',$profile)===$profileReceipt,'People input retries reuse the exact receipt');
$profileReview=purepayPeopleInputReceipt(purepayCoreGet($key,'/people/inputs/'.$profileReceipt['id']),'org-a',$profile);
$check($profileReview['packet']===$profile && $profileReview['state']==='ready' && $profileReview['employee_id']===null,'Receiving contacts preserves exact fields without creating a native employee');

require_once __DIR__.'/purepay_assignment_fixture.php';
require_once __DIR__.'/../core/integrations/purepay_assignment_inputs.php';
$check(($context['assignment_input_contract_version']??null)===PUREPAY_ASSIGNMENT_INPUT_VERSION
    && in_array('write:assignment_inputs',$context['capabilities'],true),'PHP discovers full commercial contract and separate write capability');
$commercial=purepayAssignmentFixtureBuild();
$commercialPost=static fn():array=>purepayCall($key,'POST',PUREPAY_CORE_PATH.'/assignments/inputs',$commercial,25,integrationDeliveryHash($commercial),purepayCoreApiBase());
$commercialReceipt=purepayAssignmentInputReceipt($commercialPost(),'org-a',$commercial);
$check(str_starts_with($commercialReceipt['id'],'cpa_'),'Actual PHP commercial projection accepted by provider with exact content hash');
$check(purepayAssignmentInputReceipt($commercialPost(),'org-a',$commercial)===$commercialReceipt,'Commercial retry returns identical receipt');
$commercialRead=purepayAssignmentInputReceipt(purepayCoreGet($key,'/assignments/inputs/'.$commercialReceipt['id']),'org-a',$commercial);
$check(integrationDeliveryHash($commercialRead['packet'])===integrationDeliveryHash($commercial)
    && $commercialRead['state']==='needs_mapping' && $commercialRead['assignment_id']===null,'Full graph read back intact; missing identities do not create an assignment');
try {purepayCall('pk_'.hash('sha256','reader'),'POST',PUREPAY_CORE_PATH.'/assignments/inputs',$commercial,25,integrationDeliveryHash($commercial),purepayCoreApiBase());$rejected=false;}
catch(PurePayApiException $e){$rejected=$e->httpStatus===403;}
$check($rejected,'Metadata read access cannot send commercial packets');
require_once __DIR__.'/../core/integrations/purepay_people_setup.php';
$check(($context['people_setup_contract_version']??null)===PUREPAY_PEOPLE_SETUP_VERSION
    && in_array('read:people_setup',$context['capabilities'],true),'PHP discovers the explicit setup-read contract');
$setupLink=['id'=>'cfl_'.integrationDeliveryHash(['org-a',$person]),'provider_id'=>'emp-a'];
$query=$person;unset($query['entity_type']);$query['provider_id']='emp-a';
$setup=purepayPeopleSetupReceipt(purepayCoreGet($key,'/people/setup?'.http_build_query($query)),'org-a',$person,$setupLink);
$check($setup['provider_id']==='emp-a' && $setup['indicators']['employee_status']==='inactive','Actual PHP validates the scoped provider setup observation');
$check($setup['onboarding_completion']==='not_evaluated' && $setup['payroll_authorization']==='not_evaluated','Setup presence never conveys onboarding completion or payroll approval');
try {purepayCoreGet('pk_'.hash('sha256','reader'),'/people/setup?'.http_build_query($query));$rejected=false;}
catch(RuntimeException $e){$rejected=true;}
$check($rejected,'Existing payroll read keys cannot access the new setup indicators');
echo json_encode(['passed'=>$passed,'scope'=>'synthetic Core PHP to PurePay ASGI HTTP contract']),"\n";
