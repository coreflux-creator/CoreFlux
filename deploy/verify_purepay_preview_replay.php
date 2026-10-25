<?php
/** Exercise native receipt recovery for the five completed demo deliveries. Never send records. */
declare(strict_types=1);
const REPLAY_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';

/** Original receipts may become historical only through the exact reviewed successor. */
function purepayReplayAssignmentReview(array $review,array $original,string $nativeId,?array $successor=null): bool
{
    if (($review['assignment_id']??null)!==$nativeId || ($review['assignment_status']??null)!=='draft') return false;
    if (($review['state']??null)==='created') return ($review['accepted_revision']??null)===($original['source_revision']??null);
    if (($review['state']??null)!=='superseded' || !$successor || ($original['contract_version']??null)!=='2026-10-22.2'
        || !is_array($original['work_context']??null) || array_key_exists('worksite_city',$original['work_context'])
        || !is_string($original['source_revision']??null)) return false;
    $packet=$successor['packet']??[];$current=$successor['provider_review']??[];
    $expected=$original;$expected['contract_version']='2026-10-25.1';$expected['work_context']['worksite_city']=null;
    unset($expected['source_revision'],$expected['previous_revision']);
    $expected['source_revision']=integrationDeliveryHash($expected);$expected['previous_revision']=$original['source_revision'];
    return integrationDeliveryHash($packet)===integrationDeliveryHash($expected)
        && ($current['state']??null)==='created' && ($current['assignment_id']??null)===$nativeId
        && ($current['assignment_status']??null)==='draft'
        && ($current['accepted_revision']??null)===$expected['source_revision']
        && ($review['accepted_revision']??null)===$expected['source_revision'];
}

if (realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__) return;
if (PHP_SAPI!=='cli' || ($argv[1] ?? '')!=='--verify-native-demo-replay'
    || getenv('COREFLUX_ENV')!=='staging' || realpath(getcwd())!==REPLAY_ROOT) {
    fwrite(STDERR,"Exact isolated staging CLI and demo replay verification required.\n"); exit(2);
}
$phase='environment';
try {
    require_once REPLAY_ROOT.'/core/db.php';
    require_once REPLAY_ROOT.'/deploy/migrate_purepay_preview_origin.php';
    require_once REPLAY_ROOT.'/core/integrations/purepay_people_inputs.php';
    require_once REPLAY_ROOT.'/core/integrations/purepay_assignment_inputs.php';
    require_once REPLAY_ROOT.'/core/sub_tenants.php';
    $pdo=getDB();
    if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
        || !defined('COREFLUX_STAGING') || COREFLUX_STAGING!==true || integrationInstanceId()!==CONTINUITY_INSTANCE
        || purepayCoreApiBase()!==CONTINUITY_CANDIDATE
        || (int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn()!==1) {
        throw new RuntimeException('Simulation identity changed');
    }
    foreach (['people','placements','ap'] as $module) {
        $phase='directory_'.$module;
        if (effectiveTenantIdForModule($module,999)!==999) throw new RuntimeException('Directory ownership changed');
    }
    $phase='company_binding';
    $connection=purepayCoreConnection($pdo,999);
    if (!$connection || (int)$connection['revision']!==3 || $connection['remote_organization_id']!==CONTINUITY_ORG
        || !purepayCoreReview($pdo,999,CONTINUITY_INSTANCE)['verified']) throw new RuntimeException('New company binding is not verified');
    $phase='saved_deliveries';
    $before=purepayPreviewOriginRows($pdo);
    $ap=$pdo->query('SELECT * FROM purepay_connections ORDER BY tenant_id')->fetchAll(PDO::FETCH_ASSOC);
    $results=[];$successorRows=[];$successorReads=[];$nativeLookups=0;
    foreach ($before as $row) {
        $phase='delivery_'.(int)$row['id'];
        $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
        if (($payload['organization_id'] ?? null)!==CONTINUITY_ORG) throw new RuntimeException('Foreign original receipt');
        for ($attempt=0;$attempt<2;$attempt++) {
            $nativeLookups++;
            if ($row['provider']==='purepay') {
                $result=purepayCoreLinkReconcile($pdo,999,CONTINUITY_INSTANCE,999,(int)$row['id'],$row['review_hash']);
                $native=['delivery'=>$result];
            } elseif ($row['operation']==='people.inputs') {
                $native=purepayPeopleInputStatus($pdo,999,CONTINUITY_INSTANCE,['people'=>999,'placements'=>999],(int)$row['id'],$row['review_hash']);
                if (($native['provider_review']['state'] ?? null)!=='created'
                    || ($native['provider_review']['employee_id'] ?? null)!==purepayPreviewContinuityFixtures()['person']['provider_id']) {
                    throw new RuntimeException('Native employee receipt changed');
                }
            } elseif ($row['operation']==='assignments.inputs') {
                $native=purepayAssignmentInputStatus($pdo,999,CONTINUITY_INSTANCE,['people'=>999,'placements'=>999,'ap'=>999],(int)$row['id'],$row['review_hash']);
                $successor=null;
                if (($native['provider_review']['state']??null)==='superseded') {
                    if ((int)$row['id']!==5) throw new RuntimeException('Native assignment receipt changed');
                    $next=integrationDeliveryGet($pdo,999,12);
                    if ($next['provider']!=='purepayroll'||$next['operation']!=='assignments.inputs'||$next['state']!=='delivered'
                        ||$next['entity_type']!=='placement'||(int)$next['entity_tenant_id']!==999||(int)$next['entity_id']!==13)
                        throw new RuntimeException('Native assignment receipt changed');
                    [$next,$nextPayload]=purepayAssignmentInputEnvelope($pdo,999,12,$next['review_hash'],CONTINUITY_INSTANCE,['people'=>999,'placements'=>999,'ap'=>999]);
                    if ($nextPayload['organization_id']!==CONTINUITY_ORG) throw new RuntimeException('Foreign original receipt');
                    $nextStatus=purepayAssignmentInputStatus($pdo,999,CONTINUITY_INSTANCE,['people'=>999,'placements'=>999,'ap'=>999],12,$next['review_hash']);
                    $nativeLookups++;
                    if ($nextStatus['delivery']!==integrationDeliverySummary($next)
                        || ($attempt===1 && $successorReads[12]!==$nextStatus)) throw new RuntimeException('Original delivery changed');
                    $successorRows[12]=$next;$successorReads[12]=$nextStatus;
                    $successor=['packet'=>$nextPayload['body'],'provider_review'=>$nextStatus['provider_review']];
                }
                if (!purepayReplayAssignmentReview($native['provider_review'],$payload['body'],purepayPreviewContinuityFixtures()['placement']['provider_id'],$successor)) {
                    throw new RuntimeException('Native assignment receipt changed');
                }
            } else throw new RuntimeException('Unexpected operation');
            if (($native['delivery'] ?? null)!==integrationDeliverySummary($row)) throw new RuntimeException('Original delivery changed');
            if ($attempt===0) $first=$native;
            elseif ($first!==$native) throw new RuntimeException('Repeated native lookup changed its receipt');
        }
        $results[]=['delivery_id'=>(int)$row['id'],'operation'=>$row['operation'],'state'=>'delivered',
            'same_original_receipt'=>true,'replay_identical'=>true,'native_state'=>$native['provider_review']['state']??null];
    }
    foreach($successorRows as $id=>$next)if(integrationDeliveryGet($pdo,999,$id)!==$next)throw new RuntimeException('Original delivery changed');
    if (purepayPreviewOriginRows($pdo)!==$before || purepayCoreConnection($pdo,999)!==$connection
        || $pdo->query('SELECT * FROM purepay_connections ORDER BY tenant_id')->fetchAll(PDO::FETCH_ASSOC)!==$ap) {
        throw new RuntimeException('Stored integration or AP state changed');
    }
    // Optional additive contract: inspect one existing native demo run only.
    // A complete empty page proves endpoint availability, not a payroll round trip.
    $phase='native_payroll_observation';
    $nativeObservation=['available'=>false,'reason'=>'contract_not_advertised','capture_saved'=>false];
    $review=purepayCoreReview($pdo,999,CONTINUITY_INSTANCE);
    if(($review['payroll_observation_contract_version']??null)==='2026-10-25.1') {
        if(effectiveTenantIdForModule('time',999)!==999)throw new RuntimeException('Directory ownership changed');
        require_once REPLAY_ROOT.'/core/integrations/purepay_observations.php';
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $captureCount=(int)$pdo->query('SELECT COUNT(*) FROM payroll_native_observations WHERE tenant_id=999')->fetchColumn();
        $page=purepayObservationPage($pdo,999,CONTINUITY_INSTANCE,1);
        $nativeObservation=['available'=>true,'contract_version'=>$page['contract_version'],
            'page_size'=>count($page['items']),'more_runs'=>$page['next_cursor']!==null,'capture_saved'=>false,
            'detail_verified'=>false,'empty_collection'=>!$page['items'] && $page['next_cursor']===null];
        if($page['items']) {
            $runId=$page['items'][0]['run_id'];$owners=['people'=>999,'placements'=>999,'time'=>999];
            $left=purepayObservationRead($pdo,999,CONTINUITY_INSTANCE,$owners,$runId);
            $right=purepayObservationRead($pdo,999,CONTINUITY_INSTANCE,$owners,$runId);
            if($left['review_hash']!==$right['review_hash'])throw new RuntimeException('Native observation changed during acceptance');
            $nativeObservation+=['observation_hash'=>$left['observation']['observation_hash'],
                'line_count'=>count($left['observation']['lines']),
                'linked_people'=>count(array_filter($left['comparison']['people'],static fn($person)=>$person['link_status']==='linked')),
                'repeated_review_identical'=>true,'core_time_match_evaluated'=>false,'settlement_evaluated'=>false];
            $nativeObservation['detail_verified']=true;
        }
        if((int)$pdo->query('SELECT COUNT(*) FROM payroll_native_observations WHERE tenant_id=999')->fetchColumn()!==$captureCount
            || purepayCoreConnection($pdo,999)!==$connection || purepayPreviewOriginRows($pdo)!==$before)
            throw new RuntimeException('Native observation altered local state');
    }
    echo 'PUREPAY_NATIVE_REPLAY='.json_encode(['verified'=>true,'tenant_id'=>999,'organization_id'=>CONTINUITY_ORG,
        'deliveries'=>$results,'native_lookups'=>$nativeLookups,'original_deliveries_unchanged'=>true,'connection_unchanged'=>true,
        'reviewed_successors'=>array_map(static fn($id)=>['delivery_id'=>$id,'native_state'=>$successorReads[$id]['provider_review']['state'],
            'same_original_receipt'=>true,'replay_identical'=>true,'stored_delivery_unchanged'=>true],array_keys($successorRows)),
        'ap_connection_unchanged'=>true,'provider_record_writes'=>false,'payroll_or_payments_executed'=>false,
        'native_payroll_observation'=>$nativeObservation],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $e) {
    $reason=match($e->getMessage()) {
        'Simulation identity changed'=>'simulation_identity_changed',
        'Directory ownership changed'=>'directory_ownership_changed',
        'New company binding is not verified'=>'company_binding_not_verified',
        'The person review belongs to different directory owners.'=>'person_directory_owners_mismatch',
        'This placement review belongs to different directory owners.'=>'placement_directory_owners_mismatch',
        'Original delivery changed'=>'delivery_summary_changed',
        'Native employee receipt changed'=>'employee_receipt_changed',
        'Native assignment receipt changed'=>'assignment_receipt_changed',
        default=>'native_receipt_check_failed',
    };
    $ownerTypes=[];
    foreach (($payload['owners'] ?? []) as $owner=>$value) {
        if (in_array($owner,['people','placements','ap'],true)) $ownerTypes[$owner]=get_debug_type($value);
    }
    fwrite(STDERR,'PUREPAY_NATIVE_REPLAY_FAILURE='.json_encode(['phase'=>$phase,'reason'=>$reason,
        'exception_class'=>get_class($e),'source_file'=>basename($e->getFile()),'source_line'=>$e->getLine(),
        'stored_owner_types'=>$ownerTypes,
        'stored_owners_all_simulation'=>isset($payload['owners']) && is_array($payload['owners'])
            && !array_filter($payload['owners'],static fn($value): bool=>$value!==999),
        'send_called'=>false],JSON_THROW_ON_ERROR)."\n"); exit(2);
}
