<?php
/**
 * Smoke test for the AI usage panel (panel + endpoint).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// ── Backend ───────────────────────────────────────────────────────────
$api = file_get_contents('/app/api/admin/ai_usage.php');
$a('endpoint exists',                                  $api !== false);
$a('endpoint calls api_require_auth',                  str_contains($api, 'api_require_auth()'));
$a('endpoint rejects non-admin roles',                 str_contains($api, "['master_admin', 'tenant_admin']"));
$a('endpoint clamps days to 1..90',                    str_contains($api, 'max(1, min(90, (int) ($_GET[\'days\']'));
$a('endpoint scopes ai_interactions by tenant_id',     str_contains($api, 'WHERE tenant_id = :t'));
$a('endpoint groups by feature_class via $perClass',   str_contains($api, '$perClass'));
$a('endpoint reports p50 and p95 latency',             str_contains($api, 'p50_latency_ms') && str_contains($api, 'p95_latency_ms'));
$a('endpoint returns by_feature_class array',          str_contains($api, "'by_feature_class'"));
$a('endpoint returns top_feature_keys array',          str_contains($api, "'top_feature_keys'"));
$a('endpoint sorts classes by calls desc',             str_contains($api, '$b[\'calls\'] <=> $a[\'calls\']'));
$a('endpoint aggregates cost_cents per class + total', str_contains($api, "'cost_cents'")
                                                       && str_contains($api, '$costRows')
                                                       && str_contains($api, '$costSum'));
$a('endpoint reports prompt + response token sums',    str_contains($api, "'prompt_tokens'")
                                                       && str_contains($api, "'response_tokens'"));
$a('endpoint preserves null cost (no false zeros)',    str_contains($api, '$costRows > 0 ? $costSum : null'));

exec(PHP_BINARY . ' -l /app/api/admin/ai_usage.php 2>&1', $out, $rc);
$a('endpoint passes php -l',                           $rc === 0);

// ── Frontend panel ───────────────────────────────────────────────────
$jsx = file_get_contents('/app/dashboard/src/pages/AiAccuracyDashboard.jsx');
$a('Panel imports useApi',                              str_contains($jsx, "import { useApi } from '../lib/api'"));
$a('Panel calls /api/admin/ai_usage.php',               str_contains($jsx, '/api/admin/ai_usage.php?days='));
$a('AiUsagePanel function declared',                    str_contains($jsx, 'function AiUsagePanel(') );
$a('Renders ai-usage-panel test id',                    str_contains($jsx, 'data-testid="ai-usage-panel"'));
$a('Has by-class table test id',                        str_contains($jsx, 'data-testid="ai-usage-by-class"'));
$a('Has top-keys table test id',                        str_contains($jsx, 'data-testid="ai-usage-top-keys"'));
$a('Has empty-state with link to /admin/ai-settings',   str_contains($jsx, 'data-testid="ai-usage-empty"')
                                                       && str_contains($jsx, '/admin/ai-settings'));
$a('Panel mounted inside AiAccuracyDashboard',          str_contains($jsx, '<AiUsagePanel days={'));
$a('Window clamped to <= 30 days for the usage panel',  str_contains($jsx, 'Math.min(days, 30)'));

// ── Sentries still green ──────────────────────────────────────────────
exec(PHP_BINARY . ' -d extension=pdo_sqlite -d extension=sqlite3 -d zend.assertions=1 /app/tests/tenant_leak_static_analyzer_smoke.php 2>&1', $outA, $rcA);
$a('tenant-leak sentry still green', $rcA === 0);
exec(PHP_BINARY . ' -d extension=pdo_sqlite -d extension=sqlite3 -d zend.assertions=1 /app/tests/auth_gate_static_analyzer_smoke.php 2>&1', $outB, $rcB);
$a('auth-gate sentry still green',   $rcB === 0);
exec(PHP_BINARY . ' -d extension=pdo_sqlite -d extension=sqlite3 -d zend.assertions=1 /app/tests/hy093_static_analyzer_smoke.php 2>&1', $outC, $rcC);
$a('HY093 sentry still green',       $rcC === 0);

echo "\n=========================================\n";
echo "AI usage panel smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
