<?php
/**
 * Smoke: Admin healthcheck endpoint + page.
 *
 * Verifies the API enumerates every check we shipped this sprint and
 * gates writes correctly. We DO NOT call the API end-to-end (no DB in
 * sandbox) — we verify the static contract instead.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$parses = fn (string $p): bool => is_file($p)
    && (int) shell_exec('php -l ' . escapeshellarg($p) . ' >/dev/null 2>&1; echo $?') === 0;
$read = fn (string $p) => (string) file_get_contents($p);

$apiPath = __DIR__ . '/../api/admin_healthcheck.php';
$api     = $read($apiPath);
echo "API: api/admin_healthcheck.php\n";
$a('parses',                                            $parses($apiPath));
$a('requires admin/manager/master/tenant role',         str_contains($api, "Admin/manager role required"));
$a('supports ?only=k1,k2 subset',                       str_contains($api, "isset(\$_GET['only'])"));
$a('returns tally + results + ran_at',                  str_contains($api, "'results' => \$results")
                                                        && str_contains($api, "'tally' => \$tally")
                                                        && str_contains($api, "'ran_at' => date('c')"));

// Every freshly-shipped artefact must have a registered check
$expectedChecks = [
    'db_connection',
    'snapshot_history_table', 'mail_branding_table', 'digest_schedules_table', 'share_links_table',
    'oidc_session_state', 'sso_domains_table', 'client_contacts_table', 'dunning_log_table',
    'mail_branding_endpoint', 'digest_schedule_helper',
    'snapshot_renders', 'statement_renders',
    'pdf_renderer_available', 'mail_bootstrap', 'emergent_llm_key',
    'cron_money_movement', 'cron_dunning', 'cron_ap_weekly_queue',
    'oidc_discovery', 'vite_bundle_present', 'deploy_version_matches',
];
foreach ($expectedChecks as $k) {
    $a("check registered: {$k}",                        str_contains($api, "'{$k}'"));
}

// status enum & dot-render expectations
foreach (['ok','warn','fail','skipped'] as $s) {
    $a("status enum: '{$s}'",                           str_contains($api, "'status' => '{$s}'") || str_contains($api, "'{$s}'"));
}

// individual check function helpers
foreach ([
    'admin_hc_db_connection', 'admin_hc_table_exists', 'admin_hc_branding_endpoint',
    'admin_hc_digest_helper', 'admin_hc_snapshot_renders', 'admin_hc_statement_renders',
    'admin_hc_pdf_binary', 'admin_hc_mail_bootstrap', 'admin_hc_emergent_key',
    'admin_hc_cron_script', 'admin_hc_oidc_discovery', 'admin_hc_vite_bundle', 'admin_hc_deploy_version',
] as $fn) {
    $a("helper fn declared: {$fn}",                     preg_match("/function\\s+{$fn}\\s*\\(/", $api) === 1);
}

// table-exists check uses information_schema (cannot be SQL-injected with the table name from check def)
$a('table_exists query uses information_schema',        str_contains($api, "FROM information_schema.tables"));
$a('table_exists swaps row count tolerance to warn',    str_contains($api, "'status' => \$rc === 0 ? 'warn' : 'ok'"));
$a('oidc_discovery skipped when SSO not configured',    str_contains($api, "'status' => 'skipped', 'detail' => 'no SSO configured for this tenant'"));
$a('vite bundle reads expected_bundle from .deploy-version', str_contains($api, '$verFile = __DIR__ . \'/../.deploy-version\''));
$a('deploy version compares newest-mtime vs expected',  str_contains($api, "fn (\$a, \$b) => filemtime(\$b) <=> filemtime(\$a)"));
$a('cron_script does `php -l`',                         str_contains($api, "exec('php -l '"));

echo "\nUI: HealthcheckAdmin.jsx\n";
$uiPath = __DIR__ . '/../dashboard/src/pages/HealthcheckAdmin.jsx';
$ui     = $read($uiPath);
$a('UI exists',                                         is_file($uiPath));
foreach (['admin-healthcheck','admin-healthcheck-rerun','admin-healthcheck-summary','admin-healthcheck-rows','admin-healthcheck-ran-at'] as $tid) {
    $a("testid: {$tid}",                                 str_contains($ui, $tid));
}
$a('renders per-row dot testid (template literal)',     str_contains($ui, "data-testid={`admin-healthcheck-dot-\${r.key}`}"));
$a('summary banner red when fail > 0',                  str_contains($ui, "tally.fail > 0 ? '#fef2f2'"));
$a('legend includes all 4 statuses',                    str_contains($ui, '● pass') && str_contains($ui, '● warn')
                                                        && str_contains($ui, '● fail') && str_contains($ui, '● skip'));

$adminMod = $read(__DIR__ . '/../dashboard/src/pages/AdminModule.jsx');
$a('AdminModule imports HealthcheckAdmin',              str_contains($adminMod, "import HealthcheckAdmin from './HealthcheckAdmin'"));
$a('AdminModule routes /admin/healthcheck',             str_contains($adminMod, 'path="/healthcheck"'));
$a('AdminModule sidebar lists Healthcheck',             str_contains($adminMod, "label: 'Healthcheck'"));
$a('AdminModule overview tile for Healthcheck',         str_contains($adminMod, '"Healthcheck"'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
