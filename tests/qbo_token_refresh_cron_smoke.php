<?php
/**
 * Smoke — QBO OAuth proactive token refresh cron (cron/qbo_token_refresh.php).
 *
 * Locks the contract:
 *   - cron/qbo_token_refresh.php exists, is a CLI-style entry point,
 *     declares the REFRESH_WITHIN_SEC + REFRESH_TOKEN_WARN_SEC tunables.
 *   - It scans qbo_connections WHERE status='active', triggers
 *     qboRefreshAccessToken() for any token expiring within the window,
 *     and emits an audit row when refresh-token expiry is approaching.
 *
 * Note: this is a static-shape smoke (no live network call). The
 * dynamic refresh path is exercised by qbo_oauth_smoke.php elsewhere.
 *
 * Run: php -d zend.assertions=1 /app/tests/qbo_token_refresh_cron_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nQBO OAuth token refresh cron smoke\n";
echo "==================================\n\n";

$path = '/app/cron/qbo_token_refresh.php';
check('cron/qbo_token_refresh.php exists', file_exists($path));
$src = file_exists($path) ? (string) file_get_contents($path) : '';

echo "── tunables ──\n";
check('declares REFRESH_WITHIN_SEC',
    preg_match('/const REFRESH_WITHIN_SEC\s*=/', $src) === 1);
check('declares REFRESH_TOKEN_WARN_SEC',
    preg_match('/const REFRESH_TOKEN_WARN_SEC\s*=/', $src) === 1);
check('REFRESH_WITHIN_SEC defaults to 30 minutes',
    preg_match('/REFRESH_WITHIN_SEC\s*=\s*30\s*\*\s*60/', $src) === 1);

echo "\n── core logic ──\n";
check('imports qbo/client.php',
    str_contains($src, "/core/qbo/client.php"));
check("scans active qbo_connections",
    preg_match('/FROM qbo_connections\s+WHERE status\s*=\s*[\'"]active[\'"]/s', $src) === 1);
check('calls qboRefreshAccessToken inside loop',
    str_contains($src, 'qboRefreshAccessToken($tid)'));
check('skips tokens whose access_token_exp is beyond REFRESH_WITHIN_SEC',
    preg_match('/\$accessExp\s*-\s*\$now\)\s*>\s*REFRESH_WITHIN_SEC/', $src) === 1);
check('writes qbo_audit row when refresh-token expiry approaches',
    str_contains($src, "qboAudit(\$tid, 'token_refresh_warn'"));
check('emits final summary line on STDOUT',
    str_contains($src, 'qbo_token_refresh done:'));

echo "\n── safety ──\n";
check('migration guard (try/catch around connections query)',
    preg_match('/try\s*\{\s*\$stmt\s*=\s*\$pdo->query/s', $src) === 1);
check('failure of single tenant refresh does not abort the loop',
    preg_match('/foreach.*?try\s*\{.*?qboRefreshAccessToken.*?\}\s*catch/s', $src) === 1);

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "qbo_token_refresh_cron smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
