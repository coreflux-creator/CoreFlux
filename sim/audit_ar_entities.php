<?php
/** Read-only, staging-only AR control reconciliation by legal entity. */
declare(strict_types=1);

if (getenv('COREFLUX_ENV') !== 'staging' || PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This audit is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
$tenantArgs = array_values(array_filter($argv, static fn(string $arg): bool => str_starts_with($arg, '--tenant=')));
$tenantId = count($tenantArgs) === 1 ? (int) substr($tenantArgs[0], 9) : 0;
$includeDetails = in_array('--details', $argv, true);
if ($tenantId <= 0) {
    fwrite(STDERR, "Use --tenant=ID.\n");
    exit(2);
}
$pdo = getDB();
$invoiceStmt = $pdo->prepare(
    'SELECT i.id, i.invoice_number, i.entity_id, je.entity_id AS journal_entity_id,
            i.status, i.total, i.amount_paid, i.amount_due, i.journal_entry_id,
            je.status AS journal_status
       FROM billing_invoices i
       LEFT JOIN accounting_journal_entries je
         ON je.tenant_id = i.tenant_id AND je.id = i.journal_entry_id
      WHERE i.tenant_id = :tenant_id ORDER BY i.id'
);
$invoiceStmt->execute(['tenant_id' => $tenantId]);
$invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);
$controlStmt = $pdo->prepare(
    'SELECT je.entity_id, ROUND(SUM(l.debit - l.credit), 2) AS balance
       FROM accounting_journal_entry_lines l
       JOIN accounting_journal_entries je ON je.tenant_id = l.tenant_id AND je.id = l.je_id
       JOIN accounting_accounts a ON a.tenant_id = l.tenant_id AND a.id = l.account_id
      WHERE je.tenant_id = :tenant_id AND je.status IN ("posted", "reversed") AND a.code = "1100"
      GROUP BY je.entity_id ORDER BY je.entity_id'
);
$controlStmt->execute(['tenant_id' => $tenantId]);
$controls = $controlStmt->fetchAll(PDO::FETCH_ASSOC);
$journalDetailStmt = $pdo->prepare(
    'SELECT je.id, je.posting_date, je.entity_id, je.source_module, je.source_ref_type,
            je.source_ref_id, ROUND(SUM(l.debit - l.credit), 2) AS ar_movement
       FROM accounting_journal_entry_lines l
       JOIN accounting_journal_entries je ON je.tenant_id = l.tenant_id AND je.id = l.je_id
       JOIN accounting_accounts a ON a.tenant_id = l.tenant_id AND a.id = l.account_id
      WHERE je.tenant_id = :tenant_id AND je.status IN ("posted", "reversed") AND a.code = "1100"
      GROUP BY je.id, je.posting_date, je.entity_id, je.source_module,
               je.source_ref_type, je.source_ref_id
      ORDER BY je.posting_date, je.id'
);
$byEntity = [];
foreach ($controls as $row) {
    $key = (string) ($row['entity_id'] ?? 'null');
    $byEntity[$key] = ['gl_ar' => (float) $row['balance'], 'open_invoices' => 0.0, 'difference' => 0.0];
}
$exceptions = [];
foreach ($invoices as $invoice) {
    $key = (string) ($invoice['entity_id'] ?: $invoice['journal_entity_id'] ?: 'null');
    $byEntity[$key] ??= ['gl_ar' => 0.0, 'open_invoices' => 0.0, 'difference' => 0.0];
    if (in_array($invoice['status'], ['sent', 'partially_paid'], true)) {
        $byEntity[$key]['open_invoices'] += (float) $invoice['amount_due'];
    }
    $isOpen = in_array($invoice['status'], ['sent', 'partially_paid'], true);
    if ($isOpen && (!$invoice['journal_entry_id'] || $invoice['journal_status'] !== 'posted'
        || !$invoice['entity_id'] || (int) $invoice['entity_id'] !== (int) $invoice['journal_entity_id'])) {
        $exceptions[] = $invoice;
    }
}
foreach ($byEntity as &$entity) {
    $entity['open_invoices'] = round($entity['open_invoices'], 2);
    $entity['difference'] = round($entity['gl_ar'] - $entity['open_invoices'], 2);
}
unset($entity);
$result = ['tenant_id' => $tenantId, 'entities' => $byEntity, 'invoice_exceptions' => $exceptions];
if ($includeDetails) {
    $journalDetailStmt->execute(['tenant_id' => $tenantId]);
    $result['ar_journals'] = $journalDetailStmt->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
$hasDifference = array_filter($byEntity, static fn(array $entity): bool => abs($entity['difference']) >= 0.01);
exit($hasDifference || $exceptions ? 1 : 0);
