<?php
/** Reviewed local customer creation using the dedicated Core connection. */
declare(strict_types=1);
require_once __DIR__.'/purepay_links.php';
require_once __DIR__.'/purepay_customer_contract.php';

const PUREPAY_CUSTOMER_INPUT_VERSION='2026-10-26.1';

function purepayCustomerInputEnabled(): bool
{
    return defined('COREFLUX_PUREPAY_CUSTOMER_DELIVERY') ? constant('COREFLUX_PUREPAY_CUSTOMER_DELIVERY')===true : getenv('COREFLUX_PUREPAY_CUSTOMER_DELIVERY')==='1';
}

function purepayCustomerInputSource(PDO $pdo,string $instance,int $owner,string $number): array
{
    $source=purepayCoreLinkSource($pdo,$instance,$owner,'client',$number);
    $s=$pdo->prepare('SELECT name,legal_name,primary_contact_email,primary_contact_phone,status FROM staffing_clients WHERE tenant_id=:t AND id=:i');
    $s->execute(['t'=>$owner,'i'=>$source['entity_id']]);$record=$s->fetch(PDO::FETCH_ASSOC);
    if(!$record)throw new RuntimeException('The Core client is no longer available.');
    return ['source'=>$source,'contact'=>purepayCustomerContact($record),'status'=>$record['status']];
}

function purepayCustomerInputAccess(PDO $pdo,int $tenant,string $instance,bool $write): array
{
    $access=purepayCoreLinkAccess($pdo,$tenant,$instance,'client',false);
    if(($access['review']['customer_input_contract_version']??null)!==PUREPAY_CUSTOMER_INPUT_VERSION) {
        throw new RuntimeException('This provider needs the current Core customer connection update.');
    }
    if($write&&!in_array('write:customer_inputs',$access['review']['capabilities'],true)) {
        throw new RuntimeException('The dedicated Core key needs permission to create customer profiles.');
    }
    return $access;
}

/** Check the existing immutable Core link ledger before considering a new customer. */
function purepayCustomerExistingLink(PDO $pdo,int $tenant,array $access,array $source): ?array
{
    $s=$pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND provider="purepay" AND connection_ref=:c
        AND entity_type="client" AND entity_tenant_id=:o AND entity_id=:i AND operation="record.link"
        AND state IN ("delivered","sending","uncertain") ORDER BY id LIMIT 2');
    $s->execute(['t'=>$tenant,'c'=>(string)$access['connection']['id'],'o'=>$source['entity_tenant_id'],'i'=>$source['entity_id']]);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows)return null;
    if(count($rows)!==1||$rows[0]['state']!=='delivered')throw new RuntimeException('Reconcile the existing Core client link before creating a customer.');
    $row=$rows[0];$payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    if(($payload['organization_id']??null)!==$access['review']['organization']['id']
        || integrationDeliveryHash($payload['body']['reference']??[])!==integrationDeliveryHash($source['reference'])
        || ($payload['body']['provider_id']??null)!==$row['remote_id'])throw new RuntimeException('The existing client link has conflicting identity evidence.');
    $receipt=purepayCoreLinkResolve($access['key'],$payload['organization_id'],$payload['body'],false);
    if(!$receipt)throw new RuntimeException('The provider no longer confirms the existing client link. Reconcile that link first.');
    $record=purepayCoreRecordView('client',purepayCoreGet($access['key'],'/records/client/'.rawurlencode($row['remote_id'])));
    if($record['id']!==$row['remote_id'])throw new RuntimeException('The provider returned a different customer for the saved link.');
    return $record;
}

/** Dedicated Core routes never fall back to the separate AP credential. */
function purepayCustomerLookup(array $access,array $reference): ?array
{
    try {
        $reply=purepayCall($access['key'],'GET',PUREPAY_CORE_PATH.'/customers/by-reference?'.http_build_query($reference),[],25,null,purepayCoreApiBase());
    }catch(PurePayApiException $e){
        if(purepayCustomerErrorCode($e)==='customer_external_identity_not_found')return null;
        throw new RuntimeException('The provider could not establish this client identity. Check its current connection and existing mappings.');
    }
    return purepayCustomerInputReply($reply,$access,$reference);
}

function purepayCustomerInputReply(array $reply,array $access,array $reference): array
{
    $org=$access['review']['organization']['id'];
    if(($reply['contract_version']??null)!==PUREPAY_CUSTOMER_INPUT_VERSION || ($reply['organization_id']??null)!==$org
        || !is_array($reply['reference']??null) || integrationDeliveryHash($reply['reference'])!==integrationDeliveryHash($reference)
        || !is_array($reply['customer']??null) || !in_array($reply['identity_basis']??null,['core_link','external_identity'],true)) {
        throw new RuntimeException('The provider returned an incompatible customer identity.');
    }
    $customer=$reply['customer'];
    if($reply['identity_basis']==='external_identity')$customer=purepayCustomerRecord($customer,$org,$reference);
    else {
        // A pre-existing link may predate external IDs; verify both sides of its receipt.
        if(($customer['organization_id']??null)!==$org)throw new RuntimeException('The customer belongs to a different provider company.');
        if(($customer['external_source']??null)!==null||($customer['external_customer_id']??null)!==null) {
            purepayCustomerRecord($customer,$org,$reference);
        }
        $customer=purepayCoreRecordView('client',$customer);
        $receipt=purepayCoreLinkResolve($access['key'],$org,['reference'=>$reference,'provider_id'=>$customer['id']],false);
        if(!$receipt)throw new RuntimeException('The provider has not confirmed both sides of the existing client link.');
    }
    return ['customer'=>$customer,'identity_basis'=>$reply['identity_basis']];
}

function purepayCustomerInputSnapshot(PDO $pdo,int $tenant,string $instance,int $owner,string $number): array
{
    $input=purepayCustomerInputSource($pdo,$instance,$owner,$number);
    $access=purepayCustomerInputAccess($pdo,$tenant,$instance,false);
    $linked=purepayCustomerExistingLink($pdo,$tenant,$access,$input['source']);
    $remote=purepayCustomerLookup($access,$input['source']['reference']);
    if($linked&&(!$remote||$remote['customer']['id']!==$linked['id']||$remote['identity_basis']!=='core_link')) {
        throw new RuntimeException('The current provider customer disagrees with the saved Core link. Reconcile the existing identities.');
    }
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    if(integrationDeliveryHash($input)!==integrationDeliveryHash(purepayCustomerInputSource($pdo,$instance,$owner,$number)))throw new RuntimeException('The client changed during review. Review it again.');
    $body=['contract_version'=>PUREPAY_CUSTOMER_INPUT_VERSION,'reference'=>$input['source']['reference']]+$input['contact'];
    $context=['provider'=>'purepay','connection_ref'=>(string)$access['connection']['id'],'binding_hash'=>$access['review']['review_hash'],
        'entity_type'=>'client','entity_tenant_id'=>$owner,'entity_id'=>$input['source']['entity_id'],'operation'=>'customer.create',
        'source_hash'=>integrationDeliveryHash($input),'remote_hash'=>integrationDeliveryHash($remote??[])];
    return ['access'=>$access,'input'=>$input,'remote'=>$remote,'context'=>$context,'payload'=>['organization_id'=>$access['review']['organization']['id'],'body'=>$body]];
}

function purepayCustomerInputPreview(PDO $pdo,int $tenant,int $actor,string $instance,int $owner,string $number): array
{
    $snapshot=purepayCustomerInputSnapshot($pdo,$tenant,$instance,$owner,$number);
    $source=$snapshot['input']['source'];$context=$snapshot['context'];
    $s=$pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND provider="purepay" AND connection_ref=:c AND entity_type="client"
        AND entity_tenant_id=:o AND entity_id=:i AND operation="customer.create" AND attempted_at IS NOT NULL ORDER BY id DESC LIMIT 1');
    $s->execute(['t'=>$tenant,'c'=>$context['connection_ref'],'o'=>$owner,'i'=>$source['entity_id']]);$row=$s->fetch(PDO::FETCH_ASSOC)?:null;
    if($row&&$row['remote_id']!==null&&(!$snapshot['remote']||$snapshot['remote']['customer']['id']!==$row['remote_id'])) {
        throw new RuntimeException('The current customer identity differs from the original delivery. Reconcile the existing customer first.');
    }
    if(!$row&&!$snapshot['remote']) {
        if(!in_array($snapshot['input']['status'],['active','prospect'],true))throw new RuntimeException('Only an active client or prospect can be shared as a new customer.');
        $row=integrationDeliveryPreview($pdo,$tenant,$actor,$context,$snapshot['payload']);
    }
    return ['source'=>$source,'contact'=>$snapshot['input']['contact'],'organization'=>$snapshot['access']['review']['organization'],
        'existing_customer'=>$snapshot['remote'],'delivery'=>$row?integrationDeliverySummary($row):null,
        'delivery_enabled'=>purepayCustomerInputEnabled(),'can_create'=>in_array('write:customer_inputs',$snapshot['access']['review']['capabilities'],true)];
}

function purepayCustomerInputEnvelope(PDO $pdo,int $tenant,string $instance,int $owner,int $id,string $hash): array
{
    $row=integrationDeliveryGet($pdo,$tenant,$id);
    if($row['provider']!=='purepay'||$row['operation']!=='customer.create'||$row['entity_type']!=='client'
        ||(int)$row['entity_tenant_id']!==$owner||!hash_equals($row['review_hash'],$hash))throw new InvalidArgumentException('Choose this company’s reviewed client.');
    $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    $reference=$payload['body']['reference'];purepayCustomerExternalIdentity($reference);
    if($reference['instance_id']!==$instance||$reference['directory_tenant_id']!==$owner)throw new RuntimeException('The client directory changed. Reconcile its original ownership.');
    $source=purepayCoreLinkSource($pdo,$instance,$owner,'client',$reference['number']);
    if($source['entity_id']!==(int)$row['entity_id'])throw new RuntimeException('The Core client identity changed.');
    return [$row,$payload,$source];
}

function purepayCustomerInputSend(PDO $pdo,int $tenant,int $actor,string $instance,int $owner,int $id,string $hash,?callable $audit=null): array
{
    if(!purepayCustomerInputEnabled())throw new RuntimeException('Customer sharing has not been enabled for this environment.');
    [$row,$payload,$source]=purepayCustomerInputEnvelope($pdo,$tenant,$instance,$owner,$id,$hash);
    if($row['state']==='delivered')return integrationDeliverySummary($row);
    if(!in_array($row['state'],['preview','approved'],true))throw new RuntimeException('Check the original customer attempt before making another change.');
    $snapshot=purepayCustomerInputSnapshot($pdo,$tenant,$instance,$owner,$source['reference']['number']);
    purepayCustomerInputAccess($pdo,$tenant,$instance,true);
    if($snapshot['remote'])throw new RuntimeException('A provider customer now exists. Review that customer instead of creating another.');
    if($row['state']==='preview')integrationDeliveryApprove($pdo,$tenant,$id,$actor,$hash);
    $claim=integrationDeliveryClaim($pdo,$tenant,$id,array_intersect_key($snapshot['context'],array_flip(['binding_hash','source_hash','remote_hash'])));
    try{
        purepayCoreLinkAccessUnchanged($pdo,$tenant,$snapshot['access']);
        $reply=purepayCall($snapshot['access']['key'],'POST',PUREPAY_CORE_PATH.'/customers',$claim['payload']['body'],25,$claim['idempotency_key'],purepayCoreApiBase());
        $received=purepayCustomerInputReply($reply,$snapshot['access'],$source['reference']);
        // Replay responses are snapshots. Verify the current authoritative identity separately.
        $current=purepayCustomerLookup($snapshot['access'],$source['reference']);
        if(!$current||$current['customer']['id']!==$received['customer']['id'])throw new RuntimeException('The customer outcome is not yet confirmed.');
        purepayCoreLinkAccessUnchanged($pdo,$tenant,$snapshot['access']);
        return purepayCoreLinkComplete($pdo,$tenant,$row,['id'=>$current['customer']['id'],'provider_id'=>$current['customer']['id']],$claim['claim'],$audit);
    }catch(Throwable $e){
        $live=integrationDeliveryGet($pdo,$tenant,$id);
        if($live['state']==='delivered')return integrationDeliverySummary($live);
        if($live['state']==='sending')integrationDeliveryFinish($pdo,$tenant,$id,$claim['claim'],'uncertain',null,$e instanceof PurePayApiException?purepayCustomerErrorCode($e):'customer_response_unconfirmed');
        return integrationDeliverySummary(integrationDeliveryGet($pdo,$tenant,$id));
    }
}

function purepayCustomerInputReconcile(PDO $pdo,int $tenant,string $instance,int $owner,int $id,string $hash,?callable $audit=null): array
{
    [$row,$payload,$source]=purepayCustomerInputEnvelope($pdo,$tenant,$instance,$owner,$id,$hash);
    $access=purepayCustomerInputAccess($pdo,$tenant,$instance,false);
    if($row['connection_ref']!==(string)$access['connection']['id']||$payload['organization_id']!==$access['review']['organization']['id'])throw new RuntimeException('The customer attempt belongs to a different provider connection.');
    if(!in_array($row['state'],['sending','uncertain','delivered'],true))throw new RuntimeException('Only an attempted customer delivery can be reconciled.');
    $remote=purepayCustomerLookup($access,$source['reference']);
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    if(!$remote)throw new RuntimeException('No matching customer is visible yet. The original request may still complete; its attempt remains held.');
    $delivery=purepayCoreLinkComplete($pdo,$tenant,$row,['id'=>$remote['customer']['id'],'provider_id'=>$remote['customer']['id']],null,$audit);
    return ['delivery'=>$delivery,'existing_customer'=>$remote];
}
