<?php
/** Exercise native receipt recovery for the five completed demo deliveries. Never send records. */
declare(strict_types=1);
const REPLAY_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
if (PHP_SAPI!=='cli' || ($argv[1] ?? '')!=='--verify-native-demo-replay'
    || getenv('COREFLUX_ENV')!=='staging' || realpath(getcwd())!==REPLAY_ROOT) {
    fwrite(STDERR,"Exact isolated staging CLI and demo replay verification required.\n"); exit(2);
}
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
        if (effectiveTenantIdForModule($module,999)!==999) throw new RuntimeException('Directory ownership changed');
    }
    $connection=purepayCoreConnection($pdo,999);
    if (!$connection || (int)$connection['revision']!==3 || $connection['remote_organization_id']!==CONTINUITY_ORG
        || !purepayCoreReview($pdo,999,CONTINUITY_INSTANCE)['verified']) throw new RuntimeException('New company binding is not verified');
    $before=purepayPreviewOriginRows($pdo);
    $ap=$pdo->query('SELECT * FROM purepay_connections ORDER BY tenant_id')->fetchAll(PDO::FETCH_ASSOC);
    $results=[];
    foreach ($before as $row) {
        $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
        if (($payload['organization_id'] ?? null)!==CONTINUITY_ORG) throw new RuntimeException('Foreign original receipt');
        for ($attempt=0;$attempt<2;$attempt++) {
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
                if (($native['provider_review']['state'] ?? null)!=='created'
                    || ($native['provider_review']['assignment_status'] ?? null)!=='draft'
                    || ($native['provider_review']['assignment_id'] ?? null)!==purepayPreviewContinuityFixtures()['placement']['provider_id']
                    || ($native['provider_review']['accepted_revision'] ?? null)!==($payload['body']['source_revision'] ?? null)) {
                    throw new RuntimeException('Native assignment receipt changed');
                }
            } else throw new RuntimeException('Unexpected operation');
            if (($native['delivery'] ?? null)!==integrationDeliverySummary($row)) throw new RuntimeException('Original delivery changed');
            if ($attempt===0) $first=$native;
            elseif ($first!==$native) throw new RuntimeException('Repeated native lookup changed its receipt');
        }
        $results[]=['delivery_id'=>(int)$row['id'],'operation'=>$row['operation'],'state'=>'delivered',
            'same_original_receipt'=>true,'replay_identical'=>true];
    }
    if (purepayPreviewOriginRows($pdo)!==$before || purepayCoreConnection($pdo,999)!==$connection
        || $pdo->query('SELECT * FROM purepay_connections ORDER BY tenant_id')->fetchAll(PDO::FETCH_ASSOC)!==$ap) {
        throw new RuntimeException('Stored integration or AP state changed');
    }
    echo 'PUREPAY_NATIVE_REPLAY='.json_encode(['verified'=>true,'tenant_id'=>999,'organization_id'=>CONTINUITY_ORG,
        'deliveries'=>$results,'native_lookups'=>10,'original_deliveries_unchanged'=>true,'connection_unchanged'=>true,
        'ap_connection_unchanged'=>true,'provider_record_writes'=>false,'payroll_or_payments_executed'=>false],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $e) {
    fwrite(STDERR,"Native PurePay demo replay did not verify. No send operation was called.\n"); exit(2);
}
