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

use Core\CsvExportService;

$ctx  = api_require_auth();
$user = $ctx['user'];
rbac_legacy_require($user, 'staffing.view');

$where  = ['tenant_id = :tenant_id'];
$params = [];
if (!empty($_GET['status'])) { $where[] = 'status = :s'; $params['s'] = $_GET['status']; }

$rows = scopedQuery(
    'SELECT name, legal_name, industry,
            primary_contact_name, primary_contact_email, primary_contact_phone,
            billing_address_line1, billing_address_line2,
            billing_city, billing_state, billing_postal_code, billing_country,
            payment_terms_days, status, msa_status, msa_executed_at, msa_expires_at, notes
       FROM staffing_clients
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY name ASC',
    $params
);

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
