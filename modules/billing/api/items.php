<?php
/** Billing product and service catalog. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/billing.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

const BILLING_ITEM_TYPES = [
    'labor', 'expense', 'materials', 'fixed_fee', 'milestone', 'discount',
    'subscription', 'mileage', 'per_diem', 'reimbursement', 'other',
];

function billingItemValidate(array $input, int $tenantId): array
{
    $code = trim((string) ($input['code'] ?? ''));
    $name = trim((string) ($input['name'] ?? ''));
    $type = strtolower(trim((string) ($input['item_type'] ?? 'other')));
    $unit = trim((string) ($input['default_unit'] ?? 'each'));
    if ($code === '' || $name === '') api_error('Code and name are required', 422);
    if (strlen($code) > 80 || strlen($name) > 255) api_error('Code or name is too long', 422);
    if (!in_array($type, BILLING_ITEM_TYPES, true)) api_error('Invalid item type', 422);
    if ($unit === '' || strlen($unit) > 40) api_error('A valid unit is required', 422);

    $price = $input['default_unit_price'] ?? null;
    if ($price === '') $price = null;
    if ($price !== null && !is_numeric($price)) api_error('Default price must be numeric', 422);

    $glCode = trim((string) ($input['gl_revenue_account_code'] ?? ''));
    if ($glCode !== '') {
        $stmt = getDB()->prepare(
            'SELECT id FROM accounting_accounts
              WHERE tenant_id = :tenant_id AND code = :code
                AND account_type = "revenue" AND active = 1 AND is_postable = 1
              LIMIT 1'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'code' => $glCode]);
        if (!$stmt->fetchColumn()) api_error('Revenue account is not active or postable', 422);
    }

    return [
        'code' => $code,
        'name' => $name,
        'item_type' => $type,
        'description' => trim((string) ($input['description'] ?? '')) ?: null,
        'default_unit' => $unit,
        'default_unit_price' => $price === null ? null : round((float) $price, 4),
        'gl_revenue_account_code' => $glCode ?: null,
        'taxable' => !empty($input['taxable']) ? 1 : 0,
        'active' => array_key_exists('active', $input) ? (!empty($input['active']) ? 1 : 0) : 1,
    ];
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'billing.view');
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (isset($_GET['active']) && $_GET['active'] !== '') {
        $where[] = 'active = :active';
        $params['active'] = (int) ((string) $_GET['active'] === '1');
    }
    if (!empty($_GET['item_type']) && in_array((string) $_GET['item_type'], BILLING_ITEM_TYPES, true)) {
        $where[] = 'item_type = :item_type';
        $params['item_type'] = (string) $_GET['item_type'];
    }
    if (trim((string) ($_GET['q'] ?? '')) !== '') {
        $like = '%' . trim((string) $_GET['q']) . '%';
        $where[] = '(code LIKE :q_code OR name LIKE :q_name OR description LIKE :q_desc)';
        $params['q_code'] = $like;
        $params['q_name'] = $like;
        $params['q_desc'] = $like;
    }

    $sortMap = [
        'code' => 'code', 'name' => 'name', 'item_type' => 'item_type',
        'default_unit_price' => 'default_unit_price', 'active' => 'active', 'updated_at' => 'updated_at',
    ];
    $sort = $sortMap[(string) ($_GET['sort'] ?? 'name')] ?? 'name';
    $dir = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = max(1, min(500, (int) ($_GET['per_page'] ?? 200)));
    $offset = ($page - 1) * $perPage;

    $count = getDB()->prepare('SELECT COUNT(*) FROM billing_items WHERE ' . implode(' AND ', $where));
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $stmt = getDB()->prepare(
        'SELECT id, code, name, item_type, description, default_unit, default_unit_price,
                gl_revenue_account_code, taxable, active, source_system, source_external_id, updated_at
           FROM billing_items WHERE ' . implode(' AND ', $where) . "
          ORDER BY {$sort} {$dir}, id DESC LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($params);
    api_ok(['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

if ($method === 'POST' && $action === 'bulk_update') {
    rbac_legacy_require($user, 'billing.invoice.draft');
    $body = api_json_body();
    $ids = array_values(array_filter(array_unique(array_map('intval', (array) ($body['ids'] ?? []))), static fn(int $id): bool => $id > 0));
    $field = (string) ($body['field'] ?? '');
    if (!$ids || count($ids) > 500) api_error('Select between 1 and 500 items', 422);
    if ($field !== 'active') api_error('Only status can be updated in bulk', 422);
    $value = !empty($body['value']) ? 1 : 0;
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = getDB()->prepare("UPDATE billing_items SET active = ? WHERE tenant_id = ? AND id IN ({$marks})");
    $stmt->execute(array_merge([$value, $tenantId], $ids));
    billingAudit('billing.item.bulk_updated', ['ids' => $ids, 'active' => $value]);
    api_ok(['ok' => true, 'updated' => $stmt->rowCount()]);
}

if ($method === 'POST' && $action === '') {
    rbac_legacy_require($user, 'billing.invoice.draft');
    $body = billingItemValidate(api_json_body(), $tenantId);
    $duplicate = getDB()->prepare('SELECT id FROM billing_items WHERE tenant_id = :tenant_id AND code = :code LIMIT 1');
    $duplicate->execute(['tenant_id' => $tenantId, 'code' => $body['code']]);
    if ($duplicate->fetchColumn()) api_error('An item with this code already exists', 409);
    $id = scopedInsert('billing_items', array_merge($body, [
        'tenant_id' => $tenantId,
        'created_by_user_id' => $user['id'] ?? null,
    ]));
    billingAudit('billing.item.created', ['item_id' => $id, 'code' => $body['code']], $id);
    api_ok(['id' => $id], 201);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'billing.invoice.draft');
    $id = (int) ($_GET['id'] ?? 0);
    $existing = scopedFind('SELECT * FROM billing_items WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$existing) api_error('Item not found', 404);
    $body = billingItemValidate(array_merge($existing, api_json_body()), $tenantId);
    $duplicate = getDB()->prepare('SELECT id FROM billing_items WHERE tenant_id = :tenant_id AND code = :code AND id <> :id LIMIT 1');
    $duplicate->execute(['tenant_id' => $tenantId, 'code' => $body['code'], 'id' => $id]);
    if ($duplicate->fetchColumn()) api_error('An item with this code already exists', 409);
    scopedUpdate('billing_items', $id, $body);
    billingAudit('billing.item.updated', ['item_id' => $id, 'code' => $body['code']], $id);
    api_ok(['ok' => true, 'id' => $id]);
}

api_error('Method/action not allowed', 405);
