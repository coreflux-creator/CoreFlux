<?php
/**
 * Staffing module — clients CSV export.
 *
 *   GET /api/staffing/csv_export → streams CSV of clients in tenant.
 *
 * Optional filter:
 *   ?status=active|prospect|on_hold|inactive|closed
 *
 * Built on Core\CsvExportService primitive per HARD_RULES (2026-02-XX).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvExportService.php';
require_once __DIR__ . '/../../../core/export_service.php';

use Core\CsvExportService;

$ctx  = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$userId = (int) ($user['id'] ?? 0);
rbac_legacy_require($user, 'staffing.export.run');

$datasetOptions = [
    'status' => (string) ($_GET['status'] ?? ''),
    'q'      => (string) ($_GET['q'] ?? ''),
];

$tplId = (int) ($_GET['template_id'] ?? 0);
if ($tplId > 0) {
    try {
        exportTemplateStreamDatasetCsv(
            $tenantId,
            'staffing_clients',
            $tplId,
            $datasetOptions,
            'staffing-clients',
            $userId ?: null,
            null,
            ['filename_parts' => [date('Y-m-d')]]
        );
        exit;
    } catch (ExportServiceException $e) {
        api_error($e->getMessage(), 422);
    }
}

$rows = exportDatasetFetchStaffingClients($tenantId, $datasetOptions);

exportDatasetAudit($tenantId, $userId ?: null, 'staffing.clients.exported', null, [
    'dataset' => 'staffing_clients',
    'format' => 'csv',
    'mode' => 'raw',
    'rows' => count($rows),
    'option_keys' => array_values(array_filter(array_keys($datasetOptions), fn($key) => $datasetOptions[$key] !== '')),
]);

(new CsvExportService([
    'name'                  => 'Client name',
    'legal_name'            => 'Legal name',
    'industry'              => 'Industry',
    'primary_contact_name'  => 'Primary contact name',
    'primary_contact_email' => 'Primary contact email',
    'primary_contact_phone' => 'Primary contact phone',
    'billing_address_line1' => 'Billing address line 1',
    'billing_address_line2' => 'Billing address line 2',
    'billing_city'          => 'Billing city',
    'billing_state'         => 'Billing state',
    'billing_postal_code'   => 'Billing postal code',
    'billing_country'       => 'Billing country',
    'payment_terms_days'    => 'Payment terms (days)',
    'status'                => 'Status',
    'msa_status'            => 'MSA status',
    'msa_executed_at'       => 'MSA executed at',
    'msa_expires_at'        => 'MSA expires at',
    'notes'                 => 'Notes',
]))->stream($rows, 'clients_export_' . date('Y-m-d') . '.csv');
