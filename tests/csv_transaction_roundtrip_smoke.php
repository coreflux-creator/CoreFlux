<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$billingImport = (string) file_get_contents($root . '/modules/billing/api/csv_import.php');
$billingExport = (string) file_get_contents($root . '/modules/billing/api/csv_export.php');
$billsImport = (string) file_get_contents($root . '/modules/ap/api/bills_csv_import.php');
$billsExport = (string) file_get_contents($root . '/modules/ap/api/bills_csv_export.php');
$apPaymentsImport = (string) file_get_contents($root . '/modules/ap/api/payments_csv_import.php');
$apPaymentsExport = (string) file_get_contents($root . '/modules/ap/api/payments_csv_export.php');
$arPaymentsImport = (string) file_get_contents($root . '/modules/billing/api/payments_csv_import.php');
$arPaymentsExport = (string) file_get_contents($root . '/modules/billing/api/payments_csv_export.php');
$exportDatasets = (string) file_get_contents($root . '/core/export_datasets.php');
$bulk = (string) file_get_contents($root . '/dashboard/src/pages/CsvBulkImport.jsx');
$invoiceUi = (string) file_get_contents($root . '/modules/billing/ui/InvoicesCsvImport.jsx');
$billUi = (string) file_get_contents($root . '/modules/ap/ui/BillsCsvImport.jsx');
$groupHelper = (string) file_get_contents($root . '/core/accounting/csv_document_entity.php');

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL: {$label}\n";
};

$check('invoice export is line-level',
    str_contains($billingExport, 'LEFT JOIN billing_invoice_lines')
    && str_contains($billingExport, "'line_description' => 'Line description'"));
$check('invoice export carries stable record and line ids',
    str_contains($billingExport, 'i.id AS invoice_id')
    && str_contains($billingExport, 'l.id AS line_id'));
$check('invoice CSV round trip retains line item types',
    str_contains($billingImport, "'line_item_type'   => ['label' => 'Line item type'")
    && str_contains($billingImport, "'item_type'  => \$metadata['item_type']")
    && str_contains($billingExport, 'l.item_type AS line_item_type')
    && str_contains($billingExport, "'line_item_type'   => 'Line item type'"));
$check('invoice CSV round trip retains catalog and revenue account identity',
    str_contains($billingImport, "'line_catalog_item_id' => ['label' => 'Line catalog item ID'")
    && str_contains($billingImport, "'line_gl_revenue_account_code' => ['label' => 'Line revenue account'")
    && str_contains($billingImport, "'catalog_item_id' => \$metadata['catalog_item_id']")
    && str_contains($billingImport, "'gl_rev'     => \$metadata['gl_revenue_account_code']")
    && str_contains($billingExport, 'l.catalog_item_id AS line_catalog_item_id')
    && str_contains($billingExport, 'l.gl_revenue_account_code AS line_gl_revenue_account_code'));
$check('invoice CSV round trip retains customer company identity',
    str_contains($billingImport, "'client_company_id' => ['label' => 'Client company ID'")
    && str_contains($billingImport, 'billingResolveDirectInvoiceClientCompanyId(')
    && str_contains($billingImport, "'client_company_id' => \$clientCompanyId")
    && str_contains($billingExport, 'i.client_company_id')
    && str_contains($billingExport, "'client_company_id' => 'Client company ID'"));
$check('invoice import exposes update mode',
    str_contains($billingImport, "_GET['update_existing']")
    && str_contains($billingImport, "scopedUpdate('billing_invoices'"));
$check('invoice CSV updates only unpaid unposted drafts',
    str_contains($billingImport, "!== 'draft'")
    && str_contains($billingImport, "journal_entry_id")
    && str_contains($billingImport, "amount_paid"));
$check('time-sourced invoice lines remain source-controlled',
    str_contains($billingImport, 'source_type <> "manual"')
    && str_contains($billingImport, 'must be rebuilt from Time settlement'));
$check('invoice CSV preserves legal entity and validates whole groups on commit',
    str_contains($billingImport, "'entity_id'      => \$entity['id']")
    && substr_count($billingImport, 'accountingCsvReviewDocumentGroups(') === 2
    && str_contains($billingExport, 'e.code AS entity_code'));

$check('bill export is line-level',
    str_contains($billsExport, 'LEFT JOIN ap_bill_lines')
    && str_contains($billsExport, "'line_description' => 'Line description'"));
$check('bill export carries stable record and line ids',
    str_contains($billsExport, 'b.id AS bill_id')
    && str_contains($billsExport, 'l.id AS line_id'));
$check('bill import exposes update mode',
    str_contains($billsImport, "_GET['update_existing']")
    && str_contains($billsImport, "scopedUpdate('ap_bills'"));
$check('bill CSV updates only editable manual records',
    str_contains($billsImport, "['inbox', 'pending_review', 'pending_approval']")
    && str_contains($billsImport, "['source'] ?? '') !== 'manual'")
    && str_contains($billsImport, 'Only unpaid, unposted manual bills'));
$check('source-generated bill lines remain source-controlled',
    str_contains($billsImport, 'source_type <> "manual"')
    && str_contains($billsImport, 'must be rebuilt from their source'));
$check('bill CSV preserves legal entity and validates whole groups on commit',
    str_contains($billsImport, "'entity_id'      => \$entity['id']")
    && substr_count($billsImport, 'accountingCsvReviewDocumentGroups(') === 2
    && str_contains($billsExport, 'e.code AS entity_code'));
$check('invoice and bill previews show resolved legal entity',
    str_contains($invoiceUi, "key: 'entity_code'")
    && str_contains($billUi, "key: 'entity_code'"));
$check('document import controls describe whole-document behavior',
    str_contains($invoiceUi, 'groupLabel="invoice"')
    && str_contains($billUi, 'groupLabel="bill"')
    && str_contains((string) file_get_contents($root . '/dashboard/src/components/CsvImportPage.jsx'), 'Skip invalid documents'));
$check('document and line counts stay separate in import history',
    str_contains($billingImport, "'imported_row_count' => \$importedRows")
    && str_contains($billsImport, "'imported_row_count' => \$importedRows")
    && str_contains((string) file_get_contents($root . '/dashboard/src/components/CsvImportPage.jsx'), 'res?.imported_row_count'));
$check('unassigned document lines block partial invoice and bill imports',
    str_contains($billingImport, "!empty(\$dry['blocking_error'])")
    && str_contains($billsImport, "!empty(\$dry['blocking_error'])")
    && str_contains((string) file_get_contents($root . '/dashboard/src/components/CsvImportPage.jsx'), 'preview.blocking_error'));

$check('AP payment export carries round-trip identity',
    str_contains($apPaymentsExport, "'payment_id'         => 'Payment ID'")
    && str_contains($apPaymentsExport, "'external_id'        => 'External ID (audit / integration)'")
    && str_contains($apPaymentsExport, "'source_system'      => 'Source system'"));
$check('AP payment update mode is explicit and stable-ID first',
    str_contains($apPaymentsImport, "_GET['update_existing']")
    && str_contains($apPaymentsImport, 'if ($paymentId > 0)')
    && str_contains($apPaymentsImport, 'AND source_system = :s AND external_id = :e'));
$check('AP payment CSV protects downstream workflow state',
    str_contains($apPaymentsImport, "!== 'draft'")
    && str_contains($apPaymentsImport, 'ap_payment_allocations')
    && str_contains($apPaymentsImport, 'journal_entry_id')
    && str_contains($apPaymentsImport, 'rail_external_ref')
    && str_contains($apPaymentsImport, 'Only unallocated, unposted, undispatched AP payment drafts'));
$check('AP payment updates recompute the full unallocated amount',
    str_contains($apPaymentsImport, "'unallocated_amount' => \$amount"));

$check('customer receipt export carries round-trip identity',
    str_contains($arPaymentsExport, "'payment_id'         => 'Payment ID'")
    && str_contains($arPaymentsExport, "'external_id'        => 'External ID (audit / integration)'")
    && str_contains($arPaymentsExport, "'source_system'      => 'Source system'"));
$check('customer receipt update mode is explicit and stable-ID first',
    str_contains($arPaymentsImport, "_GET['update_existing']")
    && str_contains($arPaymentsImport, 'if ($paymentId > 0)')
    && str_contains($arPaymentsImport, 'AND source_system = :s AND external_id = :e'));
$check('allocated customer receipts remain source-controlled',
    str_contains($arPaymentsImport, 'billing_payment_allocations')
    && str_contains($arPaymentsImport, '$fullyUnallocated')
    && str_contains($arPaymentsImport, 'Only fully unallocated customer receipts can be updated by CSV'));
$check('shared payment datasets expose external identity',
    substr_count($exportDatasets, "'external_id'        => ['label' => 'External ID'") >= 1
    && substr_count($exportDatasets, 'external_id, source_system') >= 1
    && str_contains($exportDatasets, 'p.external_id')
    && str_contains($exportDatasets, 'p.source_system'));

$check('preview and commit share validated document arithmetic',
    str_contains($groupHelper, 'round($quantity * $unitPrice, 2)')
    && str_contains($groupHelper, 'Line total must equal subtotal plus tax')
    && substr_count($billingImport . $billsImport, 'accountingCsvDocumentLineAmounts($r)') === 4);
$check('bulk importer uses a quote-aware CSV row parser',
    str_contains($bulk, 'function parseCsvRow(')
    && str_contains($bulk, 'function firstCsvRecord(')
    && !str_contains($bulk, "firstLine.split(',')"));
$check('bulk importer enables guarded bill and invoice updates',
    preg_match("/ap_bills:\s*\{.*?supportsUpdate:\s*true,/s", $bulk) === 1
    && preg_match("/billing_invoices:\s*\{.*?supportsUpdate:\s*true,/s", $bulk) === 1);
$check('bulk importer enables guarded AP and AR payment updates',
    preg_match("/ap_payments:\s*\{.*?supportsUpdate:\s*true,/s", $bulk) === 1
    && preg_match("/billing_payments:\s*\{.*?supportsUpdate:\s*true,/s", $bulk) === 1);
$check('bulk update copy explains locked records',
    str_contains($bulk, 'Posted, paid, approved, or otherwise locked records remain read-only.'));

foreach ([
    $root . '/modules/billing/api/csv_import.php',
    $root . '/modules/billing/api/csv_export.php',
    $root . '/modules/ap/api/bills_csv_import.php',
    $root . '/modules/ap/api/bills_csv_export.php',
    $root . '/modules/ap/api/payments_csv_import.php',
    $root . '/modules/ap/api/payments_csv_export.php',
    $root . '/modules/billing/api/payments_csv_import.php',
    $root . '/modules/billing/api/payments_csv_export.php',
    $root . '/core/export_datasets.php',
] as $file) {
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    $check('PHP syntax: ' . basename($file), $code === 0);
}

echo "CSV transaction round-trip smoke: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
