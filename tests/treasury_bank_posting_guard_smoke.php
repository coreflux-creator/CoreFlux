<?php
/** No-database checks for statement-line posting guardrails. */
declare(strict_types=1);

require_once __DIR__ . '/../modules/treasury/lib/bank_posting.php';
require_once __DIR__ . '/../modules/treasury/lib/statement_state.php';
require_once __DIR__ . '/../modules/accounting/lib/bank_rec.php';

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$rejects = static function (callable $action): bool {
    try {
        $action();
        return false;
    } catch (InvalidArgumentException|RuntimeException) {
        return true;
    }
};

$account = [
    'code' => '6100', 'account_type' => 'expense', 'is_postable' => 1,
    'currency' => 'USD', 'linked_bank_id' => null,
];
$check('ordinary expense is eligible', !$rejects(static fn() => treasuryAssertCategoryCounterpart($account)));
$check('ordinary balance-sheet account is eligible', !$rejects(static fn() => treasuryAssertCategoryCounterpart(
    ['code' => '1600', 'is_postable' => 1, 'currency' => null, 'linked_bank_id' => null]
)));
foreach ([
    'source-owned AR' => ['code' => '1100'],
    'source-owned AP' => ['code' => '2000'],
    'equity account' => ['code' => '3900', 'account_type' => 'equity'],
    'bank-linked cash' => ['linked_bank_id' => 18],
    'summary account' => ['is_postable' => 0],
    'non-USD account' => ['currency' => 'EUR'],
] as $label => $override) {
    $check($label . ' rejected', $rejects(static fn() => treasuryAssertCategoryCounterpart(
        array_replace($account, $override)
    )));
}

$journal = [
    'status' => 'posted', 'source_module' => 'treasury_feed', 'posting_date' => '2026-10-02',
    'entity_id' => 7, 'currency' => 'USD',
];
$lines = [
    ['account_id' => 10, 'debit' => '15.00', 'credit' => '0.00'],
    ['account_id' => 40, 'debit' => '0.00', 'credit' => '15.00'],
];
$assertJournal = static fn(array $j, array $l) => treasuryAssertCategorizationJournal(
    $j, $l, '2026-10-02', 7, 10, 40, 1500
);
$check('balanced requested journal accepted', !$rejects(static fn() => $assertJournal($journal, $lines)));
$check('draft journal rejected', $rejects(static fn() => $assertJournal(array_replace($journal, ['status' => 'draft']), $lines)));
$check('unrelated source rejected', $rejects(static fn() => $assertJournal(array_replace($journal, ['source_module' => 'manual']), $lines)));
$check('different date rejected', $rejects(static fn() => $assertJournal(array_replace($journal, ['posting_date' => '2026-10-03']), $lines)));
$check('different entity rejected', $rejects(static fn() => $assertJournal(array_replace($journal, ['entity_id' => 8]), $lines)));
$check('different currency rejected', $rejects(static fn() => $assertJournal(array_replace($journal, ['currency' => 'EUR']), $lines)));
$check('different counterpart rejected', $rejects(static fn() => $assertJournal($journal, [
    $lines[0], array_replace($lines[1], ['account_id' => 41]),
])));
$check('one-cent drift rejected', $rejects(static fn() => $assertJournal($journal, [
    $lines[0], array_replace($lines[1], ['credit' => '14.99']),
])));
$check('swapped debit and credit rejected', $rejects(static fn() => $assertJournal($journal, [
    array_replace($lines[0], ['debit' => '0.00', 'credit' => '15.00']), $lines[1],
])));
$check('extra journal line rejected', $rejects(static fn() => $assertJournal($journal, [
    ...$lines, ['account_id' => 50, 'debit' => '0.00', 'credit' => '0.01'],
])));

$splitLines = [
    ['account_id' => 10, 'debit' => '15.00', 'credit' => '0.00', 'counterparty_entity_id' => null],
    ['account_id' => 40, 'debit' => '0.00', 'credit' => '10.00', 'counterparty_entity_id' => null],
    ['account_id' => 41, 'debit' => '0.00', 'credit' => '5.00', 'counterparty_entity_id' => 8],
];
$splits = [
    ['account_id' => 40, 'amount' => 10.00, 'counterparty_entity_id' => null],
    ['account_id' => 41, 'amount' => 5.00, 'counterparty_entity_id' => 8],
];
$assertSplit = static fn(array $j, array $l, array $s) => treasuryAssertSplitCategorizationJournal(
    $j, $l, '2026-10-02', 7, 10, 1500, $s
);
$check('exact split accepted', !$rejects(static fn() => $assertSplit($journal, $splitLines, $splits)));
$check('changed split amount rejected', $rejects(static fn() => $assertSplit($journal, $splitLines, [
    $splits[0], array_replace($splits[1], ['amount' => 4.00]),
])));
$check('changed split account rejected', $rejects(static fn() => $assertSplit($journal, $splitLines, [
    $splits[0], array_replace($splits[1], ['account_id' => 42]),
])));
$check('changed counterparty entity rejected', $rejects(static fn() => $assertSplit($journal, $splitLines, [
    $splits[0], array_replace($splits[1], ['counterparty_entity_id' => 9]),
])));
$check('extra split journal line rejected', $rejects(static fn() => $assertSplit($journal, [
    ...$splitLines, ['account_id' => 42, 'debit' => 0, 'credit' => 0, 'counterparty_entity_id' => null],
], $splits)));

$check('ordinary reconciled bank journal may be unlinked', bankRecUnmatchBlocker([
    'id' => 12, 'source_module' => 'manual', 'source_ref_type' => 'journal_entry', 'source_ref_id' => 8,
], false) === null);
$check('bank-line journal cannot be unlinked', bankRecUnmatchBlocker([
    'id' => 12, 'source_module' => 'treasury_feed', 'source_ref_type' => 'bank_statement_line', 'source_ref_id' => 12,
], false) !== null);
$check('event-linked bank-line journal cannot be unlinked', bankRecUnmatchBlocker([
    'id' => 12, 'source_module' => 'treasury_feed', 'source_ref_type' => null, 'source_ref_id' => null,
], true) !== null);
$check('Treasury journal without optional source link still cannot be unlinked', bankRecUnmatchBlocker([
    'id' => 12, 'source_module' => 'treasury_feed', 'source_ref_type' => null, 'source_ref_id' => null,
], false) !== null);
$check('processor payout cannot be unlinked', bankRecUnmatchBlocker([
    'id' => 12, 'source_module' => 'billing', 'source_ref_type' => 'processor_payout', 'source_ref_id' => 2,
], false) !== null);
$check('liability journal created from line cannot be unlinked', treasuryLiabilityUnmatchBlocker(12, [
    'source_ref_type' => 'liability_statement_line', 'source_ref_id' => 12,
], false) !== null);
$check('event-linked liability journal cannot be unlinked', treasuryLiabilityUnmatchBlocker(12, [], true) !== null);
$check('Treasury liability journal without optional source link still cannot be unlinked',
    treasuryLiabilityUnmatchBlocker(12, ['source_module' => 'treasury_feed'], false) !== null);
$check('unrelated liability journal may be unlinked', treasuryLiabilityUnmatchBlocker(12, [
    'source_ref_type' => 'liability_statement_line', 'source_ref_id' => 13,
], false) === null);
$check('first posting preserves existing event and journal identities',
    treasuryStatementSourceId('deposit', 12, false, 1) === 'bank_line:12'
    && treasuryStatementPostingKey('deposit', 12, false, 1) === 'treasury_feed:deposit:12');
$check('corrected split uses fresh source and journal identities',
    treasuryStatementSourceId('deposit', 12, true, 2) === 'bank_line:split:attempt:2:12'
    && treasuryStatementPostingKey('deposit', 12, true, 2) === 'treasury_feed_split:deposit:12:attempt:2');
$check('attempt source is scoped to one line and account type',
    treasuryStatementSourceBelongsToLine('liab_line:attempt:3:12', 'liability', 12)
    && !treasuryStatementSourceBelongsToLine('liab_line:attempt:3:12', 'deposit', 12)
    && !treasuryStatementSourceBelongsToLine('liab_line:attempt:3:12', 'liability', 13));

$api = (string) file_get_contents(__DIR__ . '/../modules/treasury/api/account_transactions.php');
$check('endpoint locks line before posting', str_contains($api, 'SELECT * FROM {$table} WHERE tenant_id = :t AND id = :id FOR UPDATE'));
$check('event failure rolls back to savepoint', str_contains($api, 'ROLLBACK TO SAVEPOINT treasury_categorize_event'));
$check('deposit post uses validated match', str_contains($api, 'bankRecMatchLine($tenantId, $lineId, (int) $res[\'je_id\']'));
$check('post and match share transaction', str_contains($api, 'cf_tx_commit($pdo, $ownsTransaction)')
    && str_contains($api, 'cf_tx_rollback($pdo, $ownsTransaction)'));
$check('currency and active bank checked', str_contains($api, 'This posting path supports USD bank accounts only')
    && str_contains($api, "\$bankState['status'] !== 'active'"));
$check('liability side currency checked', str_contains($api, 'This posting path supports USD liability accounts only'));
$check('event idempotency conflict cannot fall back to another journal',
    str_contains($api, 'if ($e instanceof AccountingEventConflictException) throw $e;'));
$check('split posting locks and validates allocations', str_contains($api, 'SAVEPOINT treasury_split_event')
    && str_contains($api, 'ROLLBACK TO SAVEPOINT treasury_split_event')
    && str_contains($api, 'treasuryAssertSplitCategorizationJournal('));
$check('split post and match share transaction', str_contains($api, 'bankRecMatchLine($tenantId, $lineId, (int) $res[\'je_id\']')
    && substr_count($api, 'cf_tx_commit($pdo, $ownsTransaction)') >= 2);
$check('single-row ignore refuses matched lines and uses a conditional update',
    str_contains($api, "if (\$line['match_status'] === 'matched')")
    && str_contains($api, "AND id = :id AND match_status = 'unmatched'"));
$state = (string) file_get_contents(__DIR__ . '/../modules/treasury/lib/statement_state.php');
$check('liability unmatch locks and protects source-owned journals',
    str_contains($state, 'function treasuryUnmatchLiabilityLine(')
    && str_contains($state, 'treasuryLiabilityUnmatchBlocker($lineId, $journal, $hasTreasuryLineage)'));
$check('liability match validates posted entity currency and account movement',
    str_contains($state, 'function treasuryMatchLiabilityLine(')
    && str_contains($state, "\$journal['status'] !== 'posted'")
    && str_contains($state, "\$journal['entity_id']")
    && str_contains($state, 'SUM(debit - credit)'));
$migration = (string) file_get_contents(__DIR__ . '/../modules/treasury/migrations/008_statement_corrections.sql');
$check('correction audit has unique line attempts and original journals',
    str_contains($migration, 'uq_tsc_attempt') && str_contains($migration, 'uq_tsc_original_je'));
$check('Treasury correction atomically reverses event journal and statement match',
    str_contains($state, 'function treasuryCorrectCategorization(')
    && str_contains($state, 'accountingReverseJe(')
    && str_contains($state, 'UPDATE accounting_events SET status = "reversed"')
    && str_contains($state, 'INSERT INTO treasury_statement_corrections'));
$check('Treasury API uses attempt identity for both posting modes',
    str_contains($api, 'treasuryStatementPostingKey($type, $lineId, true, $postingAttempt)')
    && str_contains($api, 'treasuryStatementPostingKey($type, $lineId, false, $postingAttempt)')
    && str_contains($api, 'if ($action === \'correct_categorization\')'));

$coa = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/accounts.php');
$ai = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/bank_ai.php');
$ui = (string) file_get_contents(__DIR__ . '/../modules/treasury/ui/AccountTransactions.jsx');
$check('chart marks direct-post-safe categories', str_contains($coa, 'direct_category_eligible')
    && str_contains($coa, 'accountingDirectCategoryIssue($account)'));
$check('AI is limited to direct-post-safe categories', str_contains($ai, 'accountingDirectCategoryIssue($account)'));
$check('Treasury picker uses eligibility flag', str_contains($ui, 'a.direct_category_eligible'));
$check('Treasury hides invalid unmatch actions', str_contains($ui, 'r.match_status === \'matched\' && !r.unmatch_blocker')
    && str_contains($ui, 'Source-managed'));
$check('Treasury correction requires a reason and exposes the reversal history',
    str_contains($ui, 'treasury-txn-correction-row-')
    && str_contains($ui, 'correctionReason.trim()')
    && str_contains($ui, 'View reversal'));

echo "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed ? 1 : 0);
