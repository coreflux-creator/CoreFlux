<?php
/** Reviewed Core -> Quanta metadata. Identities come from authorized Core directories. */
declare(strict_types=1);
require_once __DIR__.'/core_contract.php';
require_once __DIR__.'/identity.php';
require_once __DIR__.'/../record_numbers.php';
require_once __DIR__.'/../audit.php';

function quantaMetadataDeliveryEnabled(): bool
{
    return defined('COREFLUX_QUANTA_METADATA_DELIVERY') ? constant('COREFLUX_QUANTA_METADATA_DELIVERY')===true : getenv('COREFLUX_QUANTA_METADATA_DELIVERY')==='1';
}

function quantaMetadataReference(PDO $pdo,string $instance,int $owner,string $kind,int $id): array
{
    $definition=coreRecordNumberDefinition($kind);
    $deleted=in_array($kind,['person','placement','company'],true)?' AND deleted_at IS NULL':'';
    $stmt=$pdo->prepare('SELECT id FROM '.$definition['table'].' WHERE tenant_id=:t AND id=:id'.$deleted);
    $stmt->execute(['t'=>$owner,'id'=>$id]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('A related '.$kind.' is unavailable in its authorized directory');
    $numbered=coreRecordNumbersAttach($pdo,$owner,$kind,[$row])[0];
    return integrationRecordReference($instance,$owner,$kind,$numbered[$definition['field']]);
}

/** Caller resolves each directory owner through Core's module scope. */
function quantaMetadataSource(PDO $pdo,string $instance,array $owners,string $kind,int $id): array
{
    if ($id<=0 || !in_array($kind,['person','placement'],true)) throw new InvalidArgumentException('Choose a person or placement');
    foreach (['people','placements','ap'] as $module) if (!is_int($owners[$module]??null) || $owners[$module]<=0) throw new InvalidArgumentException('Authorized directory owners required');
    $owner=$owners[$kind==='person'?'people':'placements'];
    $ref=quantaMetadataReference($pdo,$instance,$owner,$kind,$id);
    $placement=null;
    if ($kind==='placement') {
        $stmt=$pdo->prepare('SELECT p.id,p.person_id,p.title,p.engagement_type,p.status,p.start_date,
            COALESCE(p.actual_end_date,p.end_date) AS end_date,p.client_id,p.end_client_company_id,c.ap_vendor_id,
            p.worksite_city,p.worksite_state,p.worksite_country
            FROM placements p LEFT JOIN placement_corp_details c ON c.tenant_id=p.tenant_id AND c.placement_id=p.id
            WHERE p.tenant_id=:t AND p.id=:id AND p.deleted_at IS NULL');
        $stmt->execute(['t'=>$owner,'id'=>$id]); $placement=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$placement) throw new RuntimeException('Placement is unavailable');
        $personId=(int)$placement['person_id'];
    } else { $personId=$id; }
    $personRef=quantaMetadataReference($pdo,$instance,$owners['people'],'person',$personId);
    $stmt=$pdo->prepare('SELECT id,first_name,last_name,email_primary,status FROM people WHERE tenant_id=:t AND id=:id AND deleted_at IS NULL');
    $stmt->execute(['t'=>$owners['people'],'id'=>$personId]); $person=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$person) throw new RuntimeException('Person is unavailable');
    $result=['entity_type'=>$kind,'entity_tenant_id'=>$owner,'entity_id'=>$id,'reference'=>$ref,'person'=>$personRef,'person_record'=>$person];
    if ($placement) {
        $references=[];
        foreach (['client'=>['client_id','client',$owners['placements']], 'vendor'=>['ap_vendor_id','vendor',$owners['ap']],
                  'end_client'=>['end_client_company_id','company',$owners['people']]] as $field=>[$column,$type,$directory]) {
            $references[$field]=empty($placement[$column])?null:quantaMetadataReference($pdo,$instance,$directory,$type,(int)$placement[$column]);
        }
        $result['assignment']=['placement'=>$ref,'person'=>$personRef,'title'=>$placement['title'],
            'engagement_type'=>$placement['engagement_type'],'start_date'=>$placement['start_date'],'end_date'=>$placement['end_date'],
            'status'=>$placement['status']==='pending_start'?'draft':$placement['status'],
            'work_location'=>quantaAssignmentLocationValidate(['city'=>$placement['worksite_city'],
                'state'=>$placement['worksite_state'],'country'=>$placement['worksite_country']])]+$references;
        $result['placement_record']=$placement;
    }
    return $result;
}

/** Re-read context, source, identities and provider records before both preview and delivery. */
function quantaMetadataSnapshot(int $tenantId,string $kind,int $id,?string $worksiteId): array
{
    $verified=quantaWorkspaceForDelivery($tenantId);
    $key=$verified['api_key']; $workspace=$verified['context']['workspace']['id'];
    quantaMetadataReceiptRequire($key,$workspace);
    $owners=[];
    foreach (['people','placements','ap'] as $module) $owners[$module]=effectiveTenantIdForModule($module,$tenantId)??$tenantId;
    $source=quantaMetadataSource(getDB(),$verified['instance_id'],$owners,$kind,$id);
    if ($kind==='placement') quantaAssignmentLocationRequire($key,$workspace);
    $matches=array_values(array_filter(quantaWorkerLinks($tenantId),static fn($link)=>(int)$link['person_id']===(int)$source['person_record']['id']));
    if (count($matches)!==1) throw new RuntimeException('Link this Core person to one Quanta worker before publishing shared identities');
    $workerId=(string)$matches[0]['worker_id'];
    if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$workerId)) throw new RuntimeException('Invalid linked worker identity');
    $workers=quantaListAll($key,'/workers',[],5000);
    $found=array_values(array_filter($workers,static fn($worker)=>($worker['id']??null)===$workerId));
    if (count($found)!==1) throw new RuntimeException('Linked worker is unavailable in this Quanta workspace');
    $worker=$found[0];
    $link=quantaContractRecord($key,$workspace,$source['person']);
    if ($link && ($link['worker_id']??null)!==$workerId) throw new RuntimeException('The shared person identity points to a different Quanta worker');
    $source['worker_id']=$workerId;
    $remote=['worker'=>$worker,'person_link'=>$link];
    if ($kind==='person') {
        if ($worksiteId!==null) throw new InvalidArgumentException('A person link does not assign a worksite');
        $body=['person'=>$source['person'],'worker_id'=>$workerId];
        $plan=['operation'=>'person.link','body'=>$body,'expected'=>$body,'remote_id'=>quantaCoreRemoteId($workspace,$source['person']),'status'=>$link?'unchanged':'preview'];
    } else {
        if (!$link) throw new RuntimeException('Publish the shared person identity before this assignment');
        $site=null;
        if ($worksiteId!==null) {
            if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$worksiteId)) throw new InvalidArgumentException('Invalid worksite identity');
            $sites=quantaListAll($key,'/worksites',[],2000);
            $found=array_values(array_filter($sites,static fn($site)=>($site['id']??null)===$worksiteId && ($site['active']??null)===true));
            if (count($found)!==1) throw new RuntimeException('Choose an active worksite in the connected Quanta workspace');
            $site=$found[0];
        }
        $assignment=quantaContractRecord($key,$workspace,$source['reference']);
        $plan=quantaAssignmentPlan($workspace,$source['assignment']+['worksite_id'=>$worksiteId],$assignment);
        if ($assignment && (($assignment['worker_id']??null)!==$workerId || ($assignment['person_link_id']??null)!==$link['id'])) throw new RuntimeException('Assignment worker identity requires reconciliation');
        $remote+=['assignment'=>$assignment,'worksite'=>$site];
    }
    $context=['provider'=>'quanta','connection_ref'=>(string)$verified['connection']['id'],'binding_hash'=>$verified['binding_hash'],
        'entity_type'=>$kind,'entity_tenant_id'=>$source['entity_tenant_id'],'entity_id'=>$id,'operation'=>$plan['operation'],
        'source_hash'=>integrationDeliveryHash($source),'remote_hash'=>integrationDeliveryHash($remote)];
    return ['verified'=>$verified,'source'=>$source,'context'=>$context,'plan'=>$plan,'remote'=>$remote,
        'payload'=>['worksite_id'=>$worksiteId,'body'=>$plan['body'],'expected'=>$plan['expected']]];
}

function quantaMetadataPreview(int $tenantId,int $actorId,string $kind,int $id,?string $worksiteId): array
{
    if (!in_array($kind,['person','placement'],true) || $id<=0) throw new InvalidArgumentException('Choose a person or placement');
    $owner=effectiveTenantIdForModule($kind==='person'?'people':'placements',$tenantId)??$tenantId;
    $pending=getDB()->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND provider="quanta"
        AND operation IN ("person.link","assignment.create","assignment.update") AND entity_type=:k
        AND entity_tenant_id=:o AND entity_id=:i AND state IN ("sending","uncertain") ORDER BY id LIMIT 1');
    $pending->execute(['t'=>$tenantId,'k'=>$kind,'o'=>$owner,'i'=>$id]);
    if ($row=$pending->fetch(PDO::FETCH_ASSOC)) return ['status'=>'reconciliation_required','receipt_only'=>true,
        'delivery_enabled'=>quantaMetadataDeliveryEnabled(),'delivery'=>integrationDeliverySummary($row)];
    $snapshot=quantaMetadataSnapshot($tenantId,$kind,$id,$worksiteId);
    $result=['status'=>$snapshot['plan']['status'],'operation'=>$snapshot['plan']['operation'],
        'workspace'=>$snapshot['verified']['context']['workspace'],'reference'=>$snapshot['source']['reference'],
        'proposed'=>$snapshot['plan']['body'],'current'=>$kind==='person'?$snapshot['remote']['person_link']:$snapshot['remote']['assignment'],
        'delivery_enabled'=>quantaMetadataDeliveryEnabled()];
    if ($result['status']==='preview') {
        $row=integrationDeliveryPreview(getDB(),$tenantId,$actorId,$snapshot['context'],$snapshot['payload']);
        $result['delivery']=integrationDeliverySummary($row);
    }
    return $result;
}

/** A successful repeat returns the receipt; an uncertain attempt is never sent again. */
function quantaMetadataSend(int $tenantId,int $actorId,int $id,string $reviewHash): array
{
    if (!quantaMetadataDeliveryEnabled()) throw new RuntimeException('Metadata delivery has not been enabled for this environment');
    $pdo=getDB(); $row=integrationDeliveryGet($pdo,$tenantId,$id);
    if ($row['provider']!=='quanta' || !in_array($row['operation'],['person.link','assignment.create','assignment.update'],true)
        || !hash_equals($row['review_hash'],$reviewHash)) throw new InvalidArgumentException('This delivery does not match the reviewed metadata');
    if ($row['state']==='delivered') return integrationDeliverySummary($row);
    if (!in_array($row['state'],['preview','approved'],true)) throw new RuntimeException('This attempt requires reconciliation or a new review');
    $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    $snapshot=quantaMetadataSnapshot($tenantId,$row['entity_type'],(int)$row['entity_id'],$payload['worksite_id']);
    if ((int)$snapshot['context']['entity_tenant_id']!==(int)$row['entity_tenant_id']
        || $snapshot['context']['operation']!==$row['operation']
        || integrationDeliveryHash($snapshot['payload'])!==integrationDeliveryHash($payload)) throw new RuntimeException('The directory, identity or proposed change has changed. Preview again.');
    if ($row['state']==='preview') integrationDeliveryApprove($pdo,$tenantId,$id,$actorId,$reviewHash);
    $claim=integrationDeliveryClaim($pdo,$tenantId,$id,array_intersect_key($snapshot['context'],array_flip(['binding_hash','source_hash','remote_hash'])));
    try {
        $record=quantaWriteContract($snapshot['verified']['api_key'],$snapshot['verified']['context']['workspace']['id'],
            $row['operation'],$claim['payload']['body'],$claim['payload']['expected'],$claim['idempotency_key']);
    } catch(QuantaContractWriteException $e) {
        integrationDeliveryFinish($pdo,$tenantId,$id,$claim['claim'],$e->uncertain?'uncertain':'failed',null,$e->reason);
        throw $e;
    } catch(Throwable $e) {
        integrationDeliveryFinish($pdo,$tenantId,$id,$claim['claim'],'uncertain',null,'delivery_interrupted');
        throw new RuntimeException('Delivery interrupted; reconcile before trying another change');
    }
    integrationDeliveryFinish($pdo,$tenantId,$id,$claim['claim'],'delivered',$record['id'],null,$record['delivery_id']);
    platformAuditLogWrite($tenantId,$actorId,'quanta.metadata.delivered',null,
        ['delivery_id'=>$id,'remote_id'=>$record['id'],'provider_receipt'=>$record['delivery_id'],'operation'=>$row['operation']],['source'=>'quanta']);
    return integrationDeliverySummary(integrationDeliveryGet($pdo,$tenantId,$id));
}

/** Local pending attempts remain discoverable after deletion or a provider outage. */
function quantaMetadataPending(int $tenantId,?int $before=null): array
{
    if ($before!==null && $before<=0) throw new InvalidArgumentException('Invalid delivery page');
    $people=effectiveTenantIdForModule('people',$tenantId)??$tenantId;
    $placements=effectiveTenantIdForModule('placements',$tenantId)??$tenantId;
    $s=getDB()->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND provider="quanta"
        AND operation IN ("person.link","assignment.create","assignment.update") AND state IN ("sending","uncertain")
        AND ((entity_type="person" AND entity_tenant_id=:people) OR (entity_type="placement" AND entity_tenant_id=:placements))'
        .($before!==null?' AND id<:before':'').' ORDER BY id DESC LIMIT 51');
    $params=['t'=>$tenantId,'people'=>$people,'placements'=>$placements];if($before!==null)$params['before']=$before;
    $s->execute($params);$all=$s->fetchAll(PDO::FETCH_ASSOC);$rows=array_slice($all,0,50);
    return ['rows'=>array_map(static fn($row)=>['delivery'=>integrationDeliverySummary($row)],$rows),
        'next_before'=>count($all)>50?(int)end($rows)['id']:null];
}

function quantaMetadataEnvelope(int $tenantId,int $id,string $reviewHash): array
{
    $row=integrationDeliveryGet(getDB(),$tenantId,$id);
    if ($row['provider']!=='quanta' || !in_array($row['operation'],['person.link','assignment.create','assignment.update'],true)
        || !hash_equals($row['review_hash'],$reviewHash)) throw new InvalidArgumentException('Choose the original metadata delivery');
    $kind=$row['operation']==='person.link'?'person':'placement';
    $owners=[];foreach(['people','placements','ap'] as $module)$owners[$module]=effectiveTenantIdForModule($module,$tenantId)??$tenantId;
    if ($row['entity_type']!==$kind || (int)$row['entity_tenant_id']!==$owners[$kind==='person'?'people':'placements']) {
        throw new RuntimeException('Restore the original directory before checking this receipt');
    }
    $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    $instance=integrationInstanceId();
    $references=$kind==='person'?['person'=>['person','people']]:[
        'person'=>['person','people'],'placement'=>['placement','placements'],
        'client'=>['client','placements'],'vendor'=>['vendor','ap'],'end_client'=>['company','people']];
    foreach($references as $field=>[$type,$module]) {
        if (!array_key_exists($field,$payload['expected']??[])) throw new RuntimeException('Incomplete original metadata identity');
        $reference=$payload['expected'][$field];
        if ($reference===null && !in_array($field,['person','placement'],true)) continue;
        if (!is_array($reference)) throw new RuntimeException('Invalid original metadata identity');
        $reference=quantaCoreReferenceValidate($reference,$type);
        if ($reference['instance_id']!==$instance || $reference['directory_tenant_id']!==$owners[$module]) {
            throw new RuntimeException('Restore the original directory before checking this receipt');
        }
    }
    return [$row,$payload];
}

/** Correlate the immutable original request, not the provider's current mutable record. */
function quantaMetadataReceiptOutcome(array $row,array $payload,string $workspace,array $receipt): array
{
    $kind=$row['operation']==='person.link'?'person':'placement';
    $remoteId=quantaCoreRemoteId($workspace,$payload['expected'][$kind]);
    $path='/api/v1'.QUANTA_CORE_CONTRACT_PATH.($kind==='person'?'/person-links':'/assignments');
    if($row['operation']==='assignment.update')$path.='/'.$remoteId;
    if (!preg_match('/^cfd_[a-f0-9]{64}$/D',(string)($receipt['id']??''))
        || ($receipt['request_path']??null)!==$path
        || ($receipt['request_hash']??null)!==quantaMetadataRequestHash($payload['body'])) {
        throw new RuntimeException('Receipt does not match the original metadata request');
    }
    if (in_array($receipt['state']??null,['in_progress','uncertain'],true)) return ['state'=>'unconfirmed'];
    if (($receipt['state']??null)!=='complete') throw new RuntimeException('Unknown provider receipt state');
    $codes=match($row['operation']){'person.link'=>[200,201],'assignment.create'=>[201],'assignment.update'=>[200]};
    $success=in_array($receipt['status_code']??null,$codes,true);
    if (!$success && !in_array($receipt['status_code']??null,[400,401,403,404,409,422],true)) {
        throw new RuntimeException('Provider result remains uncertain');
    }
    $response=$receipt['response']??null;
    if (!is_array($response) || ($response['delivery_id']??null)!==$receipt['id']) throw new RuntimeException('Receipt identity differs');
    if ($success) {
        if (($response['id']??null)!==$remoteId || ($response['tenant_id']??null)!==$workspace) throw new RuntimeException('Receipt belongs to a different workspace or record');
        $expected=$payload['expected'];
        if ($row['operation']==='assignment.create')$expected['version']=1;
        foreach($expected as $field=>$value) {
            if(!array_key_exists($field,$response) || integrationDeliveryHash([$response[$field]])!==integrationDeliveryHash([$value])) {
                throw new RuntimeException('Receipt differs from the reviewed metadata');
            }
        }
    }
    return ['state'=>$success?'confirmed':'rejected','remote_id'=>$success?$remoteId:null,'receipt_id'=>$receipt['id']];
}

/** GET only at the provider. Neither a missing receipt nor a lost GET releases the send hold. */
function quantaMetadataReceipt(int $tenantId,int $actorId,int $id,string $reviewHash): array
{
    [$row,$payload]=quantaMetadataEnvelope($tenantId,$id,$reviewHash);
    if (!in_array($row['state'],['sending','uncertain'],true)) return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>$row['state']];
    $access=quantaWorkspaceForDelivery($tenantId);$workspace=$access['context']['workspace']['id'];
    if ((string)$access['connection']['id']!==(string)$row['connection_ref'] || !hash_equals($row['binding_hash'],$access['binding_hash'])) {
        throw new RuntimeException('Restore the reviewed connection before checking its receipt');
    }
    quantaMetadataReceiptRequire($access['api_key'],$workspace);
    $query=['operation'=>$row['operation']];
    if($row['operation']==='assignment.update')$query['record_id']=quantaCoreRemoteId($workspace,$payload['expected']['placement']);
    try{$receipt=quantaGet($access['api_key'],QUANTA_CORE_CONTRACT_PATH.'/metadata-deliveries/'.$row['idempotency_key'].'?'.http_build_query($query));}
    catch(QuantaApiException $e){if($e->httpStatus===404)return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>'not_found'];throw $e;}
    $outcome=quantaMetadataReceiptOutcome($row,$payload,$workspace,$receipt);
    if($outcome['state']==='unconfirmed')return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>'unconfirmed'];
    $success=$outcome['state']==='confirmed';$pdo=getDB();$pdo->beginTransaction();
    try {
        quantaWorkspaceAssertCurrent($pdo,$tenantId,$access,true);
        $s=$pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND id=:id FOR UPDATE');
        $s->execute(['t'=>$tenantId,'id'=>$id]);$live=$s->fetch(PDO::FETCH_ASSOC);
        foreach(['payload_ct','binding_hash','review_hash','idempotency_key'] as $field) {
            if(!$live || $live[$field]!==$row[$field])throw new RuntimeException('Original delivery changed during receipt lookup');
        }
        if (in_array($live['state'],['sending','uncertain'],true)) {
            $pdo->prepare('UPDATE integration_deliveries SET state=:s,remote_id=:r,remote_receipt_id=:receipt,error_code=:e,
                claim_hash=NULL,finished_at=NOW() WHERE tenant_id=:t AND id=:id')->execute([
                's'=>$success?'delivered':'failed','r'=>$outcome['remote_id'],'receipt'=>$outcome['receipt_id'],
                'e'=>$success?null:'metadata_rejected','t'=>$tenantId,'id'=>$id]);
            platformAuditLogWrite($tenantId,$actorId,'quanta.metadata.receipt_reconciled',null,
                ['delivery_id'=>$id,'provider_receipt'=>$outcome['receipt_id'],'outcome'=>$outcome['state']],['source'=>'quanta']);
        } elseif ($live['state']!==($success?'delivered':'failed') || $live['remote_receipt_id']!==$outcome['receipt_id']) {
            throw new RuntimeException('Delivery resolution changed during receipt lookup');
        }
        $pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['delivery'=>integrationDeliverySummary(integrationDeliveryGet($pdo,$tenantId,$id)),'receipt_state'=>$outcome['state']];
}
