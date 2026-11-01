<?php
/** Customer identity and contact projection. No banking, QuickBooks or financial writes. */
declare(strict_types=1);
require_once __DIR__.'/identity.php';
require_once __DIR__.'/../purepay_adapter.php';

function purepayCustomerExternalIdentity(array $reference): array
{
    if (array_diff(array_keys($reference), ['instance_id','directory_tenant_id','entity_type','number'])
        || !is_string($reference['instance_id']??null) || !is_int($reference['directory_tenant_id']??null)
        || ($reference['entity_type']??null)!=='client' || !is_string($reference['number']??null)) {
        throw new InvalidArgumentException('A canonical Core client reference is required.');
    }
    $reference=integrationRecordReference($reference['instance_id'],$reference['directory_tenant_id'],'client',$reference['number']);
    return ['external_source'=>'coreflux','external_customer_id'=>implode(':',array_values($reference))];
}

function purepayCustomerContact(array $record): array
{
    $name=trim((string)($record['legal_name']??''));
    if($name==='')$name=trim((string)($record['name']??''));
    $email=trim((string)($record['primary_contact_email']??''));
    $phone=trim((string)($record['primary_contact_phone']??''));
    foreach([[$name,500],[$email,254],[$phone,40]] as [$value,$limit]) {
        $length=preg_match_all('/./us',$value);
        if($length===false || $length>$limit || preg_match('/[\x00-\x1f\x7f]/',$value)) {
            throw new RuntimeException('Review the client name and contact fields before sharing.');
        }
    }
    if($name==='' || ($email!==''&&!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/uD',$email))) {
        throw new RuntimeException('A client name and a valid optional contact email are required.');
    }
    return ['name'=>$name,'email'=>$email,'phone'=>$phone===''?null:$phone];
}

/** Restrict customer-specific errors to recognized codes; never expose provider text. */
function purepayCustomerErrorCode(PurePayApiException $error): string
{
    $code=$error->raw['detail']['code']??null;
    $statuses=[
        'customer_external_identity_not_found'=>404,
        'customer_external_identity_ambiguous'=>409,
        'customer_request_payload_conflict'=>409,
        'customer_legacy_request_key'=>409,
        'customer_request_in_progress'=>409,
        'customer_request_unresolved'=>409,
        'customer_external_identity_exists'=>409,
        'qbo_connection_changed'=>409,
        'customer_link_pending'=>409,
        'customer_link_unresolved'=>502,
    ];
    return is_string($code)&&isset($statuses[$code])&&$error->httpStatus===$statuses[$code]
        ?$code:'customer_response_unconfirmed';
}

/** A current exact lookup must identify this company and immutable external pair. */
function purepayCustomerRecord(array $record,string $organization,array $reference,?string $expectedId=null): array
{
    $identity=purepayCustomerExternalIdentity($reference);
    if(!is_string($record['id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$record['id'])
        || ($record['organization_id']??null)!==$organization
        || ($expectedId!==null && $record['id']!==$expectedId)
        || ($record['external_source']??null)!==$identity['external_source']
        || ($record['external_customer_id']??null)!==$identity['external_customer_id']) {
        throw new RuntimeException('The returned customer does not match the reviewed Core client and provider company.');
    }
    foreach(['name','email','phone'] as $field) {
        if(isset($record[$field])&&!is_string($record[$field]))throw new RuntimeException('The provider returned an invalid customer contact.');
    }
    if(!is_string($record['name']??null)||trim($record['name'])==='')throw new RuntimeException('The provider returned a customer without a name.');
    return array_intersect_key($record,array_flip(['id','organization_id','name','email','phone','external_source','external_customer_id']));
}
