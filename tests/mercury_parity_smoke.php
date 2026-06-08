<?php
/**
 * Smoke — Mercury error playbook + Failed-PI requeue + health probe cron.
 *
 * Brings Mercury to full charter parity with QBO's polish layer:
 *   - playbook lookup with stable shape
 *   - admin Failed-PI endpoint exists with playbook enrichment
 *   - mpRequeueFailed() helper exposes Failed → Approved transition
 *   - Failed state matrix allows the requeue path
 *   - mercury_health_probe cron is shaped correctly
 *
 * Run: php -d zend.assertions=1 /app/tests/mercury_parity_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nMercury parity smoke (playbook + requeue + probe)\n";
echo "==================================================\n\n";

// ────────────────────────── 1. Error playbook ──
echo "── error playbook ──\n";
$pbPath = '/app/core/mercury/error_playbook.php';
check('core/mercury/error_playbook.php exists', file_exists($pbPath));
require_once $pbPath;

check('declares mercuryErrorPlaybookTable()',  function_exists('mercuryErrorPlaybookTable'));
check('declares mercuryErrorPlaybookLookup()', function_exists('mercuryErrorPlaybookLookup'));

$table = mercuryErrorPlaybookTable();
check('table contains at least 8 codes',   count($table) >= 8);
check('every entry has canonical 6 keys', (function () use ($table) {
    foreach ($table as $e)
        foreach (['code','category','severity','summary','suggested_fix','docs_link'] as $k)
            if (!array_key_exists($k, $e)) return false;
    return true;
})());
check('severity uses the shared allowlist', (function () use ($table) {
    $ok = ['requeue_safe','fix_data','fix_config','fix_oauth'];
    foreach ($table as $e) if (!in_array($e['severity'], $ok, true)) return false;
    return true;
})());

echo "\n── high-frequency codes mapped ──\n";
foreach (['invalid_recipient', 'insufficient_funds', 'invalid_api_key',
          'rate_limit_exceeded', 'compliance_hold', 'r01', 'r02', 'r10'] as $code) {
    check("code '{$code}' is in the playbook", isset($table[$code]));
}

echo "\n── compliance / sanctions severity is fix_config (NOT requeue_safe) ──\n";
$r = mercuryErrorPlaybookLookup('compliance_hold');
check("compliance_hold → fix_config",          $r['severity'] === 'fix_config');
check("compliance_hold suggested_fix says 'do not requeue'",
    stripos($r['suggested_fix'], 'do not') !== false || stripos($r['suggested_fix'], 'do NOT') !== false);

$r = mercuryErrorPlaybookLookup('sanctions_screen_failed');
check("sanctions → fix_config + critical wording",
    $r['severity'] === 'fix_config' && stripos($r['suggested_fix'], 'critical') !== false);

echo "\n── lookup safety ──\n";
$r = mercuryErrorPlaybookLookup('insufficient_funds');
check('insufficient_funds category=validation',          $r['category'] === 'validation');
$r = mercuryErrorPlaybookLookup('rate_limit_exceeded');
check('rate_limit_exceeded severity=requeue_safe',       $r['severity'] === 'requeue_safe');
$r = mercuryErrorPlaybookLookup('NotInTable');
check('unknown code → category=unknown (no null)',       $r['category'] === 'unknown');
$r = mercuryErrorPlaybookLookup(null);
check('null code does NOT throw',                        is_array($r));
$r = mercuryErrorPlaybookLookup('INVALID_API_KEY');     // case-insensitive
check('case-insensitive lookup hits the table',          $r['category'] === 'auth');

// ────────────────────────── 2. State machine: Failed → Approved ──
echo "\n── state machine allows Failed → Approved ──\n";
require_once '/app/core/mercury_payments.php';

check('Failed → Approved is allowed',                     mpTransitionAllowed('Failed', 'Approved'));
check('Failed → Cancelled is allowed',                    mpTransitionAllowed('Failed', 'Cancelled'));
check('Failed → Funding still REFUSED',                  !mpTransitionAllowed('Failed', 'Funding'));
check('Failed → Settled still REFUSED',                  !mpTransitionAllowed('Failed', 'Settled'));
check('Settled remains terminal-ish (no Failed → Settled jump)',
    !mpTransitionAllowed('Failed', 'Settled'));

echo "\n── mpRequeueFailed helper ──\n";
check('mpRequeueFailed function is declared',             function_exists('mpRequeueFailed'));
$src = (string) file_get_contents('/app/core/mercury_payments.php');
check('mpRequeueFailed clears funding_mercury_txn_id',    str_contains($src, "'funding_mercury_txn_id'  => null"));
check('mpRequeueFailed clears payout_mercury_txn_id',     str_contains($src, "'payout_mercury_txn_id'   => null"));
check('mpRequeueFailed audits with requeue_from_failed tag',
    str_contains($src, "'action' => 'requeue_from_failed'"));
check('mpRequeueFailed refuses non-Failed state',
    preg_match("/state.*?!==.*?'Failed'.*?throw/s", $src) === 1);

// ────────────────────────── 3. Admin endpoint shape ──
echo "\n── /api/admin/mercury/failed_payments.php ──\n";
$epPath = '/app/api/admin/mercury/failed_payments.php';
check('endpoint exists',                                  file_exists($epPath));
$ep = (string) file_get_contents($epPath);
check('endpoint requires mercury_payments.php',           str_contains($ep, "mercury_payments.php"));
check('endpoint requires error_playbook.php',             str_contains($ep, "mercury/error_playbook.php"));
check('endpoint calls api_require_auth',                  str_contains($ep, 'api_require_auth()'));
check('endpoint enforces master_admin or tenant_admin',   str_contains($ep, "rbac_legacy_require_any") &&
                                                          str_contains($ep, "master_admin"));
check('GET reads from payment_instructions',              str_contains($ep, 'FROM payment_instructions'));
check('GET filters by state=Failed (default)',            str_contains($ep, "stateFilter = \$_GET['state'] ?? 'Failed'"));
check('GET enriches each row with playbook',              str_contains($ep, 'mercuryErrorPlaybookLookup'));
check('GET surfaces can_requeue flag',                    str_contains($ep, "'can_requeue'"));
check('POST calls mpRequeueFailed',                       str_contains($ep, 'mpRequeueFailed('));
check('POST returns 409 when state machine refuses',      str_contains($ep, 'http_response_code(409)'));

// ────────────────────────── 4. Health probe cron ──
echo "\n── cron/mercury_health_probe.php ──\n";
$cronPath = '/app/cron/mercury_health_probe.php';
check('cron exists',                                      file_exists($cronPath));
$cron = (string) file_get_contents($cronPath);
check('imports mercury_service.php',                      str_contains($cron, "mercury_service.php"));
check('imports mercury_adapter.php (for listAccounts)',   str_contains($cron, "mercury_adapter.php"));
check('scans active/error mercury_connections',
    preg_match("/FROM mercury_connections\s+WHERE status IN\s*\(\s*'active',\s*'error'\s*\)/s", $cron) === 1);
check('uses mercuryListAccounts as the probe call',       str_contains($cron, 'mercuryListAccounts('));
check('flips status to error on probe failure',           str_contains($cron, "status = 'error'"));
check('flips status back to active on recovery',          str_contains($cron, "status = 'active'"));
check('writes last_probe_error on failure',               str_contains($cron, 'last_probe_error = :err'));
check('emits summary line on STDOUT',                     str_contains($cron, 'mercury_health_probe done:'));

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "mercury_parity smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
