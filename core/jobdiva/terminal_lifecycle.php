<?php
/** Exact census endings use the current owner and preserve every physical source pair. */
declare(strict_types=1);
require_once __DIR__.'/placement_write_context.php';
require_once __DIR__.'/../../modules/people/lib/company_source_ownership.php';

function jobdivaPlacementImportPolicy(PDO $pdo,int $workspace): ?array
{
    if(!$pdo->inTransaction())throw new LogicException('Assignment policy requires its write transaction.');
    $q=$pdo->prepare('SELECT id,status,sync_config,field_ownership FROM jobdiva_connections WHERE tenant_id=? FOR UPDATE');$q->execute([$workspace]);$connection=$q->fetch(PDO::FETCH_ASSOC)?:null;
    if(($connection['status']??null)==='disconnected')throw new RuntimeException('Reconnect JobDiva before applying source lifecycle changes.',409);
    $config=empty($connection['sync_config'])?[]:json_decode($connection['sync_config'],true,512,JSON_THROW_ON_ERROR);
    $policy=$config['placement']??JOBDIVA_SYNC_DEFAULTS['placement'];
    if(($policy['source']??'')!=='jobdiva'||!in_array($policy['direction']??'',['pull','two_way'],true))throw new RuntimeException('JobDiva placement imports are disabled by this workspace\'s sync policy.',409);
    return $connection;
}

/** A source lifecycle change never releases native ownership or changes approved rates. */
function jobdivaSourceLifecycleApplyLocked(PDO $pdo,int $workspace,array $context,array $identity,string $desired,?string $endDate,array $source,?int $actor,string $event): array
{
    if(!$pdo->inTransaction()||!in_array($desired,['draft','ended','cancelled'],true))throw new LogicException('A scoped source lifecycle transaction is required.');
    $placement=$identity['placement'];
    if(!$placement)return ['changed'=>false,'matched'=>false,'reason'=>'not_found'];
    $owner=$context['placement_owner'];$id=(int)$placement['id'];
    $base=['changed'=>false,'matched'=>true,'placement_id'=>$id];
    if($placement['deleted_at']!==null)return $base+['reason'=>'archived_retained'];
    if(array_intersect(['status','end_date','actual_end_date','deleted_at'],$identity['owned_fields']))return $base+['reason'=>'core_owned_lifecycle'];
    if(!in_array($placement['status'],['draft','pending_start','active','on_hold'],true))return $base+['reason'=>'already_terminal'];
    if($desired==='ended'&&$endDate!==null&&$placement['start_date']&&$endDate<$placement['start_date'])throw new RuntimeException('The JobDiva end date precedes the placement start date.',422);
    if($placement['status']===$desired&&($endDate===null||$endDate===$placement['end_date']))return $base+['reason'=>'already_current'];
    $before=jobdivaPlacementProjectionAuditSnapshot($owner,$id,true);
    $pdo->prepare('UPDATE placements SET status=?,end_date=COALESCE(?,end_date),updated_at=NOW() WHERE tenant_id=? AND id=? AND deleted_at IS NULL')->execute([$desired,$endDate,$owner,$id]);
    $graph=placementEconomicsReconcile($workspace,$id);
    if(!$graph['available']||$graph['errors'])throw new RuntimeException('The source lifecycle could not update the placement commercial graph.',422);
    $after=jobdivaPlacementProjectionAuditSnapshot($owner,$id,true);
    companySourceAudit($pdo,$workspace,$owner,'placements',$id,$event,['status','end_date'],$actor,[
        'source_system'=>'jobdiva','source_mapping_id'=>$identity['mapping']['id']??null,
        'source_external_id'=>$identity['mapping']['external_id']??jobdivaAssignmentRowId($source),
        'source_physical_placement_id'=>$identity['mapping']['internal_entity_id']??$id,'source_owner'=>$identity['source_owner'],
        'source_hash'=>mappingHash($source),'before'=>$before,'after'=>$after,
    ]);
    return ['changed'=>true,'matched'=>true,'placement_id'=>$id,'desired_status'=>$desired];
}

function jobdivaTerminalCensusFingerprint(array $context,?array $policy,array $identity): string
{
    return hash('sha256',serialize([$context,$policy,$identity]));
}

/** Capture local decisions before network discovery; return hashes, never provider payloads. */
function jobdivaTerminalCensusBaseline(int $workspace): array
{
    return companyRelationshipAtomic(getDB(),static function()use($workspace):array{
        $pdo=getDB();$context=jobdivaPlacementWriteContext($pdo,$workspace);$policy=jobdivaPlacementImportPolicy($pdo,$workspace);
        $q=$pdo->prepare('SELECT external_id FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND internal_entity_type="placement" ORDER BY id FOR UPDATE');$q->execute([$workspace]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
        $q=$pdo->prepare('SELECT external_id FROM placements WHERE tenant_id=? AND (external_id LIKE "jd:%" OR external_id REGEXP "^[0-9]+$") ORDER BY id FOR UPDATE');$q->execute([$context['placement_owner']]);$ids=[...$ids,...$q->fetchAll(PDO::FETCH_COLUMN)];
        $states=[];
        foreach(array_unique(array_map('jobdivaAssignmentIdentityNormaliseId',$ids))as$external){
            if($external==='')continue;
            try{$identity=jobdivaPlacementWriteIdentity($pdo,$context,$external);$states[$external]=['hash'=>jobdivaTerminalCensusFingerprint($context,$policy,$identity)];}
            catch(RuntimeException $e){if(!in_array($e->getCode(),[409,422],true))throw$e;$states[$external]=['review_required'=>true];}
        }
        return ['workspace'=>$workspace,'states'=>$states];
    });
}

function jobdivaTerminalCensusApply(int $workspace,array $row,?int $actor,?array $baseline=null): array
{
    return companyRelationshipAtomic(getDB(),static function()use($workspace,$row,$actor,$baseline):array{
        $pdo=getDB();$context=jobdivaPlacementWriteContext($pdo,$workspace);$policy=jobdivaPlacementImportPolicy($pdo,$workspace);
        $start=jobdivaAssignmentRowId($row);$desired=(string)($row['__cf_jobdiva_canonical_status']??'');
        if($start===''||!in_array($desired,['ended','cancelled'],true))throw new InvalidArgumentException('Choose an exact terminal assignment.',422);
        $identity=jobdivaPlacementWriteIdentity($pdo,$context,$start);$placement=$identity['placement'];
        if(!$placement)return ['changed'=>false,'matched'=>false,'reason'=>'not_found'];
        if($baseline!==null&&(($baseline['workspace']??null)!==$workspace||($baseline['states'][$start]['hash']??null)!==jobdivaTerminalCensusFingerprint($context,$policy,$identity)))
            throw new RuntimeException('Assignment ownership or source evidence changed during census discovery. Refresh and review this ending.',409);
        // Native lifecycle ownership is authoritative even when the incoming row is incomplete.
        if(array_intersect(['status','end_date','actual_end_date','deleted_at'],$identity['owned_fields']))return ['changed'=>false,'matched'=>true,'reason'=>'core_owned_lifecycle'];
        $rawEnd=jobdivaAssignmentIdentityPluck($row,['end date','endDate','end_date','assignment end date']);$end=jobdivaNormaliseDate($rawEnd);
        if($rawEnd!==''&&$end===null)throw new RuntimeException('Review the JobDiva assignment end date.',422);
        $rawStatus=jobdivaAssignmentIdentityPluck($row,['startStatus','start_status','start status','assignmentStatus','assignment_status','status']);
        $actual=jobdivaAssignmentCanonicalPlacementStatus($rawStatus,$end)['status'];
        $sourceType=jobdivaAssignmentIdentityPluck($row,['__cf_jobdiva_source_object']);
        if(($sourceType!==''&&!in_array(strtolower($sourceType),['assignment','start'],true))||count(jobdivaAssignmentIdentityValues($row,true))!==1||empty(jobdivaAssignmentStructuralEvidence($row)['complete'])||$actual!==$desired)
            throw new RuntimeException('The census row does not establish this exact terminal assignment.',409);
        if($identity['mapping']&&$identity['mapping']['payload_snapshot']!==null){
            $stored=json_decode($identity['mapping']['payload_snapshot'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($stored)||!jobdivaAssignmentContextEvidence($row,$stored)['matches'])throw new RuntimeException('The terminal row conflicts with the retained assignment context.',409);
        }
        return jobdivaSourceLifecycleApplyLocked($pdo,$workspace,$context,$identity,$desired,$end,$row,$actor,'placement.lifecycle_from_jobdiva_census');
    });
}
