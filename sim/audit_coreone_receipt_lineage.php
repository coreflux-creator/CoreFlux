<?php
/** Read-only lineage audit for synthetic Billing allocations and bank receipts. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This audit is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';

$tenantId = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--tenant=([1-9][0-9]*)$/', $arg, $match)) $tenantId = (int) $match[1];
}
if ($tenantId <= 0) {
    fwrite(STDERR, "Use --tenant=ID with a CoreFlux CI Simulation tenant.\n");
    exit(2);
}
$pdo = getDB();
$tenant = $pdo->prepare('SELECT name FROM tenants WHERE id = :t');
$tenant->execute(['t' => $tenantId]);
if (!in_array($tenant->fetchColumn(), ['CoreFlux CI Simulation', "CoreFlux CI Simulation {$tenantId}"], true)) {
    fwrite(STDERR, "This audit only reads a CI Simulation tenant.\n");
    exit(2);
}

$rows = $pdo->prepare(
    'SELECT p.id AS payment_id, p.external_id, p.received_at, p.journal_entry_id,
            a.id AS allocation_id, a.application_je_id,
            i.id AS invoice_id, i.entity_id AS invoice_entity_id,
            invoice_je.entity_id AS invoice_je_entity_id,
            payment_je.id AS payment_je_id, payment_je.status AS payment_je_status,
            payment_je.entity_id AS payment_je_entity_id,
            payment_je.posting_date AS payment_je_date,
            application_je.id AS application_je_link_id,
            application_je.status AS application_je_status,
            application_je.entity_id AS application_je_entity_id
       FROM billing_payment_allocations a
       JOIN billing_payments p ON p.id = a.payment_id
       JOIN billing_invoices i ON i.id = a.invoice_id AND i.tenant_id = p.tenant_id
  LEFT JOIN accounting_journal_entries invoice_je
         ON invoice_je.id = i.journal_entry_id AND invoice_je.tenant_id = p.tenant_id
  LEFT JOIN accounting_journal_entries payment_je
         ON payment_je.id = p.journal_entry_id AND payment_je.tenant_id = p.tenant_id
  LEFT JOIN accounting_journal_entries application_je
         ON application_je.id = a.application_je_id AND application_je.tenant_id = p.tenant_id
      WHERE p.tenant_id = :t AND p.voided_at IS NULL AND a.reversed_at IS NULL
      ORDER BY p.id, a.id'
);
$rows->execute(['t' => $tenantId]);
$bankLine = $pdo->prepare(
    'SELECT bl.match_status, bl.matched_je_id, bank_je.status AS je_status,
            bank_je.entity_id AS je_entity_id, bank_je.posting_date AS je_date
       FROM accounting_bank_statement_lines bl
  LEFT JOIN accounting_journal_entries bank_je
         ON bank_je.id = bl.matched_je_id AND bank_je.tenant_id = bl.tenant_id
      WHERE bl.tenant_id = :t AND bl.id = :id'
);

$counts = ['direct_payment_journal' => 0, 'bank_line_journal' => 0,
    'deposit_application_journal' => 0, 'missing_journal' => 0];
$issues = [];
$checked = 0;
foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $checked++;
    $kind = 'missing_journal';
    $journalStatus = null;
    $journalEntity = 0;
    $journalDate = null;
    if ($row['application_je_id'] !== null) {
        $kind = 'deposit_application_journal';
        $journalStatus = $row['application_je_status'];
        $journalEntity = (int) $row['application_je_entity_id'];
    } elseif ($row['payment_je_id'] !== null) {
        $kind = 'direct_payment_journal';
        $journalStatus = $row['payment_je_status'];
        $journalEntity = (int) $row['payment_je_entity_id'];
        $journalDate = $row['payment_je_date'];
    } elseif (preg_match('/^bank-line:([1-9][0-9]*)(?::|$)/', (string) $row['external_id'], $match)) {
        $bankLine->execute(['t' => $tenantId, 'id' => (int) $match[1]]);
        $bank = $bankLine->fetch(PDO::FETCH_ASSOC);
        if ($bank && $bank['match_status'] === 'matched' && $bank['matched_je_id'] !== null) {
            $kind = 'bank_line_journal';
            $journalStatus = $bank['je_status'];
            $journalEntity = (int) $bank['je_entity_id'];
            $journalDate = $bank['je_date'];
        }
    }
    $counts[$kind]++;
    $flags = [];
    if ($journalStatus !== 'posted') $flags[] = 'no_active_posted_allocation_journal';
    if ((int) $row['invoice_je_entity_id'] <= 0
        || $journalEntity !== (int) $row['invoice_je_entity_id']
        || (!empty($row['invoice_entity_id'])
            && (int) $row['invoice_entity_id'] !== (int) $row['invoice_je_entity_id'])) {
        $flags[] = 'entity_mismatch';
    }
    if ($journalDate !== null && $journalDate !== $row['received_at']) {
        $flags[] = 'receipt_date_differs_from_journal';
    }
    if ($flags) {
        $issues[] = ['payment_id' => (int) $row['payment_id'],
            'allocation_id' => (int) $row['allocation_id'], 'kind' => $kind, 'flags' => $flags];
    }
}

echo json_encode(['tenant_id' => $tenantId, 'allocations_checked' => $checked,
    'lineage' => $counts, 'issues_found' => count($issues), 'issues' => $issues],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($issues ? 1 : 0);
