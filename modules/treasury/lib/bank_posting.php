<?php
/** Guardrails for posting one statement line to one counterpart account. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/accounting/control_accounts.php';

function treasuryAssertCategoryCounterpart(array $account): void
{
    $issue = accountingDirectCategoryIssue($account);
    if ($issue !== null) throw new InvalidArgumentException($issue);
}

function treasuryAssertCategorizationJournal(
    array $journal,
    array $lines,
    string $postingDate,
    int $entityId,
    int $debitAccountId,
    int $creditAccountId,
    int $amountCents
): void {
    if (($journal['status'] ?? null) !== 'posted'
        || ($journal['source_module'] ?? null) !== 'treasury_feed'
        || (string) ($journal['posting_date'] ?? '') !== $postingDate
        || (int) ($journal['entity_id'] ?? 0) !== $entityId
        || strtoupper((string) ($journal['currency'] ?? '')) !== 'USD') {
        throw new RuntimeException('The journal does not match this statement line. No new posting was saved.');
    }
    if ($amountCents <= 0 || $debitAccountId <= 0 || $creditAccountId <= 0
        || $debitAccountId === $creditAccountId || count($lines) !== 2) {
        throw new RuntimeException('The journal has an unexpected shape. No new posting was saved.');
    }

    $expected = [
        $debitAccountId => [$amountCents, 0],
        $creditAccountId => [0, $amountCents],
    ];
    foreach ($lines as $line) {
        $accountId = (int) ($line['account_id'] ?? 0);
        $amounts = $expected[$accountId] ?? null;
        if (!$amounts
            || (int) round((float) ($line['debit'] ?? 0) * 100) !== $amounts[0]
            || (int) round((float) ($line['credit'] ?? 0) * 100) !== $amounts[1]) {
            throw new RuntimeException('The journal accounts or amounts do not match this statement line. No new posting was saved.');
        }
        unset($expected[$accountId]);
    }
    if ($expected) {
        throw new RuntimeException('The journal is missing an expected account. No new posting was saved.');
    }
}

function treasuryAssertSplitCategorizationJournal(
    array $journal,
    array $lines,
    string $postingDate,
    int $entityId,
    int $sideAccountId,
    int $signedAmountCents,
    array $splits
): void {
    if (($journal['status'] ?? null) !== 'posted'
        || ($journal['source_module'] ?? null) !== 'treasury_feed'
        || (string) ($journal['posting_date'] ?? '') !== $postingDate
        || (int) ($journal['entity_id'] ?? 0) !== $entityId
        || strtoupper((string) ($journal['currency'] ?? '')) !== 'USD'
        || $sideAccountId <= 0 || $signedAmountCents === 0) {
        throw new RuntimeException('The split journal does not match this statement line. Nothing was saved.');
    }

    $expected = [[
        $sideAccountId, max($signedAmountCents, 0), max(-$signedAmountCents, 0), 0,
    ]];
    foreach ($splits as $split) {
        $amountCents = (int) round((float) ($split['amount'] ?? 0) * 100);
        $accountId = (int) ($split['account_id'] ?? 0);
        if ($accountId <= 0 || $accountId === $sideAccountId || $amountCents <= 0) {
            throw new RuntimeException('The split allocation is invalid. Nothing was saved.');
        }
        $expected[] = [
            $accountId,
            $signedAmountCents < 0 ? $amountCents : 0,
            $signedAmountCents > 0 ? $amountCents : 0,
            (int) ($split['counterparty_entity_id'] ?? 0),
        ];
    }
    if (count($lines) !== count($expected)) {
        throw new RuntimeException('The split journal has a different number of allocations. Nothing was saved.');
    }
    $actual = array_map(static fn(array $line): array => [
        (int) ($line['account_id'] ?? 0),
        (int) round((float) ($line['debit'] ?? 0) * 100),
        (int) round((float) ($line['credit'] ?? 0) * 100),
        (int) ($line['counterparty_entity_id'] ?? 0),
    ], $lines);
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
        throw new RuntimeException('The split journal accounts or amounts changed. Nothing was saved.');
    }
}
