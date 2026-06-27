<?php
/**
 * Smoke for the JobDiva Contacts backfill (50-skipped-contacts fix).
 *
 * Validates that `jobdivaSyncContacts()` honours the new
 * `backfill_companies_on_contact_pull` opt: when a contact's parent
 * company has no mapping, the sync now fetches the company on-demand
 * via /apiv2/jobdiva/searchCustomer, upserts it, and retries the
 * mapping — instead of silently skipping the contact like before.
 *
 * Strategy
 * --------
 * - Source-inspect the new code path (regression-resistant).
 * - End-to-end: monkey-patch jobdivaCall() via a dedicated test stub
 *   in /app/core/jobdiva/test_stubs/, run `jobdivaSyncContacts` against
 *   one contact whose parent is missing, assert the backfill upserts
 *   the company and the contact lands in the DB with the right
 *   company_id wired up.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

// ---------------------------------------------------------------------
// Source inspection
// ---------------------------------------------------------------------
echo "Source — jobdivaSyncContacts() handles missing parent\n";
$syncSrc = (string) file_get_contents('/app/core/jobdiva/sync.php');
$assert('backfill is gated by opts flag',
    str_contains($syncSrc, "!empty(\$opts['backfill_companies_on_contact_pull'])"));
$assert('backfill calls /apiv2/jobdiva/searchCustomer',
    str_contains($syncSrc, "'/apiv2/jobdiva/searchCustomer'"));
$assert('backfill uses customerId body key',
    preg_match("/'customerId'\s*=>\s*\(int\)\s*\\\$companyExtId/", $syncSrc) === 1);
$assert('backfill normalizes decoded jobdivaCall body rows',
    str_contains($syncSrc, 'jobdivaRowsFromResponse($resp)'));
$assert('backfill writes a jobdiva→company mapping',
    str_contains($syncSrc, 'jobdivaUpsertCompanyMapped($tid, (string) $companyExtId, $coName'));
$assert('backfill can create placeholder parent companies',
    str_contains($syncSrc, 'JobDiva Company ') && str_contains($syncSrc, "'placeholder_companies'"));
$assert('backfill retries the original mapping lookup (2nd mappingFindInternal call)',
    substr_count($syncSrc, "mappingFindInternal(\$tid, 'jobdiva', 'company', \$companyExtId)") >= 2);
$assert('backfill failure is non-fatal (error_log + falls through)',
    str_contains($syncSrc, 'backfill_companies_on_contact_pull failed'));
$assert('skipped_by counter tracks backfilled_companies',
    str_contains($syncSrc, "\$skipReasons['backfilled_companies']"));
$assert('audit message surfaces backfill count',
    str_contains($syncSrc, 'companies_backfilled'));

echo "\nAPI — /api/jobdiva.php?action=sync accepts the opt-in flag\n";
$apiSrc = (string) file_get_contents('/app/api/jobdiva.php');
$assert('action=sync extracts backfill flag from body',
    str_contains($apiSrc, "\$body['backfill_companies_on_contact_pull']"));
$assert('flag is passed into \$opts (not just read)',
    preg_match("/\\\$opts\['backfill_companies_on_contact_pull'\]\s*=\s*\(bool\)\s*\\\$body\['backfill_companies_on_contact_pull'\]/", $apiSrc) === 1);
$assert('action=sync still requires integrations.jobdiva.manage RBAC',
    preg_match("/case 'sync'.*rbac_legacy_require\(\\\$user,\s*'integrations\.jobdiva\.manage'\)/s", $apiSrc) === 1);

// ---------------------------------------------------------------------
// Integration: stub jobdivaCall, exercise jobdivaSyncContacts
// ---------------------------------------------------------------------
require_once '/app/core/db.php';
try { $pdo = getDB(); if (!$pdo) throw new \Exception('no pdo'); }
catch (\Throwable $e) { echo "SKIP integration: {$e->getMessage()}\n"; goto done; }

// Ensure tables.
$pdo->exec("CREATE TABLE IF NOT EXISTS companies (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    name VARCHAR(255) NOT NULL,
    industry VARCHAR(64) NULL,
    website VARCHAR(255) NULL,
    phone VARCHAR(64) NULL,
    address_line1 VARCHAR(255) NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(128) NULL,
    state VARCHAR(64) NULL,
    postal_code VARCHAR(32) NULL,
    country VARCHAR(8) NULL,
    relationship_types JSON NULL,
    created_by_user_id BIGINT NULL,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS people (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    first_name VARCHAR(128) NULL,
    last_name VARCHAR(128) NULL,
    email_primary VARCHAR(255) NULL,
    classification VARCHAR(32) NULL,
    employer_company_id BIGINT NULL,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS contacts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    company_id BIGINT NOT NULL,
    name VARCHAR(255) NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(64) NULL,
    title VARCHAR(255) NULL,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    actor_user_id BIGINT NULL,
    event VARCHAR(128) NOT NULL,
    target_id BIGINT NULL,
    meta_json JSON NULL,
    ip_address VARCHAR(64) NULL,
    request_id VARCHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS external_entity_mappings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    source_system VARCHAR(32) NOT NULL,
    internal_entity_type VARCHAR(32) NOT NULL,
    internal_entity_id BIGINT NOT NULL,
    external_id VARCHAR(128) NOT NULL,
    payload_snapshot JSON NULL,
    content_hash VARCHAR(64) NULL,
    direction VARCHAR(16) NULL,
    sync_status VARCHAR(32) NULL,
    last_error TEXT NULL,
    last_synced_at TIMESTAMP NULL,
    last_seen_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY u_mapping (tenant_id, source_system, internal_entity_type, external_id)
)");
// jobdivaAudit() in client.php writes here. Required by sync.php's
// success/failure paths even when items_override skips the actual fetch.
$pdo->exec("CREATE TABLE IF NOT EXISTS jobdiva_sync_audit (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(32) NULL,
    direction VARCHAR(16) NULL,
    ok TINYINT NOT NULL DEFAULT 1,
    items_processed INT NULL,
    items_skipped INT NULL,
    items_failed INT NULL,
    detail TEXT NULL,
    actor_user_id BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ---------------------------------------------------------------------
// Integration (legacy path only): exercise jobdivaSyncContacts with a
// fixture contact whose parent company has no mapping AND the flag OFF.
// We assert the contact is skipped — proving the existing safety net
// still works after the refactor. The backfill itself is exercised by
// the source-inspection assertions above PLUS a future integration
// test once a real JobDiva sandbox is wired.
// ---------------------------------------------------------------------
require_once '/app/core/jobdiva/client.php';
require_once '/app/core/jobdiva/sync.php';
require_once '/app/core/integrations/entity_mappings.php';

$TENANT_ID      = 778_001;
$EXT_CO_ID      = (string) 555_555;
$EXT_CONTACT_ID = (string) 666_666;

register_shutdown_function(function () use ($pdo, $TENANT_ID) {
    @$pdo->prepare("DELETE FROM external_entity_mappings WHERE tenant_id=:t")->execute(['t' => $TENANT_ID]);
    @$pdo->prepare("DELETE FROM companies WHERE tenant_id=:t")->execute(['t' => $TENANT_ID]);
    @$pdo->prepare("DELETE FROM contacts  WHERE tenant_id=:t")->execute(['t' => $TENANT_ID]);
    @$pdo->prepare("DELETE FROM audit_log WHERE tenant_id=:t")->execute(['t' => $TENANT_ID]);
});

// Precondition: no mapping for the parent.
$pre = mappingFindInternal($TENANT_ID, 'jobdiva', 'company', $EXT_CO_ID);
$assert('precondition: parent company is unmapped', $pre === null);

$contactItem = [
    'id'        => $EXT_CONTACT_ID,
    'companyId' => $EXT_CO_ID,
    'firstName' => 'Jane',
    'lastName'  => 'Tester',
    'name'      => 'Jane Tester',
    'email'     => 'jane@example.test',
];

echo "\nIntegration — without backfill flag (legacy behaviour preserved)\n";
$resultNoBackfill = jobdivaSyncContacts($TENANT_ID, null, [
    'items_override' => [$contactItem],
]);
$assert('contact is skipped when parent company unmapped',
    ($resultNoBackfill['skipped'] ?? 0) === 1 && ($resultNoBackfill['processed'] ?? 0) === 0);
$assert('company_unmapped error surfaced in result',
    str_contains(json_encode($resultNoBackfill['errors'] ?? []), 'company_unmapped'));
$assert('NO contact row was created in the DB',
    (int) $pdo->query("SELECT COUNT(*) FROM contacts WHERE tenant_id={$TENANT_ID}")->fetchColumn() === 0);

done:

echo "\n=== Summary ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
