<?php
/** Re-review only the already-approved Meridian demo after its location schema update. */
declare(strict_types=1);

const DEMO_LOCATION_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const DEMO_LOCATION_INSTANCE='9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
const DEMO_LOCATION_BASE='https://pure-pay-support.preview.emergentagent.com/api/v1';
const DEMO_LOCATION_ORG='org_purepay_demo';

/** True only for the single reviewed schema delta; false for an exact completed replay. */
function purepayDemoLocationReviewDelta(array $row,array $review,string $key): bool
{
    if ((int)($row['id']??0)!==1 || (int)($row['tenant_id']??0)!==999
        || (int)($row['revision']??0)!==3 || ($row['status']??null)!=='active'
        || ($row['core_instance_id']??null)!==DEMO_LOCATION_INSTANCE
        || ($row['remote_organization_id']??null)!==DEMO_LOCATION_ORG
        || ($row['remote_api_base']??null)!==DEMO_LOCATION_BASE || purepayCoreApiBase()!==DEMO_LOCATION_BASE
        || ($review['tenant_id']??null)!==999 || ($review['organization']['id']??null)!==DEMO_LOCATION_ORG
        || ($review['organization']['name']??null)!==($row['remote_organization_name']??null)
        || ($review['assignment_input_contract_version']??null)!=='2026-10-25.1'
        || !is_string($row['binding_hash']??null)) throw new RuntimeException('Demo identity changed');
    $context=$review+['execution_side_effects'=>false];
    $current=purepayCoreHash($row,$key,DEMO_LOCATION_INSTANCE,$context);
    if (!is_string($review['review_hash']??null) || !hash_equals($current,$review['review_hash'])) {
        throw new RuntimeException('Fresh review changed');
    }
    if (hash_equals($row['binding_hash'],$current)) {
        if (($review['verified']??null)!==true) throw new RuntimeException('Inconsistent review');
        return false;
    }
    $context['assignment_input_contract_version']='2026-10-22.2';
    if (($review['verified']??null)!==false
        || !hash_equals($row['binding_hash'],purepayCoreHash($row,$key,DEMO_LOCATION_INSTANCE,$context))) {
        throw new RuntimeException('Changes exceed the reviewed location contract');
    }
    return true;
}

if (realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__) return;
if (PHP_SAPI!=='cli' || ($argv[1]??'')!=='--refresh-reviewed-demo-location'
    || getenv('COREFLUX_ENV')!=='staging' || realpath(getcwd())!==DEMO_LOCATION_ROOT) {
    fwrite(STDERR,"The named isolated staging directory and explicit demo review are required.\n"); exit(2);
}
$phase='environment';
try {
    require_once DEMO_LOCATION_ROOT.'/core/db.php';
    require_once DEMO_LOCATION_ROOT.'/core/integrations/purepay_links.php';
    require_once DEMO_LOCATION_ROOT.'/core/sub_tenants.php';
    require_once DEMO_LOCATION_ROOT.'/core/audit.php';
    require_once DEMO_LOCATION_ROOT.'/core/RBAC.php';
    require_once DEMO_LOCATION_ROOT.'/core/rbac/permissions.php';
    require_once DEMO_LOCATION_ROOT.'/core/rbac/legacy_map.php';
    require_once DEMO_LOCATION_ROOT.'/deploy/migrate_purepay_preview_origin.php';
    $pdo=getDB();
    if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
        || !defined('COREFLUX_STAGING') || COREFLUX_STAGING!==true
        || integrationInstanceId()!==DEMO_LOCATION_INSTANCE
        || (int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn()!==1) {
        throw new RuntimeException('Simulation identity changed');
    }
    foreach (['people','placements','ap'] as $module) {
        if (effectiveTenantIdForModule($module,999)!==999) throw new RuntimeException('Directory ownership changed');
    }
    $operator=$pdo->query("SELECT id,role FROM users WHERE id=4 AND is_active=1 AND email='stage-admin@coreflux.test'")->fetch(PDO::FETCH_ASSOC);
    if (!$operator || (RBACResolver::activeMembership(4,999)['status']??null)!=='active') throw new RuntimeException('Operator unavailable');
    $operator['global_role']=$operator['role']; $operator['role']=RBACResolver::personaTypeOf(4,999);
    [$module,$action]=RbacLegacyMap::resolve('accounting.bank.manage');
    if (!RBAC::hasPermission($operator,'accounting.bank.manage') || !RBACResolver::can($operator,999,$module,$action)) {
        throw new RuntimeException('Existing operator lacks review authority');
    }
    $phase='review_delta';
    $row=purepayCoreConnection($pdo,999);
    if (!$row) throw new RuntimeException('Missing demo connection');
    $review=purepayCoreReview($pdo,999,DEMO_LOCATION_INSTANCE);
    $key=(string)decryptField($row['api_key_ct']);
    $change=purepayDemoLocationReviewDelta($row,$review,$key);
    $deliveries=purepayPreviewOriginRows($pdo);
    $ap=$pdo->query('SELECT * FROM purepay_connections ORDER BY tenant_id')->fetchAll(PDO::FETCH_ASSOC);
    $bodies=[]; $inputs=[];
    foreach ($deliveries as $delivery) {
        $payload=json_decode((string)decryptField($delivery['payload_ct']),true,64,JSON_THROW_ON_ERROR);
        if ($delivery['provider']==='purepay') $bodies[$delivery['entity_type']]=$payload['body'];
        else $inputs[$delivery['entity_type']]=$payload['body'];
    }
    $phase='original_provider_receipts';
    $continuity=purepayPreviewContinuityRead($key,$bodies,$inputs);
    unset($key,$payload,$bodies,$inputs);
    if (!$continuity['continuity_verified'] || count($continuity['checks'])!==9) throw new RuntimeException('Original receipts changed');
    $auditEvent='purepay.core.demo_location_review';
    $auditCount=static function()use($pdo,$auditEvent):int {
        $s=$pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id=999 AND actor_user_id=4 AND event=:event AND target_id=1');
        $s->execute(['event'=>$auditEvent]); return (int)$s->fetchColumn();
    };
    $beforeAudit=$auditCount();
    $assertPreserved=static function()use($pdo,$row,$deliveries,$ap):void {
        $after=purepayCoreConnection($pdo,999);
        $before=$row;
        foreach (['binding_hash','verified_by','verified_at'] as $field) {unset($before[$field],$after[$field]);}
        if ($before!==$after || purepayPreviewOriginRows($pdo)!==$deliveries
            || $pdo->query('SELECT * FROM purepay_connections ORDER BY tenant_id')->fetchAll(PDO::FETCH_ASSOC)!==$ap) {
            throw new RuntimeException('Unrelated state changed');
        }
    };
    $phase='native_confirmation';
    if ($change) {
        purepayCoreConfirm($pdo,999,4,DEMO_LOCATION_INSTANCE,$review['review_hash'],DEMO_LOCATION_ORG,
            static function()use($assertPreserved,$auditEvent,$auditCount,$beforeAudit):void {
                $assertPreserved();
                platformAuditLogWrite(999,4,$auditEvent,1,[
                    'operation'=>'meridian-demo-location-review-20261025',
                    'performed_by'=>'authorized_pilot_automation',
                    'old_assignment_contract'=>'2026-10-22.2','new_assignment_contract'=>'2026-10-25.1',
                    'credentials_and_capabilities_unchanged'=>true,'provider_record_writes'=>false,
                ]);
                if ($auditCount()!==$beforeAudit+1) throw new RuntimeException('Atomic audit did not persist');
            });
    }
    $phase='postflight';
    $assertPreserved();
    if (!purepayCoreReview($pdo,999,DEMO_LOCATION_INSTANCE)['verified']
        || $auditCount()!==$beforeAudit+($change?1:0)) throw new RuntimeException('Confirmation did not verify');
    echo 'PUREPAY_DEMO_LOCATION_REVIEW='.json_encode([
        'verified'=>true,'tenant_id'=>999,'organization_id'=>DEMO_LOCATION_ORG,
        'assignment_contract_version'=>'2026-10-25.1','review_refreshed'=>$change,'exact_replay'=>!$change,
        'original_receipt_checks'=>9,'original_deliveries_unchanged'=>true,
        'credential_and_revision_unchanged'=>true,'capabilities_unchanged'=>true,'ap_connection_unchanged'=>true,
        'provider_record_writes'=>false,'payroll_or_payments_executed'=>false,
        'audit_rows_added'=>$change?1:0,
    ],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    fwrite(STDERR,'PUREPAY_DEMO_LOCATION_REVIEW_FAILURE='.json_encode([
        'phase'=>$phase,'exception_class'=>get_class($e),'provider_record_writes'=>false,
        'reason'=>'reviewed_identity_contract_or_state_changed',
    ],JSON_THROW_ON_ERROR)."\n"); exit(2);
}
