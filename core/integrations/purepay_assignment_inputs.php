<?php
/** Scoped commercial source reads. Delivery and native activation are separate review steps. */
declare(strict_types=1);
require_once __DIR__.'/purepay_links.php';
require_once __DIR__.'/purepay_assignment_contract.php';

function purepayAssignmentInputSource(PDO $pdo,string $instance,array $owners,string $number,?string $previous=null): array
{
    foreach(['placements','people','ap'] as $module)if(!is_int($owners[$module]??null) || $owners[$module]<=0)throw new InvalidArgumentException('Authorized module owners are required for the complete commercial graph.');
    $placement=purepayCoreLinkSource($pdo,$instance,$owners['placements'],'placement',$number);
    $refs=[];
    $resolve=static function(string $kind,int $id)use($pdo,$instance,$owners,&$refs):array {
        $key=$kind.':'.$id;
        if(isset($refs[$key]))return $refs[$key];
        $definition=coreRecordNumberDefinition($kind);$owner=$owners[$definition['module']];
        $deleted=in_array($kind,['person','placement'],true)?' AND r.deleted_at IS NULL':'';
        $s=$pdo->prepare('SELECT n.record_number FROM '.$definition['table'].' r JOIN core_record_numbers n
            ON n.tenant_id=r.tenant_id AND n.record_id=r.id AND n.canonical_record_id=r.id
            WHERE r.tenant_id=:t AND r.id=:i AND n.entity_type=:k'.$deleted.' LIMIT 2');
        $s->execute(['t'=>$owner,'i'=>$id,'k'=>$kind]);$numbers=$s->fetchAll(PDO::FETCH_COLUMN);
        if(count($numbers)!==1)throw new RuntimeException('A commercial party has no current canonical number in its authorized directory. Review its mapping first.');
        return $refs[$key]=integrationRecordReference($instance,$owner,$kind,$numbers[0]);
    };
    $s=$pdo->prepare('SELECT * FROM placements WHERE tenant_id=:t AND id=:i AND deleted_at IS NULL');
    $s->execute(['t'=>$owners['placements'],'i'=>$placement['entity_id']]);$row=$s->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('This placement is no longer available.');
    $s=$pdo->prepare('SELECT id,first_name,last_name FROM people WHERE tenant_id=:t AND id=:i AND deleted_at IS NULL');
    $s->execute(['t'=>$owners['people'],'i'=>$row['person_id']]);$person=$s->fetch(PDO::FETCH_ASSOC);
    if(!$person)throw new RuntimeException('The placed person is outside the authorized people directory.');
    $params=['t'=>$owners['placements'],'p'=>$placement['entity_id']];
    $s=$pdo->prepare('SELECT * FROM placement_rates WHERE tenant_id=:t AND placement_id=:p AND approved_at IS NOT NULL ORDER BY effective_from,id LIMIT 101');
    $s->execute($params);$rates=$s->fetchAll(PDO::FETCH_ASSOC);
    $s=$pdo->prepare('SELECT p.*,v.default_terms AS vendor_default_terms,COALESCE(v.default_pwp,0) AS vendor_default_pwp
        FROM placement_economic_parties p LEFT JOIN ap_vendors_index v ON v.tenant_id=:vendor_owner AND v.id=p.ap_vendor_id
        WHERE p.tenant_id=:t AND p.placement_id=:p ORDER BY p.id LIMIT 101');
    $s->execute($params+['vendor_owner'=>$owners['ap']]);$parties=$s->fetchAll(PDO::FETCH_ASSOC);
    $s=$pdo->prepare('SELECT * FROM placement_economic_items WHERE tenant_id=:t AND placement_id=:p ORDER BY id LIMIT 101');
    $s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach([$rates,$parties,$items] as $rows)if(count($rows)>100)throw new RuntimeException('The complete commercial graph exceeds the review limit. No partial packet was created.');
    $cycleIds=[];
    foreach(['billing','ap','payroll'] as $purpose)if($row[$purpose.'_operating_cycle_id']!==null)$cycleIds[]=purepayAssignmentId($row[$purpose.'_operating_cycle_id']);
    foreach($parties as $party)if($party['operating_cycle_id']!==null)$cycleIds[]=purepayAssignmentId($party['operating_cycle_id']);
    $cycleIds=array_values(array_unique($cycleIds));$cycles=[];
    if($cycleIds) {
        $s=$pdo->prepare('SELECT * FROM staffing_operating_cycles WHERE tenant_id=? AND id IN ('.implode(',',array_fill(0,count($cycleIds),'?')).') ORDER BY id');
        $s->execute([$owners['placements'],...$cycleIds]);$cycles=$s->fetchAll(PDO::FETCH_ASSOC);
        if(count($cycles)!==count($cycleIds))throw new RuntimeException('An operating cycle is missing from the authorized placement directory.');
    }
    $body=purepayAssignmentBuild($row,$person,$rates,$parties,$cycles,$items,$resolve,$previous);
    if($body['placement']!==$placement['reference'])throw new RuntimeException('The placement identity changed during review.');
    return ['placement'=>$placement,'body'=>$body,'source_hash'=>integrationDeliveryHash([$owners,$row,$person,$rates,$parties,$cycles,$items,$refs])];
}

function purepayAssignmentInputEnabled(): bool
{
    return getenv('COREFLUX_PUREPAY_ASSIGNMENT_DELIVERY')==='1';
}

function purepayAssignmentInputAccess(PDO $pdo,int $tenant,string $instance,bool $write): array
{
    $access=purepayCoreLinkAccess($pdo,$tenant,$instance,'placement',false);
    if(($access['review']['assignment_input_contract_version']??null)!==PUREPAY_ASSIGNMENT_INPUT_VERSION
        || ($write && !in_array('write:assignment_inputs',$access['review']['capabilities'],true)))throw new RuntimeException('The provider needs the complete commercial contract and explicit placement-review capability.');
    return $access;
}

function purepayAssignmentInputSnapshot(PDO $pdo,int $tenant,string $instance,array $owners,string $number,?string $previous=null): array
{
    $access=purepayAssignmentInputAccess($pdo,$tenant,$instance,true);
    if($pdo->inTransaction())throw new LogicException('Placement review needs its own consistent source read.');
    $pdo->beginTransaction();
    try{$source=purepayAssignmentInputSource($pdo,$instance,$owners,$number,$previous);$pdo->commit();}
    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if($source['body']['employment_type']==='w2' && !in_array('read:payroll',$access['review']['capabilities'],true))throw new RuntimeException('W-2 placement review requires provider payroll read access.');
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    $context=['provider'=>'purepayroll','connection_ref'=>(string)$access['connection']['id'],'binding_hash'=>$access['review']['review_hash'],
        'entity_type'=>'placement','entity_tenant_id'=>$owners['placements'],'entity_id'=>$source['placement']['entity_id'],'operation'=>'assignments.inputs',
        'source_hash'=>$source['source_hash'],'remote_hash'=>integrationDeliveryHash($access['review'])];
    return ['access'=>$access,'source'=>$source,'context'=>$context,
        'payload'=>['owners'=>$owners,'organization_id'=>$access['review']['organization']['id'],'body'=>$source['body']]];
}

function purepayAssignmentInputPreview(PDO $pdo,int $tenant,int $actor,string $instance,array $owners,string $number,?string $previous=null): array
{
    $snapshot=purepayAssignmentInputSnapshot($pdo,$tenant,$instance,$owners,$number,$previous);
    $delivery=integrationDeliveryPreview($pdo,$tenant,$actor,$snapshot['context'],$snapshot['payload']);
    return ['packet'=>$snapshot['source']['body'],'organization'=>$snapshot['access']['review']['organization'],
        'delivery_enabled'=>purepayAssignmentInputEnabled(),'delivery'=>integrationDeliverySummary($delivery)];
}

function purepayAssignmentInputEnvelope(PDO $pdo,int $tenant,int $id,string $hash,string $instance,array $owners): array
{
    $row=integrationDeliveryGet($pdo,$tenant,$id);
    if($row['provider']!=='purepayroll' || $row['operation']!=='assignments.inputs' || $row['entity_type']!=='placement'
        || !hash_equals($row['review_hash'],$hash))throw new InvalidArgumentException('Choose the current placement review.');
    $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    if($payload['owners']!==integrationDeliveryCanonical($owners) || $payload['body']['placement']['instance_id']!==$instance
        || (int)$row['entity_tenant_id']!==$owners['placements'])throw new RuntimeException('This placement review belongs to different directory owners.');
    $source=purepayCoreLinkSource($pdo,$instance,$owners['placements'],'placement',$payload['body']['placement']['number']);
    if($source['entity_id']!==(int)$row['entity_id'])throw new RuntimeException('The placement identity changed.');
    return [$row,$payload];
}

function purepayAssignmentInputReceipt(array $receipt,string $org,array $body): array
{
    $hash=integrationDeliveryHash($body);
    if(($receipt['id']??null)!=='cpa_'.integrationDeliveryHash([$org,$hash]) || ($receipt['organization_id']??null)!==$org
        || ($receipt['content_hash']??null)!==$hash)throw new RuntimeException('The receipt does not match the reviewed commercial snapshot.');
    return $receipt;
}

function purepayAssignmentInputSend(PDO $pdo,int $tenant,int $actor,string $instance,array $owners,int $id,string $hash,?callable $audit=null): array
{
    if(!purepayAssignmentInputEnabled())throw new RuntimeException('Placement delivery is not enabled in this environment.');
    [$row,$payload]=purepayAssignmentInputEnvelope($pdo,$tenant,$id,$hash,$instance,$owners);
    if($row['state']==='delivered')return integrationDeliverySummary($row);
    if(!in_array($row['state'],['preview','approved'],true))throw new RuntimeException('Check this attempt’s receipt before sending again.');
    $snapshot=purepayAssignmentInputSnapshot($pdo,$tenant,$instance,$owners,$payload['body']['placement']['number'],$payload['body']['previous_revision']);
    if(integrationDeliveryHash($snapshot['payload'])!==integrationDeliveryHash($payload))throw new RuntimeException('The placement or commercial graph changed. Review a fresh snapshot.');
    if($row['state']==='preview')integrationDeliveryApprove($pdo,$tenant,$id,$actor,$hash);
    $claim=integrationDeliveryClaim($pdo,$tenant,$id,array_intersect_key($snapshot['context'],array_flip(['binding_hash','source_hash','remote_hash'])));
    try {
        purepayCoreLinkAccessUnchanged($pdo,$tenant,$snapshot['access']);
        $receipt=purepayCall($snapshot['access']['key'],'POST',PUREPAY_CORE_PATH.'/assignments/inputs',$payload['body'],25,integrationDeliveryHash($payload['body']),purepayCoreApiBase());
        $receipt=purepayAssignmentInputReceipt($receipt,$payload['organization_id'],$payload['body']);
        return purepayCoreLinkComplete($pdo,$tenant,$row,['id'=>$receipt['id'],'provider_id'=>$receipt['id']],$claim['claim'],$audit);
    } catch(Throwable $e) {
        $live=integrationDeliveryGet($pdo,$tenant,$id);
        if($live['state']==='delivered')return integrationDeliverySummary($live);
        if($live['state']==='sending')integrationDeliveryFinish($pdo,$tenant,$id,$claim['claim'],'uncertain',null,'assignment_receipt_unconfirmed');
        return integrationDeliverySummary(integrationDeliveryGet($pdo,$tenant,$id));
    }
}

function purepayAssignmentInputStatus(PDO $pdo,int $tenant,string $instance,array $owners,int $id,string $hash,?callable $audit=null): array
{
    [$row,$payload]=purepayAssignmentInputEnvelope($pdo,$tenant,$id,$hash,$instance,$owners);
    if(!in_array($row['state'],['sending','uncertain','delivered'],true))throw new RuntimeException('This placement snapshot has not been sent.');
    $access=purepayAssignmentInputAccess($pdo,$tenant,$instance,false);
    if((string)$access['connection']['id']!==$row['connection_ref'] || $access['review']['organization']['id']!==$payload['organization_id'])throw new RuntimeException('This attempt belongs to a different provider connection.');
    $remoteId='cpa_'.integrationDeliveryHash([$payload['organization_id'],integrationDeliveryHash($payload['body'])]);
    $remote=purepayAssignmentInputReceipt(purepayCoreGet($access['key'],'/assignments/inputs/'.$remoteId),$payload['organization_id'],$payload['body']);
    if(!isset($remote['packet']) || integrationDeliveryHash($remote['packet'])!==integrationDeliveryHash($payload['body'])
        || !in_array($remote['state']??null,['ready','needs_mapping','recoverable','conflict','created','revision_ready','superseded'],true))throw new RuntimeException('The provider returned an incompatible placement review.');
    $assignment=$remote['assignment_id']??null;$accepted=$remote['accepted_revision']??null;
    $expected='asg_core_'.integrationDeliveryHash([$payload['organization_id'],$payload['body']['placement']]);
    if(($assignment!==null && $assignment!==$expected) || ($accepted!==null && (!is_string($accepted) || !preg_match('/^[a-f0-9]{64}$/D',$accepted))))throw new RuntimeException('The native assignment identity or accepted revision is invalid.');
    if(in_array($remote['state'],['created','revision_ready','superseded'],true)) {
        if($assignment!==$expected || !is_array($remote['existing_link']??null) || $accepted===null
            || ($remote['state']==='created' && $accepted!==$payload['body']['source_revision']))throw new RuntimeException('The accepted placement does not match this Core identity and revision.');
        purepayCoreLinkReceipt($remote['existing_link'],$payload['organization_id'],['reference'=>$payload['body']['placement'],'provider_id'=>$expected],false);
    }
    $nativeStatus=$remote['assignment_status']??null;
    if($nativeStatus!==null && !in_array($nativeStatus,['draft','active','ended','paused'],true))throw new RuntimeException('The provider returned an unsupported assignment status.');
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    $delivery=purepayCoreLinkComplete($pdo,$tenant,$row,['id'=>$remoteId,'provider_id'=>$remoteId],null,$audit);
    return ['delivery'=>$delivery,'provider_review'=>['state'=>$remote['state'],'assignment_id'=>$assignment,'assignment_status'=>$nativeStatus,'accepted_revision'=>$accepted]];
}

function purepayAssignmentInputHistory(PDO $pdo,int $tenant,string $instance,array $owners,string $number,?int $before=null): array
{
    $access=purepayAssignmentInputAccess($pdo,$tenant,$instance,false);
    $source=purepayCoreLinkSource($pdo,$instance,$owners['placements'],'placement',$number);
    if($before!==null && $before<=0)throw new InvalidArgumentException('Invalid history page.');
    $sql='SELECT * FROM integration_deliveries WHERE tenant_id=:t AND provider="purepayroll" AND connection_ref=:c AND operation="assignments.inputs" AND entity_type="placement" AND entity_tenant_id=:o AND entity_id=:i';
    $params=['t'=>$tenant,'c'=>(string)$access['connection']['id'],'o'=>$owners['placements'],'i'=>$source['entity_id']];
    if($before!==null){$sql.=' AND id<:before';$params['before']=$before;}
    $s=$pdo->prepare($sql.' ORDER BY id DESC LIMIT 26');$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC);$items=[];
    foreach(array_slice($rows,0,25) as $row) {
        [$row,$payload]=purepayAssignmentInputEnvelope($pdo,$tenant,(int)$row['id'],$row['review_hash'],$instance,$owners);
        if($payload['organization_id']!==$access['review']['organization']['id'])throw new RuntimeException('This history belongs to a different provider company.');
        $items[]=['packet'=>$payload['body'],'organization'=>$access['review']['organization'],'delivery_enabled'=>purepayAssignmentInputEnabled(),'delivery'=>integrationDeliverySummary($row)];
    }
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    return ['items'=>$items,'next_before'=>count($rows)>25?(int)$rows[24]['id']:null];
}
