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
}
$access = null;
echo 'CORE_PILOT_READINESS='.json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
