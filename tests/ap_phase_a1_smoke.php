<?php
/**
 * AP Phase A1 smoke test.
 *
 *  - Expense receipt upload + AI extract (extract_receipt route, attach_line route)
 *  - CSV export endpoint with bills / payments / expenses / 1099 / gusto_contractors types
 *  - Manifest declares ap.export.run perm + ap.export.csv audit + new expense audits
 *  - APModule has Export route, ExpensesList has status / mine filters & pill
 *  - ExpenseCreate uploads + extracts receipts per line
 *  - Sidebar in core/modules.php exposes Export action
 *
 * Static asserts only — no DB / network.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

echo "AP expenses.php — receipt upload & AI extract\n";
$exp = (string) file_get_contents(__DIR__ . '/../modules/ap/api/expenses.php');
$a('upload_url action',                       strpos($exp, "action === 'upload_url'") !== false);
$a('upload_url uses expense_line entity',     strpos($exp, "'expense_line'") !== false);
$a('attach_line action',                      strpos($exp, "action === 'attach_line'") !== false);
$a('attach_line writes receipt_storage_object_id', strpos($exp, 'receipt_storage_object_id = :s') !== false);
$a('attach_line audits ap.expense.line.attachment.added', strpos($exp, 'ap.expense.line.attachment.added') !== false);
$a('extract_receipt action',                  strpos($exp, "action === 'extract_receipt'") !== false);
$a('extract_receipt feature_key',             strpos($exp, "ap.expense.line.from_receipt") !== false);
$a('extract_receipt schema covers category',  strpos($exp, '"meals"|"travel"|"mileage"|"supplies"|"software"|"lodging"|"other"') !== false);
$a('extract_receipt audits',                  strpos($exp, 'ap.expense.line.extracted_from_receipt') !== false);
$a('extract_receipt returns review_required', strpos($exp, "'review_required' => true") !== false);

echo "\nAP export.php — CSV streaming\n";
$expp = __DIR__ . '/../modules/ap/api/export.php';
$a('export.php exists', file_exists($expp));
$exc = (string) file_get_contents($expp);
$a('requires ap.export.run',                  strpos($exc, "rbac_legacy_require(\$user, 'ap.export.run')") !== false);
$a('emits text/csv content-type',             strpos($exc, "text/csv") !== false);
$a('emits Content-Disposition attachment',    strpos($exc, 'Content-Disposition: attachment') !== false);
$a('handles type=bills',                      strpos($exc, "\$type === 'bills'") !== false);
$a('bills CSV headers',                       strpos($exc, "'bill_number'") !== false && strpos($exc, "'vendor_name'") !== false && strpos($exc, "'total'") !== false);
$a('handles type=payments',                   strpos($exc, "\$type === 'payments'") !== false);
$a('payments CSV headers',                    strpos($exc, "'pay_date'") !== false && strpos($exc, "'method'") !== false);
$a('handles type=expenses',                   strpos($exc, "\$type === 'expenses'") !== false);
$a('expenses joins lines + reports',          strpos($exc, 'ap_expense_report_lines erl') !== false && strpos($exc, 'JOIN ap_expense_reports er') !== false);
$a('handles type=1099',                       strpos($exc, "\$type === '1099'") !== false);
$a('1099 filters tax_year',                   strpos($exc, 'tax_year = :y') !== false);
$a('handles type=gusto_contractors',          strpos($exc, "\$type === 'gusto_contractors'") !== false);
$a('Gusto CSV columns match Gusto spec',
    strpos($exc, "'first_name'") !== false &&
    strpos($exc, "'last_name'") !== false &&
    strpos($exc, "'type'") !== false &&
    strpos($exc, "'wage'") !== false &&
    strpos($exc, "'reimbursement'") !== false &&
    strpos($exc, "'bonus'") !== false);
$a('Gusto only includes 1099 / C2C vendors',  strpos($exc, "'1099_individual','c2c_corp'") !== false);
$a('Gusto only includes sent/cleared payments', strpos($exc, "'sent','cleared'") !== false);
$a('export audits ap.export.csv',             strpos($exc, 'ap.export.csv') !== false);
$a('rejects unknown type',                    strpos($exc, 'Unknown export type') !== false);
$a('rejects non-GET',                         strpos($exc, "'Method not allowed', 405") !== false);

echo "\nAP manifest — Phase A1 additions\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/ap/manifest.php');
$a('manifest declares ap.export.run perm',    strpos($man, "'ap.export.run'") !== false);
$a('manifest declares ap.export.csv audit',   strpos($man, "'ap.export.csv'") !== false);
$a('manifest declares expense receipt audits',
    strpos($man, "'ap.expense.line.attachment.added'") !== false &&
    strpos($man, "'ap.expense.line.extracted_from_receipt'") !== false);
$a('manifest action Export route',            strpos($man, "'route' => 'export'") !== false);

echo "\nAP UI — APModule, ExpenseCreate, ExpensesList, Export\n";
$mod  = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/APModule.jsx');
$a('APModule imports Export',                 strpos($mod, "from './Export'") !== false);
$a('APModule routes export',                  strpos($mod, 'path="export"') !== false);
$a('APModule navItems Export',                strpos($mod, "label: 'Export'") !== false);

$ec   = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/ExpenseCreate.jsx');
$a('ExpenseCreate imports uploads helper',    strpos($ec, "from '../../../dashboard/src/lib/uploads'") !== false);
$a('ExpenseCreate has receipt upload input',  strpos($ec, 'ap-expense-line-receipt-upload-') !== false);
$a('ExpenseCreate has receipt input testid',  strpos($ec, 'ap-expense-line-receipt-input-') !== false);
$a('ExpenseCreate has AI extract button',     strpos($ec, 'ap-expense-line-receipt-extract-') !== false);
$a('ExpenseCreate calls extract_receipt API', strpos($ec, "expenses.php?action=extract_receipt") !== false);
$a('ExpenseCreate ReceiptCell defined',       strpos($ec, 'function ReceiptCell') !== false);
$a('ExpenseCreate pre-fills from draft',
    strpos($ec, 'd.expense_date') !== false &&
    strpos($ec, 'd.merchant') !== false &&
    strpos($ec, 'd.amount') !== false);

$el   = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/ExpensesList.jsx');
$a('ExpensesList status filter',              strpos($el, 'ap-expenses-filter-status') !== false);
$a('ExpensesList mine filter',                strpos($el, 'ap-expenses-filter-mine') !== false);
$a('ExpensesList StatusPill',                 strpos($el, 'function StatusPill') !== false);
$a('ExpensesList status pill testid',         strpos($el, 'ap-expense-status-') !== false);

$xui  = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/Export.jsx');
$a('Export page testid',                      strpos($xui, 'data-testid="ap-export"') !== false);
$a('Export bills card',                       strpos($xui, 'ap-export-bills') !== false);
$a('Export payments card',                    strpos($xui, 'ap-export-payments') !== false);
$a('Export expenses card',                    strpos($xui, 'ap-export-expenses') !== false);
$a('Export 1099 card',                        strpos($xui, 'ap-export-1099') !== false);
$a('Export Gusto card',                       strpos($xui, 'ap-export-gusto') !== false);
$a('Export from / to / tax_year inputs',
    strpos($xui, 'ap-export-from')     !== false &&
    strpos($xui, 'ap-export-to')       !== false &&
    strpos($xui, 'ap-export-tax-year') !== false);

echo "\nSidebar (core/modules.php)\n";
$smod = (string) file_get_contents(__DIR__ . '/../core/modules.php');
$a('sidebar AP Export action', strpos($smod, "'route' => 'export'")   !== false &&
                                strpos($smod, "'ap.export.run'")       !== false);

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
