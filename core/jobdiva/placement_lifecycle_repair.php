<?php
/** Current-directory lifecycle repair for exactly linked assignments. */
declare(strict_types=1);
require_once __DIR__.'/assignment_source_repair.php';

function jobdivaPlacementLifecycleRepairPlan(array $evidence): array
{
    $row=$evidence['placement'];$mapping=$evidence['mapping'];
    $result=['id'=>(int)$row['id'],'status'=>'skipped','reason'=>'not_active','desired_status'=>$row['status']];
    if($row['status']!=='active'||(!empty($row['deleted_at'])&&$row['deleted_at']!=='0000-00-00 00:00:00'))return$result;
    if(array_intersect(['status','start_date','end_date','actual_end_date','deleted_at'],$evidence['owned_fields']))return array_replace($result,['reason'=>'core_owned_lifecycle']);
    if($mapping['sync_status']!=='ok')return array_replace($result,['reason'=>'source_needs_review']);
    $payload=json_decode((string)$mapping['payload_snapshot'],true,512,JSON_THROW_ON_ERROR);
    if(!is_array($payload)||jobdivaAssignmentRowId($payload)!==$evidence['external']
        ||count(jobdivaAssignmentIdentityValues($payload,true))!==1
        ||empty(jobdivaAssignmentStructuralEvidence($payload)['complete'])) {
        return array_replace($result,['reason'=>'exact_assignment_evidence_required']);
    }
    // The current Core dates are retained; source contract/profile changes have
    // their own reviewed apply. A root status cannot be inferred from a mirror.
    $raw=jobdivaAssignmentIdentityPluck($payload,['startStatus','start_status','start status','status','placementStatus','assignmentStatus']);
    $lifecycle=jobdivaAssignmentPlacementStatusForWrite($raw,(string)($row['start_date']??''),(string)($row['end_date']??''),(string)($row['status']??''));
    $desired=$lifecycle['status'];
    if(!in_array($desired,['ended','cancelled','pending_start','on_hold'],true))return array_replace($result,['reason'=>'already_current']);
    return array_replace($result,['status'=>'updated','desired_status'=>$desired,'reason'=>$lifecycle['reason'],'source_status'=>$raw]);
}

function jobdivaRepairPlacementLifecycle(int $workspace,int $mappingId,?int $actor=null,bool $dryRun=true): array
{
    return JobdivaStoredReviewTransaction::run(static function()use($workspace,$mappingId,$actor,$dryRun):array{
        $pdo=getDB();$evidence=jobdivaAssignmentRepairSnapshot($workspace,$mappingId);
        $result=jobdivaPlacementLifecycleRepairPlan($evidence);
        if($dryRun||$result['status']!=='updated')return$result;
        $owner=$evidence['context']['placement_owner'];$id=$result['id'];
        $before=jobdivaPlacementProjectionAuditSnapshot($owner,$id,true);
        $pdo->prepare('UPDATE placements SET status=?,updated_at=NOW() WHERE tenant_id=? AND id=?')->execute([$result['desired_status'],$owner,$id]);
        $graph=placementEconomicsReconcile($workspace,$id);
        if(!$graph['available']||$graph['errors'])throw new RuntimeException('The repaired lifecycle could not update the assignment commercial graph.',422);
        $after=jobdivaPlacementProjectionAuditSnapshot($owner,$id,true);
        companySourceAudit($pdo,$workspace,$owner,'placements',$id,'placement.status_repaired_from_jobdiva_lifecycle',['status'],$actor,[
            'source_system'=>'jobdiva','source_mapping_id'=>$mappingId,'source_external_id'=>$evidence['mapping']['external_id'],
            'reason'=>$result['reason'],'source_status'=>$result['source_status'],'before'=>$before,'after'=>$after,
        ]);
        return$result;
    },$dryRun);
}

function jobdivaMappingRepairStaleActivePlacements(int $tenantId,?int $userId=null,int $limit=500,bool $dryRun=true): array
{
    $summary=['dry_run'=>$dryRun,'checked'=>0,'updated'=>0,'ended'=>0,'cancelled'=>0,'pending_start'=>0,'on_hold'=>0,'skipped'=>0,'failed'=>0,'reasons'=>[],'errors'=>[]];
    $limit=max(1,min(5000,$limit));$pdo=getDB();
    // Only exact source links are candidates. Titles, display numbers and the
    // parent directory's source links do not confer lifecycle authority.
    $q=$pdo->prepare("SELECT id FROM external_entity_mappings WHERE tenant_id=? AND source_system='jobdiva' AND internal_entity_type='placement' ORDER BY id LIMIT {$limit}");$q->execute([$tenantId]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN)as$mappingId){
        $summary['checked']++;
        try{
            $result=jobdivaRepairPlacementLifecycle($tenantId,(int)$mappingId,$userId,$dryRun);
            $summary[$result['status']]++;
            if($result['status']==='updated')$summary[$result['desired_status']]++;
            $summary['reasons'][$result['reason']]=($summary['reasons'][$result['reason']]??0)+1;
        }catch(Throwable $e){$summary['failed']++;if(count($summary['errors'])<10)$summary['errors'][]='source link '.$mappingId.': '.$e->getMessage();}
    }
    return$summary;
}
