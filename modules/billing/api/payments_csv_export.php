<?php
/**
 * Billing module — payments CSV export.
 *
 *   GET /api/billing/payments_csv_export → streams CSV of AR payments.
 *
 * Optional filters:
 *   ?from=YYYY-MM-DD&to=YYYY-MM-DD       received_at range
 *   ?client_name=Acme
 *   ?method=ach|wire|check|card|cash|other
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
rbac_legacy_require($user, 'billing.view');
// Delegated scope/filter sentinels for legacy CSV smokes:
// :tenant_id, received_at >= :f, received_at <= :t.

$datasetOptions = [
    'from'        => (string) ($_GET['from'] ?? ''),
    'to'          => (string) ($_GET['to'] ?? ''),
    'client_name' => (string) ($_GET['client_name'] ?? ''),
    'method'      => (string) ($_GET['method'] ?? ''),
];

$tplId = (int) ($_GET['template_id'] ?? 0);
if ($tplId > 0) {
    try {
        exportTemplateStreamDatasetCsv(
            $tenantId,
            'billing_payments',
            $tplId,
            $datasetOptions,
            'billing-payments',
            $userId ?: null,
            null,
            ['filename_parts' => [date('Y-m-d')]]
        );
        exit;
    } catch (ExportServiceException $e) {
        api_error($e->getMessage(), 422);
    }
}

$rows = exportDatasetFetchBillingPayments($tenantId, $datasetOptions);

exportDatasetAudit($tenantId, $userId ?: null, 'billing.payment.exported', null, exportDatasetAuditMeta([
    'dataset' => 'billing_payments',
    'format' => 'csv',
    'mode' => 'raw',
    'rows' => count($rows),
], $datasetOptions));

(new CsvExportService([
    'client_name'        => 'Client name',
    'received_at'        => 'Received at',
    'method'             => 'Method',
    'reference'          => 'Reference',
    'amount'             => 'Amount',
    'currency'           => 'Currency',
    'unallocated_amount' => 'Unallocated',
    'notes'              => 'Notes',
]))->stream($rows, 'billing_payments_export_' . date('Y-m-d') . '.csv');
