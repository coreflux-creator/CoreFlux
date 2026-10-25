<?php
/** Read-only diagnostics for the user-approved Acme/Meridian simulation, never production. */
declare(strict_types=1);

const PILOT_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const PILOT_TENANT = 999;
const PILOT_INSTANCE = '9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
const PILOT_QUANTA_WORKSPACE = '0598d4ef-3075-4289-9714-c93f904aa85d';

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--inspect-pilot'
    || getenv('COREFLUX_ENV') !== 'staging' || realpath(getcwd()) !== PILOT_ROOT) {
    fwrite(STDERR, "The named isolated staging directory and explicit inspection are required.\n");
    exit(2);
}

require_once PILOT_ROOT.'/core/db.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx'
    || !defined('COREFLUX_STAGING') || COREFLUX_STAGING !== true
    || (int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn() !== 1) {
    fwrite(STDERR, "The isolated simulation database could not be verified.\n");
    exit(2);
}

// Subsequent application queries cannot accidentally write through this connection.
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
require_once PILOT_ROOT.'/core/sub_tenants.php';
require_once PILOT_ROOT.'/core/quanta/onboarding_delivery.php';
if (effectiveTenantIdForModule('people', PILOT_TENANT) !== PILOT_TENANT
    || effectiveTenantIdForModule('placements', PILOT_TENANT) !== PILOT_TENANT
    || integrationInstanceId() !== PILOT_INSTANCE
    || quantaApiBase() !== 'https://clockin-hub-16.preview.emergentagent.com/api/v1') {
    fwrite(STDERR, "The reviewed simulation identity, directories or preview destination changed.\n");
    exit(2);
}

$report = [
    'checked_at' => gmdate('c'),
    'mode' => 'read_only_synthetic_integration',
    'tenant_id' => PILOT_TENANT,
    'database_identity_verified' => true,
    'private_identity_verified' => true,
    'local_writes' => false,
    'provider_record_writes' => false,
    'configuration_changed' => false,
    'onboarding_delivery_enabled' => quantaOnboardingDeliveryEnabled(),
    'document_intake_enabled' => quantaOnboardingDocumentsEnabled(PILOT_TENANT),
    'private_document_storage_ready' => quantaOnboardingDocumentStorageReady(),
];

// Schema-only field coverage. No workflow payload, record value or credential is read.
require_once PILOT_ROOT.'/core/integrations/staffing_field_contract.php';
$dictionary = staffingFieldContractInventory($pdo, PILOT_TENANT);
$workflowCounts = [];
$unverifiedContainers = [];
foreach ($dictionary['fields'] as $field) {
    if ($field['kind'] !== 'workflow_payload_field') continue;
    $workflowCounts[$field['workflow']] = ($workflowCounts[$field['workflow']] ?? 0) + 1;
    if ($field['core_schema_status'] !== 'verified_container_present') $unverifiedContainers[$field['storage_container']] = true;
}
$report['field_dictionary'] = [
    'contract_version'=>$dictionary['contract_version'], 'metadata_only'=>true,
    'activates_sync'=>$dictionary['activates_sync'], 'field_count'=>count($dictionary['fields']),
    'workflow_fields'=>$workflowCounts, 'unverified_workflow_containers'=>array_keys($unverifiedContainers),
];
unset($dictionary);

$person = $pdo->query("SELECT id,status FROM people WHERE tenant_id=999 AND id=15
    AND email_primary='core-integration-20261023@example.com' AND deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
$placement = $pdo->query("SELECT id,person_id,status FROM placements WHERE tenant_id=999 AND id=13
    AND person_id=15 AND title='Synthetic Core Integration 20261023' AND deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
if (!$person || !$placement) {
    fwrite(STDERR, "The previously accepted synthetic person and placement were not found.\n");
    exit(2);
}
$report['synthetic_records'] = ['person' => $person, 'placement' => $placement];
$report['onboarding_cases'] = $pdo->query('SELECT id,version,plan_version,confirmed_plan_version
    FROM staffing_onboarding_cases WHERE tenant_id=999 AND people_tenant_id=999
    AND placements_tenant_id=999 AND person_id=15 AND placement_id=13 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$report['synthetic_time'] = $pdo->query('SELECT t.id,t.hours,t.status,t.approved_by_user_id,i.timesheet_id,i.quanta_entry_id
    FROM time_entries t JOIN quanta_time_imports i ON i.tenant_id=t.tenant_id AND i.time_entry_id=t.id
    WHERE t.tenant_id=999 AND t.person_id=15 AND t.placement_id=13 ORDER BY t.id')->fetchAll(PDO::FETCH_ASSOC);
// Saved identity remains visible even when a provider context requires a new review.
$report['saved_purepay_binding'] = $pdo->query('SELECT id,status,revision,remote_organization_id
    FROM purepay_core_connections WHERE tenant_id=999')->fetch(PDO::FETCH_ASSOC);
require_once PILOT_ROOT.'/core/integrations/purepay_core.php';
$report['purepay_assignment_context'] = ['available'=>false, 'provider_record_writes'=>false];
if (purepayCoreApiBase()==='https://pure-pay-support.preview.emergentagent.com/api/v1'
    && ($report['saved_purepay_binding']['remote_organization_id'] ?? null)==='org_purepay_demo') {
    try {
        $review=purepayCoreReview($pdo,PILOT_TENANT,PILOT_INSTANCE);
        $report['purepay_assignment_context'] = [
            'available'=>true, 'organization_matches'=>$review['organization']['id']==='org_purepay_demo',
            'binding_verified'=>$review['verified'],
            'assignment_contract_version'=>$review['assignment_input_contract_version'] ?? null,
            'assignment_location_supported'=>($review['assignment_input_contract_version'] ?? null)==='2026-10-25.1',
            'provider_record_writes'=>false,
        ];
        if ($review['verified']) {
            try {
                require_once PILOT_ROOT.'/core/integrations/purepay_assignment_inputs.php';
                $source=purepayAssignmentInputSource($pdo,PILOT_INSTANCE,
                    ['people'=>999,'placements'=>999,'companies'=>999,'ap'=>999],'PL-13');
                $report['synthetic_commercial_source']=[
                    'complete'=>true,'contract_version'=>$source['body']['contract_version'],
                    'approved_term_periods'=>count($source['body']['terms']),
                    'worksite_city'=>$source['body']['work_context']['worksite_city'],
                    'source_revision'=>$source['body']['source_revision'],
                    'provider_record_writes'=>false,
                ];
                unset($source);
            } catch (Throwable $e) {
                $report['synthetic_commercial_source']=['complete'=>false,'reason'=>match($e->getMessage()) {
                    'Approved commercial terms do not cover the full placement.'=>'approved_terms_incomplete',
                    'Approved terms must cover the placement without gaps or overlaps.'=>'approved_terms_gaps_or_overlaps',
                    'The approved billing client no longer matches its frozen company identity.'=>'frozen_billing_company_changed',
                    'A commercial party has no current canonical number in its authorized directory. Review its mapping first.'=>'commercial_party_identity_missing',
                    default=>'commercial_source_requires_review',
                },'provider_record_writes'=>false];
            }
        }
        unset($review);
    } catch (Throwable $e) {
        $report['purepay_assignment_context']['reason']='context_or_configuration_check_failed';
    }
}

// Staffing exchange and financial callbacks have separate saved connections.
// Report configuration presence only; never load/decrypt credentials or send a callback.
require_once PILOT_ROOT.'/core/purepay_adapter.php';
$paymentConnection = $pdo->query('SELECT status,key_scope,
    (api_key_ct IS NOT NULL AND api_key_ct<>"") AS api_key_stored,
    (webhook_secret_ct IS NOT NULL AND webhook_secret_ct<>"") AS callback_secret_stored
    FROM purepay_connections WHERE tenant_id=999')->fetch(PDO::FETCH_ASSOC);
$paymentBase = purepayApiBase();
$report['payment_callback_setup'] = [
    'connection_present'=>(bool)$paymentConnection,
    'connection_status'=>$paymentConnection['status'] ?? null,
    'key_scope'=>$paymentConnection['key_scope'] ?? null,
    'api_key_stored'=>(bool)($paymentConnection['api_key_stored'] ?? false),
    'callback_secret_stored'=>(bool)($paymentConnection['callback_secret_stored'] ?? false),
    'payment_api_destination'=>$paymentBase === 'https://pure-pay-support.preview.emergentagent.com/api/v1'
        ? 'reviewed_preview' : ($paymentBase === 'https://purepay.online/api/v1' ? 'production_default' : 'other'),
    'payment_links'=>(int)$pdo->query('SELECT COUNT(*) FROM purepay_payment_links WHERE tenant_id=999')->fetchColumn(),
    'saved_callback_events'=>(int)$pdo->query('SELECT COUNT(*) FROM purepay_webhook_events WHERE tenant_id=999')->fetchColumn(),
    'credentials_decrypted'=>false, 'provider_contacted'=>false,
];
// Financial preview routing is installation-wide. Inspect every saved consumer
// before considering that switch; the pilot tenant alone cannot prove isolation.
$report['payment_callback_setup']['installation_scope'] = [
    'has_nonpilot_connections'=>(bool)$pdo->query('SELECT EXISTS(SELECT 1 FROM purepay_connections WHERE tenant_id<>999)')->fetchColumn(),
    'saved_connections'=>$pdo->query('SELECT c.tenant_id,c.status,c.key_scope,t.is_simulation,
        (c.api_key_ct IS NOT NULL AND c.api_key_ct<>"") AS api_key_stored,
        (c.webhook_secret_ct IS NOT NULL AND c.webhook_secret_ct<>"") AS callback_secret_stored
        FROM purepay_connections c LEFT JOIN tenants t ON t.id=c.tenant_id ORDER BY c.tenant_id LIMIT 101')->fetchAll(PDO::FETCH_ASSOC),
    'private_db_config_sha256'=>is_file(PILOT_ROOT.'/core/db.local.php')
        ? hash_file('sha256',PILOT_ROOT.'/core/db.local.php') : null,
    'financial_preview_constant_present'=>defined('COREFLUX_PUREPAY_FINANCIAL_PREVIEW_API_BASE'),
];
unset($paymentConnection, $paymentBase);

// Report only bounded capability/status values. Never print access arrays, keys or exception text.
$probe = static function(callable $read): array {
    try { $read(); return ['available' => true, 'http_status' => 200]; }
    catch (QuantaApiException $e) { return ['available' => false, 'http_status' => $e->httpStatus]; }
    catch (Throwable $e) { return ['available' => false, 'reason' => 'contract_or_configuration_check_failed']; }
};
$access = null;
$report['quanta_binding'] = $probe(static function() use (&$access): void {
    $access = quantaWorkspaceForDelivery(PILOT_TENANT);
    if ($access['context']['workspace']['id'] !== PILOT_QUANTA_WORKSPACE) {
        $access = null;
        throw new RuntimeException('Unexpected workspace');
    }
});
if ($report['quanta_binding']['available']) {
    require_once PILOT_ROOT.'/core/quanta/core_contract.php';
    $report['quanta_assignment_location'] = $probe(static function() use ($access): void {
        quantaAssignmentLocationRequire($access['api_key'], PILOT_QUANTA_WORKSPACE);
    });
    require_once PILOT_ROOT.'/core/quanta/metadata_delivery.php';
    $report['quanta_metadata_receipts'] = $probe(static function() use ($access): void {
        quantaMetadataReceiptRequire($access['api_key'], PILOT_QUANTA_WORKSPACE);
    });
    if ($report['quanta_metadata_receipts']['available']) {
        try {
            $originals=$pdo->query('SELECT * FROM integration_deliveries WHERE tenant_id=999 AND provider="quanta"
                AND operation IN ("person.link","assignment.create","assignment.update") AND state="delivered"
                AND entity_tenant_id=999 AND ((entity_type="person" AND entity_id=15) OR (entity_type="placement" AND entity_id=13))
                ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
            if (!$originals) throw new RuntimeException('No original demo receipt');
            $checked=[];
            foreach ($originals as $original) {
                if ((string)$original['connection_ref']!==(string)$access['connection']['id']
                    || !hash_equals($original['binding_hash'],$access['binding_hash'])) throw new RuntimeException('Original binding changed');
                [$row,$payload]=quantaMetadataEnvelope(PILOT_TENANT,(int)$original['id'],$original['review_hash']);
                $query=['operation'=>$row['operation']];
                if ($row['operation']==='assignment.update') $query['record_id']=quantaCoreRemoteId(PILOT_QUANTA_WORKSPACE,$payload['expected']['placement']);
                $path=QUANTA_CORE_CONTRACT_PATH.'/metadata-deliveries/'.$row['idempotency_key'];
                $first=quantaGet($access['api_key'],$path,$query);
                $again=quantaGet($access['api_key'],$path,$query);
                $outcome=quantaMetadataReceiptOutcome($row,$payload,PILOT_QUANTA_WORKSPACE,$first);
                if ($outcome['state']!=='confirmed' || $outcome['receipt_id']!==$row['remote_receipt_id']
                    || $outcome['remote_id']!==$row['remote_id'] || integrationDeliveryHash($first)!==integrationDeliveryHash($again)
                    || integrationDeliveryGet($pdo,PILOT_TENANT,(int)$row['id'])!==$original) throw new RuntimeException('Original receipt differs');
                $checked[]=['delivery_id'=>(int)$row['id'],'operation'=>$row['operation'],'original_receipt_verified'=>true,'repeat_read_identical'=>true];
            }
            $report['quanta_original_metadata_receipts']=['verified'=>true,'checked'=>count($checked),'receipts'=>$checked,
                'local_history_unchanged'=>true,'provider_record_writes'=>false];
            unset($originals,$original,$row,$payload,$first,$again,$outcome,$query,$path,$checked);
        } catch (Throwable $e) {
            $report['quanta_original_metadata_receipts']=['verified'=>false,'reason'=>'original_receipt_check_failed','provider_record_writes'=>false];
        }
    }
    try {
        $reference=integrationRecordReference(PILOT_INSTANCE,999,'placement','PL-13');
        $remote=quantaContractRecord($access['api_key'],PILOT_QUANTA_WORKSPACE,$reference);
        $location=$pdo->query('SELECT worksite_city AS city,worksite_state AS state,worksite_country AS country
            FROM placements WHERE tenant_id=999 AND id=13 AND person_id=15 AND deleted_at IS NULL')->fetch(PDO::FETCH_ASSOC);
        $report['synthetic_assignment_location']=[
            'core'=>$location,'quanta_assignment_found'=>$remote!==null,
            'quanta_location_present'=>isset($remote['work_location']),
            'quanta_matches_core'=>isset($remote['work_location'])
                && integrationDeliveryHash($remote['work_location'])===integrationDeliveryHash($location),
            'provider_record_writes'=>false,
        ];
        unset($remote,$location);
    } catch(Throwable $e) {
        $report['synthetic_assignment_location']=['available'=>false,'reason'=>'assignment_read_failed'];
    }
    $report['quanta_onboarding'] = $probe(static function() use ($access): void {
        quantaOnboardingContext($access['api_key'], PILOT_QUANTA_WORKSPACE);
    });
    $report['quanta_document_transfer'] = $probe(static function() use ($access): void {
        quantaOnboardingDocumentContext($access['api_key'], PILOT_QUANTA_WORKSPACE);
    });
    // A disabled capability is different from a missing permission or a mismatched contract.
    try {
        $context = quantaGet($access['api_key'], QUANTA_CORE_CONTRACT_PATH.'/onboarding-document-context');
        $report['quanta_document_context'] = [
            'http_status' => 200,
            'workspace_matches' => ($context['workspace_id'] ?? null) === PILOT_QUANTA_WORKSPACE,
            'contract_matches' => ($context['contract_version'] ?? null) === QUANTA_ONBOARDING_DOCUMENT_VERSION,
            'transfer_enabled' => ($context['document_transfer'] ?? null) === true,
            'approval_stays_in_core' => ($context['approval_authority'] ?? null) === false,
            'size_limit_matches' => ($context['max_bytes'] ?? null) === QUANTA_ONBOARDING_DOCUMENT_MAX_BYTES,
        ];
    } catch (QuantaApiException $e) {
        $report['quanta_document_context'] = ['http_status' => $e->httpStatus];
    } catch (Throwable $e) {
        $report['quanta_document_context'] = ['reason' => 'context_read_failed'];
    }
}
$access = null;

// Resolve the existing simulation operator through both permission models, without a login or grant.
require_once PILOT_ROOT.'/core/RBAC.php';
require_once PILOT_ROOT.'/core/rbac/permissions.php';
require_once PILOT_ROOT.'/core/rbac/legacy_map.php';
$operator = $pdo->query("SELECT id,role FROM users WHERE id=4 AND email='stage-admin@coreflux.test'")->fetch(PDO::FETCH_ASSOC);
$membership = RBACResolver::activeMembership(4, PILOT_TENANT);
$report['existing_operator'] = ['found' => (bool)$operator, 'active_membership' => ($membership['status'] ?? null) === 'active'];
if ($operator && ($membership['status'] ?? null) === 'active') {
    $operator['global_role'] = $operator['role'];
    $operator['role'] = RBACResolver::personaTypeOf(4, PILOT_TENANT);
    foreach (['people.onboarding.view', 'people.onboarding.manage', 'people.onboarding.review',
        'placements.view', 'people.docs.view', 'integrations.quanta.manage'] as $permission) {
        [$module, $action] = RbacLegacyMap::resolve($permission);
        $report['existing_operator']['permissions'][$permission] = [
            'legacy' => RBAC::hasPermission($operator, $permission),
            'membership' => RBACResolver::can($operator, PILOT_TENANT, $module, $action),
        ];
    }
}
// Count actual independent reviewers without exposing other users or granting access.
$candidates = $pdo->query('SELECT u.id,u.role,u.is_global_admin FROM users u
    WHERE u.is_active=1 AND (u.tenant_id=999 OR EXISTS (
        SELECT 1 FROM tenant_memberships m WHERE m.user_id=u.id AND m.tenant_id=999 AND m.status="active"
    )) ORDER BY u.id LIMIT 501')->fetchAll(PDO::FETCH_ASSOC);
if (count($candidates) > 500) {
    throw new RuntimeException('Reviewer inspection exceeds the bounded simulation cohort');
}
$eligible = [];
foreach ($candidates as $candidate) {
    $candidate['global_role'] = $candidate['role'];
    $candidate['role'] = RBACResolver::personaTypeOf((int)$candidate['id'], PILOT_TENANT);
    if (RBAC::hasPermission($candidate, 'time.approve')
        && RBACResolver::can($candidate, PILOT_TENANT, 'time', 'admin')) {
        $eligible[] = (int)$candidate['id'];
    }
}
$report['time_review_gate'] = ['eligible_in_both_permission_models'=>count($eligible), 'entries'=>[]];
foreach ($pdo->query('SELECT id,status,created_by_user_id FROM time_entries
    WHERE tenant_id=999 AND person_id=15 AND placement_id=13 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $entry) {
    $creator = (int)$entry['created_by_user_id'];
    $report['time_review_gate']['entries'][] = [
        'entry_id'=>(int)$entry['id'], 'status'=>$entry['status'],
        'independent_eligible_reviewers'=>count(array_filter($eligible, static fn(int $id): bool => $id !== $creator)),
        'existing_operator_is_creator'=>$creator === 4,
    ];
}

echo 'CORE_PILOT_READINESS='.json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
