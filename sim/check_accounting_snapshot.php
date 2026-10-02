<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/tenant_scope.php';
require_once __DIR__ . '/../modules/accounting/lib/standard_reports.php';
require_once __DIR__ . '/lib/invariants.php';

$opts = getopt('', ['tenant:', 'expect-core-pack']);
$tenantId = (int) ($opts['tenant'] ?? 0);
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php sim/check_accounting_snapshot.php --tenant=ID [--expect-core-pack]\n");
    exit(2);
}

$pdo = getDB();
if (!$pdo) throw new RuntimeException('Database unavailable');
$tenant = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
$tenant->execute(['id' => $tenantId]);
if ((int) $tenant->fetchColumn() !== 1) {
    fwrite(STDERR, "Refusing to inspect a non-simulation tenant.\n");
    exit(3);
}
setRequestTenantId($tenantId);

$entity = $pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id = :tenant_id AND code = "SIM"');
$entity->execute(['tenant_id' => $tenantId]);
$entityId = (int) $entity->fetchColumn();
if ($entityId <= 0) throw new RuntimeException('Simulation entity is missing');

$income = reportIncomeStatement($tenantId, '2026-01-01', '2026-12-31', $entityId);
$balance = reportBalanceSheet($tenantId, '2026-12-31', $entityId);
$cashFlow = reportCashFlowIndirect($tenantId, '2026-01-01', '2026-12-31', $entityId);
$trial = accountingTrialBalance($tenantId, '2026-12-31', $entityId);
$accounts = [];
foreach ($trial as $row) {
    if (in_array((string) $row['code'], ['1000', '1010', '1100', '2000', '4000', '6990'], true)) {
        $accounts[$row['code']] = (float) $row['balance_signed'];
    }
}

$ap = $pdo->prepare(
    'SELECT COALESCE(SUM(amount_due), 0) FROM ap_bills
      WHERE tenant_id = :tenant_id AND status IN ("approved", "partially_paid")'
);
$ap->execute(['tenant_id' => $tenantId]);
$apDue = round((float) $ap->fetchColumn(), 2);
$ar = $pdo->prepare(
    'SELECT COALESCE(SUM(amount_due), 0) FROM billing_invoices
      WHERE tenant_id = :tenant_id AND status IN ("sent", "partially_paid")'
);
$ar->execute(['tenant_id' => $tenantId]);
$arDue = round((float) $ar->fetchColumn(), 2);
$je = $pdo->prepare('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = :tenant_id AND status = "posted"');
$je->execute(['tenant_id' => $tenantId]);
$jeCount = (int) $je->fetchColumn();

$checks = [
    'balance_sheet_balanced' => (bool) $balance['balanced'],
    'cash_flow_balanced' => (bool) $cashFlow['balanced'],
    'ap_matches_gl' => abs($apDue - ($accounts['2000'] ?? 0.0)) < 0.01,
    'ar_matches_gl' => abs($arDue - ($accounts['1100'] ?? 0.0)) < 0.01,
    'posted_source_links' => simInvariantPostedSourceLinks($pdo, $tenantId)['ok'],
];
if (isset($opts['expect-core-pack'])) {
    $bank = $pdo->prepare(
        'SELECT COUNT(*) FROM accounting_bank_statement_lines l
           JOIN accounting_bank_accounts b ON b.id = l.bank_account_id AND b.tenant_id = l.tenant_id
           JOIN accounting_journal_entries j ON j.id = l.matched_je_id AND j.tenant_id = l.tenant_id
          WHERE l.tenant_id = :tenant_id AND l.fitid = "SIM-BANK-0001"
            AND l.amount = -850 AND l.match_status = "matched"
            AND b.gl_account_code = "1000" AND b.entity_id = j.entity_id AND j.status = "posted"'
    );
    $bank->execute(['tenant_id' => $tenantId]);
    $matchedBankLines = (int) $bank->fetchColumn();
    $checks['expected_sources'] = $jeCount === 6 && $apDue === 2250.0 && $arDue === 2500.0;
    $checks['expected_income'] = (float) $income['total_revenue'] === 2500.0
        && (float) $income['total_expense'] === 5500.0
        && (float) $income['net_income'] === -3000.0;
    $checks['expected_cash'] = ($accounts['1000'] ?? 0.0) === -3250.0
        && (float) $cashFlow['cash_change_from_gl'] === -3250.0
        && (float) $cashFlow['net_change_in_cash'] === -3250.0
        && !$cashFlow['untagged_warning'];
    $checks['expected_bank_match'] = $matchedBankLines === 1;
}

echo json_encode([
    'tenant_id' => $tenantId,
    'entity_id' => $entityId,
    'posted_journal_entries' => $jeCount,
    'ap_due' => $apDue,
    'ar_due' => $arDue,
    'accounts' => $accounts,
    'income' => [
        'revenue' => $income['total_revenue'],
        'expense' => $income['total_expense'],
        'net' => $income['net_income'],
    ],
    'balance_sheet' => [
        'assets' => $balance['total_assets'],
        'liabilities' => $balance['total_liabilities'],
        'equity' => $balance['total_equity'],
        'balanced' => $balance['balanced'],
    ],
    'cash_flow' => [
        'net_change' => $cashFlow['net_change_in_cash'],
        'gl_change' => $cashFlow['cash_change_from_gl'],
        'reconciliation_diff' => $cashFlow['reconciliation_diff'],
        'untagged_warning' => $cashFlow['untagged_warning'],
    ],
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
