<?php
/** Reference-only partial invoice suggestions in the disposable CI database. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting CI database only.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/tenant_scope.php';
require_once __DIR__ . '/../modules/accounting/lib/bank_rec.php';

$pdo = getDB();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$tenantId = (int) $pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();
$entityStmt = $pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id = :t AND active = 1 ORDER BY id LIMIT 1');
$entityStmt->execute(['t' => $tenantId]);
$entityId = (int) $entityStmt->fetchColumn();
if ($tenantId <= 0 || $entityId <= 0) throw new RuntimeException('CI tenant/entity missing.');
$GLOBALS['__cf_request_tenant_id'] = $tenantId;
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$find = static function (array $line, int $bankId) use ($tenantId): array {
    return bankRecInvoiceMatchCandidates($tenantId, $line, $bankId, 5);
};
$line = static fn(string $description, float $amount, string $date = '2026-10-11'): array => [
    'posted_date' => $date, 'description' => $description, 'amount' => $amount,
];

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO accounting_entities (tenant_id, code, legal_name)
        VALUES (:t, "CI-REF-OTHER", "CI Reference Other")')->execute(['t' => $tenantId]);
    $otherEntityId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO accounting_bank_accounts
        (tenant_id, entity_id, name, gl_account_code, currency, status)
        VALUES (:t, :e, "CI reference checking", "CI-REF-CASH", "USD", "active")')
        ->execute(['t' => $tenantId, 'e' => $entityId]);
    $bankId = (int) $pdo->lastInsertId();

    $jeInsert = $pdo->prepare('INSERT INTO accounting_journal_entries
        (tenant_id, entity_id, period_id, je_number, posting_date, source_module,
         status, currency, total_debit, total_credit, posted_at)
        VALUES (:t, :e, 1, :number, "2026-10-01", "billing",
                "posted", "USD", :amount, :amount_credit, NOW())');
    $invoiceInsert = $pdo->prepare('INSERT INTO billing_invoices
        (tenant_id, entity_id, invoice_number, client_name, currency, issue_date,
         due_date, total, amount_due, status, journal_entry_id)
        VALUES (:t, :e, :number, "CI Reference Client", :currency, :issued,
                "2026-11-01", :total, :due, :status, :je_id)');
    $insertInvoice = static function (
        string $number, float $due, string $status = 'approved',
        string $currency = 'USD', string $issued = '2026-10-01', ?int $owner = null
    ) use ($pdo, $tenantId, $entityId, $jeInsert, $invoiceInsert): int {
        $owner ??= $entityId;
        $jeId = null;
        if ($status !== 'draft') {
            $jeInsert->execute([
                't' => $tenantId, 'e' => $owner, 'number' => 'CI-JE-' . $number,
                'amount' => number_format($due, 2, '.', ''),
                'amount_credit' => number_format($due, 2, '.', ''),
            ]);
            $jeId = (int) $pdo->lastInsertId();
        }
        $invoiceInsert->execute([
            't' => $tenantId, 'e' => $owner, 'number' => $number,
            'currency' => $currency, 'issued' => $issued,
            'total' => number_format($due, 2, '.', ''),
            'due' => number_format($due, 2, '.', ''),
            'status' => $status, 'je_id' => $jeId,
        ]);
        return (int) $pdo->lastInsertId();
    };

    $partialId = $insertInvoice('INV-2026-0901', 20.00);
    $exactId = $insertInvoice('INV-2026-0903', 5.00);
    $insertInvoice('INV-2026-09012', 20.00);
    $draftId = $insertInvoice('INV-2026-0902', 20.00, 'draft');
    $insertInvoice('INV-2026-0904', 20.00, 'approved', 'EUR');
    $insertInvoice('INV-2026-0905', 20.00, 'approved', 'USD', '2026-10-12');
    $insertInvoice('INV-2026-0906', 20.00, 'approved', 'USD', '2026-10-01', $otherEntityId);

    $matches = $find($line('Payment for INV-2026-0901', 5.00), $bankId);
    $partial = array_values(array_filter($matches, static fn(array $row): bool => $row['invoice_id'] === $partialId));
    $assert(count($partial) === 1 && $partial[0]['can_apply_partial'] === true
        && $partial[0]['can_apply_payment'] === false && $partial[0]['exact_balance'] === false,
        'exact invoice reference offers review-only partial allocation');
    $assert(count(array_filter($matches, static fn(array $row): bool =>
        $row['invoice_number'] === 'INV-2026-09012')) === 0,
        'invoice-number prefix does not create a false reference match');

    $exact = $find($line('Unlabeled deposit', 5.00), $bankId);
    $exactMatch = array_values(array_filter($exact, static fn(array $row): bool => $row['invoice_id'] === $exactId));
    $assert(count($exactMatch) === 1 && $exactMatch[0]['can_apply_payment'] === true
        && $exactMatch[0]['can_apply_partial'] === false,
        'exact balance keeps its existing direct-apply path');
    $assert($find($line('CI Reference Client transfer', 4.00), $bankId) === [],
        'client name alone cannot suggest a partial invoice');

    $draft = $find($line('INV-2026-0902', 4.00), $bankId);
    $draftMatch = array_values(array_filter($draft, static fn(array $row): bool => $row['invoice_id'] === $draftId));
    $assert(count($draftMatch) === 1 && !$draftMatch[0]['can_apply_partial']
        && !$draftMatch[0]['can_apply_payment'],
        'draft reference is visible but cannot be applied');
    foreach (['INV-2026-0904', 'INV-2026-0905', 'INV-2026-0906'] as $excluded) {
        $assert($find($line($excluded, 4.00), $bankId) === [],
            "currency, date or entity guard excludes {$excluded}");
    }
    $assert($find($line('INV-2026-0901', 25.00), $bankId) === [],
        'a receipt larger than the open balance is not suggested');
    $assert(bankRecInvoiceReferenceTokens('Paid INV-2026-0901, not INV-2026-09012.')
        === ['INV-2026-0901', 'INV-2026-09012'],
        'punctuation preserves whole invoice-number tokens');
} finally {
    $pdo->rollBack();
    unset($GLOBALS['__cf_request_tenant_id']);
}
