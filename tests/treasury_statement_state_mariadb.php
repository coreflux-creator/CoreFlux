<?php
/** Rollback-only liability statement transitions on a disposable local schema. */
declare(strict_types=1);

$database = (string) getenv('DB_NAME');
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !preg_match('/^coreaccounting_blank_test\d+$/D', $database)) {
    fwrite(STDERR, "This test requires an isolated local staging schema.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/accounting/lib/accounting.php';
require_once __DIR__ . '/../modules/treasury/lib/statement_state.php';

$pdo = getDB();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
    throw new RuntimeException('Connected database does not match the requested test schema.');
}
$tenantId = (int) $pdo->query('SELECT id FROM tenants ORDER BY id LIMIT 1')->fetchColumn();
$entityId = (int) $pdo->query('SELECT id FROM accounting_entities ORDER BY id LIMIT 1')->fetchColumn();
if ($tenantId <= 0 || $entityId <= 0) throw new RuntimeException('Disposable tenant/entity missing.');
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$rejects = static function (callable $action): bool {
    try {
        $action();
        return false;
    } catch (RuntimeException $e) {
        return true;
    }
};
$before = (int) $pdo->query('SELECT COUNT(*) FROM treasury_liability_statement_lines')->fetchColumn();

$pdo->beginTransaction();
try {
    $code = '29' . random_int(100000, 999999);
    $pdo->prepare(
        'INSERT INTO accounting_accounts
            (tenant_id, code, name, account_type, normal_side, currency, is_postable, active)
         VALUES (:t, :code, "Rollback-only liability", "liability", "credit", "USD", 1, 1)'
    )->execute(['t' => $tenantId, 'code' => $code]);
    $accountId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO treasury_liability_accounts (tenant_id, account_id, entity_id, subtype)
         VALUES (:t, :account_id, :entity_id, "other_liability")'
    )->execute(['t' => $tenantId, 'account_id' => $accountId, 'entity_id' => $entityId]);

    $makeLine = static function (float $amount, string $status = 'unmatched') use ($pdo, $tenantId, $accountId): int {
        $pdo->prepare(
            'INSERT INTO treasury_liability_statement_lines
                (tenant_id, liability_account_id, posted_date, description, amount, match_status, fitid)
             VALUES (:t, :account_id, "2025-06-01", "Rollback-only test", :amount, :status, :fitid)'
        )->execute([
            't' => $tenantId, 'account_id' => $accountId, 'amount' => $amount,
            'status' => $status, 'fitid' => 'QA-' . bin2hex(random_bytes(8)),
        ]);
        return (int) $pdo->lastInsertId();
    };
    $post = static function (float $amount, ?int $sourceLineId = null) use ($tenantId, $entityId, $code): int {
        $payload = [
            'entity_id' => $entityId, 'posting_date' => '2025-06-01',
            'currency' => 'USD', 'memo' => 'Rollback-only liability match',
            'idempotency_key' => 'qa-liability-' . bin2hex(random_bytes(8)),
            'lines' => [
                ['account_code' => '6990', 'debit' => $amount, 'credit' => 0],
                ['account_code' => $code, 'debit' => 0, 'credit' => $amount],
            ],
        ];
        if ($sourceLineId !== null) {
            $payload['source_module'] = 'treasury_feed';
            $payload['source_ref_type'] = 'liability_statement_line';
            $payload['source_ref_id'] = $sourceLineId;
        }
        return (int) accountingPostJe($tenantId, $payload, null, true)['je_id'];
    };

    $lineId = $makeLine(-10.00);
    $otherLineId = $makeLine(-10.00);
    $wrongAmountLineId = $makeLine(-9.00);
    $journalId = $post(10.00);
    $check($rejects(static fn() => treasuryMatchLiabilityLine(
        $pdo, $tenantId, $wrongAmountLineId, $journalId, $entityId
    )), 'different statement amount is refused');
    $check($rejects(static fn() => treasuryMatchLiabilityLine(
        $pdo, $tenantId, $lineId, $journalId, $entityId + 1000
    )), 'different legal entity is refused');
    $pdo->prepare('UPDATE accounting_journal_entries SET currency = "EUR" WHERE id = :id')
        ->execute(['id' => $journalId]);
    $check($rejects(static fn() => treasuryMatchLiabilityLine(
        $pdo, $tenantId, $lineId, $journalId, $entityId
    )), 'different currency is refused');
    $pdo->prepare('UPDATE accounting_journal_entries SET currency = "USD", status = "draft" WHERE id = :id')
        ->execute(['id' => $journalId]);
    $check($rejects(static fn() => treasuryMatchLiabilityLine(
        $pdo, $tenantId, $lineId, $journalId, $entityId
    )), 'unposted journal is refused');
    $pdo->prepare('UPDATE accounting_journal_entries SET status = "posted" WHERE id = :id')
        ->execute(['id' => $journalId]);

    $check(treasuryMatchLiabilityLine($pdo, $tenantId, $lineId, $journalId, $entityId),
        'matching posted liability movement succeeds');
    $check(!treasuryMatchLiabilityLine($pdo, $tenantId, $lineId, $journalId, $entityId),
        'identical match retry is a no-op');
    $check($rejects(static fn() => treasuryMatchLiabilityLine(
        $pdo, $tenantId, $otherLineId, $journalId, $entityId
    )), 'one journal cannot match a second liability line');
    $check(treasuryUnmatchLiabilityLine($pdo, $tenantId, $lineId),
        'unrelated journal match may be removed');
    $check(!treasuryUnmatchLiabilityLine($pdo, $tenantId, $lineId),
        'unmatch retry is a no-op');

    $sourceLineId = $makeLine(-3.00);
    $sourceJournalId = $post(3.00, $sourceLineId);
    $pdo->prepare(
        'UPDATE treasury_liability_statement_lines SET match_status = "matched", matched_je_id = :je
          WHERE id = :id'
    )->execute(['je' => $sourceJournalId, 'id' => $sourceLineId]);
    $check($rejects(static fn() => treasuryUnmatchLiabilityLine($pdo, $tenantId, $sourceLineId)),
        'source-owned posted journal cannot be orphaned');
    $linkedLineId = $makeLine(-2.00);
    $linkedJournalId = $post(2.00);
    $pdo->prepare(
        'INSERT INTO accounting_subledger_links
            (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
         VALUES (:t, "treasury_feed", :source_ref, :je, "primary")'
    )->execute([
        't' => $tenantId, 'source_ref' => 'liab_line:split:' . $linkedLineId,
        'je' => $linkedJournalId,
    ]);
    $pdo->prepare(
        'UPDATE treasury_liability_statement_lines SET match_status = "matched", matched_je_id = :je
          WHERE id = :id'
    )->execute(['je' => $linkedJournalId, 'id' => $linkedLineId]);
    $check($rejects(static fn() => treasuryUnmatchLiabilityLine($pdo, $tenantId, $linkedLineId)),
        'event-linked split journal cannot be orphaned');
    $ignoredLineId = $makeLine(-1.00, 'ignored');
    $check(treasuryUnmatchLiabilityLine($pdo, $tenantId, $ignoredLineId),
        'ignored line can be restored');
    $check($pdo->inTransaction(), 'fixture remains inside its caller transaction');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

$after = (int) $pdo->query('SELECT COUNT(*) FROM treasury_liability_statement_lines')->fetchColumn();
$check($after === $before, 'rollback leaves no test statement lines');
$accountStmt = $pdo->prepare('SELECT COUNT(*) FROM accounting_accounts WHERE tenant_id = :t AND code = :code');
$accountStmt->execute(['t' => $tenantId, 'code' => $code]);
$check((int) $accountStmt->fetchColumn() === 0, 'rollback leaves no test account');
$journalStmt = $pdo->prepare('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = :t AND id = :id');
$journalStmt->execute(['t' => $tenantId, 'id' => $journalId]);
$check((int) $journalStmt->fetchColumn() === 0, 'rollback leaves no test journal');
echo "Treasury statement state MariaDB: {$checks} checks passed.\n";
