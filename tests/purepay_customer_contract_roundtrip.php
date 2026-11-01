<?php
/** Real Core customer consumer against only a disposable loopback provider app. */
declare(strict_types=1);
$origin=(string)getenv('COREFLUX_PUREPAY_FIXTURE_ORIGIN');
if(getenv('COREFLUX_PUREPAY_CONTRACT_FIXTURE')!=='1'||!preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{0,4}$~D',$origin))throw new RuntimeException('Explicit loopback contract fixture required');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/purepay_customer_inputs.php';
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$body)use($origin){
    if(!str_starts_with($url,'https://purepay.online/api/v1/integrations/coreflux/'))throw new RuntimeException('Unexpected provider route');
    $options=['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body??'','ignore_errors'=>true,'follow_location'=>0,'timeout'=>10]];
    $raw=file_get_contents($origin.substr($url,strlen('https://purepay.online')),false,stream_context_create($options));
    if($raw===false)throw new RuntimeException('Fixture transport failed');
    preg_match('/^HTTP\/\S+ ([0-9]{3})/',$http_response_header[0]??'',$match);
    return ['status'=>(int)($match[1]??0),'body'=>$raw];
};
$passed=0;$check=static function($ok,$label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed++;};
$key='pk_'.hash('sha256','full');$instance='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$context=purepayCoreContextValidate(purepayCoreGet($key,'/context'));
$check(($context['customer_input_contract_version']??null)===PUREPAY_CUSTOMER_INPUT_VERSION,'Scoped version advertised');
$check(in_array('write:customer_inputs',$context['capabilities'],true),'Separate customer capability');
$access=['key'=>$key,'review'=>['organization'=>['id'=>'org-a']]];
$ref=integrationRecordReference($instance,999,'client','CL-91');
$check(purepayCustomerLookup($access,$ref)===null,'Exact missing reference');
$body=['contract_version'=>PUREPAY_CUSTOMER_INPUT_VERSION,'reference'=>$ref]+purepayCustomerContact([
    'name'=>"Zoë / 李 O’Neil",'primary_contact_email'=>'client@example.invalid','primary_contact_phone'=>'555-0100']);
$post=static fn($payload,$request)=>purepayCall($key,'POST',PUREPAY_CORE_PATH.'/customers',$payload,25,$request,purepayCoreApiBase());
$created=$post($body,'php-customer-original');$result=purepayCustomerInputReply($created,$access,$ref);
$check($result['customer']['name']===$body['name'],'Unicode contact survives actual PHP and Python');
$check($result['identity_basis']==='external_identity','Created customer has immutable external identity');
$check(purepayCustomerLookup($access,$ref)===$result,'Current lookup agrees with original result');
$check($post($body,'php-customer-original')===$created,'Original request replay preserves response');
try{$post(array_replace($body,['name'=>'Different']),'php-customer-original');$rejected=false;}
catch(PurePayApiException$e){$rejected=purepayCustomerErrorCode($e)==='customer_request_payload_conflict';}
$check($rejected,'Customer error code recognized by Core');
$other=['key'=>'pk_'.hash('sha256','other'),'review'=>['organization'=>['id'=>'org-b']]];
$check(purepayCustomerLookup($other,$ref)===null,'Other company cannot read identity');
$record=purepayCoreRecordView('client',purepayCoreGet($key,'/records/client/'.$result['customer']['id']));
$link=['reference'=>$ref,'provider_id'=>$record['id'],'expected_record_version'=>$record['record_version']];
$receipt=purepayCall($key,'POST',PUREPAY_CORE_PATH.'/links',$link,25,'php-customer-link',purepayCoreApiBase());
$check(purepayCoreLinkReceipt($receipt,'org-a',$link)['provider_id']===$record['id'],'Normal reviewed link accepts created customer');
$linked=purepayCustomerLookup($access,$ref);
$check($linked['identity_basis']==='core_link'&&$linked['customer']['id']===$record['id'],'Fresh lookup now validates both sides of link');
$legacyRef=integrationRecordReference($instance,999,'client','CL-92');
$legacy=purepayCustomerLookup($access,$legacyRef);
$check($legacy['identity_basis']==='core_link'&&$legacy['customer']['id']==='php-legacy','Legacy link preserves original provider ID');
$check(!isset($legacy['customer']['external_customer_id']),'No external ID invented for legacy customer');
echo json_encode(['passed'=>$passed,'local_provider_only'=>true,'database_used_by_php'=>false],JSON_THROW_ON_ERROR)."\n";
