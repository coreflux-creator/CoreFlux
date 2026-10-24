<?php
/** Temporary, explicitly removable bank-history exceptions for the disposable QA4 app. */
declare(strict_types=1);

$mode = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || !in_array($mode, ['--seed', '--clean', '--inspect'], true)
    || getenv('COREFLUX_ENV') !== 'coreaccounting') {
    fwrite(STDERR, "Run only on isolated QA4 with --seed, --inspect or --clean.\n");
    exit(2);
}

$root = realpath((string) getenv('COREFLUX_QA4_ROOT'));
if ($root !== '/home/1516771.cloudwaysapps.com/fyqqcqhwwr/public_html') {
    throw new RuntimeException('Refusing to run outside the disposable CoreAccounting QA4 webroot.');
}
require_once $root . '/core/db.php';
require_once $root . '/core/business_integrity.php';

$pdo = getDB();
if (!$pdo || (string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'fyqqcqhwwr') {
    throw new RuntimeException('Refusing to run outside the disposable CoreAccounting QA4 database.');
}

$tenantId = 1;
$bankId = 2;
$bankEntityId = 1;
$otherEntityId = 3;
$periodId = 23;
$cashAccountId = 1;
$offsetAccountId = 2;
$marker = 'QA4-BANK-VIS-20261010';
$fitids = [$marker . '-UNMATCHED', $marker . '-MATCHED-A', $marker . '-MATCHED-B'];
$row = static function (string $sql, array $params = []) use ($pdo): ?array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};
$all = static function (string $sql, array $params = []) use ($pdo): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$bank = $row('SELECT entity_id, gl_account_code, currency FROM accounting_bank_accounts
              WHERE tenant_id = :t AND id = :id', ['t' => $tenantId, 'id' => $bankId]);
$period = $row('SELECT entity_id, start_date, end_date FROM accounting_periods
                WHERE tenant_id = :t AND id = :id', ['t' => $tenantId, 'id' => $periodId]);
$cash = $row('SELECT code FROM accounting_accounts WHERE tenant_id = :t AND id = :id',
    ['t' => $tenantId, 'id' => $cashAccountId]);
$offset = $row('SELECT code FROM accounting_accounts WHERE tenant_id = :t AND id = :id',
    ['t' => $tenantId, 'id' => $offsetAccountId]);
$assert($bank && (int) $bank['entity_id'] === $bankEntityId
    && $bank['gl_account_code'] === ($cash['code'] ?? null) && $bank['currency'] === 'USD',
    'QA4 bank account no longer matches the fixture');
$assert($period && (int) $period['entity_id'] === $otherEntityId
    && $period['start_date'] <= '2026-10-01' && $period['end_date'] >= '2026-10-01'
    && $offset && $offset['code'] !== $cash['code'],
    'QA4 period or offset account no longer matches the fixture');

$getLines = static function () use ($all, $tenantId, $fitids): array {
    return $all('SELECT id, bank_account_id, fitid, amount, match_status, matched_je_id
                   FROM accounting_bank_statement_lines
                  WHERE tenant_id = :t AND fitid IN (:f1, :f2, :f3)
                  ORDER BY fitid',
        ['t' => $tenantId, 'f1' => $fitids[0], 'f2' => $fitids[1], 'f3' => $fitids[2]]);
};
$getJournal = static function () use ($row, $tenantId, $marker): ?array {
    return $row('SELECT id, entity_id, source_module, source_ref_type, source_ref_id,
                       status, total_debit, total_credit
                   FROM accounting_journal_entries
                  WHERE tenant_id = :t AND je_number = :number',
        ['t' => $tenantId, 'number' => $marker]);
};
$audit = static function () use ($tenantId): array {
    $checks = [];
    foreach (businessIntegrityBankChecks($tenantId) as $check) {
        $checks[$check['key']] = [
            'status' => $check['status'],
            'issue_count' => $check['issue_count'],
        ];
    }
    return $checks;
};

if ($mode === '--inspect') {
    echo json_encode(['lines' => $getLines(), 'journal' => $getJournal(), 'checks' => $audit()], JSON_PRETTY_PRINT), "\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    if ($mode === '--seed') {
        $assert($getLines() === [] && $getJournal() === null,
            'Fixture marker already exists; inspect and clean it before seeding again');
        $before = $audit();
        foreach ($before as $check) {
            $assert($check['status'] === 'ok' && $check['issue_count'] === 0,
                'QA4 tenant has pre-existing bank-history exceptions');
        }
        $insertLine = $pdo->prepare(
            'INSERT INTO accounting_bank_statement_lines
                (tenant_id, bank_account_id, posted_date, description, amount, fitid, match_status)
             VALUES (:t, :bank, "2026-10-01", :description, 10.00, :fitid, :status)'
        );
        $lineIds = [];
        foreach ($fitids as $index => $fitid) {
            $insertLine->execute([
                't' => $tenantId, 'bank' => $bankId,
                'description' => $marker . ' synthetic exception ' . ($index + 1),
                'fitid' => $fitid,
                'status' => $index === 0 ? 'unmatched' : 'matched',
            ]);
            $lineIds[] = (int) $pdo->lastInsertId();
        }
        $pdo->prepare(
            'INSERT INTO accounting_journal_entries
                (tenant_id, entity_id, period_id, je_number, posting_date, source_module,
                 source_ref_type, source_ref_id, status, currency, total_debit, total_credit,
                 memo, posted_at)
             VALUES (:t, :entity, :period, :number, "2026-10-01", "treasury_feed",
                     "bank_statement_line", :source_id, "posted", "USD", 10.00, 10.00,
                     :memo, NOW())'
        )->execute([
            't' => $tenantId, 'entity' => $otherEntityId, 'period' => $periodId,
            'number' => $marker, 'source_id' => $lineIds[0], 'memo' => $marker,
        ]);
        $journalId = (int) $pdo->lastInsertId();
        $insertJournalLine = $pdo->prepare(
            'INSERT INTO accounting_journal_entry_lines
                (tenant_id, je_id, line_no, account_id, debit, credit, memo)
             VALUES (:t, :je, :line_no, :account, :debit, :credit, :memo)'
        );
        foreach ([
            [1, $cashAccountId, 10, 0],
            [2, $offsetAccountId, 0, 10],
        ] as [$lineNo, $accountId, $debit, $credit]) {
            $insertJournalLine->execute([
                't' => $tenantId, 'je' => $journalId, 'line_no' => $lineNo,
                'account' => $accountId, 'debit' => $debit, 'credit' => $credit,
                'memo' => $marker,
            ]);
        }
        $match = $pdo->prepare('UPDATE accounting_bank_statement_lines
                                SET matched_je_id = :je, matched_at = NOW()
                                WHERE tenant_id = :t AND id = :id AND fitid = :fitid');
        for ($index = 1; $index <= 2; $index++) {
            $match->execute(['je' => $journalId, 't' => $tenantId,
                'id' => $lineIds[$index], 'fitid' => $fitids[$index]]);
            $assert($match->rowCount() === 1, 'Could not link the synthetic matched line');
        }
        $checks = $audit();
        $assert($checks['bank_match_integrity']['issue_count'] === 2
            && $checks['bank_duplicate_journal_matches']['issue_count'] === 1
            && $checks['bank_unmatched_explicit_lineage']['issue_count'] === 1,
            'Expected three audit categories to find 2/1/1 QA exceptions');
        $unmatchedCheck = array_values(array_filter(businessIntegrityBankChecks($tenantId),
            static fn (array $check): bool => $check['key'] === 'bank_unmatched_explicit_lineage'))[0];
        $assert((int) ($unmatchedCheck['sample'][0]['bank_line_id'] ?? 0) === $lineIds[0]
            && $unmatchedCheck['sample'][0]['direct_posted_journal_ids'] === (string) $journalId,
            'Unmatched review must expose the linked posted journal');
    } else {
        $lines = $getLines();
        $journal = $getJournal();
        $assert(count($lines) === 3 && $journal !== null,
            'Fixture is incomplete; refusing partial cleanup');
        $lineByFitid = array_column($lines, null, 'fitid');
        $journalId = (int) $journal['id'];
        $assert((int) $journal['entity_id'] === $otherEntityId
            && $journal['source_module'] === 'treasury_feed'
            && $journal['source_ref_type'] === 'bank_statement_line'
            && (int) $journal['source_ref_id'] === (int) $lineByFitid[$fitids[0]]['id']
            && $journal['status'] === 'posted'
            && (float) $journal['total_debit'] === 10.0
            && (float) $journal['total_credit'] === 10.0,
            'Journal changed; refusing cleanup');
        foreach ($fitids as $index => $fitid) {
            $line = $lineByFitid[$fitid] ?? null;
            $assert($line && (int) $line['bank_account_id'] === $bankId
                && (float) $line['amount'] === 10.0
                && $line['match_status'] === ($index === 0 ? 'unmatched' : 'matched')
                && (int) ($line['matched_je_id'] ?? 0) === ($index === 0 ? 0 : $journalId),
                'Bank line changed; refusing cleanup');
        }
        $journalLines = $all('SELECT id, account_id, debit, credit FROM accounting_journal_entry_lines
                              WHERE tenant_id = :t AND je_id = :je ORDER BY line_no',
            ['t' => $tenantId, 'je' => $journalId]);
        $assert(count($journalLines) === 2
            && (int) $journalLines[0]['account_id'] === $cashAccountId
            && (int) $journalLines[1]['account_id'] === $offsetAccountId,
            'Journal lines changed; refusing cleanup');
        $pdo->prepare('DELETE FROM accounting_journal_entry_lines WHERE tenant_id = :t AND je_id = :je')
            ->execute(['t' => $tenantId, 'je' => $journalId]);
        $deleteLine = $pdo->prepare('DELETE FROM accounting_bank_statement_lines
                                     WHERE tenant_id = :t AND id = :id AND fitid = :fitid');
        foreach ($lines as $line) {
            $deleteLine->execute(['t' => $tenantId, 'id' => $line['id'], 'fitid' => $line['fitid']]);
            $assert($deleteLine->rowCount() === 1, 'Could not remove a synthetic bank line');
        }
        $deleteJournal = $pdo->prepare('DELETE FROM accounting_journal_entries
                                        WHERE tenant_id = :t AND id = :je AND je_number = :number');
        $deleteJournal->execute(['t' => $tenantId, 'je' => $journalId, 'number' => $marker]);
        $assert($deleteJournal->rowCount() === 1, 'Could not remove the synthetic journal');
        $checks = $audit();
        foreach ($checks as $check) {
            $assert($check['status'] === 'ok' && $check['issue_count'] === 0,
                'Bank-history audit did not return to its clean baseline');
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

echo json_encode(['mode' => $mode, 'marker' => $marker,
    'lines' => $getLines(), 'journal' => $getJournal(), 'checks' => $audit()], JSON_PRETTY_PRINT), "\n";
