<?php
/**
 * AP module — CSV bulk vendor import.
 *
 *   GET  /api/ap/csv_import?action=template
 *   POST /api/ap/csv_import?action=dry_run
 *   POST /api/ap/csv_import?action=commit (+ optional ?skip_invalid=1)
 *
 * Built on Core\CsvImportService primitive per HARD_RULES (2026-02-XX):
 * every primary-entity module MUST expose a CSV import flow so tenants
 * can self-serve initial + ongoing data loads.
 *
 * Scope: imports the ap_vendors_index row with name/type/category/terms/
 * remit + 1099 flag. Encrypted PII (tax_id_full, payment_account_full) is
 * NOT importable via CSV — those must be entered through the secure
 * VendorQuickCreate / VendorsList edit flow.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../lib/ap.php';

use Core\CsvImportService;

CsvImportService::registerSchema('ap_vendors', [
    'fields' => [
        'vendor_name'      => ['label' => 'Vendor name',         'required' => true],
        'vendor_type'      => ['label' => 'Vendor type',
                               'enum'  => ['1099_individual','c2c_corp','w9_business','utility','other']],
        'vendor_category'  => ['label' => 'Vendor category',
                               'enum'  => ['hourly_labor','service_provider']],
        'default_terms'    => ['label' => 'Default terms'],
        'remit_to_email'   => ['label' => 'Remit-to email',      'type' => 'email'],
        'remit_to_phone'   => ['label' => 'Remit-to phone'],
        'payment_method'   => ['label' => 'Payment method',
                               'enum'  => ['ach','wire','check','card','cash','plaid','other']],
        'tax_id_last4'     => ['label' => 'Tax ID last 4'],
        'requires_1099'    => ['label' => 'Requires 1099',       'type' => 'boolean'],
    ],
    'unique_within_batch' => ['vendor_name'],
]);

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'template') {
    RBAC::requirePermission($user, 'ap.bill.create');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vendors_template.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildTemplate('ap_vendors');
    exit;
}

if ($method === 'GET' && $action === 'sample') {
    RBAC::requirePermission($user, 'ap.bill.create');
    $samples = require __DIR__ . '/../../../core/csv_samples.php';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vendors_sample.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildSample('ap_vendors', $samples['ap_vendors'] ?? []);
    exit;
}

if ($method === 'POST' && $action === 'dry_run') {
    RBAC::requirePermission($user, 'ap.bill.create');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $result = CsvImportService::dryRun('ap_vendors', $csv);

    // Surface collisions with existing vendor_name rows in this tenant.
    if ($result['rows']) {
        $names = array_unique(array_filter(array_column($result['rows'], 'vendor_name')));
        if ($names) {
            $placeholders = implode(',', array_fill(0, count($names), '?'));
            $pdo = getDB();
            $stmt = $pdo->prepare(
                "SELECT vendor_name FROM ap_vendors_index
                  WHERE tenant_id = ? AND vendor_name IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$tid], $names));
            $existing = [];
            foreach ($stmt as $r) $existing[$r['vendor_name']] = true;
            foreach ($result['rows'] as $rn => $row) {
                if (!empty($row['vendor_name']) && isset($existing[$row['vendor_name']])) {
                    $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                    $result['errors'][$rn][] = "vendor_name: '{$row['vendor_name']}' already exists in tenant (will be updated on commit)";
                }
            }
            $result['error_count'] = count($result['errors']);
        }
    }
    api_ok($result);
}

if ($method === 'POST' && $action === 'commit') {
    RBAC::requirePermission($user, 'ap.bill.create');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $skipInvalid = !empty($_GET['skip_invalid']);
    // CSV existing-row errors are warnings (we upsert), so don't block commit on them.
    $opts = ['skip_invalid' => true];

    $result = CsvImportService::commit('ap_vendors', $csv, function (array $row) use ($tid, $user) {
        $vendorType = (string) ($row['vendor_type'] ?? 'other');
        $vendorCat  = (string) ($row['vendor_category']
            ?? (in_array($vendorType, ['1099_individual','c2c_corp'], true) ? 'hourly_labor' : 'service_provider'));

        $pdo = getDB();
        $pdo->prepare(
            'INSERT INTO ap_vendors_index
               (tenant_id, vendor_name, vendor_type, vendor_category,
                payment_method, remit_to_email, remit_to_phone,
                default_terms, tax_id_last4, requires_1099)
             VALUES (:t, :v, :vt, :cat, :pm, :rmail, :rphone, :terms, :last4, :r)
             ON DUPLICATE KEY UPDATE
               vendor_type     = VALUES(vendor_type),
               vendor_category = VALUES(vendor_category),
               payment_method  = COALESCE(VALUES(payment_method), payment_method),
               remit_to_email  = COALESCE(VALUES(remit_to_email), remit_to_email),
               remit_to_phone  = COALESCE(VALUES(remit_to_phone), remit_to_phone),
               default_terms   = VALUES(default_terms),
               tax_id_last4    = COALESCE(VALUES(tax_id_last4), tax_id_last4),
               requires_1099   = VALUES(requires_1099)'
        )->execute([
            't'      => $tid,
            'v'      => (string) $row['vendor_name'],
            'vt'     => $vendorType,
            'cat'    => $vendorCat,
            'pm'     => $row['payment_method'] ?? null,
            'rmail'  => $row['remit_to_email'] ?? null,
            'rphone' => $row['remit_to_phone'] ?? null,
            'terms'  => (string) ($row['default_terms'] ?? 'NET30'),
            'last4'  => $row['tax_id_last4'] ?? null,
            'r'      => isset($row['requires_1099']) ? (int) $row['requires_1099'] : 0,
        ]);
        $id = (int) $pdo->lastInsertId();
        if ($id === 0) {
            $findStmt = $pdo->prepare('SELECT id FROM ap_vendors_index WHERE tenant_id = :t AND vendor_name = :v');
            $findStmt->execute(['t' => $tid, 'v' => (string) $row['vendor_name']]);
            $id = (int) $findStmt->fetchColumn();
        }
        return $id;
    }, $opts);

    apAudit('ap.vendor.csv_imported', [
        'imported' => $result['imported_count'],
        'skipped'  => $result['skipped_count'],
        'errors'   => count($result['errors']),
    ]);
    api_ok($result);
}

api_error('Unknown action. Use ?action=template|dry_run|commit', 400);
