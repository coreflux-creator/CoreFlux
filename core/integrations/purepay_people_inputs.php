<?php
/** Reviewed contact snapshots for inactive PurePayroll employee provisioning. No activation or pay setup. */
declare(strict_types=1);
require_once __DIR__.'/delivery_enablement.php';
require_once __DIR__.'/purepay_links.php';

const PUREPAY_PEOPLE_INPUT_VERSION = '2026-10-22.1';

/** Person workflows authorize people and placements only; legacy AP metadata grants no access. */
function purepayPeopleInputOwners(array $owners): array
{
    if (array_diff(array_keys($owners),['people','placements','ap'])) {
        throw new InvalidArgumentException('Unknown person directory scope.');
    }
    foreach (['people','placements'] as $module) {
        if (!is_int($owners[$module] ?? null) || $owners[$module]<=0) {
            throw new InvalidArgumentException('Authorized person and placement directories are required.');
        }
    }
    if (array_key_exists('ap',$owners) && (!is_int($owners['ap']) || $owners['ap']<=0)) {
        throw new InvalidArgumentException('Invalid legacy directory metadata.');
    }
    return ['people'=>$owners['people'],'placements'=>$owners['placements']];
}

function purepayPeopleInputEnabled(?int $tenant=null): bool
{
    return integrationDeliveryFlagEnabled('COREFLUX_PUREPAY_PEOPLE_DELIVERY') && integrationDeliveryTenantAllowed($tenant);
}

function purepayPeopleInputSource(PDO $pdo,string $instance,array $owners,string $personNumber,string $placementNumber): array
{
    $owners=purepayPeopleInputOwners($owners);
    foreach(['people','placements'] as $module) if(!is_int($owners[$module]??null)||$owners[$module]<=0)throw new InvalidArgumentException('Authorized directory owners required.');
    $person=purepayCoreLinkSource($pdo,$instance,$owners['people'],'person',$personNumber);
    $placement=purepayCoreLinkSource($pdo,$instance,$owners['placements'],'placement',$placementNumber);
    if((int)$placement['record']['person_id']!==$person['entity_id'] || $placement['record']['engagement_type']!=='w2'
        || !in_array($placement['record']['status'],['draft','pending_start','active'],true))throw new RuntimeException('Choose a current W-2 placement for this person. Other engagement types require their own onboarding workflow.');
    $s=$pdo->prepare('SELECT first_name,last_name,email_primary,phone_primary FROM people WHERE tenant_id=:t AND id=:i AND deleted_at IS NULL');
    $s->execute(['t'=>$owners['people'],'i'=>$person['entity_id']]);$contact=$s->fetch(PDO::FETCH_ASSOC);
    if(!$contact)throw new RuntimeException('This person is no longer available.');
    $first=trim((string)($contact['first_name']??''));$last=trim((string)($contact['last_name']??''));
    $email=trim((string)($contact['email_primary']??''));$phone=trim((string)($contact['phone_primary']??''));
    foreach([[$first,200],[$last,200],[$email,254],[$phone,40]] as [$value,$limit]) {
        $length=preg_match_all('/./us',$value);
        if($length===false || $length>$limit || preg_match('/[\x00-\x1f\x7f]/',$value))throw new RuntimeException('Review this person’s contact fields before sending.');
    }
    if($first.$last==='' || ($email!=='' && !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/uD',$email)))throw new RuntimeException('A name and valid optional email are required.');
    return ['person'=>$person,'placement'=>$placement,
        'body'=>['contract_version'=>PUREPAY_PEOPLE_INPUT_VERSION,'person'=>$person['reference'],
            'source_revision'=>integrationDeliveryHash([$person,$placement,$contact]),'employment_type'=>'w2',
            'first_name'=>$first,'last_name'=>$last,'email'=>$email===''?null:$email,'phone'=>$phone===''?null:$phone]];
}

function purepayPeopleInputAccess(PDO $pdo,int $tenant,string $instance,bool $write): array
{
    $access=purepayCoreLinkAccess($pdo,$tenant,$instance,'person',false);
    if(($access['review']['people_input_contract_version']??null)!==PUREPAY_PEOPLE_INPUT_VERSION
        || ($write && !in_array('write:people_inputs',$access['review']['capabilities'],true)))throw new RuntimeException('The provider needs the current people input contract and explicit people-review capability.');
    return $access;
}

function purepayPeopleInputSnapshot(PDO $pdo,int $tenant,string $instance,array $owners,string $personNumber,string $placementNumber): array
{
    // New person envelopes omit unrelated routing metadata. Historical payloads remain byte-exact.
    $owners=purepayPeopleInputOwners($owners);
    $access=purepayPeopleInputAccess($pdo,$tenant,$instance,true);
    if($pdo->inTransaction())throw new LogicException('Person review needs its own consistent read.');
    $pdo->beginTransaction();
    try{$source=purepayPeopleInputSource($pdo,$instance,$owners,$personNumber,$placementNumber);$pdo->commit();}
    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    $context=['provider'=>'purepayroll','connection_ref'=>(string)$access['connection']['id'],'binding_hash'=>$access['review']['review_hash'],
        'entity_type'=>'person','entity_tenant_id'=>$owners['people'],'entity_id'=>$source['person']['entity_id'],'operation'=>'people.inputs',
        'source_hash'=>integrationDeliveryHash($source),'remote_hash'=>integrationDeliveryHash($access['review'])];
    return ['access'=>$access,'source'=>$source,'context'=>$context,
        'payload'=>['owners'=>$owners,'organization_id'=>$access['review']['organization']['id'],
            'placement'=>$source['placement']['reference'],'body'=>$source['body']]];
}

function purepayPeopleInputPreview(PDO $pdo,int $tenant,int $actor,string $instance,array $owners,string $personNumber,string $placementNumber): array
{
    $snapshot=purepayPeopleInputSnapshot($pdo,$tenant,$instance,$owners,$personNumber,$placementNumber);
    $delivery=integrationDeliveryPreview($pdo,$tenant,$actor,$snapshot['context'],$snapshot['payload']);
    return ['packet'=>$snapshot['source']['body'],'placement'=>$snapshot['source']['placement']['reference'],
        'organization'=>$snapshot['access']['review']['organization'],'delivery_enabled'=>purepayPeopleInputEnabled($tenant),
        'delivery'=>integrationDeliverySummary($delivery)];
}

function purepayPeopleInputEnvelope(PDO $pdo,int $tenant,int $id,string $hash,string $instance,array $owners): array
{
    $owners=purepayPeopleInputOwners($owners);
    $row=integrationDeliveryGet($pdo,$tenant,$id);
    if($row['provider']!=='purepayroll' || $row['operation']!=='people.inputs' || $row['entity_type']!=='person'
        || !hash_equals($row['review_hash'],$hash))throw new InvalidArgumentException('Choose the current person review.');
    $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    if(purepayPeopleInputOwners($payload['owners'])!==$owners || $payload['body']['person']['instance_id']!==$instance
        || (int)$row['entity_tenant_id']!==$owners['people'])throw new RuntimeException('The person review belongs to different directory owners.');
    $person=purepayCoreLinkSource($pdo,$instance,$owners['people'],'person',$payload['body']['person']['number']);
    if($person['entity_id']!==(int)$row['entity_id'])throw new RuntimeException('The person identity changed.');
    return [$row,$payload];
}

function purepayPeopleInputReceipt(array $receipt,string $org,array $body): array
{
    $hash=integrationDeliveryHash($body);
    if(($receipt['id']??null)!=='cpp_'.integrationDeliveryHash([$org,$hash]) || ($receipt['organization_id']??null)!==$org
        || ($receipt['content_hash']??null)!==$hash)throw new RuntimeException('The receipt does not match the reviewed person snapshot.');
    return $receipt;
}

function purepayPeopleInputSend(PDO $pdo,int $tenant,int $actor,string $instance,array $owners,int $id,string $hash,?callable $audit=null): array
{
    if(!purepayPeopleInputEnabled($tenant))throw new RuntimeException('People input delivery is not enabled in this environment.');
    [$row,$payload]=purepayPeopleInputEnvelope($pdo,$tenant,$id,$hash,$instance,$owners);
    if($row['state']==='delivered')return integrationDeliverySummary($row);
    if(!in_array($row['state'],['preview','approved'],true))throw new RuntimeException('Check this attempt’s receipt before sending again.');
    $snapshot=purepayPeopleInputSnapshot($pdo,$tenant,$instance,$owners,$payload['body']['person']['number'],$payload['placement']['number']);
    if(integrationDeliveryHash($snapshot['payload'])!==integrationDeliveryHash($payload))throw new RuntimeException('The person or W-2 placement changed. Review a fresh snapshot.');
    if($row['state']==='preview')integrationDeliveryApprove($pdo,$tenant,$id,$actor,$hash);
    $claim=integrationDeliveryClaim($pdo,$tenant,$id,array_intersect_key($snapshot['context'],array_flip(['binding_hash','source_hash','remote_hash'])));
    try {
        purepayCoreLinkAccessUnchanged($pdo,$tenant,$snapshot['access']);
        $receipt=purepayCall($snapshot['access']['key'],'POST',PUREPAY_CORE_PATH.'/people/inputs',$payload['body'],25,integrationDeliveryHash($payload['body']),purepayCoreApiBase());
        $receipt=purepayPeopleInputReceipt($receipt,$payload['organization_id'],$payload['body']);
        return purepayCoreLinkComplete($pdo,$tenant,$row,['id'=>$receipt['id'],'provider_id'=>$receipt['id']],$claim['claim'],$audit);
    } catch(Throwable $e) {
        $live=integrationDeliveryGet($pdo,$tenant,$id);
        if($live['state']==='delivered')return integrationDeliverySummary($live);
        if($live['state']==='sending')integrationDeliveryFinish($pdo,$tenant,$id,$claim['claim'],'uncertain',null,'people_input_receipt_unconfirmed');
        return integrationDeliverySummary(integrationDeliveryGet($pdo,$tenant,$id));
    }
}

function purepayPeopleInputStatus(PDO $pdo,int $tenant,string $instance,array $owners,int $id,string $hash,?callable $audit=null): array
{
    [$row,$payload]=purepayPeopleInputEnvelope($pdo,$tenant,$id,$hash,$instance,$owners);
    if(!in_array($row['state'],['sending','uncertain','delivered'],true))throw new RuntimeException('This person snapshot has not been sent.');
    $access=purepayPeopleInputAccess($pdo,$tenant,$instance,false);
    if((string)$access['connection']['id']!==$row['connection_ref'] || $access['review']['organization']['id']!==$payload['organization_id'])throw new RuntimeException('This attempt belongs to a different provider connection.');
    $remoteId='cpp_'.integrationDeliveryHash([$payload['organization_id'],integrationDeliveryHash($payload['body'])]);
    $remote=purepayPeopleInputReceipt(purepayCoreGet($access['key'],'/people/inputs/'.$remoteId),$payload['organization_id'],$payload['body']);
    if(!isset($remote['packet']) || integrationDeliveryHash($remote['packet'])!==integrationDeliveryHash($payload['body'])
        || !in_array($remote['state']??null,['ready','recoverable','needs_review','created'],true))throw new RuntimeException('The provider returned an incompatible person review.');
    $employeeId=null;
    if($remote['state']==='created') {
        $employeeId='emp_core_'.integrationDeliveryHash([$payload['organization_id'],$payload['body']['person']]);
        if(($remote['employee_id']??null)!==$employeeId || !is_array($remote['existing_link']??null))throw new RuntimeException('The created employee does not match this Core person.');
        purepayCoreLinkReceipt($remote['existing_link'],$payload['organization_id'],['reference'=>$payload['body']['person'],'provider_id'=>$employeeId],false);
    }
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    $delivery=purepayCoreLinkComplete($pdo,$tenant,$row,['id'=>$remoteId,'provider_id'=>$remoteId],null,$audit);
    return ['delivery'=>$delivery,'provider_review'=>['state'=>$remote['state'],'employee_id'=>$employeeId]];
}

function purepayPeopleInputHistory(PDO $pdo,int $tenant,string $instance,array $owners,string $personNumber,?int $before=null): array
{
    $owners=purepayPeopleInputOwners($owners);
    $access=purepayPeopleInputAccess($pdo,$tenant,$instance,false);
    $person=purepayCoreLinkSource($pdo,$instance,$owners['people'],'person',$personNumber);
    if($before!==null && $before<=0)throw new InvalidArgumentException('Invalid history page.');
    $sql='SELECT * FROM integration_deliveries WHERE tenant_id=:t AND provider="purepayroll" AND connection_ref=:c AND operation="people.inputs" AND entity_type="person" AND entity_tenant_id=:o AND entity_id=:i';
    $params=['t'=>$tenant,'c'=>(string)$access['connection']['id'],'o'=>$owners['people'],'i'=>$person['entity_id']];
    if($before!==null){$sql.=' AND id<:before';$params['before']=$before;}
    $s=$pdo->prepare($sql.' ORDER BY id DESC LIMIT 26');$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC);$items=[];
    foreach(array_slice($rows,0,25) as $row) {
        [$row,$payload]=purepayPeopleInputEnvelope($pdo,$tenant,(int)$row['id'],$row['review_hash'],$instance,$owners);
        if($payload['organization_id']!==$access['review']['organization']['id'])throw new RuntimeException('This history belongs to a different provider company.');
        $items[]=['packet'=>$payload['body'],'placement'=>$payload['placement'],'organization'=>$access['review']['organization'],
            'delivery_enabled'=>purepayPeopleInputEnabled($tenant),'delivery'=>integrationDeliverySummary($row)];
    }
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    return ['items'=>$items,'next_before'=>count($rows)>25?(int)$rows[24]['id']:null];
}
