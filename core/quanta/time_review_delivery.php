<?php
/** Reviewed status return for one original imported Quanta entry and all its components. */
declare(strict_types=1);
require_once __DIR__.'/../integrations/delivery_enablement.php';
require_once __DIR__.'/time_review_contract.php';
require_once __DIR__.'/metadata_delivery.php';
require_once __DIR__.'/sync.php';

function quantaTimeReviewEnabled(?int $tenant=null): bool
{
    return integrationDeliveryFlagEnabled('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY') && integrationDeliveryTenantAllowed($tenant);
}

function quantaTimeReviewProof(array $entry,array $route,array $imports,string $workspace): array
{
    $prior=$imports[$entry['id']]??[];
    if(!$prior || count($prior)!==count($entry['components'])) throw new RuntimeException('Import every source time component before sharing a Core review');
    if(empty($entry['source_approval']['timesheet_id']) || empty($entry['source_approval']['approved_at'])) throw new RuntimeException('Current source approval evidence is required before sharing time status');
    $components=[];
    foreach($entry['components'] as $name=>$hours) {
        $r=$prior[$name]??[];
        if(($r['source_workspace_id']??null)!==$workspace || !quantaTimeSourceMatches($entry,$route,$name,$hours,$r)
            || !quantaTimeApprovalMatches($entry,$r)) throw new RuntimeException('Source approval, routing or imported hours changed. Reconcile time before sharing its status.');
        $state=$r['entry_status'];
        if(!in_array($state,['draft','pending_review','approved','rejected'],true)) throw new RuntimeException('This Core time state cannot be published');
        if($state==='approved' && ((int)($r['approved_by_user_id']??0)<=0 || empty($r['approved_at']) || (int)($r['rate_snapshot_id']??0)<=0)) throw new RuntimeException('Approved Core time requires its native approval and rate evidence');
        $components[]=['entry_id'=>(int)$r['time_entry_id'],'component'=>$name,'status'=>$state];
    }
    usort($components,static fn($a,$b)=>strcmp($a['component'],$b['component']));
    return ['components'=>$components,'core_revision'=>integrationDeliveryHash(['entry'=>$entry,'route'=>$route,'imports'=>$prior])];
}

function quantaTimeReviewSnapshot(int $tenantId,string $entryId): array
{
    if(!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$entryId)) throw new InvalidArgumentException('Choose an imported Quanta time entry');
    $access=quantaWorkspaceForDelivery($tenantId);$workspace=$access['context']['workspace']['id'];
    $imports=quantaImportLookup($tenantId,[$entryId],false,true);$prior=$imports[$entryId]??[];
    if(!$prior) throw new RuntimeException('This source time has not been imported into Core');
    $placements=array_unique(array_map('intval',array_column($prior,'placement_id')));
    $people=array_unique(array_map('intval',array_column($prior,'person_id')));
    if(count($placements)!==1 || count($people)!==1 || min($placements)<=0 || min($people)<=0) throw new RuntimeException('Imported component identities require reconciliation');
    $owner=effectiveTenantIdForModule('placements',$tenantId)??$tenantId;
    $peopleOwner=effectiveTenantIdForModule('people',$tenantId)??$tenantId;
    $placement=quantaMetadataReference(getDB(),$access['instance_id'],$owner,'placement',reset($placements));
    $person=quantaMetadataReference(getDB(),$access['instance_id'],$peopleOwner,'person',reset($people));
    $assignmentId=quantaCoreRemoteId($workspace,$placement);
    $remote=quantaGet($access['api_key'],QUANTA_CORE_CONTRACT_PATH.'/time-review-context',
        ['assignment_id'=>$assignmentId,'source_entry_id'=>$entryId]);
    $assignment=$remote['assignment']??[];
    if(($remote['contract_version']??null)!==QUANTA_TIME_REVIEW_VERSION || ($remote['workspace_id']??null)!==$workspace
        || ($remote['approval_authority']??null)!==false || ($remote['financial_execution']??null)!==false
        || ($assignment['id']??null)!==$assignmentId || !is_int($assignment['version']??null) || $assignment['version']<1
        || integrationDeliveryHash($assignment['placement']??[])!==integrationDeliveryHash($placement)
        || integrationDeliveryHash($assignment['person']??[])!==integrationDeliveryHash($person)
        || !is_array($remote['source_entry']??null) || ($remote['source_entry']['id']??null)!==$entryId) throw new RuntimeException('Quanta returned time outside the reviewed assignment');
    $entry=quantaNormalizeEntry($remote['source_entry'],[],'approved');
    $route=quantaRouteFor(quantaRoutes($tenantId),$entry,quantaWorkerPersonMap(quantaWorkerLinks($tenantId)));
    if((int)$route['placement_id']!==reset($placements) || (int)$route['person_id']!==reset($people)
        || ($assignment['worker_id']??null)!==$entry['worker_id']) throw new RuntimeException('Current time routing differs from the shared assignment');
    $proof=quantaTimeReviewProof($entry,$route,$imports,$workspace);
    $current=$remote['current']??null;
    if($current!==null && (!is_array($current) || !is_int($current['version']??null) || $current['version']<1
        || ($current['tenant_id']??null)!==$workspace || ($current['assignment_id']??null)!==$assignmentId
        || ($current['source_entry_id']??null)!==$entryId)) throw new RuntimeException('Invalid existing time review identity');
    $body=quantaTimeReviewBody(['contract_version'=>QUANTA_TIME_REVIEW_VERSION,'assignment_id'=>$assignmentId,
        'assignment_version'=>$assignment['version'],'source_entry_id'=>$entryId,'source_revision'=>$remote['source_revision']??null,
        'core_tenant_id'=>$tenantId,'core_revision'=>$proof['core_revision'],'expected_version'=>$current['version']??0,'components'=>$proof['components']]);
    $expected=quantaTimeReviewExpected($workspace,$body);
    if($current && ($current['id']??null)!==$expected['id']) throw new RuntimeException('The provider review identity changed');
    $unchanged=$current && integrationDeliveryHash($current['publication']??[])===integrationDeliveryHash($expected['publication']);
    $context=['provider'=>'quanta','connection_ref'=>(string)$access['connection']['id'],'binding_hash'=>$access['binding_hash'],
        'entity_type'=>'placement','entity_tenant_id'=>$owner,'entity_id'=>reset($placements),'operation'=>'time.review',
        'source_hash'=>$proof['core_revision'],'remote_hash'=>integrationDeliveryHash($remote)];
    quantaWorkspaceAssertCurrent(getDB(),$tenantId,$access);
    return compact('access','context','remote','entry','route')+['payload'=>['workspace_id'=>$workspace,'body'=>$body,'expected'=>$expected],
        'status'=>$unchanged?'unchanged':'preview','placement_number'=>$placement['number']];
}

/** Local recovery remains available when a source entry is revoked, deleted or outside the feed window. */
function quantaTimeReviewPending(int $tenantId,?int $before=null): array
{
    if($before!==null && $before<=0) throw new InvalidArgumentException('Invalid delivery page');
    $owner=effectiveTenantIdForModule('placements',$tenantId)??$tenantId;
    $stmt=getDB()->prepare('SELECT id,provider,entity_type,entity_tenant_id,entity_id,operation,state,review_hash,
        remote_id,remote_receipt_id,error_code,created_at,expires_at,approved_at,attempted_at,finished_at
        FROM integration_deliveries WHERE tenant_id=:t AND provider="quanta" AND operation="time.review"
        AND entity_type="placement" AND entity_tenant_id=:o AND state IN ("sending","uncertain")'
        .($before!==null?' AND id<:before':'').' ORDER BY id DESC LIMIT 51');
    $params=['t'=>$tenantId,'o'=>$owner];if($before!==null)$params['before']=$before;
    $stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>50;
    $rows=array_slice($rows,0,50);
    $rows=coreRecordNumbersAttachRelated(getDB(),$owner,'placement',$rows,'entity_id');
    return ['rows'=>array_map(static fn($row)=>['delivery'=>integrationDeliverySummary($row),
        'placement_number'=>$row['placement_number']],$rows),
        'next_before'=>$more?(int)end($rows)['id']:null];
}

function quantaTimeReviewPreview(int $tenantId,int $actorId,string $entryId): array
{
    if(!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$entryId)) throw new InvalidArgumentException('Choose imported Quanta time');
    // Recovery survives a reload and source revocation; it must not depend on a new source observation.
    $owner=effectiveTenantIdForModule('placements',$tenantId)??$tenantId;
    $pending=getDB()->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND provider="quanta" AND operation="time.review" AND entity_tenant_id=:o AND state IN ("sending","uncertain") ORDER BY id DESC LIMIT 201');
    $pending->execute(['t'=>$tenantId,'o'=>$owner]);$rows=$pending->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $row) {
        $saved=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
        if(($saved['body']['source_entry_id']??null)===$entryId) return ['status'=>'reconciliation_required',
            'receipt_only'=>true,'delivery_enabled'=>quantaTimeReviewEnabled($tenantId),'delivery'=>integrationDeliverySummary($row)];
    }
    if(count($rows)>200) throw new RuntimeException('Reconcile pending time deliveries before preparing more statuses');
    $s=quantaTimeReviewSnapshot($tenantId,$entryId);
    $result=['status'=>$s['status'],'workspace'=>$s['access']['context']['workspace'],'placement_number'=>$s['placement_number'],
        'work_date'=>$s['entry']['work_date'],'state'=>$s['payload']['expected']['state'],
        'components'=>$s['payload']['body']['components'],'delivery_enabled'=>quantaTimeReviewEnabled($tenantId)];
    if($s['status']==='preview') $result['delivery']=integrationDeliverySummary(integrationDeliveryPreview(getDB(),$tenantId,$actorId,$s['context'],$s['payload']));
    return $result;
}

function quantaTimeReviewEnvelope(int $tenantId,int $id,string $reviewHash): array
{
    $row=integrationDeliveryGet(getDB(),$tenantId,$id);
    $owner=effectiveTenantIdForModule('placements',$tenantId)??$tenantId;
    if($row['provider']!=='quanta' || $row['operation']!=='time.review' || $row['entity_type']!=='placement'
        || (int)$row['entity_tenant_id']!==$owner || !hash_equals($row['review_hash'],$reviewHash)) throw new InvalidArgumentException('This time review is outside the current directory or reviewed request');
    $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    quantaTimeReviewBody($payload['body']);
    if($payload['body']['core_tenant_id']!==$tenantId
        || integrationDeliveryHash(quantaTimeReviewExpected($payload['workspace_id'],$payload['body']))!==integrationDeliveryHash($payload['expected'])) throw new RuntimeException('Invalid saved review envelope');
    return [$row,$payload];
}

function quantaTimeReviewSend(int $tenantId,int $actorId,int $id,string $reviewHash): array
{
    if(!quantaTimeReviewEnabled($tenantId)) throw new RuntimeException('Time review sharing has not been enabled for this environment');
    [$row,$payload]=quantaTimeReviewEnvelope($tenantId,$id,$reviewHash);
    if($row['state']==='delivered') return integrationDeliverySummary($row);
    if(!in_array($row['state'],['preview','approved'],true)) throw new RuntimeException('Check the original receipt before preparing another status');
    $s=quantaTimeReviewSnapshot($tenantId,$payload['body']['source_entry_id']);$pdo=getDB();
    if(integrationDeliveryHash($s['payload'])!==integrationDeliveryHash($payload)
        || $s['context']['entity_id']!==(int)$row['entity_id']) throw new RuntimeException('Time or provider status changed; review it again');
    if($row['state']==='preview') integrationDeliveryApprove($pdo,$tenantId,$id,$actorId,$reviewHash);
    $claim=integrationDeliveryClaim($pdo,$tenantId,$id,array_intersect_key($s['context'],array_flip(['binding_hash','source_hash','remote_hash'])),
        static function()use($pdo,$tenantId,$s,$payload):void {
            quantaWorkspaceAssertCurrent($pdo,$tenantId,$s['access'],true);
            $imports=quantaImportLookup($tenantId,[$s['entry']['id']],true,true);
            $route=quantaRouteFor(quantaRoutes($tenantId),$s['entry'],quantaWorkerPersonMap(quantaWorkerLinks($tenantId,true)));
            $proof=quantaTimeReviewProof($s['entry'],$route,$imports,$payload['workspace_id']);
            if($proof['core_revision']!==$payload['body']['core_revision']) throw new RuntimeException('Core time changed before delivery');
        });
    try {
        $record=quantaWriteContract($s['access']['api_key'],$payload['workspace_id'],'time.review',$payload['body'],$payload['expected'],$claim['idempotency_key']);
    }catch(QuantaContractWriteException $e) {
        integrationDeliveryFinish($pdo,$tenantId,$id,$claim['claim'],$e->uncertain?'uncertain':'failed',null,$e->reason);throw $e;
    }catch(Throwable $e) {
        integrationDeliveryFinish($pdo,$tenantId,$id,$claim['claim'],'uncertain',null,'time_review_interrupted');
        throw new RuntimeException('Status delivery needs receipt verification');
    }
    integrationDeliveryFinish($pdo,$tenantId,$id,$claim['claim'],'delivered',$record['id'],null,$record['delivery_id']);
    platformAuditLogWrite($tenantId,$actorId,'quanta.time_review.delivered',null,['delivery_id'=>$id,'state'=>$record['state']],['source'=>'quanta']);
    return integrationDeliverySummary(integrationDeliveryGet($pdo,$tenantId,$id));
}

/** Reconciliation only reads the original provider receipt; it never issues a second POST. */
function quantaTimeReviewReceipt(int $tenantId,int $id,string $reviewHash): array
{
    [$row,$payload]=quantaTimeReviewEnvelope($tenantId,$id,$reviewHash);
    if(!in_array($row['state'],['sending','uncertain'],true)) return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>$row['state']];
    $access=quantaWorkspaceForDelivery($tenantId);
    if(!hash_equals($row['binding_hash'],$access['binding_hash']) || $payload['workspace_id']!==$access['context']['workspace']['id']) throw new RuntimeException('Restore the reviewed connection before checking its receipt');
    try{$receipt=quantaGet($access['api_key'],QUANTA_CORE_CONTRACT_PATH.'/time-review-deliveries/'.$row['idempotency_key']);}
    catch(QuantaApiException $e){if($e->httpStatus===404)return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>'not_found'];throw $e;}
    if(($receipt['request_hash']??null)!==quantaTimeReviewRequestHash($payload['body']) || !preg_match('/^cfd_[a-f0-9]{64}$/D',(string)($receipt['id']??''))) throw new RuntimeException('Receipt does not match the original time review');
    if(($receipt['state']??null)!=='complete') return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>'unconfirmed'];
    $success=in_array($receipt['status_code']??null,[200,201],true);
    if($success) {
        foreach($payload['expected'] as $k=>$v) if(!array_key_exists($k,$receipt['response']??[]) || integrationDeliveryHash([$receipt['response'][$k]])!==integrationDeliveryHash([$v])) throw new RuntimeException('Receipt differs from the reviewed status');
        if(($receipt['response']['delivery_id']??null)!==$receipt['id']) throw new RuntimeException('Receipt identity differs');
    }elseif(!in_array($receipt['status_code']??null,[400,401,403,404,409,422],true)) throw new RuntimeException('Provider result remains uncertain');
    $pdo=getDB();$pdo->beginTransaction();
    try {
        quantaWorkspaceAssertCurrent($pdo,$tenantId,$access,true);
        $pdo->prepare('UPDATE integration_deliveries SET state=:s,remote_id=:r,remote_receipt_id=:receipt,error_code=:e,claim_hash=NULL,finished_at=NOW() WHERE tenant_id=:t AND id=:id AND state IN ("sending","uncertain")')
            ->execute(['s'=>$success?'delivered':'failed','r'=>$success?$payload['expected']['id']:null,'receipt'=>$receipt['id'],
                'e'=>$success?null:'time_review_rejected','t'=>$tenantId,'id'=>$id]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['delivery'=>integrationDeliverySummary(integrationDeliveryGet($pdo,$tenantId,$id)),'receipt_state'=>$success?'confirmed':'rejected'];
}
