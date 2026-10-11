<?php
/**
 * Migration 007 + line item_type non-labor support — contract smoke.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function ($n, $c) use (&$pass, &$fail) {
    if ($c) { echo "  \u{2713} {$n}\n"; $pass++; } else { echo "  \u{2717} {$n}\n"; $fail++; }
};

echo "Migration 007\n";
$sql = (string) file_get_contents(__DIR__ . '/../modules/billing/migrations/007_line_item_types.sql');
$a('migration exists',                          strlen($sql) > 0);
$a('utf8mb4_unicode_ci safe',                   strpos($sql, 'utf8mb4_0900_ai_ci') === false);
$a('ap_bill_lines.item_type',                   strpos($sql, "TABLE_NAME='ap_bill_lines' AND COLUMN_NAME='item_type'") !== false);
$a('billing_invoice_lines.item_type',           strpos($sql, "TABLE_NAME='billing_invoice_lines' AND COLUMN_NAME='item_type'") !== false);
$a('billing_invoice_lines.gl_revenue_account_code', strpos($sql, "TABLE_NAME='billing_invoice_lines' AND COLUMN_NAME='gl_revenue_account_code'") !== false);
$a('item_type ENUM has 11 categories',          substr_count($sql, "ENUM(\"labor\",\"expense\",\"materials\",\"fixed_fee\",\"milestone\",\"discount\",\"subscription\",\"mileage\",\"per_diem\",\"reimbursement\",\"other\")") >= 2);
$a('item_type DEFAULT labor',                   strpos($sql, 'NOT NULL DEFAULT "labor"') !== false);
$a('billing source_type expanded',              strpos($sql, "ENUM('time','manual','expense','recurring','milestone')") !== false);

echo "\nLib (apNormalizeItemType)\n";
$ap = (string) file_get_contents(__DIR__ . '/../modules/ap/lib/ap.php');
$a('AP_LINE_ITEM_TYPES const',                  strpos($ap, "const AP_LINE_ITEM_TYPES = [") !== false);
$a('apNormalizeItemType() exists',              strpos($ap, 'function apNormalizeItemType') !== false);
$a('time → labor mapping',                      strpos($ap, "'time'    => 'labor'") !== false);
$a('expense → expense mapping',                 strpos($ap, "'expense' => 'expense'") !== false);
$a('default → other (safer than labor)',        strpos($ap, "default   => 'other'") !== false);
$a('time-bundle line builder stamps item_type=labor', strpos($ap, "'item_type'               => 'labor'") !== false);

$bl = (string) file_get_contents(__DIR__ . '/../modules/billing/lib/billing.php');
$a('billing time-bundle line stamps item_type=labor', strpos($bl, "'item_type'        => 'labor'") !== false);

echo "\nAPI persistence\n";
$bills = (string) file_get_contents(__DIR__ . '/../modules/ap/api/bills.php');
$billDrafts = (string) file_get_contents(__DIR__ . '/../modules/ap/lib/bill_drafts.php');
$a('AP manual POST inserts item_type column',   strpos($bills, 'apCreateManualBill(') !== false
                                                 && strpos($billDrafts, 'INSERT INTO ap_bill_lines') !== false
                                                 && strpos($billDrafts, 'item_type') !== false);
$a('AP manual POST normalises item_type',       strpos($billDrafts, "apNormalizeItemType(\$line['item_type'] ?? null, 'manual')") !== false);
$a('AP time-bundle path threads item_type',     strpos($bills, "\$l['item_type'] = apNormalizeItemType") !== false);

$inv = (string) file_get_contents(__DIR__ . '/../modules/billing/api/invoices.php');
$invoiceDrafts = (string) file_get_contents(__DIR__ . '/../modules/billing/lib/invoice_drafts.php');
$a('Billing requires AP lib for normalisation', strpos($inv, "require_once __DIR__ . '/../../ap/lib/ap.php'") !== false);
$a('Billing manual POST inserts item_type',     strpos($inv, 'billingCreateDirectInvoiceDraft(') !== false
    && strpos($invoiceDrafts, 'item_type, description, quantity') !== false);
$a('Billing manual POST inserts gl_revenue_account_code', strpos($invoiceDrafts, 'gl_revenue_account_code') !== false);
$a('Billing manual POST normalises item_type',
   strpos($invoiceDrafts, "apNormalizeItemType(\$line['item_type'] ?? null, 'manual')") !== false);
$a('Billing time-bundle path threads item_type', strpos($inv, "\$l['item_type']  = apNormalizeItemType") !== false);

echo "\nGL post groups by revenue account\n";
$a('Billing GL post buckets revenue by gl_revenue_account_code', strpos($inv, 'GROUP BY item_type, gl_revenue_account_code') !== false);
$a('Billing GL fallback to 4000 when no override', strpos($inv, "\$code = \$r['gl_revenue_account_code'] ?: '4000'") !== false);
$a('Billing GL skips zero buckets but retains negative discounts',
   strpos($inv, 'if (abs($amt) <= 0.005) continue') !== false
   && strpos($inv, "'debit' => \$amt < 0 ? abs(\$amt) : 0") !== false);

echo "\nReact LineItemEditor\n";
$ed = (string) file_get_contents(__DIR__ . '/../dashboard/src/components/LineItemEditor.jsx');
$a('exports ITEM_TYPES with 11 entries',        substr_count($ed, "{ value: '") === 11);
$a('exports blankLine helper',                  strpos($ed, 'export function blankLine') !== false);
$a('item-type select per row',                  strpos($ed, '${testIdPrefix}-line-${i}-item-type') !== false);
$a('description input per row',                 strpos($ed, '${testIdPrefix}-line-${i}-description') !== false);
$a('quantity input per row',                    strpos($ed, '${testIdPrefix}-line-${i}-quantity') !== false);
$a('unit input per row',                        strpos($ed, '${testIdPrefix}-line-${i}-unit') !== false);
$a('unit_price input per row',                  strpos($ed, '${testIdPrefix}-line-${i}-unit-price') !== false);
$a('GL field per row',                          strpos($ed, '${testIdPrefix}-line-${i}-gl') !== false);
$a('subtotal cell per row',                     strpos($ed, '${testIdPrefix}-line-${i}-subtotal') !== false);
$a('add line button',                           strpos($ed, '${testIdPrefix}-add-line') !== false);
$a('item-type change resets default unit',
   preg_match('/setLine\(i,\s*\{[^}]*item_type:\s*value[^}]*unit:\s*meta\.defaultUnit/s', $ed) === 1);

echo "\nReact BillCreate page\n";
$bc = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/BillCreate.jsx');
$a('page testid',                               strpos($bc, 'data-testid="ap-bill-create"') !== false);
$a('vendor typeahead',                          strpos($bc, 'VendorTypeahead') !== false && strpos($bc, 'testId="ap-bill-create-vendor"') !== false);
$a('uses LineItemEditor',                       strpos($bc, "import LineItemEditor") !== false);
$a('passes glField=gl_expense_account_code',    strpos($bc, 'glField="gl_expense_account_code"') !== false);
$choices = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/directBillAccounts.js');
$a('fetches active account dimension rules',    strpos($bc, '/modules/accounting/api/accounts.php?active=1&include_required_dimensions=1') !== false);
$a('offers only postable expense classes',      strpos($choices, "['expense', 'other_expense'].includes(account.account_type)") !== false
    && strpos($choices, 'Number(account.is_postable) === 1') !== false);
$a('disables accounts needing absent dimensions', strpos($choices, 'disabled: missing.length > 0 || blocked.length > 0') !== false);
$a('vendor type select',                        strpos($bc, 'data-testid="ap-bill-create-vendor-type"') !== false);
$a('manual bill sends saved vendor ID and 1099 flag', strpos($bc, 'vendor_id: vendor.id || null') !== false
    && strpos($bc, 'is_1099_eligible: Number(vendor.requires_1099) === 1') !== false
    && strpos($bc, 'disabled={Boolean(vendor?.id)}') !== false);
$vendorPick = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/VendorTypeahead.jsx');
$vendorQuick = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/VendorQuickCreate.jsx');
$a('vendor pick carries the saved 1099 setting', strpos($vendorPick, 'requires_1099: Number(row.requires_1099) === 1') !== false
    && strpos($vendorQuick, 'requires_1099: requires1099') !== false
    && strpos($vendorQuick, 'id: res.id') !== false);
$a('AP service verifies saved vendor and never promotes its 1099 flag from bill type',
    strpos($billDrafts, 'WHERE tenant_id = :t AND id = :id FOR UPDATE') !== false
    && strpos($billDrafts, "\$vendorRequires1099 = (int) \$savedVendor['requires_1099'] === 1") !== false
    && strpos($billDrafts, 'requires_1099 = GREATEST') === false);
$apDomain = (string) file_get_contents(__DIR__ . '/../modules/ap/lib/ap.php');
$a('1099 ledger uses saved vendor classification', strpos($apDomain, 'AND v.requires_1099 = 1') !== false
    && strpos($apDomain, 'AND b.vendor_type IN ("1099_individual","c2c_corp")') === false);
$a('submit posts to bills.php',                 strpos($bc, "api.post('/modules/ap/api/bills.php'") !== false);

echo "\nReact InvoiceCreate page\n";
$ic = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/InvoiceCreate.jsx');
$a('page testid',                               strpos($ic, 'data-testid="billing-invoice-create"') !== false);
$a('client typeahead role=client',              strpos($ic, 'role="client"') !== false);
$a('uses LineItemEditor',                       strpos($ic, "import LineItemEditor") !== false);
$a('passes glField=gl_revenue_account_code',    strpos($ic, 'glField="gl_revenue_account_code"') !== false);
$a('fetches revenue accounts only',             strpos($ic, '/modules/accounting/api/accounts.php?type=revenue') !== false);
$a('separate internal/external notes',          strpos($ic, 'notes-internal') !== false && strpos($ic, 'notes-external') !== false);
$a('submit posts to invoices route',            strpos($ic, "api.post('/api/v1/billing/invoices'") !== false);

echo "\nRoutes + list buttons\n";
$apMod = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/APModule.jsx');
$a('AP route bills/new',                        strpos($apMod, 'path="bills/new"') !== false);
$bMod = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/BillingModule.jsx');
$a('Billing route invoices/new',                strpos($bMod, 'path="invoices/new"') !== false);
$apList = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/BillsList.jsx');
$a('Bills list "+ New bill" link',              strpos($apList, 'data-testid="ap-new-bill"') !== false);
$bList  = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/InvoicesList.jsx');
$a('Invoices list "+ New invoice" link',        strpos($bList, 'data-testid="billing-new-invoice"') !== false);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
