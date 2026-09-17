<?php
/** Billing products and services CSV export for offline round-trip edits. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvExportService.php';
require_once __DIR__ . '/../lib/billing.php';

use Core\CsvExportService;

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($user, 'billing.view');

$where = ['tenant_id = :tenant_id'];
$params = ['tenant_id' => $tenantId];
$active = (string) ($_GET['active'] ?? '');
if ($active === '0' || $active === '1') {
    $where[] = 'active = :active';
    $params['active'] = (int) $active;
}
$itemType = trim((string) ($_GET['item_type'] ?? ''));
if ($itemType !== '') {
    $where[] = 'item_type = :item_type';
    $params['item_type'] = $itemType;
}
$search = trim((string) ($_GET['q'] ?? ''));
if ($search !== '') {
    $where[] = '(code LIKE :q_code OR name LIKE :q_name OR description LIKE :q_description)';
    $like = '%' . $search . '%';
    $params['q_code'] = $like;
    $params['q_name'] = $like;
    $params['q_description'] = $like;
}

$stmt = getDB()->prepare(
    'SELECT id AS item_id, code, name, item_type, description, default_unit,
            default_unit_price, gl_revenue_account_code, taxable, active,
            source_external_id AS external_id, source_system
       FROM billing_items
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY code, id'
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

billingAudit('billing.item.csv_exported', [
    'rows' => count($rows),
    'filters' => ['active' => $active, 'item_type' => $itemType, 'q' => $search],
]);

(new CsvExportService([
    'item_id' => 'Item ID',
    'code' => 'Code',
    'name' => 'Name',
    'item_type' => 'Item type',
    'description' => 'Description',
    'default_unit' => 'Default unit',
    'default_unit_price' => 'Default unit price',
    'gl_revenue_account_code' => 'Revenue account code',
    'taxable' => 'Taxable',
    'active' => 'Active',
    'external_id' => 'External ID (audit / integration)',
    'source_system' => 'Source system',
]))->stream($rows, 'products_services_' . date('Y-m-d') . '.csv');
