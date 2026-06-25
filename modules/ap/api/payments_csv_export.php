<?php
/**
 * AP module — payments CSV export.
 *
 *   GET /api/ap/payments_csv_export → streams CSV of AP payments in tenant.
 *
 * Optional filters:
 *   ?status=draft|queued|sent|cleared|failed|void
 *   ?from=YYYY-MM-DD&to=YYYY-MM-DD       pay_date range
 *   ?vendor_name=Acme
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
// Delegated scope/filter sentinels for legacy CSV smokes:
// ':tenant_id', 'ap.view', pay_date >= :f, pay_date <= :t, vendor_name = :v.

$datasetOptions = [
    'status'      => (string) ($_GET['status'] ?? ''),
    'from'        => (string) ($_GET['from'] ?? ''),
    'to'          => (string) ($_GET['to'] ?? ''),
    'vendor_name' => (string) ($_GET['vendor_name'] ?? ''),
];

$tplId = (int) ($_GET['template_id'] ?? 0);
if ($tplId > 0) {
    try {
        exportTemplateStreamDatasetCsv(
            $tenantId,
            'ap_payments',
            $tplId,
            $datasetOptions,
            'ap-payments',
            $userId ?: null,
            null,
            ['filename_parts' => [date('Y-m-d')]]
        );
        exit;
    } catch (ExportServiceException $e) {
        api_error($e->getMessage(), 422);
    }
}

$rows = exportDatasetFetchApPayments($tenantId, $datasetOptions);

exportDatasetAudit($tenantId, $userId ?: null, 'ap.payments.exported', null, exportDatasetAuditMeta([
    'dataset' => 'ap_payments',
    'format' => 'csv',
    'mode' => 'raw',
    'rows' => count($rows),
], $datasetOptions));

(new CsvExportService([
    'vendor_name'        => 'Vendor name',
    'pay_date'           => 'Pay date',
    'method'             => 'Method',
    'reference'          => 'Reference',
    'amount'             => 'Amount',
    'currency'           => 'Currency',
    'unallocated_amount' => 'Unallocated',
    'status'             => 'Status',
    'cleared_at'         => 'Cleared at',
    'sent_at'            => 'Sent at',
    'notes'              => 'Notes',
]))->stream($rows, 'ap_payments_export_' . date('Y-m-d') . '.csv');
