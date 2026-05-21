<?php
/**
 * Schema health — multi-integration credential column auditor smoke.
 *
 * Validates:
 *   - core/integrations/schema_health.php exposes the public surface
 *     (registry, check, status helpers).
 *   - Registry covers every active integration (QBO, Zoho Books,
 *     Plaid, Mercury, JobDiva, Airtable, Mail, SSO) with sane
 *     min_bytes recommendations.
 *   - cf_schema_health_status() returns 'red'/'amber'/'green' based on
 *     synthetic row sets.
 *   - Migration 067 widens the QBO + Mail OAuth access tokens.
 *   - Original migration files 052 (QBO) and 003 (mail) carry the
 *     new widths so fresh installs inherit them.
 *   - API endpoint /api/admin/schema_health.php exists, gates on
 *     tenant.manage, returns the documented payload shape.
 *   - IntegrationsHub.jsx renders the SchemaHealthPanel with the
 *     expected testids + reads from /api/admin/schema_health.php.
 *
 * Run via: php -d zend.assertions=1 tests/schema_health_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ok  $msg\n"; $pass++; }
    else       { echo "FAIL  $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------- helper surface
echo "core/integrations/schema_health.php — surface\n";
$relCore = '/core/integrations/schema_health.php';
$src = (string) @file_get_contents($ROOT . $relCore);
$a('helper file exists',                         $src !== '');
foreach (['cf_schema_health_registry', 'cf_schema_health_check', 'cf_schema_health_status'] as $fn) {
    $a("declares $fn()",                         $c($src, "function $fn"));
}
$a('reads information_schema.COLUMNS',           $c($src, 'information_schema.COLUMNS'));
$a('checks CHARACTER_MAXIMUM_LENGTH',            $c($src, 'CHARACTER_MAXIMUM_LENGTH'));
$a('emits ALTER TABLE remediation hint',         $c($src, 'ALTER TABLE %s MODIFY COLUMN %s VARBINARY(%d)'));

$out = []; $rc = 0;
exec('php -l ' . escapeshellarg($ROOT . $relCore) . ' 2>&1', $out, $rc);
$a("php -l $relCore",                            $rc === 0);

// ----------------------------------------------------- registry contents
echo "\nRegistry — integration coverage\n";
require_once $ROOT . $relCore;
$reg = cf_schema_health_registry();
$a('registry returns array',                     is_array($reg) && count($reg) >= 10);

$indexed = [];
foreach ($reg as $r) { $indexed[$r['integration'] . '.' . $r['column']] = $r; }

$expected = [
    'qbo.access_token_ct'                  => 4096,
    'qbo.refresh_token_ct'                 => 1024,
    'zoho_books.access_token_ct'           => 2048,
    'zoho_books.refresh_token_ct'          => 2048,
    'plaid.access_token_ct'                => 512,
    'mercury.api_token_ct'                 => 512,
    'jobdiva.password_enc'                 => 1024,
    'jobdiva.session_token_enc'            => 4096,
    'jobdiva.webhook_secret_enc'           => 1024,
    'airtable.pat_ct'                      => 2048,
    'mail.oauth_access_token_ct'           => 4096,
    'mail.oauth_refresh_token_ct'          => 1024,
    'mail.imap_password_ct'                => 512,
    'sso.client_secret_enc'                => 1024,
];
foreach ($expected as $key => $minBytes) {
    $row = $indexed[$key] ?? null;
    $a("registry has $key with min_bytes=$minBytes",
        $row !== null && (int) $row['min_bytes'] === $minBytes);
    if ($row !== null) {
        $a("$key has integration_label",         !empty($row['integration_label']));
        $a("$key has 'stores' description",      !empty($row['stores']));
        $a("$key has table name",                !empty($row['table']));
    }
}

// ----------------------------------------------------- status() classifier
echo "\ncf_schema_health_status() classifier\n";
$a('green when all ok',
    cf_schema_health_status([
        ['verdict' => 'ok'], ['verdict' => 'ok'], ['verdict' => 'ok'],
    ]) === 'green');
$a('amber when missing/unknown but no undersized',
    cf_schema_health_status([
        ['verdict' => 'ok'], ['verdict' => 'missing'], ['verdict' => 'unknown'],
    ]) === 'amber');
$a('red when any undersized (beats amber)',
    cf_schema_health_status([
        ['verdict' => 'ok'], ['verdict' => 'missing'], ['verdict' => 'undersized'],
    ]) === 'red');
$a('red wins even with only undersized',
    cf_schema_health_status([
        ['verdict' => 'undersized'],
    ]) === 'red');

// ----------------------------------------------------- migration 067
echo "\nMigration 067 — widen OAuth access tokens\n";
$mig067 = (string) @file_get_contents($ROOT . '/core/migrations/067_widen_oauth_access_tokens.sql');
$a('mig 067 exists',                             $mig067 !== '');
$a('widens qbo_connections.access_token_ct',     $c($mig067, 'ALTER TABLE qbo_connections') && $c($mig067, 'MODIFY COLUMN access_token_ct VARBINARY(4096)'));
$a('widens mail_oauth.oauth_access_token_ct',    $c($mig067, 'ALTER TABLE mail_oauth')      && $c($mig067, 'MODIFY COLUMN oauth_access_token_ct VARBINARY(4096)'));

// Backfilled originals
$mig052 = (string) file_get_contents($ROOT . '/core/migrations/052_qbo_foundation.sql');
$a('mig 052 backfilled to 4096',                 $c($mig052, 'access_token_ct     VARBINARY(4096)'));
$mig003 = (string) file_get_contents($ROOT . '/core/migrations/003_mail_service.sql');
$a('mig 003 backfilled to 4096',                 $c($mig003, 'oauth_access_token_ct  VARBINARY(4096)'));

// ----------------------------------------------------- API endpoint
echo "\napi/admin/schema_health.php\n";
$relApi = '/api/admin/schema_health.php';
$api = (string) @file_get_contents($ROOT . $relApi);
$a('endpoint exists',                            $api !== '');
$a('requires schema_health.php helper',          $c($api, "require_once __DIR__ . '/../../core/integrations/schema_health.php'"));
$a('RBAC tenant.manage',                         $c($api, "rbac_legacy_require(\$user, 'tenant.manage')"));
$a('rejects non-GET with 405',                   $c($api, "api_error('Method not allowed', 405)"));
$a('emits status + counts + columns + generated_at',
    $c($api, "'status'") && $c($api, "'counts'") && $c($api, "'columns'") && $c($api, "'generated_at'"));
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg($ROOT . $relApi) . ' 2>&1', $out, $rc);
$a("php -l $relApi",                             $rc === 0);

// ----------------------------------------------------- IntegrationsHub UI
echo "\nIntegrationsHub.jsx — SchemaHealthPanel\n";
$ui = (string) file_get_contents($ROOT . '/dashboard/src/pages/IntegrationsHub.jsx');
$a('imports schema health probe via useApi',     $c($ui, "useApi('/api/admin/schema_health.php')"));
$a('renders SchemaHealthPanel component',        $c($ui, 'SchemaHealthPanel'));
$a('declares SchemaHealthPanel function',        $c($ui, 'function SchemaHealthPanel'));
$a('panel testid',                               $c($ui, 'data-testid="schema-health-panel"'));
$a('label testid',                               $c($ui, 'data-testid="schema-health-panel-label"'));
$a('counts testid',                              $c($ui, 'data-testid="schema-health-panel-counts"'));
$a('toggle testid',                              $c($ui, 'data-testid="schema-health-panel-toggle"'));
$a('details table testid',                       $c($ui, 'data-testid="schema-health-panel-details"'));
$a('per-row testid template',                    $c($ui, '`schema-health-row-${r.integration}-${r.column}`'));
$a('imports ShieldCheck / AlertOctagon icons',   $c($ui, 'ShieldCheck') && $c($ui, 'AlertOctagon'));
$a('panel mounted ABOVE Payment Rails section',
    strpos($ui, 'SchemaHealthPanel') < strpos($ui, 'Payment Rails'));

echo "\n=========================================\n";
echo "Integration schema health smoke: {$pass} ok / {$fail} fail\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
