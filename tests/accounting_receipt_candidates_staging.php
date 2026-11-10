<?php
/** Guarded check that older, relevant bank deposits survive recent-row limits. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}
define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';

$actor = null;
$cookie = null;
$run = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
$prefix = 'SYN-RECEIPT-CANDIDATE-' . $run . '-';
$inserted = 0;
try {
    $entity = qaOne($pdo, 'SELECT id FROM accounting_entities
        WHERE tenant_id = :tenant_id AND code = :code AND active = 1',
        ['tenant_id' => QA_TENANT, 'code' => QA_ENTITY_CODE]);
    $entityId = (int) ($entity['id'] ?? 0);
    $bank = qaOne($pdo, 'SELECT id FROM accounting_bank_accounts
        WHERE tenant_id = :tenant_id AND entity_id = :entity_id
          AND gl_account_code = :code AND status = "active"',
        ['tenant_id' => QA_TENANT, 'entity_id' => $entityId, 'code' => QA_BANK_CODE]);
    $bankId = (int) ($bank['id'] ?? 0);
    $invoice = qaOne($pdo, 'SELECT bi.id, bi.invoice_number, bi.issue_date, bi.amount_due
        FROM billing_invoices bi
        JOIN accounting_journal_entries je
          ON je.tenant_id = bi.tenant_id AND je.id = bi.journal_entry_id
        WHERE bi.tenant_id = :tenant_id AND bi.entity_id = :entity_id
          AND bi.status IN ("approved", "sent", "partially_paid")
          AND bi.amount_due >= 0.01 AND je.status = "posted"
        ORDER BY bi.id DESC LIMIT 1',
        ['tenant_id' => QA_TENANT, 'entity_id' => $entityId]);
    qaExpect($bankId > 0 && $invoice !== null, 'synthetic bank and open posted invoice exist');

    $actor = qaEnsureActor($pdo, 'receipt-candidate-search');
    $cookie = tempnam(sys_get_temp_dir(), 'cf-qa-candidates-');
    if ($cookie === false) throw new RuntimeException('Could not create synthetic session');
    qaLogin($actor, $cookie);
    $before = qaBalances($pdo, $entityId);
    $postedDate = max('2026-10-08', (string) $invoice['issue_date']);
    $due = round((float) $invoice['amount_due'], 2);
    $insert = $pdo->prepare('INSERT INTO accounting_bank_statement_lines
        (tenant_id, bank_account_id, posted_date, description, amount, bank_reference, fitid, match_status)
        VALUES (:tenant_id, :bank_account_id, :posted_date, :description, :amount,
                :bank_reference, :fitid, "unmatched")');
    $addLine = static function (string $suffix, string $description, float $amount) use
        ($insert, $bankId, $postedDate, $prefix, &$inserted, $pdo): int {
        $insert->execute([
            'tenant_id' => QA_TENANT, 'bank_account_id' => $bankId,
            'posted_date' => $postedDate, 'description' => $description,
            'amount' => number_format($amount, 2, '.', ''),
            'bank_reference' => $prefix . $suffix, 'fitid' => $prefix . $suffix,
        ]);
        $inserted++;
        return (int) $pdo->lastInsertId();
    };
    $referenceLineId = $addLine('reference',
        'Invented customer payment ' . $invoice['invoice_number'], min($due, 12.34));
    $exactLineId = $addLine('exact', 'Invented unlabeled deposit', $due);
    for ($i = 0; $i < 101; $i++) {
        $addLine('decoy-' . $i, 'Invented unrelated deposit ' . $i, $due + 10);
    }
    qaExpect($inserted === 103, 'two relevant deposits precede 101 newer decoys');

    $response = qaRequest('/modules/accounting/api/bank_statements.php?action=receipt_candidates&invoice_id='
        . (int) $invoice['id'], 'GET', null, $cookie);
    $rows = $response['rows'] ?? [];
    $byId = [];
    foreach ($rows as $row) $byId[(int) $row['id']] = $row;
    qaExpect(count($rows) === 25 && count($byId) === 25,
        'invoice receipt suggestions remain bounded and unique');
    qaExpect(!empty($byId[$referenceLineId]['reference_match']),
        'older invoice-reference deposit survives the recent-row cap');
    qaExpect(isset($byId[$exactLineId])
        && abs((float) $byId[$exactLineId]['amount'] - $due) < 0.005,
        'older exact-balance deposit survives the recent-row cap');
    qaExpect($before === qaBalances($pdo, $entityId),
        'candidate read leaves the synthetic ledger unchanged');
} finally {
    if ($actor && isset($actor['id'])) {
        $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL, password = NULL
            WHERE tenant_id = :tenant_id AND id = :id')
            ->execute(['tenant_id' => QA_TENANT, 'id' => (int) $actor['id']]);
    }
    if (is_string($cookie) && is_file($cookie)) unlink($cookie);
    $delete = $pdo->prepare('DELETE FROM accounting_bank_statement_lines
        WHERE tenant_id = :tenant_id AND bank_account_id = :bank_account_id
          AND fitid LIKE :prefix AND match_status = "unmatched" AND matched_je_id IS NULL');
    if (isset($bankId) && $bankId > 0) {
        $delete->execute(['tenant_id' => QA_TENANT, 'bank_account_id' => $bankId,
            'prefix' => $prefix . '%']);
        echo 'CLEANUP invented bank lines: ' . $delete->rowCount() . '/' . $inserted . PHP_EOL;
    }
}
