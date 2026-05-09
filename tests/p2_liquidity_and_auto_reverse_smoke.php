<?php
/**
 * P2 smoke — Liquidity Forecast + Auto-reversing accruals.
 *
 * Asserts:
 *   - api/liquidity_forecast.php contract: GET-only, RBAC, days clamped 1..365,
 *     starting_cash from active accounting_bank_accounts joined on GL,
 *     inflows from billing_invoices.amount_due fallback, outflows union
 *     treasury_payments + ap_bills with vendor+amount dedup heuristic.
 *   - Daily projection has runway-to-zero detection + lowest-balance tracking.
 *   - Module-namespaced kebab alias /api/treasury/liquidity-forecast.
 *   - LiquidityForecast.jsx page renders tiles + bars + runway alert.
 *   - TreasuryModule routes /forecast and adds tab.
 *   - Migration 024 adds auto_reverses_on + attempted_at + last_error columns.
 *   - core/jobdiva-style helper accountingPostJe accepts auto_reverses_on,
 *     validates YYYY-MM-DD, ignores blank.
 *   - api/je_auto_reverse.php contract: POST-only, RBAC, validates JE is
 *     posted + non-reversal + date after posting.
 *   - scripts/auto_reverse_accruals.php cron — finds eligible JEs (no prior
 *     reversal), uses accountingReverseJe(), nulls auto_reverses_on on
 *     success, captures error string on failure.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Liquidity Forecast — api/liquidity_forecast.php\n";
$apiPath = "{$ROOT}/api/liquidity_forecast.php";
$api = (string) file_get_contents($apiPath);
$assert('parses',                                  $lint($apiPath));
$assert('GET-only',                                strpos($api, "if (api_method() !== 'GET') api_error('Method not allowed', 405)") !== false);
$assert('RBAC treasury.payment.view',              strpos($api, "RBAC::requirePermission(\$user, 'treasury.payment.view')") !== false);
$assert('days clamped 1..365 default 90',          strpos($api, "max(1, min(365, (int) (api_query('days') ?? 90)))") !== false);
$assert('imports shared liquidity projection engine',
    strpos($api, "require_once __DIR__ . '/../core/treasury/liquidity_projection.php'") !== false);
$assert('calls liquidityBaselineDatasets',
    strpos($api, 'liquidityBaselineDatasets($tid, $today, $endDate, $entityId)') !== false);
$assert('calls liquidityBucketDatasets',
    strpos($api, 'liquidityBucketDatasets($datasets)') !== false);
$assert('runs the day-by-day projection via shared walker',
    strpos($api, '$result   = liquidityWalkProjection(') !== false);

echo "\nShared engine — core/treasury/liquidity_projection.php\n";
$libPath = "{$ROOT}/core/treasury/liquidity_projection.php";
$libSrc  = (string) file_get_contents($libPath);
$assert('starting_cash sums active bank accounts via GL',
    strpos($libSrc, 'FROM accounting_bank_accounts ba') !== false
    && strpos($libSrc, "ba.status = 'active'") !== false
    && strpos($libSrc, 'JOIN accounting_journal_lines') !== false);
$assert('only posted JEs feed starting_cash',
    strpos($libSrc, "je.status = 'posted'") !== false);
$assert('AR uses amount_due fallback to total - amount_paid',
    strpos($libSrc, 'COALESCE(amount_due, total - amount_paid)') !== false);
$assert('AR status filter excludes paid/void/draft',
    strpos($libSrc, "status IN ('approved','sent','partially_paid')") !== false);
$assert('outflows from treasury_payments status filter',
    strpos($libSrc, "status IN ('draft','pending_approval','approved','scheduled')") !== false);
$assert('AP bills due-window query',
    strpos($libSrc, "FROM ap_bills") !== false
    && strpos($libSrc, "status IN ('approved','partially_paid','pending_approval')") !== false);
$assert('vendor+amount dedup heuristic prevents double-counting',
    strpos($libSrc, '$tpKeys[strtolower((string) $r[\'payee_name\']) . \'|\' . number_format((float) $r[\'amount\'], 2, \'.\', \'\')] = true') !== false
    && strpos($libSrc, 'if (isset($tpKeys[$key])) continue') !== false);
$assert('day-by-day projection loop',              strpos($libSrc, 'for ($i = 0; $i <= $days; $i++)') !== false);
$assert('runway-to-zero detection (first negative day)',
    strpos($libSrc, 'if ($runwayDay === null && $closing < 0)') !== false);
$assert('lowest-balance tracking',                 strpos($libSrc, 'if ($closing < $lowest)') !== false);
$assert('emits guards envelope (operator-friendly)',
    strpos($api, "'has_bank_accounts'") !== false
    && strpos($api, "'has_open_ar'") !== false
    && strpos($api, "'has_scheduled_payments'") !== false);
$assert('emits per-source breakdown',
    strpos($api, "'source' => 'invoice'") !== false
    && strpos($api, "'source' => 'treasury_payment'") !== false
    && strpos($api, "'source' => 'ap_bill'") !== false);

echo "\nKebab alias — /api/treasury/liquidity-forecast\n";
$aliasPath = "{$ROOT}/modules/treasury/api/liquidity_forecast.php";
$assert('alias file exists',                       file_exists($aliasPath));
$assert('alias delegates via require_once',
    strpos((string) file_get_contents($aliasPath), "require_once __DIR__ . '/../../../api/liquidity_forecast.php'") !== false);

echo "\nUI — LiquidityForecast.jsx page\n";
$pgPath = "{$ROOT}/dashboard/src/pages/LiquidityForecast.jsx";
$pg = (string) file_get_contents($pgPath);
$assert('default export',                          strpos($pg, 'export default function LiquidityForecast') !== false);
$assert('reads /api/liquidity_forecast.php',       strpos($pg, "/api/liquidity_forecast.php?days=") !== false);
$assert('window selector testid + 4 horizons',
    strpos($pg, 'data-testid="liquidity-window-select"') !== false
    && strpos($pg, '<option value={30}>') !== false
    && strpos($pg, '<option value={60}>') !== false
    && strpos($pg, '<option value={90}>') !== false
    && strpos($pg, '<option value={180}>') !== false);
$assert('runway alert testid (red when runway hit)',
    strpos($pg, 'data-testid="liquidity-runway-alert"') !== false
    && strpos($pg, 'totals.runway_days_to_zero != null') !== false);
$assert('5 KPI tiles wired',
    strpos($pg, 'liquidity-tile-starting') !== false
    && strpos($pg, 'liquidity-tile-inflows') !== false
    && strpos($pg, 'liquidity-tile-outflows') !== false
    && strpos($pg, 'liquidity-tile-ending') !== false
    && strpos($pg, 'liquidity-tile-lowest') !== false);
$assert('per-day bar testid template',             strpos($pg, 'data-testid={`liquidity-daily-bar-${i}`}') !== false);
$assert('no-banks operator nudge',                 strpos($pg, 'data-testid="liquidity-no-banks"') !== false);

echo "\nRouting — TreasuryModule\n";
$tm = (string) file_get_contents("{$ROOT}/modules/treasury/ui/TreasuryModule.jsx");
$assert('imports LiquidityForecast page',          strpos($tm, "import LiquidityForecast  from '../../../dashboard/src/pages/LiquidityForecast'") !== false);
$assert('mounts /forecast route',                  strpos($tm, '<Route path="forecast"      element={<LiquidityForecast />} />') !== false);
$assert('adds Liquidity Forecast nav tab',         strpos($tm, '<TreasuryTab to="forecast"    label="Liquidity Forecast" />') !== false);

echo "\nAuto-reverse migration 024\n";
$mig = (string) file_get_contents("{$ROOT}/core/migrations/024_auto_reversing_accruals.sql");
$assert('migration exists',                        strlen($mig) > 0);
$assert('adds auto_reverses_on column',            strpos($mig, 'ADD COLUMN auto_reverses_on DATE NULL') !== false);
$assert('adds auto_reverse_attempted_at column',   strpos($mig, 'ADD COLUMN auto_reverse_attempted_at TIMESTAMP NULL') !== false);
$assert('adds auto_reverse_last_error column',     strpos($mig, 'ADD COLUMN auto_reverse_last_error VARCHAR(500)') !== false);
$assert('adds idx_aje_auto_reverse for cron query',
    strpos($mig, 'ADD INDEX idx_aje_auto_reverse (tenant_id, auto_reverses_on, status)') !== false);

echo "\nJE create — accepts auto_reverses_on\n";
$lib = (string) file_get_contents("{$ROOT}/modules/accounting/lib/accounting.php");
$assert('accountingPostJe writes auto_reverses_on',
    strpos($lib, "'auto_reverses_on'  => (function (\$v) {") !== false);
$assert('validates YYYY-MM-DD',                    strpos($lib, "preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/', \$v) ? \$v : null") !== false);
$assert('blank/empty coerced to null',             strpos($lib, 'if (empty($v)) return null') !== false);

echo "\nAPI — api/je_auto_reverse.php\n";
$arApi = "{$ROOT}/api/je_auto_reverse.php";
$arSrc = (string) file_get_contents($arApi);
$assert('parses',                                  $lint($arApi));
$assert('POST-only',                               strpos($arSrc, "if (api_method() !== 'POST') api_error('Method not allowed', 405)") !== false);
$assert('RBAC accounting.je.post',                 strpos($arSrc, "RBAC::requirePermission(\$user, 'accounting.je.post')") !== false);
$assert('validates JE exists + tenant-scoped',     strpos($arSrc, 'WHERE id = :id AND tenant_id = :t LIMIT 1') !== false);
$assert("rejects when status !== 'posted'",        strpos($arSrc, "if (\$je['status'] !== 'posted') api_error('JE must be posted', 422)") !== false);
$assert('rejects when JE itself is a reversal',    strpos($arSrc, "if (\$je['reverses_je_id'] !== null) api_error('JE is itself a reversal', 422)") !== false);
$assert('rejects date <= posting_date',
    strpos($arSrc, "if (\$date !== null && \$date <= \$je['posting_date'])") !== false);
$assert('clears attempt_at + last_error on toggle',
    strpos($arSrc, 'auto_reverse_attempted_at = NULL') !== false
    && strpos($arSrc, 'auto_reverse_last_error   = NULL') !== false);

echo "\nCron — scripts/auto_reverse_accruals.php\n";
$cronPath = "{$ROOT}/scripts/auto_reverse_accruals.php";
$cron = (string) file_get_contents($cronPath);
$assert('parses',                                  $lint($cronPath));
$assert("only fires on posted, non-reversal JEs",
    strpos($cron, "WHERE je.status = 'posted'") !== false
    && strpos($cron, 'AND je.reverses_je_id IS NULL') !== false);
$assert('idempotent — skips if already reversed',
    strpos($cron, 'NOT EXISTS (') !== false
    && strpos($cron, 'r.reverses_je_id = je.id') !== false);
$assert('uses existing accountingReverseJe() helper',
    strpos($cron, 'accountingReverseJe($tid, $jeId, $reason, null)') !== false);
$assert('clears auto_reverses_on after success',
    strpos($cron, 'auto_reverses_on        = NULL') !== false);
$assert('persists last_error on failure',
    strpos($cron, "auto_reverse_last_error   = :err") !== false);
$assert('exit code reflects failures',             strpos($cron, 'exit($failed > 0 ? 1 : 0)') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
