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
