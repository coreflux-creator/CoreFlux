<?php
/** Reviewed recovery of source-owned active status; never approves money or rewrites identities. */
declare(strict_types=1);
require_once __DIR__.'/live_contract_refresh.php';
require_once __DIR__.'/../../modules/placements/lib/company_references.php';
require_once __DIR__.'/../RBAC.php';
require_once __DIR__.'/../rbac/permissions.php';

function jobdivaActiveRestoreIds(array $ids): array
{
    if (!$ids || count($ids)>100) throw new InvalidArgumentException('Select between one and 100 placement IDs.',422);
    foreach ($ids as &$id) {
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]*$/D',(string)$id)
            || (string)(int)$id !== (string)$id) throw new InvalidArgumentException('Use complete positive placement IDs.',422);
        $id=(int)$id;
    }
    unset($id);
    if (count(array_unique($ids))!==count($ids)) throw new InvalidArgumentException('Select each placement once.',422);
    sort($ids,SORT_NUMERIC);
    return $ids;
}

/** Fresh actor and grants, independent of browser sessions or CLI environment roles. */
function jobdivaActiveRestoreActor(PDO $pdo,int $workspace,int $actor): array
{
    $q=$pdo->prepare('SELECT id,parent_id,is_active FROM tenants WHERE id=? FOR UPDATE');$q->execute([$workspace]);$tenant=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT id,role,is_active,is_global_admin FROM users WHERE id=? FOR UPDATE');$q->execute([$actor]);$user=$q->fetch(PDO::FETCH_ASSOC);
    if (!$tenant || !$tenant['is_active'] || !$user || !$user['is_active']) throw new RuntimeException('An active reviewer and workspace are required.',403);
    $q=$pdo->prepare('SELECT * FROM tenant_memberships WHERE user_id=? AND tenant_id IN(?,?) ORDER BY is_primary DESC,last_active_at DESC,id FOR UPDATE');
    $q->execute([$actor,$workspace,$tenant['parent_id']??0]);$memberships=$q->fetchAll(PDO::FETCH_ASSOC);
    $selected=null;
    foreach ($memberships as $m) if ((int)$m['tenant_id']===$workspace && $m['status']==='active') { $selected=$m;break; }
    // A revoked direct membership cannot fall through to a parent permission.
    $hasDirect=(bool)array_filter($memberships,static fn($m)=>(int)$m['tenant_id']===$workspace);
    if (!$selected && !$hasDirect) foreach ($memberships as $m) {
        if ($m['status']==='active' && in_array($m['persona_type'],['tenant_admin','master_admin'],true)) { $selected=$m;break; }
    }
    $grants=[];
    if ($selected) {
        $q=$pdo->prepare('SELECT * FROM membership_module_access WHERE membership_id=? ORDER BY module_key FOR UPDATE');$q->execute([$selected['id']]);$grants=$q->fetchAll(PDO::FETCH_ASSOC);
        $user['tenant_role']=$selected['persona_type'];
    }
    RBACResolver::resetCache();RBAC::reset();
    $global=(int)$user['is_global_admin']===1;
    if ($global) $user['global_role']='master_admin';
    if ((!$global && !$selected) || !RBAC::hasPermission($user,'placements.manage')
        || !RBACResolver::can($user,(int)($selected['tenant_id']??$workspace),'placements','write',
            $selected && (int)$selected['tenant_id']!==$workspace ? $workspace : null,$selected ? (int)$selected['id'] : null)) {
        throw new RuntimeException('The reviewer needs current placement management access in this workspace.',403);
    }
    return ['user'=>$user,'membership'=>$selected,'grants'=>$grants];
}

/** Internal evidence, not a public response. All reads participate in the serializable review. */
function jobdivaActiveRestoreSnapshot(int $workspace,int $actor,array $ids): array
{
    if (!JobdivaStoredReviewTransaction::active()) throw new LogicException('Active restoration requires its protected review transaction.');
    $pdo=getDB();$auth=jobdivaActiveRestoreActor($pdo,$workspace,$actor);
    $context=jobdivaPlacementWriteContext($pdo,$workspace);$owner=$context['placement_owner'];$today=date('Y-m-d');
    $rows=[];$evidence=[];
    foreach ($ids as $id) {
        $q=$pdo->prepare('SELECT id FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND internal_entity_type="placement" AND internal_entity_id=? ORDER BY id FOR UPDATE');
        $q->execute([$workspace,$id]);$links=$q->fetchAll(PDO::FETCH_COLUMN);
        if (count($links)!==1) throw new RuntimeException('Each selected placement needs one exact JobDiva link in the original workspace.',409);
        $item=jobdivaLiveContractSnapshot($workspace,(int)$links[0]);$base=$item['base'];$p=$base['placement'];$blocked=[];
        if (!in_array($p['status'],['pending_start','ended'],true)) $blocked[]='review_current_status';
        if (!empty($p['deleted_at']) || !empty($p['actual_end_date'])) $blocked[]='ended_or_archived';
        if (empty($p['start_date']) || $p['start_date']>$today || (!empty($p['end_date']) && $p['end_date']<$today)) $blocked[]='outside_current_dates';
        if (array_intersect(['status','start_date','end_date','actual_end_date','deleted_at'],$base['owned_fields'])) $blocked[]='core_owned_lifecycle';
        if (!$base['person'] || !empty($base['person']['deleted_at']) || !in_array($base['person']['status'],['active','bench'],true)) $blocked[]='worker_unavailable';
        if (!in_array('person_id',$base['owned_fields'],true)
            && (!$item['person_source'] || $item['person_source']['sync_status']!=='ok' || (int)$item['person_source']['internal_entity_id']!==(int)$p['person_id'])) $blocked[]='worker_source_mismatch';

        $stored=json_decode((string)$base['mapping']['payload_snapshot'],true,512,JSON_THROW_ON_ERROR);
        $root=jobdivaAssignmentStripDerivedFacets($stored,$base['external']);$validation=jobdivaAssignmentValidate($root,$base['external']);
        $observed=strtotime((string)$base['mapping']['last_seen_at']);
        if (($base['connection']['status']??'')!=='connected' || $base['mapping']['sync_status']!=='ok'
            || ($base['mirrors'] && $base['mirrors'][0]['sync_status']!=='ok')
            || !jobdivaAssignmentObservedInLatestSync($validation,$base['mapping']['last_seen_at'],$base['last_sync_at'])
            || $observed===false || $observed<time()-86400 || $observed>time()+300) $blocked[]='refresh_source_evidence';
        // A generated active label cannot substitute for the retained raw Start and financial sections.
        $detail=$stored['_jd_assignment_detail']??[];
        // Stripping cached facets adds trusted-context metadata. Compare the
        // financial rows to the exact Start tuple, not that metadata to the
        // unnormalised stored root. Every supplied detail row must match.
        $exactDetail=is_array($detail) && $detail && jobdivaAssignmentContractRowsForStart($detail,$root,$base['external'])===$detail;
        $contract=$exactDetail ? jobdivaAssignmentContractBuild($detail,$root,$base['external']) : [];
        if (!$contract || mappingHash($contract)!==mappingHash($stored['_jd_contract']??[])
            || !jobdivaAssignmentContractSnapshotIsStronglyActive(['_jd_contract'=>$contract])) $blocked[]='review_exact_active_contract';
        if ($contract && (jobdivaNormaliseDate($contract['start_date']??null)!==$p['start_date']
            || jobdivaNormaliseDate($contract['end_date']??null)!==($p['end_date']?:null))) $blocked[]='review_source_dates';
        foreach ($base['mirrors'] as $mirror) {
            $mirrorPayload=json_decode((string)$mirror['payload_snapshot'],true,512,JSON_THROW_ON_ERROR);
            if (!is_array($mirrorPayload) || array_is_list($mirrorPayload)) throw new RuntimeException('Review the saved assignment mirror.',409);
            $mirrorRoot=jobdivaAssignmentStripDerivedFacets($mirrorPayload,$base['external']);
            $mirrorStatus=jobdivaAssignmentCanonicalPlacementStatus(jobdivaAssignmentIdentityPluck($mirrorRoot,['startStatus','start_status','status','assignmentStatus']),jobdivaNormaliseDate(jobdivaAssignmentIdentityPluck($mirrorRoot,['endDate','end_date','end date'])));
            if (empty(jobdivaAssignmentValidate($mirrorRoot,$base['external'])['valid']) || empty(jobdivaAssignmentContextEvidence($mirrorRoot,$root)['matches'])
                || in_array($mirrorStatus['status']??'',['ended','cancelled','on_hold'],true)) $blocked[]='review_conflicting_assignment_mirror';
        }

        $coverage=[];
        foreach (array_unique([$p['start_date'],$today]) as $date) {
            $matching=array_values(array_filter($item['rates'],static fn($r)=>!empty($r['approved_at']) && $r['effective_from']<=$date && (empty($r['effective_to']) || $r['effective_to']>=$date)));
            if (count($matching)!==1) $blocked[]='review_approved_rate_coverage';
            else $coverage[$date]=(int)$matching[0]['id'];
        }
        // Validate referenced directories without creating or repairing companies, roles or schedules.
        $scope=companyRelationshipScope($pdo,$workspace,'placements');
        placementResolveCurrentCompanyRoles($owner,['client_id'=>$p['client_id'],'end_client_company_id'=>$p['end_client_company_id']],$p,$actor,$workspace);
        if (!empty($p['staffing_job_id'])) placementResolveNativeJob($pdo,$scope,$p['staffing_job_id']);
        if (!empty($p['accounting_entity_id'])) placementResolveNativeAccountingEntity($pdo,$scope,$p['accounting_entity_id']);
        $economics=placementEconomicsContext($owner,$id,false);
        if (empty($economics['available']) || empty($economics['readiness']['ready'])) $blocked[]='review_economic_setup';
        $references=[];
        foreach ($economics['parties']??[] as $party) {
            $ref=['party_id'=>$party['id']];$channel=$party['settlement_channel'];
            if (!empty($party['company_id'])) $ref['company']=companyRelationshipCurrent($pdo,$scope,(int)$party['company_id']);
            if ($channel==='ar') {
                $q=$pdo->prepare('SELECT id,company_id,status FROM staffing_clients WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$context['staffing_owner'],$p['client_id']]);$ref['client']=$q->fetch(PDO::FETCH_ASSOC);
                if (!$ref['client'] || $ref['client']['status']!=='active' || (int)$ref['client']['company_id']!==(int)$party['company_id']) $blocked[]='review_current_billing_recipient';
            }
            if ($channel==='ap') {
                $q=$pdo->prepare('SELECT id,company_id FROM ap_vendors_index WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$owner,$party['ap_vendor_id']]);$ref['vendor']=$q->fetch(PDO::FETCH_ASSOC);
                if (!$ref['vendor'] || (!empty($party['company_id']) && (int)$ref['vendor']['company_id']!==(int)$party['company_id'])) $blocked[]='review_current_vendor';
            }
            if ($channel==='payroll') {
                $ref['employee']=placementEconomicsPayrollEmployee($owner,$party,true);
                if (!$ref['employee']) $blocked[]='review_current_payroll_recipient';
            }
            if ($party['fee_basis']==='pay_rate' && in_array($p['engagement_type'],['w2','1099','temp_to_perm','internal'],true)
                && (int)$party['person_id']!==(int)$p['person_id']) $blocked[]='review_current_labor_payee';
            if (in_array($channel,['ar','ap','payroll'],true)) {
                $q=$pdo->prepare('SELECT * FROM staffing_operating_cycles WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$owner,$party['operating_cycle_id']]);$ref['cycle']=$q->fetch(PDO::FETCH_ASSOC);
                if (!$ref['cycle'] || !$ref['cycle']['active'] || $ref['cycle']['purpose']!==($channel==='ar'?'billing':$channel)) $blocked[]='review_current_operating_cycle';
            }
            $references[]=$ref;
        }
        $q=$pdo->prepare('SELECT record_number,canonical_record_id FROM core_record_numbers WHERE tenant_id=? AND entity_type="placement" AND record_id=? FOR UPDATE');$q->execute([$owner,$id]);$number=$q->fetch(PDO::FETCH_ASSOC);
        if (!$number || (int)$number['canonical_record_id']!==$id) $blocked[]='review_permanent_placement_identity';
        $item['number']=$number;$item['economics']=$economics;$item['recipient_references']=$references;$evidence[]=$item;
        $rows[]=['placement_id'=>$id,'placement_number'=>$number['record_number']??null,'source_external_id'=>$base['mapping']['external_id'],
            'from_status'=>$p['status'],'to_status'=>'active','eligible'=>!$blocked,'blockers'=>array_values(array_unique($blocked)),
            'approved_rate_ids'=>$coverage];
    }
    $q=$pdo->prepare('SELECT id FROM placements WHERE tenant_id=? AND status="active" AND deleted_at IS NULL ORDER BY id FOR UPDATE');$q->execute([$owner]);
    return ['version'=>1,'workspace'=>$workspace,'actor'=>$actor,'auth'=>$auth,'context'=>$context,'date'=>$today,
        'ids'=>$ids,'active_ids'=>array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)),'rows'=>$rows,'evidence'=>$evidence];
}

function jobdivaActiveRestoreReview(int $workspace,int $actor,array $ids): array
{
    $ids=jobdivaActiveRestoreIds($ids);
    return JobdivaStoredReviewTransaction::run(static function()use($workspace,$actor,$ids):array {
        $snapshot=jobdivaActiveRestoreSnapshot($workspace,$actor,$ids);
        $eligible=count(array_filter($snapshot['rows'],static fn($r)=>$r['eligible']));
        return ['dry_run'=>true,'workspace_id'=>$workspace,'placement_owner_id'=>$snapshot['context']['placement_owner'],
            'review_token'=>jobdivaAssignmentRepairFingerprint($snapshot),'rows'=>$snapshot['rows'],
            'eligible_count'=>$eligible,'selected_count'=>count($ids),'active_total_before'=>count($snapshot['active_ids']),
            'expected_active_total_after'=>count($snapshot['active_ids'])+$eligible,'can_apply'=>$eligible===count($ids)];
    },true);
}

function jobdivaActiveRestoreApply(int $workspace,int $actor,array $body): array
{
    $ids=jobdivaActiveRestoreIds($body['placement_ids']??[]);$token=$body['review_token']??null;$request=$body['request_id']??null;$reason=$body['reason']??null;
    $expected=$body['expected_restore_count']??null;$total=$body['expected_active_total']??null;
    if (($body['confirm']??'')!=='RESTORE_REVIEWED_ACTIVE_STATUS' || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)
        || !is_string($request) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di',$request)
        || !is_string($reason) || strlen(trim($reason))<3 || strlen($reason)>1000 || !preg_match('//u',$reason)
        || preg_match('/[\x00-\x1f\x7f]/',$reason) || !is_int($expected) || $expected!==count($ids) || !is_int($total) || $total<$expected) {
        throw new InvalidArgumentException('Provide the reviewed selection, token, request ID, exact counts and reason.',422);
    }
    $request=strtolower($request);$reason=trim($reason);
    $hash=jobdivaAssignmentRepairFingerprint([$workspace,$actor,$ids,$token,$request,$reason,$expected,$total]);
    return JobdivaStoredReviewTransaction::run(static function()use($workspace,$actor,$ids,$token,$request,$reason,$expected,$total,$hash):array {
        $pdo=getDB();jobdivaActiveRestoreActor($pdo,$workspace,$actor);
        // The workspace row serializes receipt lookup; recovery never repeats a write after a later native edit.
        $q=$pdo->prepare('SELECT actor_user_id,meta_json FROM audit_log WHERE tenant_id=? AND event="jobdiva.active_status_restored" AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.request_id"))=? FOR UPDATE');$q->execute([$workspace,$request]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        if ($prior) {
            $receipt=json_decode($prior['meta_json'],true,512,JSON_THROW_ON_ERROR);
            if ((int)$prior['actor_user_id']!==$actor || ($receipt['request_hash']??'')!==$hash) throw new RuntimeException('This request differs from its saved restoration receipt.',409);
            return array_replace($receipt['result'],['replayed'=>true]);
        }
        $snapshot=jobdivaActiveRestoreSnapshot($workspace,$actor,$ids);$owner=$snapshot['context']['placement_owner'];
        if (!hash_equals($token,jobdivaAssignmentRepairFingerprint($snapshot))) throw new RuntimeException('The reviewed assignment evidence changed. Preview and review again.',409);
        if (count(array_filter($snapshot['rows'],static fn($r)=>$r['eligible']))!==$expected || count($snapshot['active_ids'])+$expected!==$total) throw new RuntimeException('The reviewed selection or active count is no longer eligible.',409);
        foreach ($snapshot['rows'] as $i=>$row) {
            $q=$pdo->prepare('UPDATE placements SET status="active",updated_at=NOW() WHERE tenant_id=? AND id=? AND status=? AND deleted_at IS NULL AND actual_end_date IS NULL');
            $q->execute([$owner,$row['placement_id'],$row['from_status']]);
            if ($q->rowCount()!==1) throw new RuntimeException('An assignment changed before restoration.',409);
            companySourceAudit($pdo,$workspace,$owner,'placements',$row['placement_id'],'placement.active_status_restored',['status'],$actor,[
                'request_id'=>$request,'reason'=>$reason,'source_system'=>'jobdiva','source_external_id'=>$row['source_external_id'],
                'source_mapping_id'=>(int)$snapshot['evidence'][$i]['base']['mapping']['id'],'placement_number'=>$row['placement_number'],
                'before'=>['status'=>$row['from_status']],'after'=>['status'=>'active'],'approved_rate_ids'=>$row['approved_rate_ids'],
            ]);
        }
        $q=$pdo->prepare('SELECT COUNT(*) FROM placements WHERE tenant_id=? AND status="active" AND deleted_at IS NULL');$q->execute([$owner]);
        if ((int)$q->fetchColumn()!==$total) throw new RuntimeException('The final active count differs from the reviewed result.',409);
        $result=['restored'=>$expected,'restored_ids'=>$ids,'active_total_after'=>$total,'replayed'=>false];
        companySourceAudit($pdo,$workspace,$owner,'placements',0,'jobdiva.active_status_restored',['status'],$actor,[
            'request_id'=>$request,'request_hash'=>$hash,'review_token'=>$token,'reason'=>$reason,'result'=>$result,
        ]);
        return $result;
    });
}
