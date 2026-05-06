<?php
/**
 * Sprint 6h — Idempotent migration runner + Treasury bank-feed AI cat. /
 * Split-IC + structured AI rendering + time_entries.person_id backfill.
 *
 *   php -d zend.assertions=1 /app/tests/sprint6h_treasury_ai_split_smoke.php
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, bool $cond, ?string $hint = null) use (&$pass, &$fail): void {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}" . ($hint ? "  ({$hint})" : '') . "\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Migration runner — idempotent / per-statement recovery\n";
$mr = (string) file_get_contents("{$ROOT}/core/installer_helpers.php");
$assert('installer_helpers.php parses',                 $lint("{$ROOT}/core/installer_helpers.php"));
$assert('splits SQL on per-statement boundaries',       stripos($mr, "preg_split('/;\\\\s*\\\\R/m'") !== false);
$assert('catches Throwable per-statement',              preg_match('#try\s*\{\s*\$pdo->exec\(\$clean\);\s*\}\s*catch\s*\(\s*\\\\Throwable\s+\$e\s*\)#', $mr) === 1);
$assert('safePatterns includes "Duplicate column name"',stripos($mr, "'Duplicate column name'") !== false);
$assert('safePatterns includes "Duplicate key name"',   stripos($mr, "'Duplicate key name'") !== false);
$assert('safePatterns includes "already exists"',       stripos($mr, "'already exists'") !== false);
$assert("safePatterns includes \"Can't DROP\"",         stripos($mr, "Can't DROP") !== false);
$assert('returns applied_with_skips status',            stripos($mr, "'applied_with_skips'") !== false);
$assert('hard-error log surfaces failed migration',     stripos($mr, "'status' => 'failed'") !== false);
$assert('strips comment-only lines before exec',        stripos($mr, "preg_replace('/^\\\\s*--.*\$/m'") !== false);

echo "\ntime_entries.person_id backfill migration\n";
$be = (string) file_get_contents("{$ROOT}/modules/time/migrations/007_backfill_person_id.sql");
$assert('migration file exists',                        strlen($be) > 0);
$assert('adds person_id column',                        stripos($be, 'ADD COLUMN person_id') !== false);
$assert('backfills from placements.worker_id',          stripos($be, 'placements p ON p.id = te.placement_id') !== false
                                                      && stripos($be, 'p.worker_id') !== false);
$assert('idempotent comment present',                   stripos($be, 'Duplicate column name') !== false);
$assert('adds compound index',                          stripos($be, 'idx_te_tenant_person_date') !== false);

echo "\nTreasury account_transactions API — split_categorize\n";
$at = (string) file_get_contents("{$ROOT}/modules/treasury/api/account_transactions.php");
$assert('account_transactions.php parses',              $lint("{$ROOT}/modules/treasury/api/account_transactions.php"));
$assert('handles ?action=split_categorize',             stripos($at, "\$action === 'split_categorize'") !== false);
$assert('rejects when splits empty',                    stripos($at, 'At least one split row required') !== false);
$assert('validates sum vs line amount',                 stripos($at, "Splits sum to {\$sum} but line amount is {\$abs}") !== false);
$assert('supports per-row entity_id (intercompany)',    preg_match("#'entity_id'\\s*=>.*\\\$s\\['entity_id'\\]#", $at) === 1);
$assert('posts ONE balanced JE via accountingPostJe',   stripos($at, 'accountingPostJe(') !== false);
$assert('idempotency_key uses treasury_feed_split prefix',
                                                        stripos($at, 'treasury_feed_split:') !== false);
$assert('marks line matched after post',                preg_match("#UPDATE.*SET match_status\\s*=\\s*'matched'#s", $at) === 1);

echo "\nTreasury UI — AI cat + Split/IC affordances\n";
$tx = (string) file_get_contents("{$ROOT}/modules/treasury/ui/AccountTransactions.jsx");
$assert('AI cat button per row',                        stripos($tx, 'data-testid={`treasury-txn-ai-cat-${r.id}`}') !== false);
$assert('Split/IC button per row',                      stripos($tx, 'data-testid={`treasury-txn-split-${r.id}`}') !== false);
$assert('fetchAiCat hits bank_ai endpoint',             stripos($tx, '/modules/accounting/api/bank_ai.php?action=suggest_categorize') !== false);
$assert('split panel posts to split_categorize',        stripos($tx, '/modules/treasury/api/account_transactions.php?action=split_categorize') !== false);
$assert('TreasuryAiResultPanel reads ai.suggestion shape',
                                                        stripos($tx, 'const sug = ai.suggestion') !== false
                                                      && stripos($tx, 'sug.suggested_account_id') !== false);
$assert('confidence percentage rendered',               stripos($tx, '{conf}% · {source}') !== false);
$assert('Accept & post button testid',                  stripos($tx, 'treasury-ai-result-accept-') !== false);
$assert('SplitIcPanel sums vs line amount before post', stripos($tx, 'balanced = Math.abs(sum - total) < 0.005') !== false);
$assert('SplitIcPanel surfaces "balanced" status',      stripos($tx, "'✓ balanced'") !== false);
$assert('SplitIcPanel supports intercompany entity_id', stripos($tx, 'placeholder="entity id"') !== false);
$assert('split row testids per index',                  stripos($tx, 'treasury-txn-split-account-${line.id}-${i}') !== false
                                                      && stripos($tx, 'treasury-txn-split-amount-${line.id}-${i}') !== false);

echo "\nBank Reconciliation UI — AI cat result reads nested suggestion\n";
$br = (string) file_get_contents("{$ROOT}/modules/accounting/ui/BankReconciliation.jsx");
$assert('AiResultPanel reads ai.suggestion shape',      stripos($br, 'const sug = ai.suggestion') !== false);
$assert('renders confidence chip',                      stripos($br, '{conf}% confidence · {source}') !== false);
$assert('Accept-and-post posts categorize_and_post',    stripos($br, "/modules/accounting/api/account_transactions.php?action=categorize_and_post") !== false);
$assert('Accept passes counterpart_account_id correctly',stripos($br, 'counterpart_account_id: suggestedAccountId') !== false);
$assert('No more raw JSON.stringify dump',              stripos($br, 'JSON.stringify(aiResp.candidates') === false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
