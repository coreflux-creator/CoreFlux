<?php
/** Rollback-only open AR/AP cutover check on an isolated local MariaDB schema. */
declare(strict_types=1);

$database = (string) getenv('DB_NAME');
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !preg_match('/^coreaccounting_blank_test\d+$/D', $database)) {
    fwrite(STDERR, "This test requires an isolated local staging schema.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/accounting/lib/opening_cutover.php';
require_once __DIR__ . '/../modules/accounting/lib/standard_reports.php';
require_once __DIR__ . '/../core/accounting/entity_setup.php';
require_once __DIR__ . '/../sim/lib/accounting_snapshot.php';

$pdo = getDB();
if (!$pdo || (string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
    throw new RuntimeException('Connected database does not match the isolated test schema.');
}
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$entityId = (int) $pdo->query('SELECT id FROM accounting_entities WHERE tenant_id = 1 AND code = "MAIN"')->fetchColumn();
$assert($entityId > 0, 'fresh tenant has an explicit entity');
$baseline = [
    'journals' => (int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = 1')->fetchColumn(),
    'invoices' => (int) $pdo->query('SELECT COUNT(*) FROM billing_invoices WHERE tenant_id = 1')->fetchColumn(),
    'bills' => (int) $pdo->query('SELECT COUNT(*) FROM ap_bills WHERE tenant_id = 1')->fetchColumn(),
    'batches' => (int) $pdo->query('SELECT COUNT(*) FROM accounting_opening_document_cutovers WHERE tenant_id = 1')->fetchColumn(),
];
$assert($baseline['journals'] === 0 && $baseline['batches'] === 0, 'entity has no prior cutover');
$balances = "Account code,Balance\n1000,1000.00\n3900,200.00\n";
$ar = "Invoice number,Client name,Issue date,Due date,Open amount\n"
    . "LEG-AR-100,Example Customer,2024-11-30,2025-01-15,150.00\n";
$ap = "Bill number,Vendor name,Bill date,Due date,Open amount\n"
    . "LEG-AP-200,Example Vendor,2024-12-01,2025-01-10,80.00\n";

$pdo->beginTransaction();
try {
    $preview = accountingOpeningCutoverReview($pdo, 1, $entityId, $balances, $ar, $ap);
    $assert($preview['error_count'] === 0 && $preview['preview_token'] !== null,
        'combined preview is valid and read-only');
    $assert($preview['ar_total'] === '150.00' && $preview['ap_total'] === '80.00',
        'source totals are exact cents');
    $assert($preview['opening_equity'] === ['amount' => '870.00', 'negative' => false],
        'combined preview includes source offsets in account 3000');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM billing_invoices WHERE tenant_id = 1')->fetchColumn()
        === $baseline['invoices'], 'preview creates no invoice');
    $badAr = "Invoice number,Client name,Issue date,Due date,Open amount\n"
        . "BAD,Example Customer,2024-99-99,2025-01-15,150.001\n";
    $invalid = accountingOpeningCutoverReview($pdo, 1, $entityId, $balances, $badAr, $ap);
    $assert($invalid['error_count'] > 0 && $invalid['preview_token'] === null,
        'bad date and sub-cent amount are blocked before posting');
    $pdo->prepare(
        'INSERT INTO billing_invoices
           (tenant_id, entity_id, invoice_number, client_name, issue_date, due_date)
         VALUES (1, :e, "PREEXISTING-DRAFT", "Example Customer", "2024-12-01", "2025-01-01")'
    )->execute(['e' => $entityId]);
    $draftId = (int) $pdo->lastInsertId();
    $occupied = accountingOpeningCutoverReview($pdo, 1, $entityId, $balances, $ar, $ap);
    $assert($occupied['error_count'] > 0 && $occupied['preview_token'] === null,
        'an existing unposted source document blocks first-run cutover');
    $pdo->prepare('DELETE FROM billing_invoices WHERE id = :id')->execute(['id' => $draftId]);
    try {
        accountingOpeningCutoverCommit($pdo, 1, $entityId, $balances, $ar, $ap, str_repeat('0', 64), null);
        throw new RuntimeException('Stale token accepted');
    } catch (AccountingOpeningConflict $error) {
        $assert(str_contains($error->getMessage(), 'changed since preview'), 'stale token is rejected');
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = 1')->fetchColumn()
        === $baseline['journals'], 'failed commit left no journal');

    $posted = accountingOpeningCutoverCommit($pdo, 1, $entityId,
        $balances, $ar, $ap, $preview['preview_token'], null);
    $assert(!$posted['idempotent_replay'] && $posted['ar_count'] === 1 && $posted['ap_count'] === 1,
        'one transaction posted one invoice and one bill');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = 1')->fetchColumn()
        === $baseline['journals'] + 3, 'base, AR, and AP each have a canonical journal');
    $assert((int) $pdo->query(
        'SELECT COUNT(*) FROM accounting_subledger_links
          WHERE tenant_id = 1 AND link_kind = "primary" AND source_module IN ("billing", "ap")'
    )->fetchColumn() === 2, 'each opening source document links to its posted journal');
    $invoice = $pdo->query('SELECT * FROM billing_invoices WHERE tenant_id = 1 AND invoice_number = "LEG-AR-100"')->fetch(PDO::FETCH_ASSOC);
    $bill = $pdo->query('SELECT * FROM ap_bills WHERE tenant_id = 1 AND bill_number = "LEG-AP-200"')->fetch(PDO::FETCH_ASSOC);
    $assert($invoice && $invoice['status'] === 'approved' && (int) $invoice['opening_cutover_id'] === $posted['cutover_id']
        && (float) $invoice['amount_due'] === 150.0 && !empty($invoice['journal_entry_id']),
        'opening invoice is collectible but not marked sent');
    $assert($bill && $bill['status'] === 'approved' && (int) $bill['opening_cutover_id'] === $posted['cutover_id']
        && (float) $bill['amount_due'] === 80.0 && !empty($bill['journal_entry_id']),
        'opening bill is payable and linked to its journal');
    $agingAr = billingComputeAging(1, '2024-12-31', $entityId);
    $agingAp = apComputeAging(1, '2024-12-31', $entityId);
    $assert(count($agingAr) === 1 && (float) $agingAr[0]['total_due'] === 150.0,
        'AR aging agrees with opening receivable');
    $assert(count($agingAp) === 1 && (float) $agingAp[0]['total_due'] === 80.0,
        'AP aging agrees with opening payable');
    $balance = reportBalanceSheet(1, '2024-12-31', $entityId);
    $assert($balance['balanced'] && (float) $balance['total_assets'] === 1150.0
        && (float) $balance['total_liabilities'] === 80.0
        && (float) $balance['total_equity'] === 1070.0,
        'balance sheet includes source-owned controls and balances');
    $income = reportIncomeStatement(1, '2024-12-31', '2024-12-31', $entityId);
    $assert((float) $income['total_revenue'] === 0.0 && (float) $income['total_expense'] === 0.0,
        'cutover does not manufacture revenue or expense');
    $other = accountingCreateEntityWithCalendar($pdo, 1, [
        'code' => 'OTHER', 'legal_name' => 'Rollback-only other entity',
        'country' => 'US', 'base_currency' => 'USD', 'entity_type' => 'llc',
        'accounting_basis' => 'accrual', 'fiscal_year_start_month' => 1,
    ], 2024);
    $otherId = (int) $other['entity_id'];
    $otherAr = "Invoice number,Client name,Issue date,Due date,Open amount\n"
        . "OTHER-AR-1,Other Customer,2023-12-15,2024-01-15,240.00\n";
    $otherAp = "Bill number,Vendor name,Bill date,Due date,Open amount\n"
        . "OTHER-AP-1,Other Vendor,2023-12-15,2024-01-15,60.00\n";
    $otherPreview = accountingOpeningCutoverReview($pdo, 1, $otherId, '', $otherAr, $otherAp);
    $assert($otherPreview['error_count'] === 0,
        'second entity can preview its own source documents: ' . json_encode($otherPreview['errors']));
    accountingOpeningCutoverCommit($pdo, 1, $otherId, '', $otherAr, $otherAp,
        $otherPreview['preview_token'], null);
    $mainDue = simSnapshotDocumentDue(1, $entityId, '2024-12-31');
    $otherDue = simSnapshotDocumentDue(1, $otherId, '2024-12-31');
    $assert($mainDue === ['ar_due' => 150.0, 'ap_due' => 80.0]
        && $otherDue === ['ar_due' => 240.0, 'ap_due' => 60.0],
        'snapshot source balances stay inside their own entity, including approved opening documents');
    $mainTrial = array_column(accountingTrialBalance(1, '2024-12-31', $entityId), 'balance_signed', 'code');
    $otherTrial = array_column(accountingTrialBalance(1, '2024-12-31', $otherId), 'balance_signed', 'code');
    $assert((float) ($mainTrial['1100'] ?? 0) === $mainDue['ar_due']
        && (float) ($mainTrial['2000'] ?? 0) === $mainDue['ap_due']
        && (float) ($otherTrial['1100'] ?? 0) === $otherDue['ar_due']
        && (float) ($otherTrial['2000'] ?? 0) === $otherDue['ap_due'],
        'each entity aging agrees with its own GL controls');
    $otherBalance = reportBalanceSheet(1, '2024-12-31', $otherId);
    $assert($otherBalance['balanced'] && (float) $otherBalance['total_assets'] === 240.0
        && (float) $otherBalance['total_liabilities'] === 60.0
        && (float) $otherBalance['total_equity'] === 180.0,
        'other entity balance sheet excludes the first entity');
    $replay = accountingOpeningCutoverCommit($pdo, 1, $entityId,
        $balances, $ar, $ap, $preview['preview_token'], null);
    $assert($replay['idempotent_replay'] && $replay['cutover_id'] === $posted['cutover_id'],
        'exact retry returns the original batch');
    $changed = accountingOpeningCutoverReview($pdo, 1, $entityId,
        $balances, str_replace('150.00', '151.00', $ar), $ap);
    $assert($changed['error_count'] > 0 && $changed['preview_token'] === null,
        'changed source amount cannot replace posted cutover');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = 1')->fetchColumn()
    === $baseline['journals'], 'rollback removed all cutover journals');
$assert((int) $pdo->query('SELECT COUNT(*) FROM billing_invoices WHERE tenant_id = 1')->fetchColumn()
    === $baseline['invoices'], 'rollback removed opening invoice');
$assert((int) $pdo->query('SELECT COUNT(*) FROM ap_bills WHERE tenant_id = 1')->fetchColumn()
    === $baseline['bills'], 'rollback removed opening bill');
$assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_opening_document_cutovers WHERE tenant_id = 1')->fetchColumn()
    === $baseline['batches'], 'rollback removed cutover batch');

$pdo->beginTransaction();
try {
    $preview = accountingOpeningCutoverReview($pdo, 1, $entityId, '', $ar, $ap);
    $assert($preview['error_count'] === 0 && $preview['preview_token'] !== null
        && $preview['balances']['journal_lines'] === [],
        'document-only preview needs no ordinary opening journal');
    $assert($preview['opening_equity'] === ['amount' => '70.00', 'negative' => false],
        'document-only AR less AP is the opening-equity offset');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = 1')->fetchColumn()
        === $baseline['journals'], 'document-only preview creates no journal');
    $posted = accountingOpeningCutoverCommit($pdo, 1, $entityId, '', $ar, $ap,
        $preview['preview_token'], null);
    $assert(!$posted['idempotent_replay'] && $posted['ar_count'] === 1 && $posted['ap_count'] === 1,
        'document-only cutover posts both source types');
    $batch = $pdo->query('SELECT balance_je_id FROM accounting_opening_document_cutovers WHERE tenant_id = 1')
        ->fetch(PDO::FETCH_ASSOC);
    $assert($batch && $batch['balance_je_id'] === null,
        'document-only cutover has no fabricated balance journal');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = 1')->fetchColumn()
        === $baseline['journals'] + 2, 'only AR and AP source journals were posted');
    $assert((int) $pdo->query(
        'SELECT COUNT(*) FROM accounting_subledger_links
          WHERE tenant_id = 1 AND link_kind = "primary" AND source_module IN ("billing", "ap")'
    )->fetchColumn() === 2, 'both source documents link to canonical journals');
    $assert($posted['journal_entry_id'] === $posted['invoices'][0]['journal_entry_id'],
        'document-only result links to a real source journal');
    $agingAr = billingComputeAging(1, '2024-12-31', $entityId);
    $agingAp = apComputeAging(1, '2024-12-31', $entityId);
    $assert(count($agingAr) === 1 && (float) $agingAr[0]['total_due'] === 150.0
        && count($agingAp) === 1 && (float) $agingAp[0]['total_due'] === 80.0,
        'document-only AR and AP both appear in aging');
    $balance = reportBalanceSheet(1, '2024-12-31', $entityId);
    $assert($balance['balanced'] && (float) $balance['total_assets'] === 150.0
        && (float) $balance['total_liabilities'] === 80.0
        && (float) $balance['total_equity'] === 70.0,
        'document-only source journals balance the sheet');
    $income = reportIncomeStatement(1, '2024-12-31', '2024-12-31', $entityId);
    $assert((float) $income['total_revenue'] === 0.0 && (float) $income['total_expense'] === 0.0,
        'document-only cutover creates no current-period income');
    $replay = accountingOpeningCutoverCommit($pdo, 1, $entityId, '', $ar, $ap,
        $preview['preview_token'], null);
    $assert($replay['idempotent_replay'] && $replay['cutover_id'] === $posted['cutover_id']
        && $replay['journal_entry_id'] === $posted['journal_entry_id'],
        'document-only retry returns the original batch and journal');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = 1')->fetchColumn()
        === $baseline['journals'] + 2, 'document-only retry does not duplicate journals');
    $changed = accountingOpeningCutoverReview($pdo, 1, $entityId, '',
        str_replace('150.00', '151.00', $ar), $ap);
    $assert($changed['error_count'] > 0 && $changed['preview_token'] === null,
        'changed source amount cannot replace document-only cutover');
    $lateBalances = accountingOpeningReview($pdo, 1, $entityId, $balances);
    $assert($lateBalances['error_count'] > 0 && $lateBalances['preview_token'] === null,
        'ordinary balances cannot be added after document-only cutover');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = 1')->fetchColumn()
    === $baseline['journals'], 'rollback removed document-only journals');
$assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_opening_document_cutovers WHERE tenant_id = 1')->fetchColumn()
    === $baseline['batches'], 'rollback removed document-only batch');

$pdo->beginTransaction();
try {
    $preview = accountingOpeningCutoverReview($pdo, 1, $entityId, '', '', $ap);
    $assert($preview['error_count'] === 0 && $preview['ar_count'] === 0 && $preview['ap_count'] === 1,
        'AP-only source file is valid without balances or AR');
    $assert($preview['opening_equity'] === ['amount' => '80.00', 'negative' => true],
        'AP-only preview shows debit-side opening equity');
    $posted = accountingOpeningCutoverCommit($pdo, 1, $entityId, '', '', $ap,
        $preview['preview_token'], null);
    $assert($posted['journal_entry_id'] === $posted['bills'][0]['journal_entry_id'],
        'AP-only result links to the payable journal');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = 1')->fetchColumn()
        === $baseline['journals'] + 1, 'AP-only cutover creates just one journal');
    $balance = reportBalanceSheet(1, '2024-12-31', $entityId);
    $assert($balance['balanced'] && (float) $balance['total_assets'] === 0.0
        && (float) $balance['total_liabilities'] === 80.0
        && (float) $balance['total_equity'] === -80.0,
        'AP-only source journal balances with opening equity');
    $replay = accountingOpeningCutoverCommit($pdo, 1, $entityId, '', '', $ap,
        $preview['preview_token'], null);
    $assert($replay['idempotent_replay'] && $replay['journal_entry_id'] === $posted['journal_entry_id'],
        'AP-only retry is idempotent');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = 1')->fetchColumn()
    === $baseline['journals'], 'rollback removed AP-only journal');
echo "Accounting opening documents MariaDB: {$checks} checks passed.\n";
