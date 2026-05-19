<?php
/**
 * Hardening smoke — migration runner + bootstrap auto-apply +
 * AI saved-rules pipeline + bank_ai 500 hardening.
 *
 * All static-string checks. Real DB integration sits in /tests/integration
 * (future work).
 */
declare(strict_types=1);

$assertCount = 0; $failCount = 0;
function _h(string $label, bool $cond, ?string $hint = null): void {
    global $assertCount, $failCount;
    $assertCount++;
    if ($cond) {
        echo "  ok  $label\n";
    } else {
        $failCount++;
        echo "FAIL  $label" . ($hint ? "  ($hint)" : '') . "\n";
    }
}

echo "Migration runner — coreflux_run_migrations()\n";
$mig = (string) file_get_contents(__DIR__ . '/../core/migrate.php');
_h('migrate.php exists',                                $mig !== '');
_h('declares coreflux_run_migrations',                  str_contains($mig, 'function coreflux_run_migrations'));
_h('creates _migrations ledger table',                  str_contains($mig, 'CREATE TABLE IF NOT EXISTS _migrations'));
_h('hashes file content (sha256)',                      str_contains($mig, "hash('sha256'"));
_h('skips files with unchanged hash',                   str_contains($mig, '$prev === $hash'));
_h('handles "Duplicate column name" idempotently',      str_contains($mig, 'Duplicate column name'));
_h('handles "already exists" idempotently',             str_contains($mig, 'already exists'));
_h('per-process cache via static $ranOnce',             str_contains($mig, 'static $ranOnce'));
_h('CLI entry point',                                   str_contains($mig, 'PHP_SAPI === \'cli\''));

echo "\nBootstrap wiring\n";
$boot = (string) file_get_contents(__DIR__ . '/../core/api_bootstrap.php');
_h('api_bootstrap requires migrate.php',                str_contains($boot, "require_once __DIR__ . '/migrate.php'"));
_h('api_bootstrap calls runner non-fatally',            str_contains($boot, 'try { coreflux_run_migrations(); }'));

echo "\nMigration 010 split into atomic ALTERs (no IF NOT EXISTS dependency)\n";
$mig010 = (string) file_get_contents(__DIR__ . '/../core/migrations/010_plaid_account_balances.sql');
_h('010 has 5 separate ALTER lines',                    substr_count($mig010, 'ALTER TABLE plaid_accounts ADD COLUMN') >= 5);
_h('010 does NOT use ADD COLUMN IF NOT EXISTS',         !str_contains($mig010, 'ADD COLUMN IF NOT EXISTS'));

echo "\nAI rules visibility — migration 011\n";
$mig011 = (string) file_get_contents(__DIR__ . '/../core/migrations/011_ai_categorization_rules_visibility.sql');
_h('011 exists',                                        $mig011 !== '');
_h('adds reject_count',                                 str_contains($mig011, 'reject_count'));
_h('adds disabled_at',                                  str_contains($mig011, 'disabled_at'));
_h('atomic ALTER per column',                           substr_count($mig011, 'ALTER TABLE ai_categorization_history ADD COLUMN') >= 5);

echo "\nReject-tracking helper + history skips disabled/contested rules\n";
$cat = (string) file_get_contents(__DIR__ . '/../core/ai_categorization.php');
_h('aiRecordCategorizationReject defined',              str_contains($cat, 'function aiRecordCategorizationReject'));
_h('history query filters disabled rules',              str_contains($cat, 'disabled_at IS NULL'));
_h('history query enforces positive net score',         str_contains($cat, '(accept_count - COALESCE(reject_count,0)) > 0'));
_h('history query orders by net score',                 str_contains($cat, 'ORDER BY (accept_count - COALESCE(reject_count,0)) DESC'));
_h('reject penalty in confidence calc',                 str_contains($cat, '$penalty'));

echo "\naccount_transactions records reject when override differs from suggestion\n";
$at = (string) file_get_contents(__DIR__ . '/../modules/treasury/api/account_transactions.php');
_h('reject path queries ai_suggestions',                str_contains($at, "FROM ai_suggestions"));
_h('reject path calls aiRecordCategorizationReject',    str_contains($at, 'aiRecordCategorizationReject'));
_h('reject only fires when ids differ',                 str_contains($at, '!== $counterId'));

echo "\nai_categorization_rules.php endpoint (saved-rules UI backend)\n";
$rules = (string) file_get_contents(__DIR__ . '/../api/ai_categorization_rules.php');
_h('endpoint exists',                                   $rules !== '');
_h('GET joins accounting_accounts for display',         str_contains($rules, 'JOIN accounting_accounts'));
_h('GET decorates auto_apply_eligible flag',            str_contains($rules, 'auto_apply_eligible'));
_h('PATCH handles disable/enable',                      str_contains($rules, "if (\$method === 'PATCH'"));
_h('DELETE forgets a rule',                             str_contains($rules, "if (\$method === 'DELETE')"));
_h('permission gated on accounting.je.create',          str_contains($rules, "rbac_legacy_require(\$ctx['user'], 'accounting.je.create')"));

echo "\nSavedRules UI page wired into Treasury module\n";
$tm = (string) file_get_contents(__DIR__ . '/../modules/treasury/ui/TreasuryModule.jsx');
_h('TreasuryModule imports SavedRules',                 str_contains($tm, "import SavedRules"));
_h('TreasuryModule has /rules route',                   str_contains($tm, 'path="rules"'));
_h('TreasuryModule has Saved Rules tab',                str_contains($tm, 'Saved Rules'));

$sr = (string) file_get_contents(__DIR__ . '/../modules/treasury/ui/SavedRules.jsx');
_h('SavedRules calls /api/ai_categorization_rules',     str_contains($sr, '/api/ai_categorization_rules.php'));
_h('SavedRules has Mute/Unmute toggle',                 str_contains($sr, "saved-rule-toggle-"));
_h('SavedRules has Forget action',                      str_contains($sr, "saved-rule-forget-"));
_h('SavedRules surfaces auto-apply badge',              str_contains($sr, 'saved-rule-status-auto-'));
_h('SavedRules surfaces contested badge',               str_contains($sr, "contested"));

echo "\nbank_ai.php — graceful AI fallback (no more 500)\n";
$ba = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/bank_ai.php');
_h('suggest_match wraps aiAsk in try/catch',            str_contains($ba, 'AIDisabledException'));
_h('suggest_categorize catches Throwable',              substr_count($ba, 'ai_unavailable') >= 3);
_h('suggest_rule catches Throwable',                    substr_count($ba, 'catch (\\Throwable') >= 3);

echo "\nDeposit/liability detail navigation — absolute paths only\n";
$dep = (string) file_get_contents(__DIR__ . '/../modules/treasury/ui/DepositAccounts.jsx');
$lia = (string) file_get_contents(__DIR__ . '/../modules/treasury/ui/LiabilityAccounts.jsx');
_h('Deposit row navigates absolute /modules/treasury/deposits/', str_contains($dep, '/modules/treasury/deposits/${r.id}'));
_h('Liability row navigates absolute /modules/treasury/liabilities/', str_contains($lia, '/modules/treasury/liabilities/${r.id}'));
_h('No "Open full reconciliation workspace" link',      !str_contains($dep, 'Open full reconciliation'));
_h('Deposit detail has explicit back link',             str_contains($dep, 'treasury-deposit-detail-back'));

echo "\n--- $assertCount assertions, $failCount failed ---\n";
exit($failCount === 0 ? 0 : 1);
