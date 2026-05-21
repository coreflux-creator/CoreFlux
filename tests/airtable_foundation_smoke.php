<?php
/**
 * Airtable integration — Slice 1 (Foundation) smoke.
 *
 * Validates:
 *   - migration 063 is shaped correctly
 *   - core/airtable/client.php exposes the documented public surface
 *   - core/airtable/sync.php exposes mapping CRUD + sync pipeline
 *   - api/airtable.php dispatches all expected actions
 *   - dashboard/src/pages/AirtableSettings.jsx renders the documented testids
 *   - AdminModule + IntegrationsHub wire Airtable into the centralised
 *     /admin/integrations surface
 *   - RBAC legacy_map registers integrations.airtable.{view,manage}
 *
 * Run via: php -d zend.assertions=1 tests/airtable_foundation_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ok  $msg\n"; $pass++; }
    else       { echo "FAIL  $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- migration shape
echo "Migration 063 — airtable_foundation\n";
$migPath = $ROOT . '/core/migrations/063_airtable_foundation.sql';
$mig = file_exists($migPath) ? (string) file_get_contents($migPath) : '';
$a('migration file present',                     $mig !== '');
$a('declares airtable_connections',              $c($mig, 'CREATE TABLE IF NOT EXISTS airtable_connections'));
$a('declares airtable_table_mappings',           $c($mig, 'CREATE TABLE IF NOT EXISTS airtable_table_mappings'));
$a('declares airtable_sync_audit',               $c($mig, 'CREATE TABLE IF NOT EXISTS airtable_sync_audit'));
$a('unique tenant on connections',               $c($mig, 'UNIQUE KEY uq_airtable_tenant'));
$a('AES-256-GCM PAT column',                     $c($mig, 'pat_ct'));
$a('field_map JSON column',                      $c($mig, 'field_map       JSON NULL'));
$a('direction enum on mappings',                 $c($mig, "ENUM('pull','off')"));

// ----------------------------------------------------------------- client.php surface
echo "\ncore/airtable/client.php — public surface\n";
$cliPath = $ROOT . '/core/airtable/client.php';
$cli = (string) file_get_contents($cliPath);
$a('file exists',                                $cli !== '');
$a('declares strict types',                      $c($cli, 'declare(strict_types=1);'));
$a('api base default constant',                  $c($cli, "AIRTABLE_API_BASE_DEFAULT = 'https://api.airtable.com'"));
foreach ([
    'airtableConfigured', 'airtableConnection', 'airtablePAT',
    'airtableSavePAT', 'airtableDisconnect', 'airtablePing',
    'airtableCall', 'airtableRawRequest', 'airtableListBases',
    'airtableListTables', 'airtableSelectRecords', 'airtableAudit',
] as $fn) {
    $a("declares $fn()",                         $c($cli, "function $fn"));
}
$a('encrypts PAT via encryptField',              substr_count($cli, 'encryptField(') >= 2);
$a('decrypts PAT via decryptField',              $c($cli, 'decryptField('));
$a('PAT format guard pat-prefix',                $c($cli, "'/^pat[A-Za-z0-9._-]{10,}\$/'"));
$a('rate-limit 429 backoff + retry',             $c($cli, "\$resp['status'] === 429"));
$a('test transport hook supported',              $c($cli, '__airtable_transport'));

// ----------------------------------------------------------------- sync.php surface
echo "\ncore/airtable/sync.php — mapping + sync surface\n";
$syncPath = $ROOT . '/core/airtable/sync.php';
$sync = (string) file_get_contents($syncPath);
$a('file exists',                                $sync !== '');
foreach ([
    'airtableMappingList', 'airtableMappingGet',
    'airtableMappingUpsert', 'airtableMappingDelete',
    'airtableSyncTable',
    'airtableUserAdminTenantSet', 'airtableMappingDuplicate',
] as $fn) {
    $a("declares $fn()",                         $c($sync, "function $fn"));
}
$a('routes through mappingUpsert',               $c($sync, 'mappingUpsert('));
$a('source_system airtable',                     $c($sync, "'airtable'"));
$a('direction guard',                            $c($sync, "AIRTABLE_MAPPING_DIRECTIONS = ['pull', 'off']"));
$a('entity allowlist constant',                  $c($sync, 'AIRTABLE_INTERNAL_ENTITIES'));

// ----------------------------------------------------------------- api dispatch
echo "\napi/airtable.php — action dispatch\n";
$apiPath = $ROOT . '/api/airtable.php';
$api = (string) file_get_contents($apiPath);
$a('file exists',                                $api !== '');
foreach ([
    'status', 'connect', 'disconnect', 'ping',
    'list_bases', 'list_tables',
    'mappings', 'mapping_save', 'mapping_delete', 'sync_now',
    'duplicate_targets', 'mapping_duplicate',
] as $act) {
    $a("handles action: $act",                   $c($api, "case '$act'"));
}
$a('requires integrations.airtable.view',        $c($api, "rbac_legacy_require(\$user, 'integrations.airtable.view')"));
$a('requires integrations.airtable.manage',      $c($api, "rbac_legacy_require(\$user, 'integrations.airtable.manage')"));

// shim files exist
foreach ([
    'status', 'connect', 'disconnect', 'ping',
    'list_bases', 'list_tables',
    'mappings', 'mapping_save', 'mapping_delete', 'sync_now',
    'duplicate_targets', 'mapping_duplicate',
] as $shim) {
    $a("shim api/airtable/$shim.php present",    file_exists($ROOT . "/api/airtable/$shim.php"));
}

// ----------------------------------------------------------------- syntax sanity
echo "\nSyntax sanity (php -l)\n";
foreach ([
    'core/airtable/client.php',
    'core/airtable/sync.php',
    'api/airtable.php',
    'api/airtable/status.php',
    'api/airtable/connect.php',
    'api/airtable/sync_now.php',
    'cron/airtable_sync.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($ROOT . '/' . $f) . ' 2>&1', $out, $rc);
    $a("php -l $f",                              $rc === 0);
}

// ----------------------------------------------------------------- UI: AirtableSettings.jsx
echo "\nUI — AirtableSettings.jsx\n";
$uiPath = $ROOT . '/dashboard/src/pages/AirtableSettings.jsx';
$ui = (string) file_get_contents($uiPath);
$a('file exists',                                $ui !== '');
$a('root testid airtable-settings',              $c($ui, 'data-testid="airtable-settings"'));
$a('not-connected branch testid',                $c($ui, 'data-testid="airtable-not-connected"'));
$a('connected branch testid',                    $c($ui, 'data-testid="airtable-connected"'));
$a('PAT input testid',                           $c($ui, 'data-testid="airtable-pat-input"'));
$a('connect btn testid',                         $c($ui, 'data-testid="airtable-connect-btn"'));
$a('disconnect btn testid',                      $c($ui, 'data-testid="airtable-disconnect-btn"'));
$a('ping btn testid',                            $c($ui, 'data-testid="airtable-ping-btn"'));
$a('mappings container testid',                  $c($ui, 'data-testid="airtable-mappings"'));
$a('add mapping btn testid',                     $c($ui, 'data-testid="airtable-add-mapping-btn"'));
$a('base picker testid',                         $c($ui, 'data-testid="airtable-base-select"'));
$a('table picker testid',                        $c($ui, 'data-testid="airtable-table-select"'));
$a('save mapping btn testid',                    $c($ui, 'data-testid="airtable-mapping-save-btn"'));
$a('uses /api/airtable/connect',                 $c($ui, '/api/airtable/connect.php'));
$a('uses /api/airtable/sync_now',                $c($ui, '/api/airtable/sync_now.php'));
$a('uses /api/airtable/mapping_save',            $c($ui, '/api/airtable/mapping_save.php'));
$a('duplicate modal testid',                     $c($ui, 'data-testid="airtable-duplicate-modal"'));
$a('duplicate apply btn testid',                 $c($ui, 'data-testid="airtable-duplicate-apply-btn"'));
$a('duplicate per-row trigger testid',           $c($ui, 'data-testid={`airtable-duplicate-mapping-${mapping.id}`}'));
$a('uses /api/airtable/duplicate_targets',       $c($ui, '/api/airtable/duplicate_targets.php'));
$a('uses /api/airtable/mapping_duplicate',       $c($ui, '/api/airtable/mapping_duplicate.php'));

// ----------------------------------------------------------------- Admin + Hub wiring
echo "\nUI — AdminModule + IntegrationsHub wiring\n";
$ad = (string) file_get_contents($ROOT . '/dashboard/src/pages/AdminModule.jsx');
$a('imports AirtableSettings',                   $c($ad, "import AirtableSettings from './AirtableSettings'"));
$a('mounts /admin/integrations/airtable',
    $c($ad, '<Route path="/integrations/airtable" element={<AirtableSettings session={session} />} />'));

$hub = (string) file_get_contents($ROOT . '/dashboard/src/pages/IntegrationsHub.jsx');
$a('hub adds airtable card testid',              $c($hub, 'data-testid="integration-card-airtable"') || $c($hub, 'testid="integration-card-airtable"'));
$a('hub probes /api/airtable/status',            $c($hub, '/api/airtable/status.php?action=status'));
$a('hub links to /admin/integrations/airtable',  $c($hub, 'href="/admin/integrations/airtable"'));

// ----------------------------------------------------------------- RBAC legacy_map
echo "\nRBAC — legacy_map entries\n";
$map = (string) file_get_contents($ROOT . '/core/rbac/legacy_map.php');
$a('legacy_map registers integrations.airtable.view',   $c($map, "'integrations.airtable.view'"));
$a('legacy_map registers integrations.airtable.manage', $c($map, "'integrations.airtable.manage'"));

// ----------------------------------------------------------------- Functional smoke (transport injection)
echo "\nFunctional — adapter via injected transport stub\n";
require_once $cliPath;
$captured = [];
$GLOBALS['__airtable_transport'] = function (string $method, string $url, array $headers, ?string $body) use (&$captured) {
    $captured[] = compact('method', 'url', 'headers', 'body');
    if (strpos($url, '/v0/meta/whoami') !== false) {
        return ['status' => 200, 'body' => ['id' => 'usrTest', 'scopes' => ['data.records:read', 'schema.bases:read']], 'headers' => []];
    }
    if (strpos($url, '/v0/meta/bases') !== false && strpos($url, '/tables') === false) {
        return ['status' => 200, 'body' => ['bases' => [
            ['id' => 'appAAAAAAAAAAAAAA', 'name' => 'Ops Sidecar', 'permissionLevel' => 'create'],
        ]], 'headers' => []];
    }
    if (preg_match('#/v0/meta/bases/(app[A-Za-z0-9]+)/tables#', $url)) {
        return ['status' => 200, 'body' => ['tables' => [
            ['id' => 'tblBBBBBBBBBBBBBB', 'name' => 'Companies', 'primaryFieldId' => 'fldX', 'fields' => [
                ['id' => 'fldX', 'name' => 'Name', 'type' => 'singleLineText'],
                ['id' => 'fldY', 'name' => 'Domain', 'type' => 'url'],
            ]],
        ]], 'headers' => []];
    }
    return ['status' => 200, 'body' => ['records' => [], 'offset' => null], 'headers' => []];
};
// rawRequest hits whoami via our stub
$resp = airtableRawRequest('GET', 'https://api.airtable.com/v0/meta/whoami', null, ['Authorization: Bearer patFAKE']);
$a('transport stub captured a call',             count($captured) === 1);
$a('whoami URL hits api.airtable.com',           $captured[0]['url'] === 'https://api.airtable.com/v0/meta/whoami');
$a('response decodes scopes array',              is_array($resp['body']['scopes'] ?? null));
unset($GLOBALS['__airtable_transport']);

echo "\n=========================================\n";
echo "Airtable Foundation smoke: {$pass} ok / {$fail} fail\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
