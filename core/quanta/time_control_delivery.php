<?php
/** Reviewed worker/worksite time eligibility. No hours, approvals or financial writes. */
declare(strict_types=1);
require_once __DIR__.'/metadata_delivery.php';
require_once __DIR__.'/time_control_contract.php';

function quantaTimeControlEnabled(?int $tenant=null): bool
{
    return integrationDeliveryFlagEnabled('COREFLUX_QUANTA_TIME_ACCESS_DELIVERY')&&integrationDeliveryTenantAllowed($tenant);
}

function quantaTimeControlOwners(int $tenant,bool $lock=false): array
{
    $pdo=getDB();if($lock&&!$pdo->inTransaction())throw new LogicException('Directory locks require a transaction');
    // Do not use the request-local tenant cache at a claim/recovery boundary.
    $q=$pdo->prepare('SELECT id,parent_id,tenant_type FROM tenants WHERE id=:t'.($lock?' FOR UPDATE':''));$q->execute(['t'=>$tenant]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Core company is unavailable');
    $q=$pdo->prepare('SELECT module_key,scope_mode FROM tenant_module_scope WHERE tenant_id=:t'.($lock?' FOR UPDATE':''));$q->execute(['t'=>$tenant]);
    $modes=array_column($q->fetchAll(PDO::FETCH_ASSOC),'scope_mode','module_key');$owners=[];
    foreach(['people','placements','companies','ap'] as $module){
        $mode=$modes[$module]??SUBTENANT_MODULE_SCOPE_DEFAULTS[$module];
        if(!in_array($mode,['shared','isolated'],true))throw new RuntimeException('Directory ownership needs review');
        $owners[$module]=$row['tenant_type']==='sub'&&!empty($row['parent_id'])&&$mode==='shared'?(int)$row['parent_id']:$tenant;
    }
    return $owners;
}

function quantaTimeControlPlacementIds(array $ids): array
{
    if(!array_is_list($ids)||count($ids)>50)throw new InvalidArgumentException('Choose up to 50 placements');
    foreach($ids as $id)if(!is_int($id)||$id<=0)throw new InvalidArgumentException('Choose Core placement records');
    if(count(array_unique($ids))!==count($ids))throw new InvalidArgumentException('Repeated placement');
    sort($ids,SORT_NUMERIC);return $ids;
}

function quantaTimeControlOwned(array $identity,string $workspace,string $worker,string $instance,array $owners,array $person): array
{
    $identity=quantaTimeControlIdentity($identity,$workspace,$worker);
    foreach(['person'=>'people','placement'=>'placements','client'=>'placements','vendor'=>'ap','end_client'=>'companies'] as $field=>$module){
        $ref=$identity[$field];if($ref===null)continue;
        if($ref['instance_id']!==$instance||$ref['directory_tenant_id']!==$owners[$module])throw new RuntimeException('This worksite includes assignments from another Core directory. Reconcile ownership first.');
    }
    if(integrationDeliveryHash($identity['person'])!==integrationDeliveryHash($person))throw new RuntimeException('The assignment belongs to a different Core person');
    return $identity;
}

/** Only canonical records from the currently authorized directories enter the envelope. */
function quantaTimeControlSource(int $tenant,string $instance,int $personId,array $ids,bool $lock=false): array
{
    $ids=quantaTimeControlPlacementIds($ids);$owners=quantaTimeControlOwners($tenant,$lock);$pdo=getDB();
    $person=quantaMetadataSource($pdo,$instance,$owners,'person',$personId,$lock);
    if($ids&&($person['person_record']['status']??null)!=='active')throw new RuntimeException('Only an active Core person can receive assignment time. Review an empty selection to hold new time.');
    $links=array_values(array_filter(quantaWorkerLinks($tenant,$lock),static fn($r)=>(int)$r['person_id']===$personId));
    if(count($links)!==1)throw new RuntimeException('Link this person to exactly one Quanta worker first');
    $worker=quantaTimeControlIdentifier($links[0]['worker_id']);$placements=[];
    foreach($ids as $id){
        $source=quantaMetadataSource($pdo,$instance,$owners,'placement',$id,$lock);
        if((int)$source['person_record']['id']!==$personId)throw new RuntimeException('Selected placement belongs to another person');
        $placements[]=$source;
    }
    return ['owners'=>$owners,'person'=>$person,'worker_id'=>$worker,'placements'=>$placements];
}

function quantaTimeControlContext(int $tenant,int $personId,string $site): array
{
    quantaTimeControlIdentifier($site);$access=quantaWorkspaceForDelivery($tenant);$workspace=$access['context']['workspace']['id'];
    $source=quantaTimeControlSource($tenant,$access['instance_id'],$personId,[]);$worker=$source['worker_id'];
    $link=quantaContractRecord($access['api_key'],$workspace,$source['person']['reference']);
    if(!$link||($link['worker_id']??null)!==$worker)throw new RuntimeException('Publish and reconcile the shared person identity first');
    $remote=quantaGet($access['api_key'],QUANTA_CORE_CONTRACT_PATH.'/time-access-context',['worker_id'=>$worker,'worksite_id'=>$site]);
    $hash=$remote['context_hash']??null;$hashed=$remote;unset($hashed['context_hash']);
    if(($remote['contract_version']??null)!==QUANTA_TIME_CONTROL_VERSION||($remote['workspace_id']??null)!==$workspace
        ||($remote['worker_id']??null)!==$worker||($remote['worksite_id']??null)!==$site||!is_string($hash)
        ||!hash_equals(quantaMetadataRequestHash($hashed),$hash)||!is_bool($remote['configuration_enabled']??null)
        ||($remote['worker_status']??null)!=='active'||($remote['worksite_active']??null)!==true
        ||!is_array($remote['assignments']??null)||!array_is_list($remote['assignments'])||count($remote['assignments'])>100
        ||!is_int($remote['unbound_time']['count']??null)||$remote['unbound_time']['count']<0||!is_bool($remote['unbound_time']['complete']??null))throw new RuntimeException('Quanta returned an incomplete or foreign time setup');
    quantaTimeControlIdentifier($remote['native_membership_id']??null);
    $owned=static fn($identity)=>quantaTimeControlOwned($identity,$workspace,$worker,$access['instance_id'],$source['owners'],$source['person']['reference']);
    $stmt=getDB()->prepare('SELECT id FROM placements WHERE tenant_id=:t AND person_id=:p AND deleted_at IS NULL ORDER BY id LIMIT 1001');
    $stmt->execute(['t'=>$source['owners']['placements'],'p'=>$personId]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)>1000)throw new RuntimeException('Review the person placement directory before configuring time');
    $byNumber=[];foreach(coreRecordNumbersAttach(getDB(),$source['owners']['placements'],'placement',$rows) as $row)$byNumber[$row['placement_number']]=(int)$row['id'];
    $choices=[];$seen=[];
    foreach($remote['assignments'] as $candidate){
        $identity=$owned($candidate['identity']??[]);$id=$identity['id'];
        if(isset($seen[$id])||!is_int($candidate['version']??null)||$candidate['version']<1)throw new RuntimeException('Invalid or repeated assignment revision');
        $seen[$id]=true;
        $choices[]=['placement_id'=>$byNumber[$identity['placement']['number']]??null,'placement_number'=>$identity['placement']['number'],
            'title'=>$candidate['title']??'','status'=>$candidate['status']??'', 'start_date'=>$identity['start_date'],'end_date'=>$candidate['end_date']??null,
            'assignment_id'=>$id];
    }
    $current=$remote['current']??null;
    if($current!==null){
        if(!is_array($current)||($current['id']??null)!=='cfta_'.integrationDeliveryHash([$workspace,$worker,$site])
            ||($current['tenant_id']??null)!==$workspace||($current['worker_id']??null)!==$worker||($current['worksite_id']??null)!==$site
            ||!is_int($current['version']??null)||$current['version']<1||!is_array($current['assignments']??null)||!array_is_list($current['assignments']))throw new RuntimeException('Existing time setup requires identity reconciliation');
        foreach($current['assignments'] as $selected)$owned($selected['identity']??[]);
    }
    $selectedIds=array_column(array_column($current['assignments']??[],'identity'),'id');
    foreach($choices as &$choice)$choice['selected']=in_array($choice['assignment_id'],$selectedIds,true);unset($choice);
    quantaWorkspaceAssertCurrent(getDB(),$tenant,$access);
    return compact('access','source','remote','link')+['view'=>['workspace'=>$access['context']['workspace'],
        'person_number'=>$source['person']['reference']['number'],'worker_name'=>$remote['worker_name']??$worker,'worksite_name'=>$remote['worksite_name']??$site,
        'context_hash'=>$hash,'choices'=>$choices,'current_version'=>$current['version']??0,'unbound_time'=>$remote['unbound_time'],
        'delivery_enabled'=>quantaTimeControlEnabled($tenant)&&$remote['configuration_enabled']]];
}

function quantaTimeControlSnapshot(int $tenant,int $personId,string $site,array $ids,?string $reviewedHash=null): array
{
    $ids=quantaTimeControlPlacementIds($ids);$s=quantaTimeControlContext($tenant,$personId,$site);$remote=$s['remote'];
    if($reviewedHash!==null&&!hash_equals($remote['context_hash'],$reviewedHash))throw new RuntimeException('Time setup changed. Refresh and review the assignments again.');
    if((!$remote['current']||$ids)&&($remote['unbound_time']['count']||!$remote['unbound_time']['complete']))throw new RuntimeException('Review existing unlinked time before enabling assignment time. Existing policies can still hold new time.');
    $source=quantaTimeControlSource($tenant,$s['access']['instance_id'],$personId,$ids);
    if(integrationDeliveryHash($source['person'])!==integrationDeliveryHash($s['source']['person'])||$source['worker_id']!==$s['source']['worker_id'])throw new RuntimeException('Person identity changed during review');
    $workspace=$s['access']['context']['workspace']['id'];$selected=[];$records=[];
    foreach($source['placements'] as $placement){
        $id=quantaCoreRemoteId($workspace,$placement['reference']);
        $candidates=array_values(array_filter($remote['assignments'],static fn($r)=>$r['identity']['id']===$id));
        if(count($candidates)!==1||!in_array($candidates[0]['status'],['draft','active','ended'],true))throw new RuntimeException('The selected placement is unavailable at this worksite');
        $candidate=$candidates[0];$record=quantaContractRecord($s['access']['api_key'],$workspace,$placement['reference']);
        $plan=quantaAssignmentPlan($workspace,$placement['assignment']+['worksite_id'=>$site],$record);
        $identity=array_intersect_key($record??[],array_flip(array_keys($candidate['identity'])));
        if($plan['status']!=='unchanged'||($record['version']??null)!==$candidate['version']||integrationDeliveryHash($identity)!==integrationDeliveryHash($candidate['identity']))throw new RuntimeException('Publish the current assignment details before enabling its time');
        $selected[]=['id'=>$id,'version'=>$record['version']];$records[]=$record;
    }
    $body=quantaTimeControlBody(['contract_version'=>QUANTA_TIME_CONTROL_VERSION,'worker_id'=>$source['worker_id'],'worksite_id'=>$site,
        'expected_version'=>$remote['current']['version']??0,'expected_context_hash'=>$remote['context_hash'],'assignments'=>$selected,'confirm_core_control'=>true]);
    $expected=quantaTimeControlExpected($workspace,$body,$remote);
    $context=['provider'=>'quanta','connection_ref'=>(string)$s['access']['connection']['id'],'binding_hash'=>$s['access']['binding_hash'],
        'entity_type'=>'person','entity_tenant_id'=>$source['owners']['people'],'entity_id'=>$personId,'operation'=>'time.access',
        'source_hash'=>integrationDeliveryHash($source),'remote_hash'=>integrationDeliveryHash([$remote,$s['link'],$records])];
    return $s+['context'=>$context,'selected_source'=>$source,'payload'=>['workspace_id'=>$workspace,'person'=>$source['person']['reference'],
        'placement_ids'=>$ids,'body'=>$body,'expected'=>$expected]];
}

function quantaTimeControlPending(int $tenant,?int $before=null): array
{
    if($before!==null&&$before<=0)throw new InvalidArgumentException('Invalid delivery page');
    $q=getDB()->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND provider="quanta" AND operation="time.access"
        AND entity_type="person" AND entity_tenant_id=:o AND state IN ("sending","uncertain")'.($before?' AND id<:before':'').' ORDER BY id DESC LIMIT 51');
    $params=['t'=>$tenant,'o'=>quantaTimeControlOwners($tenant)['people']];if($before)$params['before']=$before;
    $q->execute($params);$all=$q->fetchAll(PDO::FETCH_ASSOC);$rows=array_slice($all,0,50);
    return ['rows'=>array_map(static fn($r)=>['delivery'=>integrationDeliverySummary($r)],$rows),'next_before'=>count($all)>50?(int)end($rows)['id']:null];
}

function quantaTimeControlPreview(int $tenant,int $actor,int $personId,string $site,array $ids,string $reviewedHash): array
{
    $q=getDB()->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND provider="quanta" AND entity_type="person"
        AND entity_tenant_id=:o AND entity_id=:p AND state IN ("sending","uncertain") ORDER BY id LIMIT 1');
    $q->execute(['t'=>$tenant,'o'=>quantaTimeControlOwners($tenant)['people'],'p'=>$personId]);
    if($row=$q->fetch(PDO::FETCH_ASSOC)){
        if($row['operation']!=='time.access')throw new RuntimeException('Reconcile this person’s earlier delivery before changing time setup');
        return ['receipt_only'=>true,'delivery'=>integrationDeliverySummary($row)];
    }
    $s=quantaTimeControlSnapshot($tenant,$personId,$site,$ids,$reviewedHash);
    $row=integrationDeliveryPreview(getDB(),$tenant,$actor,$s['context'],$s['payload']);
    return $s['view']+['selected_placements'=>array_column(array_column($s['selected_source']['placements'],'reference'),'number'),
        'delivery'=>integrationDeliverySummary($row),'receipt_only'=>false];
}

function quantaTimeControlPayloadOwners(array $payload,array $owners): void
{
    $body=quantaTimeControlBody($payload['body']);quantaTimeControlExpectedValidate($payload['workspace_id'],$body,$payload['expected']);
    $person=quantaCoreReferenceValidate($payload['person'],'person');$instance=integrationInstanceId();
    if($person['instance_id']!==$instance||$person['directory_tenant_id']!==$owners['people'])throw new RuntimeException('Restore the original person directory before checking this setup');
    foreach($payload['expected']['assignments'] as $selected)quantaTimeControlOwned($selected['identity'],$payload['workspace_id'],$body['worker_id'],$instance,$owners,$person);
    quantaTimeControlPlacementIds($payload['placement_ids']);
}

function quantaTimeControlEnvelope(int $tenant,int $id,string $reviewHash): array
{
    $row=integrationDeliveryGet(getDB(),$tenant,$id);$owners=quantaTimeControlOwners($tenant);
    if($row['provider']!=='quanta'||$row['operation']!=='time.access'||$row['entity_type']!=='person'
        ||(int)$row['entity_tenant_id']!==$owners['people']||!hash_equals($row['review_hash'],$reviewHash))throw new InvalidArgumentException('Choose the original time setup in its authorized directory');
    $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    quantaTimeControlPayloadOwners($payload,$owners);return [$row,$payload];
}

function quantaTimeControlSend(int $tenant,int $actor,int $id,string $reviewHash): array
{
    if(!quantaTimeControlEnabled($tenant))throw new RuntimeException('Assignment time setup has not been enabled for this environment');
    [$row,$payload]=quantaTimeControlEnvelope($tenant,$id,$reviewHash);
    if($row['state']==='delivered')return integrationDeliverySummary($row);
    if(!in_array($row['state'],['preview','approved'],true))throw new RuntimeException('Check the original receipt before another time setup change');
    $s=quantaTimeControlSnapshot($tenant,(int)$row['entity_id'],$payload['body']['worksite_id'],$payload['placement_ids'],$payload['body']['expected_context_hash']);
    if(!$s['view']['delivery_enabled']||integrationDeliveryHash($s['payload'])!==integrationDeliveryHash($payload))throw new RuntimeException('Assignment setup changed. Review it again.');
    $pdo=getDB();if($row['state']==='preview')integrationDeliveryApprove($pdo,$tenant,$id,$actor,$reviewHash);
    $claim=integrationDeliveryClaim($pdo,$tenant,$id,array_intersect_key($s['context'],array_flip(['binding_hash','source_hash','remote_hash'])),
        static function()use($pdo,$tenant,$s,$row,$payload):void {
            quantaWorkspaceAssertCurrent($pdo,$tenant,$s['access'],true);
            $source=quantaTimeControlSource($tenant,$s['access']['instance_id'],(int)$row['entity_id'],$payload['placement_ids'],true);
            if(integrationDeliveryHash($source)!==$s['context']['source_hash'])throw new RuntimeException('Core assignment changed before delivery');
        });
    try{$record=quantaWriteContract($s['access']['api_key'],$payload['workspace_id'],'time.access',$payload['body'],$payload['expected'],$claim['idempotency_key']);}
    catch(QuantaContractWriteException $e){integrationDeliveryFinish($pdo,$tenant,$id,$claim['claim'],$e->uncertain?'uncertain':'failed',null,$e->reason);throw $e;}
    catch(Throwable $e){integrationDeliveryFinish($pdo,$tenant,$id,$claim['claim'],'uncertain',null,'time_setup_interrupted');throw new RuntimeException('Check the original time setup receipt');}
    integrationDeliveryFinish($pdo,$tenant,$id,$claim['claim'],'delivered',$record['id'],null,$record['delivery_id']);
    platformAuditLogWrite($tenant,$actor,'quanta.time_access.delivered',null,['delivery_id'=>$id,'version'=>$record['version']],['source'=>'quanta']);
    return integrationDeliverySummary(integrationDeliveryGet($pdo,$tenant,$id));
}

function quantaTimeControlReceiptOutcome(array $payload,array $receipt): string
{
    if(($receipt['request_hash']??null)!==quantaTimeControlHash($payload['body'])||!is_string($receipt['id']??null)
        ||!preg_match('/^cfd_[a-f0-9]{64}$/D',$receipt['id']))throw new RuntimeException('Receipt does not match the original time setup');
    if(in_array($receipt['state']??null,['in_progress','uncertain'],true))return 'unconfirmed';
    if(($receipt['state']??null)!=='complete')throw new RuntimeException('Unknown time setup receipt state');
    if(in_array($receipt['status_code']??null,[200,201],true)){
        $response=$receipt['response']??[];
        foreach($payload['expected'] as $field=>$value)if(!array_key_exists($field,$response)||integrationDeliveryHash([$response[$field]])!==integrationDeliveryHash([$value]))throw new RuntimeException('Receipt differs from the reviewed time setup');
        if(($response['delivery_id']??null)!==$receipt['id']||($response['last_delivery_id']??null)!==$receipt['id'])throw new RuntimeException('Time setup receipt identity differs');
        return 'confirmed';
    }
    if(in_array($receipt['status_code']??null,[400,401,403,404,409,422],true)&&($receipt['response']['delivery_id']??null)===$receipt['id'])return 'rejected';
    throw new RuntimeException('Time setup result remains uncertain');
}

/** Recovery consults only the immutable original receipt and committed policy history. */
function quantaTimeControlReceipt(int $tenant,int $id,string $reviewHash): array
{
    [$row,$payload]=quantaTimeControlEnvelope($tenant,$id,$reviewHash);
    if(!in_array($row['state'],['sending','uncertain'],true))return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>$row['state']];
    $access=quantaWorkspaceForDelivery($tenant);
    if((string)$access['connection']['id']!==(string)$row['connection_ref']||!hash_equals($row['binding_hash'],$access['binding_hash'])
        ||$access['context']['workspace']['id']!==$payload['workspace_id'])throw new RuntimeException('Restore the reviewed connection before checking its receipt');
    try{$receipt=quantaGet($access['api_key'],QUANTA_CORE_CONTRACT_PATH.'/time-access-deliveries/'.$row['idempotency_key']);}
    catch(QuantaApiException $e){if($e->httpStatus===404)return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>'not_found'];throw $e;}
    $outcome=quantaTimeControlReceiptOutcome($payload,$receipt);
    if($outcome==='unconfirmed'){
        quantaWorkspaceAssertCurrent(getDB(),$tenant,$access);
        $recovered=quantaTimeControlRecoverRemote($access['api_key'],$row['idempotency_key']);
        if(($recovered['id']??null)!==$receipt['id'])throw new RuntimeException('Original receipt identity changed during recovery');
        $receipt=$recovered;$outcome=quantaTimeControlReceiptOutcome($payload,$receipt);
    }
    if($outcome==='unconfirmed')return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>$outcome];
    $pdo=getDB();$pdo->beginTransaction();
    try{
        quantaWorkspaceAssertCurrent($pdo,$tenant,$access,true);
        $owners=quantaTimeControlOwners($tenant,true);quantaTimeControlPayloadOwners($payload,$owners);
        if($owners['people']!==(int)$row['entity_tenant_id'])throw new RuntimeException('Person directory changed during recovery');
        $q=$pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND id=:id FOR UPDATE');$q->execute(['t'=>$tenant,'id'=>$id]);$live=$q->fetch(PDO::FETCH_ASSOC);
        foreach(['payload_ct','binding_hash','review_hash','idempotency_key'] as $field)if(!$live||$live[$field]!==$row[$field])throw new RuntimeException('Original setup changed during recovery');
        $success=$outcome==='confirmed';
        $pdo->prepare('UPDATE integration_deliveries SET state=:s,remote_id=:r,remote_receipt_id=:receipt,error_code=:e,claim_hash=NULL,finished_at=NOW() WHERE tenant_id=:t AND id=:id AND state IN ("sending","uncertain")')
            ->execute(['s'=>$success?'delivered':'failed','r'=>$success?$payload['expected']['id']:null,'receipt'=>$receipt['id'],'e'=>$success?null:'time_setup_rejected','t'=>$tenant,'id'=>$id]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['delivery'=>integrationDeliverySummary(integrationDeliveryGet($pdo,$tenant,$id)),'receipt_state'=>$outcome];
}
