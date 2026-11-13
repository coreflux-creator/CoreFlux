<?php
/** Source schema refresh keeps provider evidence separate from native business rows. */
declare(strict_types=1);
require_once __DIR__.'/sync.php';
require_once __DIR__.'/assignment_source_repair.php';
require_once __DIR__.'/../../modules/people/lib/company_source_ownership.php';

/** Internal evidence, captured again after the remote lookup. */
function jobdivaJoinedIndexSnapshot(int $workspace,int $mappingId): array
{
    $evidence=jobdivaAssignmentRepairSnapshot($workspace,$mappingId);
    $mapping=$evidence['mapping'];$placement=$evidence['placement'];
    if($mapping['sync_status']!=='ok'||!empty($placement['deleted_at']))throw new RuntimeException('Review this assignment source before refreshing its fields.',409);
    $payload=json_decode((string)$mapping['payload_snapshot'],true,512,JSON_THROW_ON_ERROR);
    if(!is_array($payload)||array_is_list($payload))throw new RuntimeException('Review the saved assignment evidence.',409);
    $root=jobdivaAssignmentStripDerivedFacets($payload,$evidence['external']);
    if(jobdivaAssignmentRowId($root)!==$evidence['external']||empty(jobdivaAssignmentValidate($root,$evidence['external'])['valid']))throw new RuntimeException('The saved source is not the exact JobDiva assignment.',409);
    $candidate=jobdivaPluckField($root,['candidate id','candidateId','candidate_id','candidateID','employeeId']);
    $personLink=jobdivaPlacementSourceLink(getDB(),$workspace,'person',$candidate);
    if(!$personLink||$personLink['sync_status']!=='ok'||!$evidence['person']||(int)$personLink['internal_entity_id']!==(int)$placement['person_id'])throw new RuntimeException('Review the assignment worker source identity.',409);
    $stats=[];$payload=jobdivaPlacementPayloadWithMirrors($workspace,$payload,$stats,$evidence['external'],true);
    return $evidence+['person_source'=>$personLink,'joined_payload'=>$payload];
}

/** Reject a returned root or facet belonging to a different source record. */
function jobdivaJoinedIndexValidatePayload(array $expected,array $payload): void
{
    $original=$expected['joined_payload'];
    $keys=['_jd_job','_jd_candidate','_jd_customer','_jd_contact','_jd_start','_jd_contract','_jd_assignment_detail','__cf_resolved_job_title'];
    if(mappingHash(array_diff_key($original,array_flip($keys)))!==mappingHash(array_diff_key($payload,array_flip($keys))))throw new RuntimeException('The source refresh changed the reviewed assignment root.',409);
    $relations=[
        '_jd_candidate'=>[jobdivaPluckField($original,['candidate id','candidateId','candidate_id','candidateID','employeeId']),['id','candidateId','candidate_id','candidate id','employeeId']],
        '_jd_job'=>[jobdivaPluckField($original,['job id','jobId','job_id','jobID','JOBID']),['id','jobId','job_id','job id','JOBID']],
        '_jd_customer'=>[jobdivaPluckField($original,['companyId','company_id','company id','endClientCompanyId','end_client_company_id']),['id','companyId','company_id','company id','COMPANYID']],
        '_jd_contact'=>[jobdivaPluckField($original,['job contact id','jobContactId','job_contact_id','contactId','contact_id','contact id']),['id','contactId','contact_id','contact id','CONTACTID']],
    ];
    if($relations['_jd_customer'][0]==='')$relations['_jd_customer'][0]=jobdivaPluckField((array)($original['_jd_job']??[]),['companyId','company_id','company id','COMPANYID','clientCompanyId']);
    if($relations['_jd_contact'][0]==='')$relations['_jd_contact'][0]=jobdivaPluckField((array)($original['_jd_job']??[]),['contactId','contact_id','contact id','CONTACTID','customerId','CUSTOMERID']);
    foreach($relations as$key=>[$id,$fields]){
        if(!isset($payload[$key]))continue;
        if($payload[$key]===($original[$key]??null)){
            // A cached mirror can itself be cross-linked. Do not let equality
            // with the old cache establish a different related source ID.
            $savedId=is_array($payload[$key])?jobdivaPluckField($payload[$key],$fields):'';
            if($id!==''&&$savedId!==''&&$savedId!==$id)throw new RuntimeException('Review the saved related source identity.',409);
            continue;
        }
        if(!is_array($payload[$key])||array_is_list($payload[$key])||$id===''||jobdivaPluckField($payload[$key],$fields)!==$id)throw new RuntimeException('The returned related record does not match the reviewed assignment.',409);
    }
    foreach(['_jd_start','_jd_contract']as$key){
        if(!isset($payload[$key])||$payload[$key]===($original[$key]??null))continue;
        if(!is_array($payload[$key])||jobdivaAssignmentRowId($payload[$key])!==$expected['external']||empty(jobdivaAssignmentContextEvidence($payload[$key],$original)['matches']))throw new RuntimeException('The returned contract does not match the reviewed assignment.',409);
    }
    if(isset($payload['_jd_assignment_detail'])&&$payload['_jd_assignment_detail']!==($original['_jd_assignment_detail']??null)){
        $rows=$payload['_jd_assignment_detail'];
        if(!is_array($rows)||!$rows||jobdivaAssignmentContractRowsForStart($rows,$original,$expected['external'])!==$rows)throw new RuntimeException('The returned financial detail includes another assignment.',409);
    }
}

function jobdivaJoinedIndexApply(array $expected,array $payload,?int $actor=null): array
{
    return JobdivaStoredReviewTransaction::run(static function()use($expected,$payload,$actor):array{
        $workspace=(int)$expected['context']['workspace'];$mappingId=(int)$expected['mapping']['id'];
        $current=jobdivaJoinedIndexSnapshot($workspace,$mappingId);
        if(!hash_equals(jobdivaAssignmentRepairFingerprint($expected),jobdivaAssignmentRepairFingerprint($current)))throw new RuntimeException('Assignment evidence changed during field refresh. Refresh and review again.',409);
        jobdivaJoinedIndexValidatePayload($current,$payload);
        $pdo=getDB();$mapping=$current['mapping'];$external=$current['external'];
        $compact=jobdivaAssignmentCompactSnapshotPayload($payload,$external);$hash=mappingHash($compact);
        $changed=$hash!==mappingHash(json_decode($mapping['payload_snapshot'],true,512,JSON_THROW_ON_ERROR));
        if($changed||$hash!==$mapping['content_hash']){
            // Enrichment is not a new Start observation. Preserve provider pair,
            // status, last_seen_at, last_synced_at and every native business row.
            $pdo->prepare('UPDATE external_entity_mappings SET payload_snapshot=?,content_hash=?,updated_at=NOW() WHERE tenant_id=? AND id=?')->execute([json_encode($compact,JSON_THROW_ON_ERROR),$hash,$workspace,$mappingId]);
        }
        $indexed=[];
        foreach(jobdivaExtractJoinedSubPayloads($payload)as$entity=>$sub){
            if(!$sub)continue;
            foreach(jobdivaCanonicalFieldIndexEntityTypes($entity)as$type){
                $indexPayload=jobdivaCanonicalPayloadForEntity($entity,$type,$sub);
                $count=integrationPayloadFieldIndexRecord($workspace,'jobdiva',$type,$indexPayload,true);
                if($count>0)$indexed[$type]=($indexed[$type]??0)+1;
            }
        }
        companySourceAudit($pdo,$workspace,$current['context']['placement_owner'],'external_entity_mappings',$mappingId,'jobdiva.source_fields.refreshed',['payload_snapshot','source_fields'],$actor,[
            'source_external_id'=>$mapping['external_id'],'placement_id'=>(int)$current['placement']['id'],
            'before_hash'=>$mapping['content_hash'],'after_hash'=>$hash,'indexed'=>$indexed,
        ]);
        return ['mapping_id'=>$mappingId,'snapshot_changed'=>$changed,'sub_records_indexed'=>$indexed];
    });
}

/** Remote enrichment never runs while business-directory locks are held. */
function jobdivaRefreshJoinedIndexes(int $workspace,?int $actor=null,int $limit=5000): array
{
    $pdo=getDB();
    if($pdo->inTransaction())throw new RuntimeException('Start source-field refresh outside an existing transaction.',409);
    $limit=max(1,min(5000,$limit));
    $summary=['placements_walked'=>0,'sub_records_indexed'=>array_fill_keys(['placement','staffing_job','person','company','job','jobdiva_customer','contact','assignment'],0),'enrichment_ran_for'=>0,'enrichment_errors'=>[],'failed'=>0,'endpoint_diagnostics'=>[]];
    $q=$pdo->prepare("SELECT id FROM external_entity_mappings WHERE tenant_id=? AND source_system='jobdiva' AND internal_entity_type='placement' AND sync_status='ok' AND payload_snapshot IS NOT NULL ORDER BY id LIMIT {$limit}");$q->execute([$workspace]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN)as$id){
        try{
            $expected=JobdivaStoredReviewTransaction::run(static fn():array=>jobdivaJoinedIndexSnapshot($workspace,(int)$id),true);
            $payload=$expected['joined_payload'];
            if(empty($payload['_jd_job'])||empty($payload['_jd_candidate'])||empty($payload['_jd_customer'])||empty($payload['_jd_start'])||empty($payload['_jd_contract'])){
                $diag=[];$items=jobdivaSyncEnrichRelatedEntities($workspace,[$payload],$actor,['enrich_start'=>1],$diag);
                $payload=$items[0]??$payload;$summary['enrichment_ran_for']++;
                foreach($diag as$kind=>$d){
                    $prior=$summary['endpoint_diagnostics'][$kind]??[];
                    foreach($d as$key=>$value){if(is_int($value))$prior[$key]=($prior[$key]??0)+$value;elseif(is_bool($value))$prior[$key]=($prior[$key]??false)||$value;elseif($value!==null)$prior[$key]=$value;}
                    $summary['endpoint_diagnostics'][$kind]=$prior;
                }
            }
            $result=jobdivaJoinedIndexApply($expected,$payload,$actor);$summary['placements_walked']++;
            foreach($result['sub_records_indexed']as$type=>$count)$summary['sub_records_indexed'][$type]=($summary['sub_records_indexed'][$type]??0)+$count;
        }catch(Throwable $e){$summary['failed']++;if(count($summary['enrichment_errors'])<10)$summary['enrichment_errors'][]='Source link '.$id.': '.$e->getMessage();}
    }
    return $summary;
}
