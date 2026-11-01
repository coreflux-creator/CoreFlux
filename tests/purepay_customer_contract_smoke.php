<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/purepay_customer_contract.php';
require_once __DIR__.'/fixtures/staffing_dictionary_checks.php';
require_once __DIR__.'/../core/integrations/staffing_exchange_fields.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$reject=static function(callable $call)use($check):void{try{$call();}catch(InvalidArgumentException|RuntimeException $e){$check(true,'rejected');return;}throw new RuntimeException('Unsafe input accepted');};
$ref=['instance_id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','directory_tenant_id'=>10,'entity_type'=>'client','number'=>'CL-12'];
$identity=purepayCustomerExternalIdentity($ref);
$check($identity===['external_source'=>'coreflux','external_customer_id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa:10:client:CL-12'],'Exact installation/directory/type/number identity');
foreach(['directory_tenant_id'=>11,'instance_id'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','number'=>'CL-13'] as $field=>$value) {
    $check(purepayCustomerExternalIdentity(array_replace($ref,[$field=>$value]))!==$identity,'Independent identity namespaces');
}
foreach([['directory_tenant_id'=>'10'],['directory_tenant_id'=>0],['entity_type'=>'company'],['number'=>'CO-12'],['number'=>'CL-012'],['id'=>12]] as $change)$reject(fn()=>purepayCustomerExternalIdentity(array_replace($ref,$change)));
$contact=purepayCustomerContact(['name'=>' Display ','legal_name'=>' Legal LLC ','primary_contact_email'=>' client@example.test ','primary_contact_phone'=>' 555-0100 ','bank_account'=>'sensitive','qbo_customer_id'=>'42','id'=>12]);
$check($contact===['name'=>'Legal LLC','email'=>'client@example.test','phone'=>'555-0100'],'Only reviewed identity/contact fields leave Core');
staffingDictionaryAssertPayload('purepay_customer_input','purepay',
    ['contract_version'=>'2026-10-26.1','reference'=>$ref]+$contact);
$check(true,'Dictionary covers the actual customer contact projection and typed identity');
foreach (['legal_name'=>'name','name'=>'name','primary_contact_email'=>'email','primary_contact_phone'=>'phone','client_number'=>'reference'] as $column=>$path) {
    $mappings=staffingExchangeFieldMappings()['staffing_clients.'.$column]['purepay']??[];
    $customerMappings=array_values(array_filter($mappings,static fn($mapping)=>$mapping['route']==='/api/v1/integrations/coreflux/customers'));
    $check(count($customerMappings)===1 && $customerMappings[0]['path']===$path,'Actual customer source field has one explicit creation mapping');
}
$reject(fn()=>staffingDictionaryAssertPayload('purepay_customer_input','purepay',
    ['contract_version'=>'2026-10-26.1','reference'=>$ref]+$contact+['bank_account'=>'forbidden']));
$check(purepayCustomerContact(['name'=>'Display'])===['name'=>'Display','email'=>'','phone'=>null],'Optional contact fields do not create invented values');
foreach([['name'=>''],['name'=>'Valid','primary_contact_email'=>'bad'],['name'=>"Bad\nHeader"],['name'=>str_repeat('a',501)],['name'=>'Valid','primary_contact_phone'=>str_repeat('1',41)]] as $bad)$reject(fn()=>purepayCustomerContact($bad));
$record=['id'=>'cus_existing','organization_id'=>'org-a','name'=>'Current name','email'=>'current@example.test']+$identity;
$record['bank']=['account_number'=>'sensitive'];$record['qbo_customer_id']='42';
$safe=purepayCustomerRecord($record,'org-a',$ref,'cus_existing');
$check(!isset($safe['bank'])&&!isset($safe['qbo_customer_id'])&&$safe['name']==='Current name','Current customer is projected without banking or QuickBooks fields');
foreach([['id'=>'wrong'],['organization_id'=>'org-b'],['external_customer_id'=>'CL-12'],['external_source'=>'other'],['name'=>[]]] as $change)$reject(fn()=>purepayCustomerRecord(array_replace($record,$change),'org-a',$ref,'cus_existing'));
foreach([['customer_request_unresolved',409],['customer_external_identity_not_found',404],['customer_external_identity_exists',409],['customer_link_unresolved',502]] as [$code,$status]) {
    $e=new PurePayApiException('secret text');$e->httpStatus=$status;$e->raw=['detail'=>['code'=>$code,'message'=>'secret']];$e->outcomeUncertain=true;
    $check(purepayCustomerErrorCode($e)===$code && $e->outcomeUncertain,'Customer error decoding preserves generic outcome uncertainty');
    $e->httpStatus=500;$check(purepayCustomerErrorCode($e)==='customer_response_unconfirmed','Wrong-status code is never trusted');
}
$e=new PurePayApiException('secret');$e->httpStatus=409;$e->raw=['detail'=>'unexpected private text'];
$check(purepayCustomerErrorCode($e)==='customer_response_unconfirmed','Unknown provider text is not returned');
echo "PASS {$checks} PurePay customer identity and recovery contract checks\n";
