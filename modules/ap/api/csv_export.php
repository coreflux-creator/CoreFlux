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

use Core\CsvExportService;

$ctx  = api_require_auth();
$user = $ctx['user'];
rbac_legacy_require($user, 'ap.view');

$where  = ['tenant_id = :tenant_id'];
$params = [];
if (!empty($_GET['type']))     { $where[] = 'vendor_type = :vt';     $params['vt']  = $_GET['type']; }
if (!empty($_GET['category'])) { $where[] = 'vendor_category = :cat'; $params['cat'] = $_GET['category']; }

$rows = scopedQuery(
    'SELECT vendor_name, vendor_type, vendor_category,
            default_terms, remit_to_email, remit_to_phone,
            payment_method, tax_id_last4, payment_account_last4,
            requires_1099, last_bill_at
       FROM ap_vendors_index
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY vendor_name ASC',
    $params
);

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
