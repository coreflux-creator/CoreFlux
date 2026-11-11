<?php
/** Associate original imported time without changing its hours, approvals or source payload. */
declare(strict_types=1);
require_once __DIR__.'/legacy_time_contract.php';
require_once __DIR__.'/time_control_delivery.php';
require_once __DIR__.'/time_review_delivery.php';

function quantaLegacyTimeLocal(int $tenant,array $access,array $entry,bool $lock=false): array
{
    $owners=quantaTimeControlOwners($tenant,$lock);$pdo=getDB();
    $links=quantaWorkerLinks($tenant,$lock);
    $imports=quantaImportLookup($tenant,[$entry['id']],$lock,true);
    $route=quantaRouteFor(quantaRoutes($tenant,$lock,$owners),$entry,quantaWorkerPersonMap($links),$access);
    $proof=quantaTimeReviewProof($entry,$route,$imports,$access['context']['workspace']['id']);
    return ['owners'=>$owners,'proof'=>$proof,'placement_id'=>(int)$route['placement_id'],
        'person'=>quantaMetadataReference($pdo,$access['instance_id'],$owners['people'],'person',(int)$route['person_id'],$lock),
        'placement'=>quantaMetadataReference($pdo,$access['instance_id'],$owners['placements'],'placement',(int)$route['placement_id'],$lock)];
}

function quantaLegacyTimeSnapshot(int $tenant,string $entryId): array
{
    $review=quantaTimeReviewSnapshot($tenant,$entryId);$access=$review['access'];$workspace=$access['context']['workspace']['id'];
    $remote=quantaGet($access['api_key'],QUANTA_CORE_CONTRACT_PATH.'/legacy-time-context',
        ['assignment_id'=>$review['payload']['body']['assignment_id'],'source_entry_id'=>$entryId]);
    $plan=quantaLegacyTimeContext($remote,$review);$local=quantaLegacyTimeLocal($tenant,$access,$review['entry']);
    $identity=quantaTimeControlOwned($remote['proof']['assignment_identity'],$workspace,$review['entry']['worker_id'],
        $access['instance_id'],$local['owners'],$local['person']);
    if(integrationDeliveryHash($identity['placement'])!==integrationDeliveryHash($local['placement'])
        ||$remote['proof']['review_publication']['core_tenant_id']!==$tenant
        ||$local['proof']['core_revision']!==$remote['proof']['review_publication']['core_revision'])throw new RuntimeException('Core import or directory changed during historical review');
    $current=$remote['current'];$unchanged=$current&&integrationDeliveryHash($current['proof']??[])===integrationDeliveryHash($remote['proof']);
    $context=['provider'=>'quanta','connection_ref'=>(string)$access['connection']['id'],'binding_hash'=>$access['binding_hash'],
        'entity_type'=>'placement','entity_tenant_id'=>$local['owners']['placements'],'entity_id'=>$local['placement_id'],'operation'=>'time.legacy',
        'source_hash'=>integrationDeliveryHash($local),'remote_hash'=>integrationDeliveryHash($remote)];
    $payload=['workspace_id'=>$workspace,'body'=>$plan['body'],'expected'=>$plan['expected']];
    quantaWorkspaceAssertCurrent(getDB(),$tenant,$access);
    return compact('access','context','payload','local')+['entry'=>$review['entry'],'view'=>[
        'status'=>$unchanged?'unchanged':'preview','workspace'=>$access['context']['workspace'],'person_number'=>$local['person']['number'],
        'placement_number'=>$local['placement']['number'],'work_date'=>$review['entry']['work_date'],
        'hours'=>array_sum($review['entry']['components']),'components'=>$review['payload']['body']['components'],
        'source_approved_at'=>$review['entry']['source_approval']['approved_at'],
        'delivery_enabled'=>quantaTimeControlEnabled($tenant)&&$remote['configuration_enabled'],'receipt_only'=>false]];
}

/** Native inventory supplies IDs; the browser cannot substitute a placement or approval. */
function quantaLegacyTimeInventory(int $tenant,int $person,string $site): array
{
    $setup=quantaTimeControlContext($tenant,$person,$site);$inventory=$setup['remote']['unbound_time'];
    $ids=$inventory['sample_entry_ids']??[];
    if(!is_array($ids)||!array_is_list($ids)||count($ids)>20)throw new RuntimeException('Incomplete historical time inventory');
    foreach($ids as $id)quantaTimeControlIdentifier($id);
    $imports=quantaImportLookup($tenant,$ids,false,true);$rows=[];
    foreach($ids as $id){
        $parts=array_values($imports[$id]??[]);$dates=array_unique(array_column($parts,'work_date'));
        $rows[]=['source_entry_id'=>$id,'work_date'=>count($dates)===1?reset($dates):null,'imported'=>count($parts)>0,
            'hours'=>array_sum(array_map(static fn($r)=>(float)($r['current_hours']??0),$parts))];
    }
    quantaWorkspaceAssertCurrent(getDB(),$tenant,$setup['access']);
    return ['rows'=>$rows,'remaining'=>$inventory['count'],'complete'=>$inventory['complete']];
}

function quantaLegacyTimePending(int $tenant,?int $before=null): array
{
    if($before!==null&&$before<=0)throw new InvalidArgumentException('Invalid delivery page');
    $q=getDB()->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND provider="quanta" AND operation="time.legacy"
        AND entity_type="placement" AND entity_tenant_id=:o AND state IN ("sending","uncertain")'.($before?' AND id<:before':'').' ORDER BY id DESC LIMIT 51');
    $params=['t'=>$tenant,'o'=>quantaTimeControlOwners($tenant)['placements']];if($before)$params['before']=$before;
    $q->execute($params);$all=$q->fetchAll(PDO::FETCH_ASSOC);$rows=array_slice($all,0,50);
    return ['rows'=>array_map(static fn($r)=>['delivery'=>integrationDeliverySummary($r)],$rows),'next_before'=>count($all)>50?(int)end($rows)['id']:null];
}

function quantaLegacyTimePreview(int $tenant,int $actor,string $entryId): array
{
    quantaTimeControlIdentifier($entryId);
    $q=getDB()->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND provider="quanta" AND operation="time.legacy"
        AND entity_type="placement" AND entity_tenant_id=:o AND state IN ("sending","uncertain") ORDER BY id DESC LIMIT 201');
    $q->execute(['t'=>$tenant,'o'=>quantaTimeControlOwners($tenant)['placements']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $row){
        $saved=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
        if(($saved['body']['source_entry_id']??null)===$entryId)return ['receipt_only'=>true,'delivery'=>integrationDeliverySummary($row)];
    }
    if(count($rows)>200)throw new RuntimeException('Reconcile unconfirmed historical links before reviewing more');
    $s=quantaLegacyTimeSnapshot($tenant,$entryId);$result=$s['view'];
    if($result['status']!=='unchanged')$result['delivery']=integrationDeliverySummary(integrationDeliveryPreview(getDB(),$tenant,$actor,$s['context'],$s['payload']));
    return $result;
}

function quantaLegacyTimePayloadOwners(int $tenant,array $payload,array $owners): void
{
    $body=quantaLegacyTimeBody($payload['body']);quantaLegacyTimeExpectedValidate($payload['workspace_id'],$body,$payload['expected']);
    $proof=$payload['expected']['proof'];$identity=$proof['assignment_identity'];
    quantaTimeControlOwned($identity,$payload['workspace_id'],$payload['expected']['worker_id'],integrationInstanceId(),$owners,$identity['person']);
    if($proof['review_publication']['core_tenant_id']!==$tenant)throw new RuntimeException('Restore the original Core company before checking its link');
}

function quantaLegacyTimeEnvelope(int $tenant,int $id,string $reviewHash): array
{
    $row=integrationDeliveryGet(getDB(),$tenant,$id);$owners=quantaTimeControlOwners($tenant);
    if($row['provider']!=='quanta'||$row['operation']!=='time.legacy'||$row['entity_type']!=='placement'
        ||(int)$row['entity_tenant_id']!==$owners['placements']||!hash_equals($row['review_hash'],$reviewHash))throw new InvalidArgumentException('Choose the original historical link in its authorized directory');
    $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    quantaLegacyTimePayloadOwners($tenant,$payload,$owners);return [$row,$payload];
}

function quantaLegacyTimeSend(int $tenant,int $actor,int $id,string $reviewHash): array
{
    if(!quantaTimeControlEnabled($tenant))throw new RuntimeException('Historical time linking is not enabled for this environment');
    [$row,$payload]=quantaLegacyTimeEnvelope($tenant,$id,$reviewHash);
    if($row['state']==='delivered')return integrationDeliverySummary($row);
    if(!in_array($row['state'],['preview','approved'],true))throw new RuntimeException('Check the original historical link receipt first');
    $s=quantaLegacyTimeSnapshot($tenant,$payload['body']['source_entry_id']);
    if(!$s['view']['delivery_enabled']||integrationDeliveryHash($s['payload'])!==integrationDeliveryHash($payload)
        ||$s['context']['entity_id']!==(int)$row['entity_id'])throw new RuntimeException('Historical time changed. Review it again.');
    $pdo=getDB();if($row['state']==='preview')integrationDeliveryApprove($pdo,$tenant,$id,$actor,$reviewHash);
    $claim=integrationDeliveryClaim($pdo,$tenant,$id,array_intersect_key($s['context'],array_flip(['binding_hash','source_hash','remote_hash'])),
        static function()use($pdo,$tenant,$s):void {
            quantaWorkspaceAssertCurrent($pdo,$tenant,$s['access'],true);
            $local=quantaLegacyTimeLocal($tenant,$s['access'],$s['entry'],true);
            if(integrationDeliveryHash($local)!==$s['context']['source_hash'])throw new RuntimeException('Core time, routing or directory changed before delivery');
        });
    try{$record=quantaWriteContract($s['access']['api_key'],$payload['workspace_id'],'time.legacy',$payload['body'],$payload['expected'],$claim['idempotency_key']);}
    catch(QuantaContractWriteException $e){integrationDeliveryFinish($pdo,$tenant,$id,$claim['claim'],$e->uncertain?'uncertain':'failed',null,$e->reason);throw $e;}
    catch(Throwable $e){integrationDeliveryFinish($pdo,$tenant,$id,$claim['claim'],'uncertain',null,'historical_link_interrupted');throw new RuntimeException('Check the original historical link receipt');}
    integrationDeliveryFinish($pdo,$tenant,$id,$claim['claim'],'delivered',$record['id'],null,$record['delivery_id']);
    platformAuditLogWrite($tenant,$actor,'quanta.legacy_time.delivered',null,['delivery_id'=>$id,'version'=>$record['version']],['source'=>'quanta']);
    return integrationDeliverySummary(integrationDeliveryGet($pdo,$tenant,$id));
}

function quantaLegacyTimeReceiptOutcome(array $payload,array $receipt): string
{
    if(($receipt['request_hash']??null)!==quantaMetadataRequestHash($payload['body'])||!is_string($receipt['id']??null)
        ||!preg_match('/^cfd_[a-f0-9]{64}$/D',$receipt['id']))throw new RuntimeException('Receipt differs from the original historical link');
    if(in_array($receipt['state']??null,['in_progress','uncertain'],true))return 'unconfirmed';
    if(($receipt['state']??null)!=='complete')throw new RuntimeException('Unknown historical link receipt state');
    if(in_array($receipt['status_code']??null,[200,201],true)){
        $response=$receipt['response']??[];
        foreach($payload['expected'] as $field=>$value)if(!array_key_exists($field,$response)||integrationDeliveryHash([$response[$field]])!==integrationDeliveryHash([$value]))throw new RuntimeException('Receipt differs from the reviewed historical evidence');
        if(($response['delivery_id']??null)!==$receipt['id']||($response['last_delivery_id']??null)!==$receipt['id'])throw new RuntimeException('Historical receipt identity differs');
        return 'confirmed';
    }
    if(in_array($receipt['status_code']??null,[400,401,403,404,409,422],true)&&($receipt['response']['delivery_id']??null)===$receipt['id'])return 'rejected';
    throw new RuntimeException('Historical link result remains uncertain');
}

/** Recovery uses the original binding and receipt even when the source disappears. */
function quantaLegacyTimeReceipt(int $tenant,int $id,string $reviewHash): array
{
    [$row,$payload]=quantaLegacyTimeEnvelope($tenant,$id,$reviewHash);
    if(!in_array($row['state'],['sending','uncertain'],true))return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>$row['state']];
    $access=quantaWorkspaceForDelivery($tenant);
    if((string)$access['connection']['id']!==(string)$row['connection_ref']||!hash_equals($row['binding_hash'],$access['binding_hash'])
        ||$access['context']['workspace']['id']!==$payload['workspace_id'])throw new RuntimeException('Restore the original connection before checking its receipt');
    try{$receipt=quantaGet($access['api_key'],QUANTA_CORE_CONTRACT_PATH.'/legacy-time-deliveries/'.$row['idempotency_key']);}
    catch(QuantaApiException $e){if($e->httpStatus===404)return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>'not_found'];throw $e;}
    $outcome=quantaLegacyTimeReceiptOutcome($payload,$receipt);
    if($outcome==='unconfirmed'){
        quantaWorkspaceAssertCurrent(getDB(),$tenant,$access);
        $recovered=quantaLegacyTimeRecoverRemote($access['api_key'],$row['idempotency_key']);
        if(($recovered['id']??null)!==$receipt['id'])throw new RuntimeException('Original receipt identity changed during recovery');
        $receipt=$recovered;$outcome=quantaLegacyTimeReceiptOutcome($payload,$receipt);
    }
    if($outcome==='unconfirmed')return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>$outcome];
    $pdo=getDB();$pdo->beginTransaction();
    try{
        quantaWorkspaceAssertCurrent($pdo,$tenant,$access,true);
        $owners=quantaTimeControlOwners($tenant,true);quantaLegacyTimePayloadOwners($tenant,$payload,$owners);
        if($owners['placements']!==(int)$row['entity_tenant_id'])throw new RuntimeException('Placement directory changed during recovery');
        $q=$pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND id=:id FOR UPDATE');$q->execute(['t'=>$tenant,'id'=>$id]);$live=$q->fetch(PDO::FETCH_ASSOC);
        foreach(['payload_ct','binding_hash','review_hash','idempotency_key'] as $field)if(!$live||$live[$field]!==$row[$field])throw new RuntimeException('Original link changed during recovery');
        $success=$outcome==='confirmed';
        $pdo->prepare('UPDATE integration_deliveries SET state=:s,remote_id=:r,remote_receipt_id=:receipt,error_code=:e,claim_hash=NULL,finished_at=NOW() WHERE tenant_id=:t AND id=:id AND state IN ("sending","uncertain")')
            ->execute(['s'=>$success?'delivered':'failed','r'=>$success?$payload['expected']['id']:null,'receipt'=>$receipt['id'],'e'=>$success?null:'historical_link_rejected','t'=>$tenant,'id'=>$id]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['delivery'=>integrationDeliverySummary(integrationDeliveryGet($pdo,$tenant,$id)),'receipt_state'=>$outcome];
}
