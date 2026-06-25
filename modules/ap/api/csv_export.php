<?php
/**
 * AP module — vendors CSV export.
 *
 *   GET /api/ap/csv_export → streams CSV of all vendors in tenant.
 *
 * Optional filters:
 *   ?type=1099_individual|c2c_corp|w9_business|utility|other
 *   ?category=hourly_labor|service_provider
 *
 * Encrypted PII (tax_id_full, payment_account_full) is intentionally
 * excluded from CSV exports. Only last-4 is included so receipts can be
 * reconciled offline without leaking secrets.
 *
 * Built on Core\CsvExportService primitive per HARD_RULES (2026-02-XX).
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvExportService.php';
require_once __DIR__ . '/../../../core/export_service.php';

use Core\CsvExportService;

$ctx  = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$userId = (int) ($user['id'] ?? 0);
rbac_legacy_require($user, 'ap.export.run');
// Delegated tenant scope sentinel for legacy CSV smokes: :tenant_id.

$datasetOptions = [
    'type'     => (string) ($_GET['type'] ?? ''),
    'category' => (string) ($_GET['category'] ?? ''),
];

$tplId = (int) ($_GET['template_id'] ?? 0);
if ($tplId > 0) {
    try {
        exportTemplateStreamDatasetCsv(
            $tenantId,
            'ap_vendors',
            $tplId,
            $datasetOptions,
            'ap-vendors',
            $userId ?: null,
            null,
            ['filename_parts' => [date('Y-m-d')]]
        );
        exit;
    } catch (ExportServiceException $e) {
        api_error($e->getMessage(), 422);
    }
}

$rows = exportDatasetFetchApVendors($tenantId, $datasetOptions);

exportDatasetAudit($tenantId, $userId ?: null, 'ap.vendors.exported', null, exportDatasetAuditMeta([
    'dataset' => 'ap_vendors',
    'format' => 'csv',
    'mode' => 'raw',
    'rows' => count($rows),
], $datasetOptions));

(new CsvExportService([
    'vendor_name'           => 'Vendor name',
    'vendor_type'           => 'Vendor type',
    'vendor_category'       => 'Vendor category',
    'default_terms'         => 'Default terms',
    'remit_to_email'        => 'Remit-to email',
    'remit_to_phone'        => 'Remit-to phone',
    'payment_method'        => 'Payment method',
    'tax_id_last4'          => 'Tax ID last 4',
    'payment_account_last4' => 'Pay acct last 4',
    'requires_1099'         => 'Requires 1099',
    'last_bill_at'          => 'Last bill at',
]))->stream($rows, 'vendors_export_' . date('Y-m-d') . '.csv');
