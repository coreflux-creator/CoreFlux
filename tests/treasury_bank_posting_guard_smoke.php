<?php
/** No-database checks for statement-line posting guardrails. */
declare(strict_types=1);

require_once __DIR__ . '/../modules/treasury/lib/bank_posting.php';

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

$account = ['code' => '6100', 'is_postable' => 1, 'currency' => 'USD', 'linked_bank_id' => null];
$check('ordinary expense is eligible', !$rejects(static fn() => treasuryAssertCategoryCounterpart($account)));
$check('ordinary balance-sheet account is eligible', !$rejects(static fn() => treasuryAssertCategoryCounterpart(
    ['code' => '1600', 'is_postable' => 1, 'currency' => null, 'linked_bank_id' => null]
)));
foreach ([
    'source-owned AR' => ['code' => '1100'],
    'source-owned AP' => ['code' => '2000'],
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

$coa = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/accounts.php');
$ai = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/bank_ai.php');
$ui = (string) file_get_contents(__DIR__ . '/../modules/treasury/ui/AccountTransactions.jsx');
$check('chart marks direct-post-safe categories', str_contains($coa, 'direct_category_eligible')
    && str_contains($coa, 'accountingDirectCategoryIssue($account)'));
$check('AI is limited to direct-post-safe categories', str_contains($ai, 'accountingDirectCategoryIssue($account)'));
$check('Treasury picker uses eligibility flag', str_contains($ui, 'a.direct_category_eligible'));

echo "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed ? 1 : 0);
