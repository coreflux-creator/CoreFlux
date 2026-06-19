<?php
/**
 * Sprint 7c.2 + 7d smoke — bank-feed replay + spec §38 URL aliases.
 *
 * Sprint 7c.2: Replay action (backfill cleared bank txs through engine
 *              for audit-ledger continuity)
 * Sprint 7d:   Kebab-case + module-namespaced aliases for accounting +
 *              treasury endpoints. Legacy paths preserved.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_router.php';
require_once __DIR__ . '/../core/ModuleRegistry.php';

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

echo "Sprint 7c.2 — Bank-feed replay endpoint\n";
$rep = (string) file_get_contents("{$ROOT}/api/posting_rules_replay.php");
$assert('replay endpoint exists',                strlen($rep) > 0);
$assert('parses',                                $lint("{$ROOT}/api/posting_rules_replay.php"));
$assert('admin-gated (manage_posting_rules)',    strpos($rep, "rbac_legacy_require(\$ctx['user'], 'accounting.manage_posting_rules')") !== false);
$assert('POST-only',                             strpos($rep, "if (api_method() !== 'POST')") !== false);
$assert('days clamped 1..365',                   strpos($rep, 'min(365, (int) (api_query') !== false);
$assert('since YYYY-MM-DD validated',            strpos($rep, "/^\\d{4}-\\d{2}-\\d{2}\$/") !== false);
$assert('iterates accounting_bank_statement_lines',
    strpos($rep, 'FROM accounting_bank_statement_lines') !== false);
$assert("emits source_module='treasury_replay'",
    strpos($rep, "'source_module'    => 'treasury_replay'") !== false);
$assert("emits event_type='treasury.bank_transaction.matched'",
    strpos($rep, "'treasury.bank_transaction.matched'") !== false);
$assert('source_record_id namespaced bank_line:',
    strpos($rep, "'bank_line:' . \$line['id']") !== false);
$assert('idempotency pre-check by source_record_id',
    strpos($rep, "AND source_module = 'treasury_replay'") !== false);
$assert('hydrates bank_gl_account_id into payload',
    strpos($rep, "'bank_gl_account_id'    => (int) \$bank['gl_account_id']") !== false);
$assert('dry_run path skips post',               strpos($rep, "if (\$dryRun) { \$out['replayed']++; continue; }") !== false);
$assert('returns shape: scanned/replayed/skipped/failed',
    strpos($rep, "'scanned'") !== false
    && strpos($rep, "'replayed'") !== false
    && strpos($rep, "'skipped_already_event'") !== false
    && strpos($rep, "'skipped_no_bank_gl'") !== false
    && strpos($rep, "'failed'") !== false);
$assert('errors[] truncated at 50',              strpos($rep, 'count($out[\'errors\']) > 50') !== false);

echo "\nSprint 7c.2 — RuleSandbox UI replay strip\n";
$jsx = (string) file_get_contents("{$ROOT}/dashboard/src/pages/RuleSandbox.jsx");
$assert('replay strip testid',                   strpos($jsx, 'data-testid="rule-sandbox-replay-strip"') !== false);
$assert('replay days select testid',             strpos($jsx, 'data-testid="rule-sandbox-replay-days"') !== false);
$assert('replay dry-run checkbox testid',        strpos($jsx, 'data-testid="rule-sandbox-replay-dry-run"') !== false);
$assert('replay run button testid',              strpos($jsx, 'data-testid="rule-sandbox-replay-run"') !== false);
$assert('replay result testid',                  strpos($jsx, 'data-testid="rule-sandbox-replay-result"') !== false);
$assert('replay error testid',                   strpos($jsx, 'data-testid="rule-sandbox-replay-error"') !== false);
$assert('hits v1 replay endpoint',
    strpos($jsx, "const POSTING_RULES_REPLAY_API = '/api/v1/accounting/posting-rules-replay'") !== false
    && strpos($jsx, 'api.post(POSTING_RULES_REPLAY_API + qs') !== false);
$assert('window options 7/30/90/180/365',
    strpos($jsx, 'value={7}>7d') !== false
    && strpos($jsx, 'value={30}>30d') !== false
    && strpos($jsx, 'value={365}>365d') !== false);

echo "\nSprint 7d — router accepts kebab-case endpoints\n";
$r = apiRouterParse('/accounting/journal-entries/123/post', '/api/accounting/journal-entries/123/post');
$assert('parses kebab-case endpoint name',       $r['ok'] === true);
$assert('module_id = accounting',                ($r['module_id'] ?? '') === 'accounting');
$assert('endpoint = journal-entries (preserved)', ($r['endpoint'] ?? '') === 'journal-entries');
$assert('subpath includes id and action',        ($r['subpath'] ?? []) === ['123', 'post']);

$r2 = apiRouterParse('/treasury/cash-position', '/api/treasury/cash-position');
$assert('treasury/cash-position parses',         $r2['ok'] === true);
$assert("endpoint = 'cash-position'",            ($r2['endpoint'] ?? '') === 'cash-position');

// Underscore form still works (back-compat)
$r3 = apiRouterParse('/treasury/cash_position', '/api/treasury/cash_position');
$assert('treasury/cash_position back-compat',    $r3['ok'] === true
                                               && ($r3['endpoint'] ?? '') === 'cash_position');

// Resolver maps kebab-case to snake_case file
$f = apiRouterResolveFile('treasury', 'cash-position');
$assert('kebab → snake_case .php fallback resolves',
    $f !== null && str_ends_with((string) $f, '/modules/treasury/api/cash_position.php'));

// Plus the new dedicated alias files exist
$aliases = [
    "{$ROOT}/modules/accounting/api/events.php",
    "{$ROOT}/modules/accounting/api/posting_rules_seed.php",
    "{$ROOT}/modules/accounting/api/posting_rules_replay.php",
    "{$ROOT}/modules/treasury/api/payments.php",
    "{$ROOT}/modules/treasury/api/transfers.php",
    "{$ROOT}/modules/treasury/api/cash_position.php",
];
foreach ($aliases as $a) {
    $assert('alias file exists: ' . str_replace($ROOT, '', $a), is_file($a));
    $assert('alias parses: '       . str_replace($ROOT, '', $a), $lint($a));
}

// Verify illegal characters still rejected (no path traversal)
$bad = apiRouterParse('/accounting/../etc/passwd', '/api/accounting/../etc/passwd');
$assert('rejects path traversal in endpoint',    $bad['ok'] === false);

$bad2 = apiRouterParse('/accounting/JournalEntries', '/api/accounting/JournalEntries');
$assert('rejects mixed-case (camel)',            $bad2['ok'] === false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
