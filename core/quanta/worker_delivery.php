<?php
/** Reviewed worker profiles, durable delivery and receipt-backed local identity linkage. */
declare(strict_types=1);
require_once __DIR__.'/../integrations/delivery_enablement.php';
require_once __DIR__.'/worker_profile_contract.php';
require_once __DIR__.'/identity.php';
require_once __DIR__.'/../record_numbers.php';
require_once __DIR__.'/../audit.php';

function quantaWorkerDeliveryEnabled(?int $tenant=null): bool
{
    return integrationDeliveryFlagEnabled('COREFLUX_QUANTA_WORKER_DELIVERY') && integrationDeliveryTenantAllowed($tenant);
}

function quantaWorkerDeliveryAccess(int $tenant): array
{
    $access=quantaWorkspaceForDelivery($tenant);
    $profile=quantaWorkerProfileContext($access['api_key']);
    if($profile['workspace']['id']!==$access['context']['workspace']['id'])throw new RuntimeException('The Quanta workspace changed. Verify the connection again.');
    quantaWorkspaceAssertCurrent(getDB(),$tenant,$access);
    return $access;
}

function quantaWorkerDeliverySource(PDO $pdo,string $instance,int $owner,int $personId): array
{
    if($owner<=0 || $personId<=0)throw new InvalidArgumentException('Choose a person in the authorized directory');
    $s=$pdo->prepare('SELECT id,first_name,last_name,email_primary,phone_primary,status FROM people WHERE tenant_id=:t AND id=:id AND deleted_at IS NULL');
    $s->execute(['t'=>$owner,'id'=>$personId]);$person=$s->fetch(PDO::FETCH_ASSOC);
    if(!$person)throw new RuntimeException('This person is unavailable in the current people directory');
    $person=coreRecordNumbersAttach($pdo,$owner,'person',[$person])[0];
    return ['record'=>$person,'reference'=>integrationRecordReference($instance,$owner,'person',$person['person_number'])];
}

/** No email matching; an existing worker requires the reviewed link on both sides. */
function quantaWorkerProfilePlan(array $source,?string $localWorker,?array $profile,array $workers): array
{
    $person=$source['record'];
    if(($person['status']??null)!=='active')throw new RuntimeException('Only an active Core person can be prepared for Quanta');
    $name=trim(implode(' ',array_filter([$person['first_name']??'',$person['last_name']??''],static fn($v)=>$v!=='')));
    $email=strtolower(trim((string)($person['email_primary']??'')));$phone=trim((string)($person['phone_primary']??''));
    if($localWorker!==null && (!$profile || $profile['worker_id']!==$localWorker))throw new RuntimeException('Publish or reconcile the shared person identity for this existing Quanta worker first');
    if($profile && $localWorker===null)throw new RuntimeException('This person already exists in Quanta. Check its earlier delivery or review the existing worker link');
    if(!$profile)foreach($workers as $worker)if(strtolower(trim((string)($worker['email']??'')))===$email)throw new RuntimeException('This email already exists in Quanta. Review and link that worker first');
    $body=quantaWorkerProfileBodyValidate(['person'=>$source['reference'],'email'=>$email,'full_name'=>$name,
        'phone'=>$phone===''?null:$phone,'worker_id'=>$profile['worker_id']??null,'expected_worker'=>$profile['worker']??null]);
    $changes=$profile?array_filter(['full_name'=>$name,'phone'=>$body['phone']],static fn($v,$k)=>$v!==null && $v!==$profile['worker'][$k],ARRAY_FILTER_USE_BOTH)
        :['full_name'=>$name,'email'=>$email,'phone'=>$body['phone']];
    return ['operation'=>$profile?'worker.profile.update':'worker.profile.create','status'=>$changes?'preview':'unchanged',
        'body'=>$body,'changes'=>$changes,'current'=>$profile['worker']??null,
        'message'=>$profile?($changes?'Review the proposed contact changes. Access and provider IDs are preserved.':'These contact fields already agree.')
                           :'Prepare this person as a disabled Quanta worker. Activation and invitations happen in Quanta.'];
}

function quantaWorkerDeliverySnapshot(int $tenant,int $personId,?array $batch=null): array
{
    $pdo=getDB();$access=$batch['access']??quantaWorkerDeliveryAccess($tenant);
    $owner=effectiveTenantIdForModule('people',$tenant)??$tenant;
    $source=quantaWorkerDeliverySource($pdo,$access['instance_id'],$owner,$personId);
    $links=$batch['links']??quantaWorkerLinks($tenant);
    $matches=array_values(array_filter($links,static fn($r)=>(int)$r['person_id']===$personId));
    if(count($matches)>1)throw new RuntimeException('This person has multiple worker links. Review identity before sending');
    $local=$matches?(string)$matches[0]['worker_id']:null;
    $profile=quantaWorkerProfileRead($access['api_key'],$access['context']['workspace']['id'],$source['reference']);
    $workers=$profile?[]:($batch['workers']??quantaListAll($access['api_key'],'/workers',[],5000));
    $plan=quantaWorkerProfilePlan($source,$local,$profile,$workers);
    quantaWorkspaceAssertCurrent($pdo,$tenant,$access);
    $context=['provider'=>'quanta','connection_ref'=>(string)$access['connection']['id'],'binding_hash'=>$access['binding_hash'],
        'entity_type'=>'person','entity_tenant_id'=>$owner,'entity_id'=>$personId,'operation'=>$plan['operation'],
        'source_hash'=>integrationDeliveryHash($source),'remote_hash'=>integrationDeliveryHash(['profile'=>$profile,'local_worker'=>$local])];
    return ['access'=>$access,'source'=>$source,'plan'=>$plan,'context'=>$context,
        'payload'=>['profile_contract_version'=>QUANTA_WORKER_PROFILE_VERSION,'workspace_id'=>$access['context']['workspace']['id'],'body'=>$plan['body']]];
}

function quantaWorkerProfilesPreview(int $tenant,int $actor,array $personIds): array
{
    if(!$personIds || !array_is_list($personIds) || count($personIds)>50)throw new InvalidArgumentException('Choose 1 to 50 people');
    foreach($personIds as $id)if(!is_int($id)||$id<=0)throw new InvalidArgumentException('Invalid person identity');
    $access=quantaWorkerDeliveryAccess($tenant);
    $batch=['access'=>$access,'links'=>quantaWorkerLinks($tenant),'workers'=>quantaListAll($access['api_key'],'/workers',[],5000)];
    $rows=[];$owner=effectiveTenantIdForModule('people',$tenant)??$tenant;
    foreach(array_unique($personIds) as $id){
        // Resolve scope before returning even a blocked row; do not leak other directories.
        $source=quantaWorkerDeliverySource(getDB(),$access['instance_id'],$owner,$id);
        $base=['person_id'=>$id,'person_number'=>$source['reference']['number'],
            'person_name'=>trim($source['record']['first_name'].' '.$source['record']['last_name'])];
        try{
            $snapshot=quantaWorkerDeliverySnapshot($tenant,$id,$batch);$plan=$snapshot['plan'];
            $row=$base+['status'=>$plan['status'],'operation'=>$plan['operation'],'message'=>$plan['message'],
                'payload'=>$plan['changes'],'current'=>$plan['current'],'proposed'=>$plan['body'],'reference'=>$source['reference']];
            if($plan['status']==='preview')$row['delivery']=integrationDeliverySummary(integrationDeliveryPreview(getDB(),$tenant,$actor,$snapshot['context'],$snapshot['payload']));
            $rows[]=$row;
        }catch(InvalidArgumentException|RuntimeException $e){
            $rows[]=$base+['status'=>'blocked','message'=>$e->getMessage(),'payload'=>[]];
        }
    }
    return ['rows'=>$rows,'workspace'=>$access['context']['workspace'],'delivery_enabled'=>quantaWorkerDeliveryEnabled($tenant),
        'hold_reason'=>quantaWorkerDeliveryEnabled($tenant)?'Review each person before sending. Quanta activation and invitations are separate.':'Worker delivery has not been enabled for this environment.'];
}

function quantaWorkerDeliveryEnvelope(int $tenant,int $id,string $reviewHash): array
{
    $pdo=getDB();$row=integrationDeliveryGet($pdo,$tenant,$id);
    if($row['provider']!=='quanta'||$row['entity_type']!=='person'||!in_array($row['operation'],['worker.profile.create','worker.profile.update'],true)
        ||!hash_equals($row['review_hash'],$reviewHash))throw new InvalidArgumentException('Choose the reviewed worker-profile delivery');
    $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    if(($payload['profile_contract_version']??null)!==QUANTA_WORKER_PROFILE_VERSION || !is_array($payload['body']??null))throw new RuntimeException('This is not a current worker-profile review');
    $body=quantaWorkerProfileBodyValidate($payload['body']);$owner=effectiveTenantIdForModule('people',$tenant)??$tenant;
    $instance=integrationInstanceId();
    if(!$instance || (int)$row['entity_tenant_id']!==$owner || $body['person']['instance_id']!==$instance
        || $body['person']['directory_tenant_id']!==$owner)throw new RuntimeException('The people directory or Core installation changed');
    $source=quantaWorkerDeliverySource($pdo,$instance,$owner,(int)$row['entity_id']);
    if(integrationDeliveryHash($source['reference'])!==integrationDeliveryHash($body['person']))throw new RuntimeException('The reviewed person identity changed');
    return [$row,$payload];
}

/** Bypass request-local tenant caches after provider IO and lock the current directory policy. */
function quantaWorkerDeliveryDirectoryLock(PDO $pdo,int $tenant,int $owner): void
{
    if(!$pdo->inTransaction())throw new LogicException('Directory verification requires a transaction');
    $s=$pdo->prepare('SELECT id,parent_id,tenant_type,is_active FROM tenants WHERE id=:t FOR UPDATE');
    $s->execute(['t'=>$tenant]);$current=$s->fetch(PDO::FETCH_ASSOC);
    if(!$current || !(int)$current['is_active'])throw new RuntimeException('The Core workspace is no longer active');
    $s=$pdo->prepare('SELECT scope_mode FROM tenant_module_scope WHERE tenant_id=:t AND module_key="people" FOR UPDATE');
    $s->execute(['t'=>$tenant]);$mode=$s->fetchColumn();
    if(!in_array($mode,['shared','isolated'],true))$mode=SUBTENANT_MODULE_SCOPE_DEFAULTS['people'];
    $resolved=$current['tenant_type']==='sub' && $current['parent_id'] && $mode==='shared'?(int)$current['parent_id']:$tenant;
    if($resolved!==$owner)throw new RuntimeException('The people directory changed. Restore and review its identity before completing this delivery');
}

/** Receipt and local identity mapping commit together, after the provider's write. */
function quantaWorkerDeliveryComplete(int $tenant,int $actor,array $row,array $payload,array $access,array $record,?string $claim): array
{
    $pdo=getDB();if($pdo->inTransaction())throw new LogicException('Worker completion requires its own transaction');
    quantaWorkerProfileConfirm($record,$payload['workspace_id'],$payload['body'],$row['operation']==='worker.profile.create'?201:200);
    if(!hash_equals($row['binding_hash'],$access['binding_hash']) || $payload['workspace_id']!==$access['context']['workspace']['id'])throw new RuntimeException('The connection no longer matches this delivery');
    $pdo->beginTransaction();
    try{
        quantaWorkspaceAssertCurrent($pdo,$tenant,$access,true);
        quantaWorkerDeliveryDirectoryLock($pdo,$tenant,(int)$row['entity_tenant_id']);
        $s=$pdo->prepare('SELECT subject_hash FROM integration_delivery_subjects WHERE tenant_id=:t AND subject_hash=:s FOR UPDATE');
        $s->execute(['t'=>$tenant,'s'=>$row['subject_hash']]);if(!$s->fetchColumn())throw new RuntimeException('Delivery subject is unavailable');
        $s=$pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND id=:id FOR UPDATE');$s->execute(['t'=>$tenant,'id'=>$row['id']]);$live=$s->fetch(PDO::FETCH_ASSOC);
        if(!$live || !hash_equals($live['review_hash'],$row['review_hash']))throw new RuntimeException('The delivery changed');
        if($live['state']==='delivered'){$pdo->commit();return integrationDeliverySummary($live);}
        if(!in_array($live['state'],['sending','uncertain'],true) || ($claim!==null && ($live['state']!=='sending'||!hash_equals((string)$live['claim_hash'],hash('sha256',$claim)))))throw new RuntimeException('This delivery is not awaiting a receipt');
        // Serialize against the existing link writer on the canonical Core person.
        $s=$pdo->prepare('SELECT id FROM people WHERE tenant_id=:t AND id=:id AND deleted_at IS NULL FOR UPDATE');
        $s->execute(['t'=>$row['entity_tenant_id'],'id'=>$row['entity_id']]);if(!$s->fetchColumn())throw new RuntimeException('The Core person is no longer available');
        $current=quantaWorkerDeliverySource($pdo,$access['instance_id'],(int)$row['entity_tenant_id'],(int)$row['entity_id']);
        if(integrationDeliveryHash($current['reference'])!==integrationDeliveryHash($payload['body']['person']))throw new RuntimeException('The canonical person number changed');
        $s=$pdo->prepare('SELECT worker_id,person_id FROM quanta_worker_links WHERE tenant_id=:t AND (worker_id=:w OR person_id=:p) FOR UPDATE');
        $s->execute(['t'=>$tenant,'w'=>$record['worker_id'],'p'=>$row['entity_id']]);$links=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach($links as $link)if($link['worker_id']!==$record['worker_id']||(int)$link['person_id']!==(int)$row['entity_id'])throw new RuntimeException('The worker or person is linked elsewhere. Reconcile that identity before completing this delivery');
        if(!$links)$pdo->prepare('INSERT INTO quanta_worker_links(tenant_id,worker_id,person_id,linked_by_user_id) VALUES(:t,:w,:p,:u)')
            ->execute(['t'=>$tenant,'w'=>$record['worker_id'],'p'=>$row['entity_id'],'u'=>$actor]);
        $pdo->prepare('UPDATE integration_deliveries SET state="delivered",remote_id=:w,remote_receipt_id=:r,error_code=NULL,claim_hash=NULL,finished_at=NOW() WHERE tenant_id=:t AND id=:id')
            ->execute(['w'=>$record['worker_id'],'r'=>$record['delivery_id'],'t'=>$tenant,'id'=>$row['id']]);
        platformAuditLogWrite($tenant,$actor,'quanta.worker_profile.delivered',(int)$row['entity_id'],['delivery_id'=>(int)$row['id'],'worker_id'=>$record['worker_id'],'provider_receipt'=>$record['delivery_id']],['source'=>'quanta']);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return integrationDeliverySummary(integrationDeliveryGet($pdo,$tenant,(int)$row['id']));
}

function quantaWorkerProfilesSend(int $tenant,int $actor,int $id,string $reviewHash): array
{
    if(!quantaWorkerDeliveryEnabled($tenant))throw new RuntimeException('Worker delivery has not been enabled for this environment');
    [$row,$payload]=quantaWorkerDeliveryEnvelope($tenant,$id,$reviewHash);
    if($row['state']==='delivered')return integrationDeliverySummary($row);
    if(!in_array($row['state'],['preview','approved'],true))throw new RuntimeException('Check this delivery’s receipt before another attempt');
    $snapshot=quantaWorkerDeliverySnapshot($tenant,(int)$row['entity_id']);
    if($snapshot['context']['operation']!==$row['operation'] || integrationDeliveryHash($payload)!==integrationDeliveryHash($snapshot['payload']))throw new RuntimeException('The proposed contact fields or worker changed. Preview again');
    $pdo=getDB();if($row['state']==='preview')integrationDeliveryApprove($pdo,$tenant,$id,$actor,$reviewHash);
    $claim=integrationDeliveryClaim($pdo,$tenant,$id,array_intersect_key($snapshot['context'],array_flip(['binding_hash','source_hash','remote_hash'])));
    try{
        $record=quantaWriteWorkerProfile($snapshot['access']['api_key'],$payload['workspace_id'],$payload['body'],$claim['idempotency_key']);
        return quantaWorkerDeliveryComplete($tenant,$actor,$row,$payload,$snapshot['access'],$record,$claim['claim']);
    }catch(Throwable $e){
        $live=integrationDeliveryGet($pdo,$tenant,$id);
        if($live['state']==='sending')integrationDeliveryFinish($pdo,$tenant,$id,$claim['claim'],
            $e instanceof QuantaContractWriteException&&!$e->uncertain?'failed':'uncertain',null,'worker_profile_unconfirmed');
        throw $e;
    }
}

function quantaWorkerProfilesStatus(int $tenant,int $actor,int $id,string $reviewHash): array
{
    [$row,$payload]=quantaWorkerDeliveryEnvelope($tenant,$id,$reviewHash);
    if(!in_array($row['state'],['sending','uncertain'],true))return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>$row['state']==='delivered'?'confirmed':'not_pending'];
    $access=quantaWorkerDeliveryAccess($tenant);
    if(!hash_equals($row['binding_hash'],$access['binding_hash']) || $payload['workspace_id']!==$access['context']['workspace']['id'])throw new RuntimeException('Restore the original reviewed connection before checking this receipt');
    $receipt=quantaWorkerProfileReceipt($access['api_key'],$payload['workspace_id'],$payload['body'],$row['idempotency_key']);
    if(!$receipt || $receipt['state']!=='complete')return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>$receipt['state']??'not_found',
        'message'=>'The provider has not confirmed this attempt. It remains held; no record was resent.'];
    if(in_array($receipt['status_code']??null,[200,201],true)){
        $current=quantaWorkerProfileRead($access['api_key'],$payload['workspace_id'],$payload['body']['person']);
        if(!$current || $current['worker_id']!==$receipt['response']['worker_id'])throw new RuntimeException('The confirmed worker identity is no longer available. Reconcile it before completing the delivery');
        $summary=quantaWorkerDeliveryComplete($tenant,$actor,$row,$payload,$access,$receipt['response'],null);
        return ['delivery'=>$summary,'receipt_state'=>'confirmed'];
    }
    if(in_array($receipt['status_code']??null,[400,401,403,404,409,422],true)){
        $pdo=getDB();if($pdo->inTransaction())throw new LogicException('Receipt rejection requires its own transaction');
        $pdo->beginTransaction();
        try{
            quantaWorkspaceAssertCurrent($pdo,$tenant,$access,true);
            quantaWorkerDeliveryDirectoryLock($pdo,$tenant,(int)$row['entity_tenant_id']);
            $pdo->prepare('UPDATE integration_deliveries SET state="failed",error_code="provider_rejected_profile",remote_receipt_id=:r,claim_hash=NULL,finished_at=NOW() WHERE tenant_id=:t AND id=:id AND state IN ("sending","uncertain")')
                ->execute(['r'=>$receipt['id'],'t'=>$tenant,'id'=>$id]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return ['delivery'=>integrationDeliverySummary(integrationDeliveryGet($pdo,$tenant,$id)),'receipt_state'=>'rejected'];
    }
    throw new RuntimeException('The provider receipt is not a confirmed success or rejection');
}

function quantaWorkerProfilesHistory(int $tenant,int $personId,?int $before=null): array
{
    $owner=effectiveTenantIdForModule('people',$tenant)??$tenant;$instance=integrationInstanceId();
    if(!$instance)throw new RuntimeException('The Core installation identity is unavailable');
    quantaWorkerDeliverySource(getDB(),$instance,$owner,$personId);
    if($before!==null&&$before<=0)throw new InvalidArgumentException('Invalid history cursor');
    $s=getDB()->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND provider="quanta" AND entity_type="person" AND entity_tenant_id=:owner AND entity_id=:person AND operation IN ("worker.profile.create","worker.profile.update")'.($before===null?'':' AND id<:before').' ORDER BY id DESC LIMIT 21');
    $params=['t'=>$tenant,'owner'=>$owner,'person'=>$personId];if($before!==null)$params['before']=$before;$s->execute($params);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>20;$rows=array_slice($rows,0,20);
    return ['rows'=>array_map('integrationDeliverySummary',$rows),'next_before'=>$more?(int)end($rows)['id']:null];
}
