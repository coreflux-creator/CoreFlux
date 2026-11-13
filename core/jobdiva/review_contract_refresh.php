<?php
/** Resolve uncertain Starts using exact financial evidence and current native ownership. */
declare(strict_types=1);
require_once __DIR__.'/live_contract_refresh.php';
require_once __DIR__.'/terminal_lifecycle.php';

/** Internal evidence: the review queue is a source observation, never a placement identity. */
function jobdivaReviewContractSnapshot(int $workspace,int $mappingId,string $corporationId=''): array
{
    if(!JobdivaStoredReviewTransaction::active())throw new LogicException('Contract review requires its protected transaction.');
    $pdo=getDB();$context=jobdivaPlacementWriteContext($pdo,$workspace);$connection=jobdivaPlacementImportPolicy($pdo,$workspace);
    $q=$pdo->prepare('SELECT * FROM external_entity_mappings WHERE tenant_id=? AND id=? AND source_system="jobdiva" AND internal_entity_type="jobdiva_assignment_review" FOR UPDATE');
    $q->execute([$workspace,$mappingId]);$review=$q->fetch(PDO::FETCH_ASSOC);
    if(!$review||$review['sync_status']!=='ok')throw new RuntimeException('The exact assignment review is unavailable.',409);
    $external=jobdivaAssignmentIdentityNormaliseId($review['external_id']);
    $root=json_decode((string)$review['payload_snapshot'],true,512,JSON_THROW_ON_ERROR);
    $type=is_array($root)?jobdivaAssignmentIdentityPluck($root,['__cf_jobdiva_source_object']):'';
    if(!preg_match('/^[0-9]+$/D',$external)||!is_array($root)||array_is_list($root)||jobdivaAssignmentRowId($root)!==$external
        ||count(jobdivaAssignmentIdentityValues($root,true))!==1||empty(jobdivaAssignmentStructuralEvidence($root)['complete'])
        ||($type!==''&&!in_array(strtolower($type),['assignment','start'],true)))throw new RuntimeException('Review the exact Start, worker, job and start date before resolving its contract.',409);
    $identity=jobdivaPlacementWriteIdentity($pdo,$context,$external);
    if($identity['mapping']){
        $stored=json_decode((string)$identity['mapping']['payload_snapshot'],true,512,JSON_THROW_ON_ERROR);
        if($identity['mapping']['sync_status']!=='ok'||!is_array($stored)||!jobdivaAssignmentContextEvidence($root,$stored)['matches'])throw new RuntimeException('The review row conflicts with the retained placement source.',409);
    }
    $q=$pdo->prepare('SELECT * FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND internal_entity_type="jobdiva_assignment" AND external_id IN(?,?) ORDER BY id FOR UPDATE');
    $q->execute([$workspace,$external,'jd:'.$external]);$mirrors=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($mirrors)>1)throw new RuntimeException('Review competing assignment mirrors before contract reconciliation.',409);
    if($mirrors){
        $stored=json_decode((string)$mirrors[0]['payload_snapshot'],true,512,JSON_THROW_ON_ERROR);
        if($mirrors[0]['sync_status']!=='ok'||!is_array($stored)||!jobdivaAssignmentContextEvidence($root,$stored)['matches'])throw new RuntimeException('The assignment mirror conflicts with the reviewed Start.',409);
    }
    $q=$pdo->prepare('SELECT id,tenant_id,status,deleted_at,external_id FROM people WHERE tenant_id=? AND id=? FOR UPDATE');
    $q->execute([$context['people_owner'],$identity['placement']['person_id']??0]);$person=$q->fetch(PDO::FETCH_ASSOC)?:null;
    $base=['context'=>$context,'connection'=>$connection,'external'=>$external,'mapping'=>$review,'primary_mapping'=>$identity['mapping'],
        'mirrors'=>$mirrors,'placement'=>$identity['placement'],'owned_fields'=>$identity['owned_fields'],'source_owner'=>$identity['source_owner'],'person'=>$person];
    $snapshot=jobdivaContractRelatedSnapshot($workspace,$base,$corporationId);
    // Projection can adopt an exact legacy candidate even before a provider pair exists.
    // Capture that row too, so a remote response cannot silently revive or replace it.
    $candidate=jobdivaPluckField($snapshot['joined_payload'],['candidateId','candidate_id','candidate id','employeeId']);
    $q=$pdo->prepare('SELECT * FROM people WHERE tenant_id=? AND external_id IN(?,?) ORDER BY id FOR UPDATE');
    $q->execute([$context['people_owner'],$candidate,'jd:'.$candidate]);$snapshot['legacy_people']=$q->fetchAll(PDO::FETCH_ASSOC);
    return $snapshot;
}

function jobdivaReviewContractInput(array $expected): array
{
    $payload=jobdivaAssignmentMarkVerified($expected['joined_payload'],$expected['base']['external'],'searchStart:contract_review');
    $payload['__cf_jobdiva_census_scope'] = 'review';
    unset($payload['_jd_assignment_detail'], $payload['_jd_contract']);
    return $payload;
}

function jobdivaReviewContractValidate(array $expected,array $payload): array
{
    $input=jobdivaReviewContractInput($expected);$external=$expected['base']['external'];
    jobdivaJoinedIndexValidatePayload(['joined_payload'=>$input,'external'=>$external],$payload);
    $rows=$payload['_jd_assignment_detail']??[];
    if(!is_array($rows)||!$rows)throw new RuntimeException('JobDiva returned no exact assignment financial detail.',422);
    $contract=jobdivaAssignmentContractBuild($rows,jobdivaAssignmentStripDerivedFacets($input,$external),$external);
    if(!$contract||mappingHash($contract)!==mappingHash($payload['_jd_contract']??[]))throw new RuntimeException('The reviewed contract does not agree with the exact returned financial records.',409);
    $payload['_jd_contract']=$contract;
    return $payload;
}

/** Preserve original pairs and census dates; enrichment is not a new Start observation. */
function jobdivaReviewContractSaveSnapshot(int $workspace,array $mapping,array $payload,?int $actor): void
{
    $compact=jobdivaAssignmentCompactSnapshotPayload($payload,jobdivaAssignmentIdentityNormaliseId($mapping['external_id']));
    $snapshot=json_encode($compact,JSON_THROW_ON_ERROR);$hash=mappingHash($compact);$pdo=getDB();
    if($hash!==$mapping['content_hash']){
        $pdo->prepare('UPDATE external_entity_mappings SET payload_snapshot=?,content_hash=?,updated_at=NOW() WHERE tenant_id=? AND id=?')->execute([$snapshot,$hash,$workspace,$mapping['id']]);
        entitySyncHistoryRecord($workspace,'jobdiva',$mapping['internal_entity_type'],(int)$mapping['internal_entity_id'],$mapping['external_id'],'pull',$mapping['payload_snapshot'],$snapshot,$mapping['content_hash'],$hash,$actor,true);
    }
    foreach(jobdivaCanonicalFieldIndexEntityTypes($mapping['internal_entity_type'])as$type)integrationPayloadFieldIndexRecord($workspace,'jobdiva',$type,$compact,true);
}

/** No provider reads occur inside this complete native/source write transaction. */
function jobdivaReviewContractApply(array $expected,array $payload,?array $companyPayload,?int $actor): array
{
    return JobdivaStoredReviewTransaction::run(static function()use($expected,$payload,$companyPayload,$actor):array{
        $base=$expected['base'];$workspace=(int)$base['context']['workspace'];$pdo=getDB();
        $current=jobdivaReviewContractSnapshot($workspace,(int)$base['mapping']['id'],$expected['corporation_id']);
        if(!hash_equals(jobdivaAssignmentRepairFingerprint($expected),jobdivaAssignmentRepairFingerprint($current)))throw new RuntimeException('Assignment review, ownership or source evidence changed during the remote lookup. Retry this contract.',409);
        $payload=jobdivaReviewContractValidate($current,$payload);$contract=$payload['_jd_contract'];$external=$base['external'];
        $status=trim((string)($contract['placement_status']??''));$end=jobdivaNormaliseDate($contract['end_date']??null);
        $canonical=$status!==''?jobdivaAssignmentCanonicalPlacementStatus($status,$end)['status']:'';
        $placement=$base['placement'];$placementId=(int)($placement['id']??0);$owner=$base['context']['placement_owner'];
        $result=['projected'=>0,'created'=>0,'updated'=>0,'restored'=>0,'demoted'=>0,'skipped_not_current'=>0,'review_required'=>0];
        $archived=$placement&&!empty($placement['deleted_at']);
        $held=(bool)array_intersect(['status','start_date','end_date','actual_end_date','deleted_at'],$base['owned_fields']);
        if(!in_array($canonical,['active','pending_start','on_hold'],true)){
            $result['skipped_not_current']=1;
            if($placement&&in_array($canonical,['draft','ended','cancelled'],true)){
                $identity=['placement'=>$placement,'mapping'=>$base['primary_mapping'],'owned_fields'=>$base['owned_fields'],'source_owner'=>$base['source_owner']];
                $lifecycle=jobdivaSourceLifecycleApplyLocked($pdo,$workspace,$base['context'],$identity,$canonical,$end,$payload,$actor,'assignment_contract_lifecycle');
                $result['demoted']=(int)$lifecycle['changed'];
                $result['review_required']=(int)(($lifecycle['reason']??'')==='core_owned_lifecycle');
            }
        }else{
            $worker=in_array('person_id',$base['owned_fields'],true)?$base['person']:$current['source_person'];
            if(!$worker&&!$current['person_source']&&count($current['legacy_people'])===1)$worker=$current['legacy_people'][0];
            if($current['person_source']&&!in_array('person_id',$base['owned_fields'],true)&&(!$worker||$current['person_source']['sync_status']!=='ok'))throw new RuntimeException('Review the assignment worker source link.',409);
            $workerCurrent=$worker&&empty($worker['deleted_at'])&&in_array($worker['status'],['active','bench'],true);
            if(!$archived&&$worker&&!$workerCurrent)throw new RuntimeException('Review the inactive assignment worker before resolving this contract.',409);
            if($archived&&($held||!$workerCurrent||!empty($placement['actual_end_date']))){
                $result['skipped_not_current']=1;$result['review_required']=1;
            }else{
                jobdivaContractResolveCompany($current,$payload,$companyPayload,$actor);
                $payload['startStatus']=$canonical;$payload['status']=$canonical;
                $payload['__cf_jobdiva_census_scope']='current';$payload['__cf_jobdiva_canonical_status']=$canonical;
                $payload=jobdivaAssignmentMarkVerified($payload,$external,'EmployeeAssignmentRecordsDetail:contract_review');
                $projection=jobdivaProjectorProjectPlacement($workspace,jobdivaCanonicalPlacementPayload($payload,jobdivaExtractJoinedSubPayloads($payload)),$actor,[
                    'payload_is_enriched'=>true,'external_id'=>$external,'existing_placement_id'=>$placementId,'person_id'=>0,'force_source_contract'=>true,'required_observation'=>true,
                ]);
                if(empty($projection['projected']))throw new RuntimeException(implode('; ',array_map('strval',$projection['errors']??['Assignment projection failed.'])),409);
                $placementId=(int)$projection['placement_id'];
                $primary=jobdivaPlacementWriteIdentity($pdo,$base['context'],$external)['mapping'];
                // Restore the previously observed Start date; a new identity uses the queue's census date.
                $observed=$base['primary_mapping']['last_seen_at']??$base['mapping']['last_seen_at'];
                $pdo->prepare('UPDATE external_entity_mappings SET last_seen_at=? WHERE tenant_id=? AND id=?')->execute([$observed,$workspace,$primary['id']]);
                $result['projected']=1;$result[$placement?($archived?'restored':'updated'):'created']=1;
            }
        }
        jobdivaReviewContractSaveSnapshot($workspace,$base['mapping'],$payload,$actor);
        if($base['mirrors'])jobdivaReviewContractSaveSnapshot($workspace,$base['mirrors'][0],$payload,$actor);
        elseif($result['projected']){
            $mirror=mappingBindStable($workspace,'jobdiva','jobdiva_assignment',$external,(int)$external,jobdivaAssignmentCompactSnapshotPayload($payload,$external),'pull',$actor,true);
            $pdo->prepare('UPDATE external_entity_mappings SET last_seen_at=? WHERE tenant_id=? AND id=?')->execute([$base['mapping']['last_seen_at'],$workspace,$mirror['id']]);
        }
        companySourceAudit($pdo,$workspace,$owner,'external_entity_mappings',(int)$base['mapping']['id'],'jobdiva.contract_review.applied',['source','contract','lifecycle'],$actor,[
            'source_external_id'=>$base['mapping']['external_id'],'placement_id'=>$placementId?:null,'source_owner'=>$base['source_owner'],
            'primary_mapping_id'=>$base['primary_mapping']['id']??null,'review_fingerprint'=>jobdivaAssignmentRepairFingerprint($expected),
            'before'=>$current['graph'],'after'=>jobdivaPlacementProjectionAuditSnapshot($owner,$placementId,true),'result'=>$result,
        ]);
        return $result;
    });
}

function jobdivaRefreshReviewContract(int $workspace,int $mappingId,?int $actor,string $runToken=''): array
{
    if(getDB()->inTransaction()||JobdivaStoredReviewTransaction::inScope())throw new RuntimeException('Start contract review outside an existing transaction.',409);
    try{$expected=JobdivaStoredReviewTransaction::run(static fn()=>jobdivaReviewContractSnapshot($workspace,$mappingId),true);}
    catch(JobdivaHistoricalPlacementSource){return ['skipped_not_current'=>1];}
    if($runToken!==''&&($expected['joined_payload']['__cf_jobdiva_review_run_token']??'')!==$runToken)throw new RuntimeException('The assignment belongs to a different reconciliation run.',409);
    $input=jobdivaReviewContractInput($expected);$diagnostics=[];
    $items=jobdivaSyncEnrichRelatedEntities($workspace,[$input],$actor,['kinds'=>['financial'],'enrich_start'=>false,'enrich_financial'=>true],$diagnostics);
    $payload=$items[0]??[];$financial=$diagnostics['financial']??null;
    if(empty($payload['_jd_contract']))return ['unavailable'=>1,'external_id'=>$expected['base']['external'],'financial'=>$financial];
    $payload=jobdivaReviewContractValidate($expected,$payload);
    $corporation=in_array('corp_identity',$expected['base']['owned_fields'],true)?'':jobdivaAssignmentSubcontractCompanyId($payload);$companyPayload=null;
    if($corporation!==''){
        $expanded=JobdivaStoredReviewTransaction::run(static fn()=>jobdivaReviewContractSnapshot($workspace,$mappingId,$corporation),true);
        if(jobdivaAssignmentRepairFingerprint(array_replace($expanded,['corporation_id'=>'','company_evidence'=>null]))!==jobdivaAssignmentRepairFingerprint($expected))throw new RuntimeException('Assignment evidence changed during contract lookup. Retry this review.',409);
        $expected=$expanded;
        if(empty($expanded['company_evidence']['available']))$companyPayload=jobdivaLiveContractFetchCompany($workspace,$corporation);
    }
    return jobdivaReviewContractApply($expected,$payload,$companyPayload,$actor)+['financial'=>$financial];
}
