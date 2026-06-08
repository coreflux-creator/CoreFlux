<?php
/**
 * Smoke — QBO error-code remediation playbook.
 *
 * Locks the contract:
 *   - core/qbo/error_playbook.php exposes qboErrorPlaybookTable() and
 *     qboErrorPlaybookLookup(), with a stable return shape on every
 *     entry: {code, category, severity, summary, suggested_fix, docs_link}.
 *   - DLQ endpoint enriches each row with `playbook` via lookup.
 *   - Known codes (6210 lines unbalanced, 3200 token expired, 6610
 *     duplicate, etc.) resolve to the right category + severity.
 *   - Unknown codes fall through to a safe stub (never null).
 *
 * Run: php -d zend.assertions=1 /app/tests/qbo_error_playbook_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nQBO error playbook smoke\n";
echo "========================\n\n";

$path = '/app/core/qbo/error_playbook.php';
check('core/qbo/error_playbook.php exists', file_exists($path));
require_once $path;

echo "── module shape ──\n";
check('declares qboErrorPlaybookTable()',
    function_exists('qboErrorPlaybookTable'));
check('declares qboErrorPlaybookLookup()',
    function_exists('qboErrorPlaybookLookup'));

$table = qboErrorPlaybookTable();
check('table contains > 5 known codes',          count($table) >= 5);
check('every entry has the canonical 6 keys', (function () use ($table) {
    foreach ($table as $entry) {
        foreach (['code','category','severity','summary','suggested_fix','docs_link'] as $k) {
            if (!array_key_exists($k, $entry)) return false;
        }
    }
    return true;
})());
check('severity always in allowlist', (function () use ($table) {
    $ok = ['requeue_safe','fix_data','fix_config','fix_oauth'];
    foreach ($table as $e) if (!in_array($e['severity'], $ok, true)) return false;
    return true;
})());
check('category always in allowlist', (function () use ($table) {
    $ok = ['validation','auth','permission','duplicate','rate_limit','unknown'];
    foreach ($table as $e) if (!in_array($e['category'], $ok, true)) return false;
    return true;
})());

echo "\n── code coverage (high-frequency real-world failures) ──\n";
foreach (['6210','6190','6610','3200','3100','4001'] as $code) {
    check("code {$code} is in the playbook", isset($table[$code]));
}

echo "\n── lookup behaviour ──\n";
$r = qboErrorPlaybookLookup('6210');
check('6210 → validation category',                       $r['category'] === 'validation');
check('6210 → fix_data severity',                         $r['severity'] === 'fix_data');
check('6210 summary mentions lines/balance',
    str_contains(strtolower($r['summary']), 'line') && str_contains(strtolower($r['summary']), 'balance'));
check('6210 suggested_fix is actionable (non-empty)',     strlen($r['suggested_fix']) > 30);

$r = qboErrorPlaybookLookup('3200');
check('3200 → auth category',                             $r['category'] === 'auth');
check('3200 → fix_oauth severity',                        $r['severity'] === 'fix_oauth');
check('3200 mentions refresh',                            stripos($r['suggested_fix'], 'refresh') !== false);

$r = qboErrorPlaybookLookup('6610');
check('6610 → duplicate category',                        $r['category'] === 'duplicate');

$r = qboErrorPlaybookLookup('4001');
check('4001 → rate_limit + requeue_safe',
    $r['category'] === 'rate_limit' && $r['severity'] === 'requeue_safe');

echo "\n── fallback for unknown / empty codes ──\n";
$r = qboErrorPlaybookLookup('9999');
check('unknown code does NOT return null',                is_array($r));
check('unknown code → category=unknown',                  $r['category'] === 'unknown');
check('unknown code carries the original code',           $r['code'] === '9999');
check('unknown code still has actionable suggested_fix',  strlen($r['suggested_fix']) > 20);

$r = qboErrorPlaybookLookup(null);
check('null code does NOT throw',                         is_array($r));
check('null code → category=unknown',                     $r['category'] === 'unknown');

$r = qboErrorPlaybookLookup('');
check('empty code does NOT throw',                        is_array($r));
check('empty code summary mentions "No QBO error code"',
    str_contains($r['summary'], 'No QBO error code'));

echo "\n── DLQ endpoint wiring ──\n";
$dlSrc = (string) file_get_contents('/app/api/admin/qbo/dead_letters.php');
check('endpoint requires error_playbook.php',             str_contains($dlSrc, "qbo/error_playbook.php"));
check('endpoint calls qboErrorPlaybookLookup per row',    str_contains($dlSrc, 'qboErrorPlaybookLookup($r['));
check('enrichment lands on row[playbook]',                str_contains($dlSrc, "\$r['playbook'] = qboErrorPlaybookLookup"));

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "qbo_error_playbook smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
