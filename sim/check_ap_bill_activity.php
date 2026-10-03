<?php
/** Read-only staging check for AP bill correction eligibility. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/ap/lib/ap.php';

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) continue;
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $args[$key] = $value;
}
$tenantId = (int) ($args['tenant'] ?? 0);
$billId = (int) ($args['bill'] ?? 0);
if ($tenantId <= 0 || $billId <= 0) {
    fwrite(STDERR, "Use --tenant=ID --bill=ID [--expect=active|clear].\n");
    exit(2);
}

$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM ap_bills WHERE tenant_id = :tenant_id AND id = :id');
$stmt->execute(['tenant_id' => $tenantId, 'id' => $billId]);
$bill = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$bill) {
    fwrite(STDERR, "Bill not found in tenant.\n");
    exit(2);
}
$hasActivity = apBillHasLedgerOrPaymentActivity($pdo, $tenantId, $bill);
$journal = null;
$links = [];
if (!empty($bill['journal_entry_id'])) {
    $journalStmt = $pdo->prepare(
        'SELECT id, entity_id, source_module, source_ref_type, source_ref_id, status, currency,
                total_debit, total_credit, reversed_by_je_id
           FROM accounting_journal_entries WHERE tenant_id = :tenant_id AND id = :id'
    );
    $journalStmt->execute(['tenant_id' => $tenantId, 'id' => (int) $bill['journal_entry_id']]);
    $journal = $journalStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $linkStmt = $pdo->prepare(
        'SELECT source_module, source_record_id, journal_entry_id, link_kind
           FROM accounting_subledger_links
          WHERE tenant_id = :tenant_id AND journal_entry_id = :journal_entry_id'
    );
    $linkStmt->execute(['tenant_id' => $tenantId, 'journal_entry_id' => (int) $bill['journal_entry_id']]);
    $links = $linkStmt->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode([
    'tenant_id' => $tenantId,
    'bill_id' => $billId,
    'status' => $bill['status'],
    'has_ledger_or_payment_activity' => $hasActivity,
    'journal' => $journal,
    'journal_links' => $links,
], JSON_PRETTY_PRINT) . "\n";
$expected = $args['expect'] ?? null;
exit($expected === null || $expected === ($hasActivity ? 'active' : 'clear') ? 0 : 1);
