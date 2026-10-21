<?php
/** Optional real PHP client -> isolated provider ASGI app over a loopback-only bridge. */
declare(strict_types=1);
$origin=(string)getenv('COREFLUX_PUREPAY_FIXTURE_ORIGIN');
if(getenv('COREFLUX_PUREPAY_CONTRACT_FIXTURE')!=='1' || !preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{0,4}$~D',$origin))throw new RuntimeException('Explicit loopback contract fixture required');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/purepay_links.php';
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
echo json_encode(['passed'=>$passed,'scope'=>'synthetic Core PHP to PurePay ASGI HTTP contract']),"\n";
