<?php
/** Reviewed identity links only. Delivered envelopes are the immutable local mapping ledger. */
declare(strict_types=1);
require_once __DIR__.'/delivery_enablement.php';
require_once __DIR__.'/purepay_core.php';
require_once __DIR__.'/../record_numbers.php';

function purepayCoreLinkEnabled(?int $tenant=null): bool
{
    return integrationDeliveryFlagEnabled('COREFLUX_PUREPAY_CORE_LINK_DELIVERY') && integrationDeliveryTenantAllowed($tenant);
}

/** The API resolves the owner using module scope; the client can supply neither owner nor number assignment. */
function purepayCoreLinkSource(PDO $pdo,string $instance,int $owner,string $kind,string $number): array
{
    integrationRecordReference($instance,$owner,$kind,$number);
    $columns=match($kind) {
        'person'=>"r.id,CONCAT_WS(' ',r.first_name,r.last_name) AS name,r.email_primary AS email,r.status",
        'placement'=>'r.id,r.title AS name,r.person_id,r.client_id,r.engagement_type,r.status,r.start_date,COALESCE(r.actual_end_date,r.end_date) AS end_date',
        'client'=>'r.id,r.name,r.legal_name,r.primary_contact_email AS email,r.status',
        'vendor'=>'r.id,r.vendor_name AS name,r.vendor_type',
        default=>throw new InvalidArgumentException('Choose a supported record type.'),
    };
    $definition=coreRecordNumberDefinition($kind);
    $deleted=in_array($kind,['person','placement'],true)?' AND r.deleted_at IS NULL':'';
    $stmt=$pdo->prepare('SELECT '.$columns.' FROM '.$definition['table'].' r JOIN core_record_numbers n ON n.tenant_id=r.tenant_id AND n.record_id=r.id AND n.canonical_record_id=r.id
        WHERE r.tenant_id=:t AND n.entity_type=:kind AND n.record_number=:number'.$deleted.' LIMIT 2');
    $stmt->execute(['t'=>$owner,'kind'=>$kind,'number'=>$number]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1)throw new RuntimeException('This current Core number is unavailable in the authorized directory. Review merged or missing records first.');
    return ['entity_tenant_id'=>$owner,'entity_id'=>(int)$rows[0]['id'],
        'reference'=>integrationRecordReference($instance,$owner,$kind,$number),'record'=>$rows[0]];
}

/** Read a fresh company contract and ensure the credential did not change while it was read. */
function purepayCoreLinkAccess(PDO $pdo,int $tenant,string $instance,string $kind,bool $write): array
{
    $review=purepayCoreReview($pdo,$tenant,$instance);
    if(!$review['verified'] || $review['link_contract_version']!==PUREPAY_CORE_LINK_VERSION)throw new RuntimeException('Confirm the provider company with the current record-link contract first.');
    if($write && !in_array('write:coreflux',$review['capabilities'],true))throw new RuntimeException('The dedicated Core key needs the link existing records capability.');
    if($kind==='person' && !in_array('read:payroll',$review['capabilities'],true))throw new RuntimeException('Employee links require the read payroll capability.');
    $connection=purepayCoreConnection($pdo,$tenant);
    if(!$connection || $connection['status']!=='active')throw new RuntimeException('Restore and review the Core connection.');
    $key=(string)decryptField($connection['api_key_ct']);
    if(!hash_equals($review['review_hash'],purepayCoreHash($connection,$key,$instance,$review+['execution_side_effects'=>false])))throw new RuntimeException('The connection changed. Check it again.');
    return ['review'=>$review,'connection'=>$connection,'key'=>$key];
}

function purepayCoreLinkAccessUnchanged(PDO $pdo,int $tenant,array $access): void
{
    $live=purepayCoreConnection($pdo,$tenant);
    if(!$live || $live['status']!=='active' || $live['revision']!==$access['connection']['revision'] || $live['binding_hash']!==$access['connection']['binding_hash'])throw new RuntimeException('The connection changed during the read. Review it again.');
}

function purepayCoreLinkReceipt(array $receipt,string $org,array $body,bool $checkVersion=true): array
{
    if(!is_string($receipt['id']??null) || !preg_match('/^cfl_[a-f0-9]{64}$/D',$receipt['id'])
        || $receipt['id']!=='cfl_'.integrationDeliveryHash([$org,$body['reference']])
        || ($receipt['organization_id']??null)!==$org || ($receipt['provider_id']??null)!==$body['provider_id']
        || !is_array($receipt['reference']??null) || integrationDeliveryHash($receipt['reference'])!==integrationDeliveryHash($body['reference'])
        || !is_string($receipt['provider_record_version']??null) || !preg_match('/^[a-f0-9]{64}$/D',$receipt['provider_record_version'])
        || ($checkVersion && $receipt['provider_record_version']!==$body['expected_record_version'])) {
        throw new RuntimeException('The provider link does not match the reviewed identity and version.');
    }
    return array_intersect_key($receipt,array_flip(['id','organization_id','reference','provider_id','provider_record_version']));
}

/** Point lookup checks both the Core number and native provider identity, without scanning a truncated list. */
function purepayCoreLinkResolve(string $key,string $org,array $body,bool $checkVersion): ?array
{
    $result=purepayCoreGet($key,'/links/resolve?'.http_build_query($body['reference']+['provider_id'=>$body['provider_id']]));
    if(!array_key_exists('reference_link',$result) || !array_key_exists('provider_link',$result))throw new RuntimeException('The provider returned an incomplete link lookup.');
    if($result['reference_link']===null && $result['provider_link']===null)return null;
    if(!is_array($result['reference_link']) || !is_array($result['provider_link']))throw new RuntimeException('One of these identities is already linked to a different record.');
    $left=purepayCoreLinkReceipt($result['reference_link'],$org,$body,$checkVersion);
    $right=purepayCoreLinkReceipt($result['provider_link'],$org,$body,$checkVersion);
    if($left!==$right)throw new RuntimeException('The provider returned conflicting link receipts.');
    return $left;
}

function purepayCoreLinkSnapshot(PDO $pdo,int $tenant,string $instance,int $owner,string $kind,string $number,string $providerId): array
{
    if(!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$providerId))throw new InvalidArgumentException('Choose an existing provider record.');
    $source=purepayCoreLinkSource($pdo,$instance,$owner,$kind,$number);
    $access=purepayCoreLinkAccess($pdo,$tenant,$instance,$kind,true);
    $remote=purepayCoreRecordView($kind,purepayCoreGet($access['key'],'/records/'.$kind.'/'.$providerId));
    if($remote['id']!==$providerId || !isset($remote['record_version']))throw new RuntimeException('The provider record has no reviewable version.');
    $body=['reference'=>$source['reference'],'provider_id'=>$providerId,'expected_record_version'=>$remote['record_version']];
    $receipt=purepayCoreLinkResolve($access['key'],$access['review']['organization']['id'],$body,false);
    // Existing immutable receipts can be adopted only after reviewing both records.
    if($receipt)$body['expected_record_version']=$receipt['provider_record_version'];
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    $liveSource=purepayCoreLinkSource($pdo,$instance,$owner,$kind,$number);
    if(integrationDeliveryHash($source)!==integrationDeliveryHash($liveSource))throw new RuntimeException('The Core record changed while the provider was being checked. Review the records again.');
    $context=['provider'=>'purepay','connection_ref'=>(string)$access['connection']['id'],'binding_hash'=>$access['review']['review_hash'],
        'entity_type'=>$kind,'entity_tenant_id'=>$owner,'entity_id'=>$source['entity_id'],'operation'=>'record.link',
        'source_hash'=>integrationDeliveryHash($source),'remote_hash'=>integrationDeliveryHash(['record'=>$remote,'receipt'=>$receipt])];
    return ['access'=>$access,'source'=>$source,'remote'=>$remote,'receipt'=>$receipt,'context'=>$context,
        'payload'=>['organization_id'=>$access['review']['organization']['id'],'body'=>$body]];
}

function purepayCoreLinkPreview(PDO $pdo,int $tenant,int $actor,string $instance,int $owner,string $kind,string $number,string $providerId): array
{
    $snapshot=purepayCoreLinkSnapshot($pdo,$tenant,$instance,$owner,$kind,$number,$providerId);
    // Return an already recorded link rather than creating another runnable review for it.
    $subject=integrationDeliveryHash(array_intersect_key($snapshot['context'],array_flip(['provider','connection_ref','entity_type','entity_tenant_id','entity_id'])));
    $existing=$pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND subject_hash=:s AND operation="record.link" AND state IN ("delivered","sending","uncertain") ORDER BY id LIMIT 1');
    $existing->execute(['t'=>$tenant,'s'=>$subject]);$previous=$existing->fetch(PDO::FETCH_ASSOC);
    if($previous) {
        $old=json_decode((string)decryptField($previous['payload_ct']),true,64,JSON_THROW_ON_ERROR);
        if(($old['body']['provider_id']??null)!==$providerId)throw new RuntimeException('This Core record has a different completed or unresolved link. Reconcile it before changing the match.');
        $row=$previous;
    } else $row=integrationDeliveryPreview($pdo,$tenant,$actor,$snapshot['context'],$snapshot['payload']);
    return ['source'=>$snapshot['source'],'provider_record'=>$snapshot['remote'],'organization'=>$snapshot['access']['review']['organization'],
        'existing_provider_link'=>$snapshot['receipt']!==null,'delivery_enabled'=>purepayCoreLinkEnabled($tenant),'delivery'=>integrationDeliverySummary($row)];
}

/** Paginated recovery surface, independent of whether the native provider record is still listed. */
function purepayCoreLinkHistory(PDO $pdo,int $tenant,string $instance,int $owner,string $kind,?int $before=null): array
{
    if(!in_array($kind,['person','placement','client','vendor'],true) || $owner<=0 || ($before!==null && $before<=0))throw new InvalidArgumentException('Invalid link history page.');
    $access=purepayCoreLinkAccess($pdo,$tenant,$instance,$kind,false);
    $stmt=$pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND provider="purepay" AND operation="record.link" AND connection_ref=:c
        AND entity_type=:kind AND entity_tenant_id=:owner'.($before===null?'':' AND id<:before').' ORDER BY id DESC LIMIT 51');
    $params=['t'=>$tenant,'c'=>(string)$access['connection']['id'],'kind'=>$kind,'owner'=>$owner];
    if($before!==null)$params['before']=$before;
    $stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);$items=[];
    foreach(array_slice($rows,0,50)as$row) {
        $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
        if(($payload['organization_id']??null)!==$access['review']['organization']['id'] || ($payload['body']['reference']['instance_id']??null)!==$instance)throw new RuntimeException('A stored link belongs to another provider identity. Review migration history.');
        $items[]=['delivery'=>integrationDeliverySummary($row),'reference'=>$payload['body']['reference'],'provider_id'=>$payload['body']['provider_id']];
    }
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    return ['items'=>$items,'next_before'=>count($rows)>50?(int)$rows[49]['id']:null];
}

/** Payload and confirmed receipt remain tied to the permanent provider pin, including after key rotation. */
function purepayCoreLinkEnvelope(PDO $pdo,int $tenant,int $id,string $hash,string $instance,int $owner): array
{
    $row=integrationDeliveryGet($pdo,$tenant,$id);
    if($row['provider']!=='purepay' || $row['operation']!=='record.link' || !hash_equals($row['review_hash'],$hash)
        || (int)$row['entity_tenant_id']!==$owner)throw new InvalidArgumentException('The link does not match this review or the current directory.');
    $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    $source=purepayCoreLinkSource($pdo,$instance,$owner,$row['entity_type'],$payload['body']['reference']['number']);
    if((int)$row['entity_id']!==$source['entity_id'] || integrationDeliveryHash($source['reference'])!==integrationDeliveryHash($payload['body']['reference']))throw new RuntimeException('The Core identity changed. Review its ownership before reconciling.');
    return [$row,$payload,$source];
}

/** State, receipt and audit commit together. The delivered envelope is the local immutable link. */
function purepayCoreLinkComplete(PDO $pdo,int $tenant,array $row,array $receipt,?string $claim,?callable $audit): array
{
    if($pdo->inTransaction())throw new LogicException('Link results require their own transaction.');
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND id=:id FOR UPDATE');
        $stmt->execute(['t'=>$tenant,'id'=>$row['id']]);$live=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$live || $live['review_hash']!==$row['review_hash'])throw new RuntimeException('Link review changed.');
        if($live['state']==='delivered') {
            if($live['remote_id']!==$receipt['provider_id'] || $live['remote_receipt_id']!==$receipt['id'])throw new RuntimeException('Conflicting stored link receipt.');
        } else {
            $allowed=$claim===null?in_array($live['state'],['sending','uncertain'],true):($live['state']==='sending' && hash_equals((string)$live['claim_hash'],hash('sha256',$claim)));
            if(!$allowed)throw new RuntimeException('This attempt cannot accept a link receipt.');
            $pdo->prepare('UPDATE integration_deliveries SET state="delivered",remote_id=:r,remote_receipt_id=:receipt,error_code=NULL,claim_hash=NULL,finished_at=NOW() WHERE tenant_id=:t AND id=:id')
                ->execute(['r'=>$receipt['provider_id'],'receipt'=>$receipt['id'],'t'=>$tenant,'id'=>$row['id']]);
            if($audit!==null)$audit();
        }
        $pdo->commit();return integrationDeliverySummary(integrationDeliveryGet($pdo,$tenant,(int)$row['id']));
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function purepayCoreLinkSend(PDO $pdo,int $tenant,int $actor,string $instance,int $owner,int $id,string $hash,?callable $audit=null): array
{
    if(!purepayCoreLinkEnabled($tenant))throw new RuntimeException('Record linking has not been enabled for this environment.');
    [$row,$payload,$source]=purepayCoreLinkEnvelope($pdo,$tenant,$id,$hash,$instance,$owner);
    if($row['state']==='delivered')return integrationDeliverySummary($row);
    if(!in_array($row['state'],['preview','approved'],true))throw new RuntimeException('Check the provider receipt for this attempt before doing anything else.');
    $snapshot=purepayCoreLinkSnapshot($pdo,$tenant,$instance,$owner,$row['entity_type'],$source['reference']['number'],$payload['body']['provider_id']);
    if($row['state']==='preview')integrationDeliveryApprove($pdo,$tenant,$id,$actor,$hash);
    $current=array_intersect_key($snapshot['context'],array_flip(['binding_hash','source_hash','remote_hash']));
    $claim=integrationDeliveryClaim($pdo,$tenant,$id,$current);
    try {
        purepayCoreLinkAccessUnchanged($pdo,$tenant,$snapshot['access']);
        $receipt=$snapshot['receipt']??purepayCall($snapshot['access']['key'],'POST',PUREPAY_CORE_PATH.'/links',$claim['payload']['body'],25,$claim['idempotency_key'],purepayCoreApiBase());
        $receipt=purepayCoreLinkReceipt($receipt,$payload['organization_id'],$payload['body']);
        return purepayCoreLinkComplete($pdo,$tenant,$row,$receipt,$claim['claim'],$audit);
    } catch(Throwable $e) {
        $live=integrationDeliveryGet($pdo,$tenant,$id);
        if($live['state']==='delivered')return integrationDeliverySummary($live);
        // A timeout, malformed receipt or interrupted local commit is never permission to resend.
        if($live['state']==='sending')integrationDeliveryFinish($pdo,$tenant,$id,$claim['claim'],'uncertain',null,'link_receipt_unconfirmed');
        return integrationDeliverySummary(integrationDeliveryGet($pdo,$tenant,$id));
    }
}

function purepayCoreLinkReconcile(PDO $pdo,int $tenant,string $instance,int $owner,int $id,string $hash,?callable $audit=null): array
{
    [$row,$payload]=purepayCoreLinkEnvelope($pdo,$tenant,$id,$hash,$instance,$owner);
    if($row['state']==='delivered')return integrationDeliverySummary($row);
    if(!in_array($row['state'],['sending','uncertain'],true))throw new RuntimeException('Only an attempted link can be reconciled.');
    $access=purepayCoreLinkAccess($pdo,$tenant,$instance,$row['entity_type'],false);
    if((string)$access['connection']['id']!==$row['connection_ref'] || $access['review']['organization']['id']!==$payload['organization_id'])throw new RuntimeException('The attempt belongs to a different provider connection.');
    $receipt=purepayCoreLinkResolve($access['key'],$payload['organization_id'],$payload['body'],true);
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    if(!$receipt)throw new RuntimeException('The provider has not confirmed this link. The attempt remains held; it will not be resent automatically.');
    return purepayCoreLinkComplete($pdo,$tenant,$row,$receipt,null,$audit);
}
