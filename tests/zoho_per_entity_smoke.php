<?php
/**
 * Zoho Books per-entity parity + Copy-Sync-Config smoke.
 *
 * Validates:
 *   1. Migration 099 adds sub_tenant_id everywhere it's needed
 *   2. zohoBooksConnection signature accepts sub_tenant_id
 *   3. All public Zoho helpers (Disconnect/Ping/AccessToken/Refresh/Call/
 *      SyncConfigRead/Write/Copy) accept ?int $subTenantId = null
 *   4. zohoBooksConsumeOAuthState now returns int sub_tenant_id
 *   5. api/zoho_books.php threads sub_tenant_id via _zbSub() helper
 *   6. ZohoBooksSettings.jsx renders entity picker + copy-config + mapping
 *   7. JazIntegrationSettings.jsx renders JazCopyConfigCard
 *   8. Generic /api/accounting.php?action=sync_config_copy route wired
 *   9. accountingSyncConfigCopy is the generic provider-neutral copier
 *  10. Each sync_* worker sets $GLOBALS["__zb_sub_tenant_id"] from $opts
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }

echo "Migration 099 — Zoho Books per-entity\n";
$mig = @file_get_contents($ROOT . '/core/migrations/099_zoho_books_per_entity.sql');
if ($mig === false) bad('migration 099 missing');
else {
    if (strpos($mig, 'zoho_books_connections') !== false && strpos($mig, 'sub_tenant_id') !== false) ok('adds sub_tenant_id to zoho_books_connections');
    if (strpos($mig, 'zoho_books_oauth_state') !== false && substr_count($mig, 'sub_tenant_id') >= 3) ok('adds sub_tenant_id to oauth_state + audit');
    if (strpos($mig, 'uq_zoho_tenant_sub') !== false) ok('swaps to UNIQUE (tenant_id, sub_tenant_id)');
    if (strpos($mig, 'UPDATE zoho_books_connections SET sub_tenant_id = tenant_id WHERE sub_tenant_id = 0') !== false) ok('backfills legacy rows to parent self-entity');
}

echo "core/zoho_books/client.php signatures\n";
$client = file_get_contents($ROOT . '/core/zoho_books/client.php');
foreach ([
    'function zohoBooksConnection(int $tenantId, ?int $subTenantId = null)' => 'zohoBooksConnection accepts sub_tenant_id',
    'function zohoBooksConnectionsForTenant(int $tenantId'                  => 'zohoBooksConnectionsForTenant helper present',
    'function zohoBooksDisconnect(int $tenantId, ?int $userId, ?int $subTenantId = null)' => 'Disconnect accepts sub_tenant_id',
    'function zohoBooksAccessToken(int $tenantId, ?int $subTenantId = null)' => 'AccessToken accepts sub_tenant_id',
    'function zohoBooksRefreshAccessToken(int $tenantId, ?int $subTenantId = null)' => 'Refresh accepts sub_tenant_id',
    'function zohoBooksPing(int $tenantId, ?int $userId, ?int $subTenantId = null)' => 'Ping accepts sub_tenant_id',
    'function zohoBooksCall(int $tenantId, string $method, string $path, ?array $body = null, ?array $query = null, ?int $subTenantId = null)' => 'Call accepts sub_tenant_id',
    'function zohoBooksSyncConfigRead(int $tenantId, ?int $subTenantId = null)' => 'SyncConfigRead accepts sub_tenant_id',
    'function zohoBooksSyncConfigWrite(int $tenantId, array $config, ?int $userId, ?int $subTenantId = null)' => 'SyncConfigWrite accepts sub_tenant_id',
    'function zohoBooksSyncConfigCopy(' => 'SyncConfigCopy declared',
    'function zohoBooksExchangeCode(int $tenantId, string $code, string $accountsServer, ?int $userId, ?int $subTenantId = null)' => 'ExchangeCode accepts sub_tenant_id',
    'function zohoBooksBuildAuthorizeUrl(int $tenantId, ?int $userId, ?int $subTenantId = null)' => 'BuildAuthorizeUrl accepts sub_tenant_id',
    'function zohoBooksConsumeOAuthState(int $tenantId, string $state): int' => 'ConsumeOAuthState returns int sub_tenant_id',
] as $needle => $label) {
    if (strpos($client, $needle) !== false) ok($label);
    else                                    bad($label . ' missing');
}
if (strpos($client, '$GLOBALS[\'__zb_sub_tenant_id\']') !== false || strpos($client, "\$GLOBALS['__zb_sub_tenant_id']") !== false) {
    ok('zohoBooksCall reads $GLOBALS["__zb_sub_tenant_id"] fallback');
} else {
    bad('global fallback in zohoBooksCall missing');
}

echo "api/zoho_books.php per-entity wiring\n";
$apiSrc = file_get_contents($ROOT . '/api/zoho_books.php');
foreach ([
    'function _zbSub('                       => '_zbSub helper present',
    "'sync_config_copy'"                     => 'sync_config_copy action wired',
    'zohoBooksSyncConfigCopy('               => 'invokes zohoBooksSyncConfigCopy',
    'zohoBooksConnectionsForTenant('         => 'status returns all connections',
    "'all_connections'" => 'all_connections in status response',
    '_zbo["sub_tenant_id"] = _zbSub'         => 'sync_* opts get sub_tenant_id threaded',
] as $needle => $label) {
    if (strpos($apiSrc, $needle) !== false) ok($label);
    else                                    bad($label . ' missing');
}

echo "Sync workers carry sub_tenant_id via \$GLOBALS\n";
foreach ([
    'sync_accounts', 'sync_bills', 'sync_billables', 'sync_contacts',
    'sync_invoices', 'sync_je',    'sync_payments',
] as $w) {
    $body = @file_get_contents($ROOT . "/core/zoho_books/{$w}.php");
    if ($body && strpos($body, '$GLOBALS["__zb_sub_tenant_id"]') !== false) ok("{$w}.php sets global");
    else                                                                     bad("{$w}.php missing global set");
}

echo "Generic /api/accounting.php sync_config_copy route\n";
$accApi = file_get_contents($ROOT . '/api/accounting.php');
if (strpos($accApi, "\$action === 'sync_config_copy'") !== false) ok('accounting.php has sync_config_copy route');
else                                                              bad('accounting.php missing sync_config_copy route');

require_once $ROOT . '/core/accounting/sync_config_service.php';
if (function_exists('accountingSyncConfigCopy')) ok('accountingSyncConfigCopy declared');
else                                              bad('accountingSyncConfigCopy missing');

echo "JazIntegrationSettings UI + ZohoBooksSettings UI\n";
$jaz = file_get_contents($ROOT . '/dashboard/src/pages/JazIntegrationSettings.jsx');
foreach ([
    'function JazCopyConfigCard'                  => 'Jaz copy-config card declared',
    'data-testid="jaz-copy-config-card"'          => 'jaz copy card testid',
    'data-testid="jaz-copy-from-select"'          => 'jaz copy source picker testid',
    'data-testid="jaz-copy-config-btn"'           => 'jaz copy button testid',
    "?action=sync_config_copy"                    => 'jaz copy calls generic backend route',
] as $needle => $label) {
    if (strpos($jaz, $needle) !== false) ok($label);
    else                                 bad($label . ' missing');
}
$zoh = file_get_contents($ROOT . '/dashboard/src/pages/ZohoBooksSettings.jsx');
foreach ([
    'data-testid="zoho-books-entity-picker"'          => 'entity picker testid',
    'data-testid="zoho-books-entity-select"'          => 'entity <select> testid',
    'data-testid="zoho-books-copy-config-card"'       => 'copy card testid',
    'data-testid="zoho-books-copy-from-select"'       => 'copy source picker testid',
    'data-testid="zoho-books-copy-config-btn"'        => 'copy button testid',
    'function ZohoAccountMappingCard'                 => 'account mapping card component',
    'data-testid="zoho-books-account-mapping-card"'   => 'mapping card testid',
    'data-testid="zoho-books-account-mapping-automap"'=> 'auto-map button testid',
    "?action=sync_config_copy"                        => 'copy calls backend route',
    "sub_tenant_id=\${subTenantId}"                   => 'API calls pass sub_tenant_id',
    "provider=zoho_books"                             => 'mapping calls scoped to zoho_books',
] as $needle => $label) {
    if (strpos($zoh, $needle) !== false) ok($label);
    else                                 bad($label . ' missing');
}

echo "Connection-row backward compatibility\n";
// Sanity: source-level confirmation that zohoBooksConnection has the
// optional second arg (we don't load the file at runtime because it
// pulls in a live DB connection chain).
if (strpos($client, 'function zohoBooksConnection(int $tenantId, ?int $subTenantId = null)') !== false) {
    ok('zohoBooksConnection signature is back-compat (sub_tenant_id optional)');
} else {
    bad('zohoBooksConnection sub_tenant_id is not optional');
}

echo "\n";
echo "============================================================\n";
echo "Zoho per-entity + Copy sync config: $pass ✓ / $fail ✗\n";
echo "============================================================\n";
exit($fail === 0 ? 0 : 1);
