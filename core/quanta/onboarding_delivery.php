<?php
/** Reviewed portal publication and authenticated evidence intake. No approval, access or payroll writes. */
declare(strict_types=1);
require_once __DIR__.'/onboarding_contract.php';
require_once __DIR__.'/onboarding_documents.php';
require_once __DIR__.'/identity.php';

function quantaOnboardingDeliveryEnabled(): bool {
    return defined('COREFLUX_QUANTA_ONBOARDING_DELIVERY')?constant('COREFLUX_QUANTA_ONBOARDING_DELIVERY')===true:getenv('COREFLUX_QUANTA_ONBOARDING_DELIVERY')==='1';
}
function quantaOnboardingSourceHash(array $case): string {
    return onboardingHash(array_intersect_key($case,array_flip(['id','tenant_id','people_tenant_id','placements_tenant_id',
        'person_id','placement_id','person_number','placement_number','version','plan_version','confirmed_plan_version',
        'source_revision','source_changed','tasks'])));
}
/** Resolve policy directly under lock, rather than using request-local directory caches after provider IO. */
function quantaOnboardingDirectoryLock(PDO $pdo,array $scope): void {
    if(!$pdo->inTransaction())throw new LogicException('Directory lock requires a transaction');
    $s=$pdo->prepare('SELECT id,parent_id,tenant_type,is_active FROM tenants WHERE id=:t FOR UPDATE');
    $s->execute(['t'=>$scope['tenant_id']]);$tenant=$s->fetch(PDO::FETCH_ASSOC);
    if(!$tenant||!(int)$tenant['is_active'])throw new OnboardingConflict('Core workspace is no longer active');
    foreach(['people','placements']as$module){
        $s=$pdo->prepare('SELECT scope_mode FROM tenant_module_scope WHERE tenant_id=:t AND module_key=:m FOR UPDATE');
        $s->execute(['t'=>$scope['tenant_id'],'m'=>$module]);$mode=$s->fetchColumn();
        if(!in_array($mode,['shared','isolated'],true))$mode=SUBTENANT_MODULE_SCOPE_DEFAULTS[$module];
        $owner=$tenant['tenant_type']==='sub'&&$tenant['parent_id']&&$mode==='shared'?(int)$tenant['parent_id']:$scope['tenant_id'];
        if($owner!==$scope[$module.'_tenant_id'])throw new OnboardingConflict('Core directory ownership changed. Reload the case.');
    }
}
function quantaOnboardingWorker(PDO $pdo,array $case,?string $expected=null,bool $lock=false): string {
    $s=$pdo->prepare('SELECT worker_id,person_id FROM quanta_worker_links WHERE tenant_id=:t AND person_id=:p'.($lock?' FOR UPDATE':''));
    $s->execute(['t'=>$case['tenant_id'],'p'=>$case['person_id']]);$links=$s->fetchAll(PDO::FETCH_ASSOC);
    if(count($links)!==1||!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',(string)$links[0]['worker_id'])
        ||($expected!==null&&$links[0]['worker_id']!==$expected))throw new OnboardingConflict('Review the Quanta worker link for this person');
    return $links[0]['worker_id'];
}
function quantaOnboardingRead(string $key,string $workspace,array $case,string $instance): ?array {
    $person=integrationRecordReference($instance,$case['people_tenant_id'],'person',$case['person_number']);
    $placement=integrationRecordReference($instance,$case['placements_tenant_id'],'placement',$case['placement_number']);
    $id=quantaOnboardingRemoteId($workspace,['placement'=>$placement,'operating_tenant_id'=>$case['tenant_id'],'core_case_id'=>$case['id']]);
    try{$row=quantaGet($key,QUANTA_CORE_CONTRACT_PATH.'/onboarding/'.$id);}
    catch(QuantaApiException $e){if($e->httpStatus===404)return null;throw $e;}
    $p=$row['publication']??[];
    if(($row['id']??null)!==$id||($row['tenant_id']??null)!==$workspace||!is_int($row['version']??null)||$row['version']<1
        ||($p['contract_version']??null)!==QUANTA_ONBOARDING_CONTRACT_VERSION||!in_array($p['state']??null,['published','withdrawn'],true)
        ||($p['core_case_id']??null)!==$case['id']||($p['operating_tenant_id']??null)!==$case['tenant_id']
        ||onboardingHash($p['person']??[])!==onboardingHash($person)||onboardingHash($p['placement']??[])!==onboardingHash($placement)
        ||($row['projection_hash']??null)!==onboardingHash($p)||!is_array($row['submissions']??null)||!array_is_list($row['submissions'])
        ||count($row['submissions'])>500)throw new OnboardingConflict('Quanta returned an incompatible onboarding case');
    return $row;
}
function quantaOnboardingSnapshot(array $scope,string $caseId,callable $head): array {
    onboardingScope($scope);$pdo=getDB();$case=onboardingView($pdo,$scope,$caseId,$head);
    $access=quantaWorkspaceForDelivery($scope['tenant_id']);$workspace=$access['context']['workspace']['id'];
    quantaOnboardingContext($access['api_key'],$workspace);
    $worker=quantaOnboardingWorker($pdo,$case);
    $ref=integrationRecordReference($access['instance_id'],$case['placements_tenant_id'],'placement',$case['placement_number']);
    $assignment=quantaContractRecord($access['api_key'],$workspace,$ref);
    if(!$assignment||($assignment['worker_id']??null)!==$worker)throw new OnboardingConflict('Publish and verify the shared assignment before onboarding');
    $remote=quantaOnboardingRead($access['api_key'],$workspace,$case,$access['instance_id']);
    // Withdrawal validates identity without requiring a presently confirmed plan.
    quantaOnboardingPlan($case,$access['instance_id'],$workspace,$assignment,$remote,'withdrawn');
    quantaWorkspaceAssertCurrent($pdo,$scope['tenant_id'],$access);
    return compact('case','access','worker','assignment','remote');
}
function quantaOnboardingBuild(array $snapshot,string $state): array {
    $case=$snapshot['case'];$access=$snapshot['access'];$workspace=$access['context']['workspace']['id'];
    $plan=quantaOnboardingPlan($case,$access['instance_id'],$workspace,$snapshot['assignment'],$snapshot['remote'],$state);
    $context=['provider'=>'quanta','connection_ref'=>(string)$access['connection']['id'],'binding_hash'=>$access['binding_hash'],
        'entity_type'=>'placement','entity_tenant_id'=>$case['placements_tenant_id'],'entity_id'=>$case['placement_id'],
        'operation'=>'onboarding.publish','source_hash'=>quantaOnboardingSourceHash($case),
        'remote_hash'=>integrationDeliveryHash(['assignment'=>$snapshot['assignment'],'remote'=>$snapshot['remote'],'worker'=>$snapshot['worker']])];
    return $snapshot+['plan'=>$plan,'context'=>$context,'payload'=>['case_id'=>$case['id'],'workspace_id'=>$workspace,
        'body'=>$plan['body'],'expected'=>$plan['expected']]];
}
function quantaOnboardingPreview(array $scope,string $caseId,string $state,callable $head): array {
    onboardingScope($scope,'manage');$snapshot=quantaOnboardingBuild(quantaOnboardingSnapshot($scope,$caseId,$head),$state);
    $same=$snapshot['remote']&&$snapshot['remote']['projection_hash']===$snapshot['plan']['expected']['projection_hash'];
    $result=['status'=>$same?'unchanged':'preview','workspace'=>$snapshot['access']['context']['workspace'],
        'proposed'=>$snapshot['plan']['body'],'delivery_enabled'=>quantaOnboardingDeliveryEnabled()];
    if(!$same)$result['delivery']=integrationDeliverySummary(integrationDeliveryPreview(getDB(),$scope['tenant_id'],$scope['actor_user_id'],$snapshot['context'],$snapshot['payload']));
    return $result;
}
function quantaOnboardingEnvelope(PDO $pdo,array $scope,string $caseId,int $deliveryId,?string $reviewHash=null): array {
    $case=onboardingCase($pdo,$scope,$caseId);$row=integrationDeliveryGet($pdo,$scope['tenant_id'],$deliveryId);
    if($row['provider']!=='quanta'||$row['operation']!=='onboarding.publish'||$row['entity_type']!=='placement'
        ||(int)$row['entity_tenant_id']!==$case['placements_tenant_id']||(int)$row['entity_id']!==$case['placement_id']
        ||($reviewHash!==null&&!hash_equals($row['review_hash'],$reviewHash)))throw new OnboardingConflict('This publication is not part of the reviewed case');
    $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    if(($payload['case_id']??null)!==$caseId||($payload['body']['core_case_id']??null)!==$caseId)throw new OnboardingConflict('Publication belongs to another case');
    return [$row,$payload];
}
/** Called only with a fresh provider snapshot; locks all local authority before an import/claim. */
function quantaOnboardingLock(PDO $pdo,array $scope,array $snapshot,callable $head,bool $checkRevision=true): array {
    quantaWorkspaceAssertCurrent($pdo,$scope['tenant_id'],$snapshot['access'],true);
    quantaOnboardingDirectoryLock($pdo,$scope);
    $case=onboardingView($pdo,$scope,$snapshot['case']['id'],$head,true);
    quantaOnboardingWorker($pdo,$case,$snapshot['worker'],true);
    if($checkRevision&&quantaOnboardingSourceHash($case)!==quantaOnboardingSourceHash($snapshot['case']))throw new OnboardingConflict('The onboarding case changed during the provider check');
    return $case;
}
function quantaOnboardingSend(array $scope,string $caseId,int $id,string $reviewHash,callable $head): array {
    onboardingScope($scope,'manage');if(!quantaOnboardingDeliveryEnabled())throw new OnboardingConflict('Onboarding delivery has not been enabled for this environment');
    $pdo=getDB();[$row,$payload]=quantaOnboardingEnvelope($pdo,$scope,$caseId,$id,$reviewHash);
    if($row['state']==='delivered')return integrationDeliverySummary($row);
    if(!in_array($row['state'],['preview','approved'],true))throw new OnboardingConflict('Check the receipt for this attempt before another publication');
    $snapshot=quantaOnboardingBuild(quantaOnboardingSnapshot($scope,$caseId,$head),$payload['body']['state']);
    if(integrationDeliveryHash($payload)!==integrationDeliveryHash($snapshot['payload']))throw new OnboardingConflict('Requirements or Quanta changed. Preview again.');
    if($row['state']==='preview')integrationDeliveryApprove($pdo,$scope['tenant_id'],$id,$scope['actor_user_id'],$reviewHash);
    $claim=integrationDeliveryClaim($pdo,$scope['tenant_id'],$id,array_intersect_key($snapshot['context'],array_flip(['binding_hash','source_hash','remote_hash'])),
        static fn()=>quantaOnboardingLock($pdo,$scope,$snapshot,$head));
    try{
        $record=quantaWriteContract($snapshot['access']['api_key'],$payload['workspace_id'],'onboarding.publish',$payload['body'],$payload['expected'],$claim['idempotency_key']);
    }catch(Throwable $e){
        integrationDeliveryFinish($pdo,$scope['tenant_id'],$id,$claim['claim'],$e instanceof QuantaContractWriteException&&!$e->uncertain?'failed':'uncertain',null,'onboarding_unconfirmed');throw $e;
    }
    integrationDeliveryFinish($pdo,$scope['tenant_id'],$id,$claim['claim'],'delivered',$record['id'],null,$record['delivery_id']);
    return integrationDeliverySummary(integrationDeliveryGet($pdo,$scope['tenant_id'],$id));
}
function quantaOnboardingCheckReceipt(array $scope,string $caseId,int $id,string $reviewHash,callable $head): array {
    onboardingScope($scope,'manage');$pdo=getDB();[$row,$payload]=quantaOnboardingEnvelope($pdo,$scope,$caseId,$id,$reviewHash);
    if(!in_array($row['state'],['sending','uncertain'],true))return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>$row['state']];
    $snapshot=quantaOnboardingSnapshot($scope,$caseId,$head);$access=$snapshot['access'];
    if(!hash_equals($row['binding_hash'],$access['binding_hash'])||$payload['workspace_id']!==$access['context']['workspace']['id'])throw new OnboardingConflict('Restore the reviewed connection before checking this receipt');
    try{$receipt=quantaGet($access['api_key'],QUANTA_CORE_CONTRACT_PATH.'/onboarding-deliveries/'.$row['idempotency_key']);}
    catch(QuantaApiException $e){if($e->httpStatus===404)return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>'not_found'];throw $e;}
    if(($receipt['request_hash']??null)!==quantaOnboardingRequestHash($payload['body'])||!preg_match('/^cfd_[a-f0-9]{64}$/D',(string)($receipt['id']??'')))throw new OnboardingConflict('Provider receipt does not match this publication');
    if(($receipt['state']??null)!=='complete')return ['delivery'=>integrationDeliverySummary($row),'receipt_state'=>'unconfirmed'];
    $success=in_array($receipt['status_code']??null,[200,201],true);
    if($success){
        foreach($payload['expected']as$k=>$v)if(!array_key_exists($k,$receipt['response']??[])||onboardingHash([$receipt['response'][$k]])!==onboardingHash([$v]))throw new OnboardingConflict('Publication acknowledgement differs from the reviewed request');
        if(($receipt['response']['delivery_id']??null)!==$receipt['id'])throw new OnboardingConflict('Publication receipt identity differs');
    }elseif(!in_array($receipt['status_code']??null,[400,401,403,404,409,422],true))throw new OnboardingConflict('Provider result remains uncertain');
    $pdo->beginTransaction();
    try{
        quantaOnboardingLock($pdo,$scope,$snapshot,$head,false);
        $pdo->prepare('UPDATE integration_deliveries SET state=:s,remote_id=:r,remote_receipt_id=:receipt,error_code=:e,claim_hash=NULL,finished_at=NOW() WHERE tenant_id=:t AND id=:id AND state IN ("sending","uncertain")')
            ->execute(['s'=>$success?'delivered':'failed','r'=>$success?$payload['expected']['id']:null,'receipt'=>$receipt['id'],
                'e'=>$success?null:'onboarding_rejected','t'=>$scope['tenant_id'],'id'=>$id]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['delivery'=>integrationDeliverySummary(integrationDeliveryGet($pdo,$scope['tenant_id'],$id)),'receipt_state'=>$success?'confirmed':'rejected'];
}
/** Python digest uses ASCII JSON and unescaped slashes (distinct from projection_hash). */
function quantaOnboardingRequestHash(array $body): string {
    return hash('sha256',json_encode(integrationDeliveryCanonical($body),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}
function quantaOnboardingImportId(string $workspace,string $receiptId): string {
    $h=onboardingHash(['quanta_onboarding',$workspace,$receiptId]);
    return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20,12);
}
/** Validated proof is shared by preflight and the final locked command. */
function quantaOnboardingImportProof(PDO $pdo,array $scope,array $snapshot,array $case,array $receipt,int $deliveryId,int $expectedVersion,bool $allowDocument=false): array {
    $workspace=$snapshot['access']['context']['workspace']['id'];
    if($case['version']!==$expectedVersion)throw new OnboardingConflict('This case changed. Reload before importing evidence.');
    [$row,$payload]=quantaOnboardingEnvelope($pdo,$scope,$case['id'],$deliveryId);
    if($row['state']!=='delivered'||!$row['remote_receipt_id']||$row['connection_ref']!==(string)$snapshot['access']['connection']['id']
        ||$payload['workspace_id']!==$workspace||$payload['expected']['worker_id']!==$snapshot['worker'])throw new OnboardingConflict('A confirmed publication to this worker is required');
    if(($snapshot['remote']['publication']['state']??null)!=='published')throw new OnboardingConflict('This case has been withdrawn from Quanta');
    $proof=$payload['expected'];
    quantaOnboardingSubmissionValidate($case,$proof,$receipt,$snapshot['worker'],$snapshot['access']['instance_id'],$workspace,$allowDocument);
    $publishedTask=null;foreach($proof['publication']['tasks']as$t)if($t['id']===$receipt['task_id'])$publishedTask=$t;
    if(!$publishedTask||$publishedTask['version']!==$receipt['task_version'])throw new OnboardingConflict('The receipt does not match the delivered requirement');
    return $publishedTask;
}
/** Provider receipt must be freshly fetched by the adapter, and tied to a delivered encrypted Core envelope. */
function quantaOnboardingImportVerified(PDO $pdo,array $scope,array $snapshot,array $receipt,int $deliveryId,int $expectedVersion,callable $head,?array $document=null): array {
    onboardingScope($scope,'manage');
    if($document!==null)quantaOnboardingDocumentAuthority($scope);
    $workspace=$snapshot['access']['context']['workspace']['id'];
    $rid=quantaOnboardingImportId($workspace,(string)($receipt['id']??''));
    // Importing staff is audit context, not the worker's identity or the retry identity.
    $hash=onboardingHash(['workspace'=>$workspace,'case_id'=>$snapshot['case']['id'],'receipt'=>$receipt]);
    if($pdo->inTransaction())throw new LogicException('Onboarding intake owns its transaction');
    $pdo->beginTransaction();
    try{
        $case=quantaOnboardingLock($pdo,$scope,$snapshot,$head,false);
        if($prior=onboardingReceipt($pdo,$scope,$rid,$hash,true)){$pdo->commit();return $prior;}
        if($document!==null&&($document['binding_hash']??null)!==$snapshot['access']['binding_hash'])throw new OnboardingConflict('The prepared document connection changed');
        $publishedTask=quantaOnboardingImportProof($pdo,$scope,$snapshot,$case,$receipt,$deliveryId,$expectedVersion,$document!==null);
        foreach($case['tasks']as&$task)if($task['id']===$receipt['task_id']){
            foreach(['title','instructions','assignee','evidence_kind','required']as$k)if($publishedTask[$k]!==$task[$k])throw new OnboardingConflict('Delivered requirement differs from Core');
            $task['evidence']=['source'=>'quanta_worker','provider_workspace_id'=>$workspace,'provider_worker_id'=>$receipt['worker_id'],
                'provider_identity_id'=>$receipt['identity_id'],'provider_receipt_id'=>$receipt['id'],'publication_delivery_id'=>$deliveryId,
                'projection_hash'=>$receipt['projection_hash'],'source_revision'=>$receipt['source_revision'],'submitted_at'=>$receipt['submitted_at'],
                'acknowledged'=>true,'imported_by_user_id'=>$scope['actor_user_id'],'imported_at'=>gmdate('Y-m-d\TH:i:s\Z')];
            if($document!==null)$task['evidence']+=quantaOnboardingAttachDocument($pdo,$scope,$case,$receipt,$document,$head);
            $task['review']=null;$task['state']='submitted';$task['version']++;$evidence=$task['evidence'];
        }unset($task);
        foreach($case['tasks']as&$task)unset($task['effective_state']);unset($task);
        $case['version']++;
        $pdo->prepare('UPDATE staffing_onboarding_cases SET version=:v,tasks_json=:tasks,updated_at=UTC_TIMESTAMP() WHERE tenant_id=:t AND id=:id')
            ->execute(['v'=>$case['version'],'tasks'=>onboardingJson($case['tasks']),'t'=>$scope['tenant_id'],'id'=>$case['id']]);
        $result=['case_id'=>$case['id'],'case_version'=>$case['version'],'task_id'=>$receipt['task_id'],'action'=>'import_quanta','provider_receipt_id'=>$receipt['id']];
        if(isset($evidence['document_id']))$result['document_id']=$evidence['document_id'];
        $pdo->prepare('INSERT INTO staffing_onboarding_events (tenant_id,case_id,case_version,request_id,request_hash,actor_user_id,action,task_id,details_json,receipt_json) VALUES (:t,:c,:v,:r,:h,:u,"import_quanta",:task,:d,:receipt)')
            ->execute(['t'=>$scope['tenant_id'],'c'=>$case['id'],'v'=>$case['version'],'r'=>$rid,'h'=>$hash,'u'=>$scope['actor_user_id'],
                'task'=>$receipt['task_id'],'d'=>onboardingJson(['evidence'=>$evidence,'provider_receipt'=>$receipt]),'receipt'=>onboardingJson($result)]);
        $pdo->commit();return $result+['replayed'=>false];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function quantaOnboardingImport(array $scope,string $caseId,string $receiptId,int $deliveryId,int $expectedVersion,callable $head): array {
    if(!preg_match('/^cfs_[a-f0-9]{64}$/D',$receiptId))throw new InvalidArgumentException('Choose a verified worker submission');
    $snapshot=quantaOnboardingSnapshot($scope,$caseId,$head);
    $found=array_values(array_filter($snapshot['remote']['submissions']??[],static fn($r)=>($r['id']??null)===$receiptId));
    if(count($found)!==1)throw new OnboardingConflict('Worker submission is unavailable in this workspace');
    return quantaOnboardingImportVerified(getDB(),$scope,$snapshot,$found[0],$deliveryId,$expectedVersion,$head);
}
function quantaOnboardingStatus(array $scope,string $caseId,callable $head): array {
    $snapshot=quantaOnboardingSnapshot($scope,$caseId,$head);$case=$snapshot['case'];$pdo=getDB();
    $s=$pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND provider="quanta" AND operation="onboarding.publish" AND entity_tenant_id=:o AND entity_id=:p ORDER BY id DESC LIMIT 201');
    $s->execute(['t'=>$scope['tenant_id'],'o'=>$case['placements_tenant_id'],'p'=>$case['placement_id']]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    $history=[];$proofs=[];foreach(array_slice($rows,0,200)as$row){
        $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
        if(($payload['case_id']??null)!==$caseId)continue;
        $history[]=integrationDeliverySummary($row);
        if($row['state']==='delivered'&&$payload['workspace_id']===$snapshot['access']['context']['workspace']['id'])$proofs[$payload['expected']['projection_hash']]??=(int)$row['id'];
    }
    $receipts=[];foreach($snapshot['remote']['submissions']??[]as$r){
        $rid=quantaOnboardingImportId($snapshot['access']['context']['workspace']['id'],(string)($r['id']??''));
        $s=$pdo->prepare('SELECT receipt_json FROM staffing_onboarding_events WHERE tenant_id=:t AND case_id=:c AND request_id=:r');
        $s->execute(['t'=>$scope['tenant_id'],'c'=>$caseId,'r'=>$rid]);$imported=(bool)$s->fetchColumn();
        $doc=($r['kind']??null)==='document'?quantaOnboardingDocumentMetadata($r):null;
        $receipts[]=['id'=>$r['id'],'task_id'=>$r['task_id'],'task_version'=>$r['task_version'],'submitted_at'=>$r['submitted_at'],
            'kind'=>$r['kind']??'attestation','document'=>$doc?array_intersect_key($doc,array_flip(['filename','mime','size_bytes'])):null,
            'imported'=>$imported,'delivery_id'=>$proofs[$r['projection_hash']]??null];
    }
    $documents=false;
    if(quantaOnboardingDocumentsEnabled($scope['tenant_id'])&&($scope['permissions']['documents_manage']??false)===true&&quantaOnboardingDocumentStorageReady()){
        try{quantaOnboardingDocumentContext($snapshot['access']['api_key'],$snapshot['access']['context']['workspace']['id']);$documents=true;}
        catch(QuantaApiException|OnboardingConflict $e){$documents=false;}
    }
    return ['workspace'=>$snapshot['access']['context']['workspace'],'publication'=>$snapshot['remote']?[
        'state'=>$snapshot['remote']['publication']['state'],'core_version'=>$snapshot['remote']['publication']['core_version'],
        'assignment_current'=>$snapshot['remote']['assignment_current']??false,'source_current'=>$snapshot['remote']['publication']['source_revision']===$case['source_revision']]:null,
        'receipts'=>$receipts,'deliveries'=>array_slice($history,0,20),'history_has_more'=>count($history)>20||count($rows)>200,
        'delivery_enabled'=>quantaOnboardingDeliveryEnabled(),'document_transfer'=>$documents,
        'document_transfers'=>quantaOnboardingTransfers($pdo,$scope,$caseId)];
}
