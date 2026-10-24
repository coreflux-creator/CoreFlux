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
// Inspect saved binding only; never wake or contact the separately managed PurePay preview.
$report['saved_purepay_binding'] = $pdo->query('SELECT id,status,revision,remote_organization_id
    FROM purepay_core_connections WHERE tenant_id=999')->fetch(PDO::FETCH_ASSOC);

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
echo 'CORE_PILOT_READINESS='.json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
