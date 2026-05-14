<?php
/**
 * tests/financial_state_cache_smoke.php — Phase 2 Unified Financial State Cache.
 *
 * Verifies:
 *   - Migration 045 shape (financial_state_cache + financial_state_cache_dirty)
 *   - core/financial_state_cache.php library surface + contracts (no DB)
 *   - api/financial_state.php endpoint shape (GET / POST mark_dirty / rebuild)
 *   - accountingPostJe / accountingReverseJe call fscMarkDirty (event hook)
 *
 * Lane: core (default — this is infrastructure for the accounting engine).
 *
 * No live DB calls — every check is a static contract assertion against
 * file contents. Behavioural validation lives in the simulation harness
 * (sim_harness_smoke.php) once a sim scenario exercises the cache rebuild.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "  ok    {$name}\n"; }
    else     { $fail++; echo "  FAIL  {$name}\n"; }
};

$ROOT = realpath(__DIR__ . '/..');

// ── Migration 045 ────────────────────────────────────────────────────
echo "Migration 045 — financial_state_cache schema\n";
$migPath = "{$ROOT}/core/migrations/045_financial_state_cache.sql";
$a('045_financial_state_cache.sql exists',       is_file($migPath));
$mig = (string) file_get_contents($migPath);

$a('cache table created (IF NOT EXISTS)',        str_contains($mig, 'CREATE TABLE IF NOT EXISTS financial_state_cache'));
$a('dirty-log table created (IF NOT EXISTS)',    str_contains($mig, 'CREATE TABLE IF NOT EXISTS financial_state_cache_dirty'));
$a('cache scope columns (key + value)',          str_contains($mig, 'scope_key') && str_contains($mig, 'scope_value'));
$a('cache metric_key column',                    str_contains($mig, 'metric_key'));
$a('numeric_value column DECIMAL(18,4)',         str_contains($mig, 'numeric_value   DECIMAL(18,4)'));
$a('json_value JSON column',                     str_contains($mig, 'json_value      JSON'));
$a('source_hash CHAR(64) (sha256)',              str_contains($mig, 'source_hash     CHAR(64)'));
$a('computed_at + computed_ms tracking',         str_contains($mig, 'computed_at') && str_contains($mig, 'computed_ms'));
$a('UNIQUE on (tenant, scope, metric)',          str_contains($mig, 'UNIQUE KEY uq_fsc_scope_metric (tenant_id, scope_key, scope_value, metric_key)'));
$a('index for scope reads',                      str_contains($mig, 'ix_fsc_tenant_scope'));
$a('index for metric reads',                     str_contains($mig, 'ix_fsc_tenant_metric'));
$a('dirty-log has reason column',                str_contains($mig, 'reason          VARCHAR(120)'));
$a('dirty-log has marked_at timestamp',          str_contains($mig, 'marked_at       TIMESTAMP'));
$a('dirty-log has marked_by_user_id',            str_contains($mig, 'marked_by_user_id BIGINT'));
$a('utf8mb4_unicode_ci on both tables',          substr_count($mig, 'utf8mb4_unicode_ci') >= 2);
$a('Cloudways-compatible (no JSON-only feats)',  !str_contains($mig, 'JSON_TABLE') && !str_contains($mig, 'GENERATED ALWAYS'));

// ── Library core/financial_state_cache.php ───────────────────────────
echo "\ncore/financial_state_cache.php library\n";
$libPath = "{$ROOT}/core/financial_state_cache.php";
$a('library exists',                             is_file($libPath));
$a('library parses',
   (int) shell_exec('php -l ' . escapeshellarg($libPath) . ' >/dev/null 2>&1; echo $?') === 0);
$lib = (string) file_get_contents($libPath);

$a('declare strict_types',                       str_contains($lib, 'declare(strict_types=1)'));
$a('exposes FSC_SCOPE_PERIOD const',             str_contains($lib, "const FSC_SCOPE_PERIOD = 'period_id'"));
$a('exposes FSC_SCOPE_TENANT const',             str_contains($lib, "const FSC_SCOPE_TENANT = 'tenant'"));
$a('exposes FSC_SCOPE_ENTITY const',             str_contains($lib, "const FSC_SCOPE_ENTITY = 'entity_id'"));

foreach (['fscRead','fscWrite','fscMarkDirty','fscIsDirty','fscRebuild',
          'fscBuildPeriodAccountBalances','fscBuildPeriodKpis'] as $fn) {
    $a("function {$fn}() defined",
       preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', $lib) === 1);
}

$a('fscRead auto-rebuilds when dirty',           str_contains($lib, 'if ($autoRebuild && fscIsDirty(') && str_contains($lib, 'fscRebuild('));
$a('fscRead degrades when table missing',        str_contains($lib, 'catch (\Throwable $_)') && str_contains($lib, 'Table missing'));
$a('fscWrite uses ON DUPLICATE KEY UPDATE',      str_contains($lib, 'ON DUPLICATE KEY UPDATE'));
$a('fscMarkDirty silently survives no table',    str_contains($lib, "Migration 045 not run on this tenant"));
$a('fscRebuild dispatches on scope_key',         str_contains($lib, "case FSC_SCOPE_PERIOD:"));
$a('fscRebuild clears dirty log AFTER success',  preg_match('/fscBuildPeriodAccountBalances[\s\S]*fscBuildPeriodKpis[\s\S]*DELETE FROM financial_state_cache_dirty/', $lib) === 1);
$a('rebuild keeps dirty on builder throw',       str_contains($lib, "if the rebuilders") || str_contains($lib, 'leave the dirty entries'));
$a('rebuild reports metrics_written + ms',       str_contains($lib, "'metrics_written'") && str_contains($lib, "'ms'"));

$a('account-balance builder reads posted only',  str_contains($lib, 'je.status    = "posted"'));
$a('account-balance builder joins entries+lines',
    str_contains($lib, 'JOIN accounting_journal_entries     je') &&
    str_contains($lib, 'FROM accounting_journal_entry_lines l'));
$a('account-balance builder groups by account',  str_contains($lib, 'GROUP BY l.account_id'));
$a('account-balance directional math (debit normal)',
    str_contains($lib, "side === 'credit' ? (\$credit - \$debit) : (\$debit - \$credit)"));
$a('account-balance computes sha256 source_hash',str_contains($lib, "hash('sha256'"));
$a('account-balance writes metric_key account_balance.{id}',
    str_contains($lib, '"account_balance.{$accId}"'));

$a('kpi builder reads from cache (not raw)',     str_contains($lib, 'WHERE tenant_id = :t AND scope_key = :sk AND scope_value = :sv') && str_contains($lib, 'metric_key LIKE "account_balance.%"'));
$a('kpi builder writes revenue.posted',          str_contains($lib, "'revenue.posted'"));
$a('kpi builder writes expense.posted',          str_contains($lib, "'expense.posted'"));
$a('kpi builder writes net_income',              str_contains($lib, "'net_income'"));
$a('kpi builder writes asset/liability/equity balances',
    str_contains($lib, "'asset_balance'") && str_contains($lib, "'liability_balance'") && str_contains($lib, "'equity_balance'"));
$a('net_income = revenue - expense',             str_contains($lib, "totals['revenue'] - \$totals['expense']"));

// ── Endpoint api/financial_state.php ─────────────────────────────────
echo "\napi/financial_state.php endpoint\n";
$apiPath = "{$ROOT}/api/financial_state.php";
$a('api/financial_state.php exists',             is_file($apiPath));
$a('api/financial_state.php parses',
   (int) shell_exec('php -l ' . escapeshellarg($apiPath) . ' >/dev/null 2>&1; echo $?') === 0);
$apiSrc = (string) file_get_contents($apiPath);

$a('requires api_bootstrap',                     str_contains($apiSrc, "require_once __DIR__ . '/../core/api_bootstrap.php'"));
$a('requires financial_state_cache library',     str_contains($apiSrc, "require_once __DIR__ . '/../core/financial_state_cache.php'"));
$a('calls api_require_auth()',                   str_contains($apiSrc, 'api_require_auth()'));
$a('extracts tenant_id from ctx',                str_contains($apiSrc, "ctx['tenant_id']"));
$a('GET requires scope_key + scope_value',       str_contains($apiSrc, "scope_key and scope_value are required") && str_contains($apiSrc, '422'));
$a('GET supports single-metric lookup',          str_contains($apiSrc, 'metric_key'));
$a('GET returns 404 on missing metric',          str_contains($apiSrc, "'metric not found in cache', 404"));
$a('GET returns was_dirty + dirty_now',          str_contains($apiSrc, "'was_dirty'") && str_contains($apiSrc, "'dirty_now'"));
$a('POST action=rebuild route',                  str_contains($apiSrc, "action === 'rebuild'"));
$a('POST action=mark_dirty route',               str_contains($apiSrc, "action === 'mark_dirty'"));
$a('POST mark_dirty passes user_id',             str_contains($apiSrc, 'fscMarkDirty($tenantId, $scopeKey, $scopeValue, (string) $reason, $userId)'));
$a('POST unknown action → 422',                  str_contains($apiSrc, "Unknown action. Use \"mark_dirty\" or \"rebuild\""));
$a('rejects non-GET/POST methods',               str_contains($apiSrc, "Method not allowed', 405"));

// ── Event hook integration ───────────────────────────────────────────
echo "\nEvent hook — accountingPostJe / accountingReverseJe fire fscMarkDirty\n";
$acc = (string) file_get_contents("{$ROOT}/modules/accounting/lib/accounting.php");
$a('accounting.php requires fsc library',        str_contains($acc, "require_once __DIR__ . '/../../../core/financial_state_cache.php'"));
$a('postJe marks period dirty after commit',
    preg_match('/\$pdo->commit\(\);[\s\S]{0,400}fscMarkDirty\(\s*\$tenantId,\s*FSC_SCOPE_PERIOD/', $acc) === 1);
$a('postJe uses je_posted reason',               str_contains($acc, "'je_posted'"));
$a('postJe wraps fscMarkDirty in try/catch',
    preg_match('/try\s*\{\s*fscMarkDirty\([\s\S]+?never block the post/', $acc) === 1);
$a('postJe only marks when $post is true',
    preg_match('/if\s*\(\s*\$post\s*&&\s*isset\(\$period\[.id.\]\)\s*\)\s*\{[\s\S]{0,200}fscMarkDirty\(/', $acc) === 1);

$a('reverseJe marks original period dirty',
    preg_match('/UPDATE accounting_journal_entries SET reverses_je_id[\s\S]{0,400}fscMarkDirty\(\s*\$tenantId,\s*FSC_SCOPE_PERIOD,\s*\(string\) \$je\[.period_id.\]/', $acc) === 1);
$a('reverseJe uses je_reversed reason',          str_contains($acc, "'je_reversed'"));
$a('reverseJe wraps fscMarkDirty in try/catch',
    preg_match('/try\s*\{\s*fscMarkDirty\([\s\S]+?never block the reversal/', $acc) === 1);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
