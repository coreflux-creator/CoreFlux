<?php
/**
 * Accounting API — tenant-configurable dimensions.
 *
 *   GET    /api/accounting/dimensions                      → list dimensions
 *   POST   /api/accounting/dimensions                      → upsert dimension {key,label,data_type,required_default,sort_order}
 *   DELETE /api/accounting/dimensions?id=N                 → soft-deactivate
 *   GET    /api/accounting/dimensions?action=values&id=N   → list values for dim
 *   POST   /api/accounting/dimensions?action=add_value     → add value to enum dim
 *   POST   /api/accounting/dimensions?action=set_account_rule  → upsert per-account rule
 *   GET    /api/accounting/dimensions?action=account_rules&account_id=N
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/dimensions.php';

$ctx       = api_require_auth();
$user      = $ctx['user'];
$tenantId  = (int) $ctx['tenant_id'];
$method    = api_method();
$action    = (string) (api_query('action') ?? '');

if ($method === 'GET' && $action === '') {
    RBAC::requirePermission($user, 'accounting.dimensions.view');
    $rows = scopedQuery(
        "SELECT id, dim_key, label, data_type, reference_table, required_default, sort_order, active
           FROM accounting_dimensions
          WHERE tenant_id = :tenant_id
          ORDER BY sort_order, dim_key"
    );
    api_ok(['dimensions' => $rows]);
}

if ($method === 'POST' && $action === '') {
    RBAC::requirePermission($user, 'accounting.dimensions.manage');
    $body = api_json_body();
    api_require_fields($body, ['dim_key', 'label']);
    $key = strtolower(preg_replace('/[^a-z0-9_]/', '_', (string) $body['dim_key']));
    $pdo = getDB();
    $existing = $pdo->prepare("SELECT id FROM accounting_dimensions WHERE tenant_id = :t AND dim_key = :k");
    $existing->execute(['t' => $tenantId, 'k' => $key]);
    $id = (int) ($existing->fetchColumn() ?: 0);
    if ($id) {
        scopedUpdate('accounting_dimensions', $id, [
            'label'            => (string) $body['label'],
            'data_type'        => (string) ($body['data_type'] ?? 'text'),
            'reference_table'  => $body['reference_table'] ?? null,
            'description'      => $body['description'] ?? null,
            'required_default' => !empty($body['required_default']) ? 1 : 0,
            'sort_order'       => (int) ($body['sort_order'] ?? 0),
            'active'           => isset($body['active']) ? (int) (bool) $body['active'] : 1,
        ]);
    } else {
        $id = scopedInsert('accounting_dimensions', [
            'dim_key'          => $key,
            'label'            => (string) $body['label'],
            'data_type'        => (string) ($body['data_type'] ?? 'text'),
            'reference_table'  => $body['reference_table'] ?? null,
            'description'      => $body['description'] ?? null,
            'required_default' => !empty($body['required_default']) ? 1 : 0,
            'sort_order'       => (int) ($body['sort_order'] ?? 0),
            'active'           => 1,
        ]);
    }
    api_ok(['id' => $id, 'dim_key' => $key]);
}

if ($method === 'DELETE') {
    RBAC::requirePermission($user, 'accounting.dimensions.manage');
    $id = (int) (api_query('id') ?? 0);
    if (!$id) api_error('id required', 422);
    scopedUpdate('accounting_dimensions', $id, ['active' => 0]);
    api_ok(['ok' => true]);
}

if ($method === 'GET' && $action === 'values') {
    RBAC::requirePermission($user, 'accounting.dimensions.view');
    $dimId = (int) (api_query('id') ?? 0);
    if (!$dimId) api_error('id required', 422);
    $rows = scopedQuery(
        "SELECT id, value_code, value_label, active
           FROM accounting_dimension_values
          WHERE tenant_id = :tenant_id AND dimension_id = :d
          ORDER BY value_code",
        ['d' => $dimId]
    );
    api_ok(['values' => $rows]);
}

if ($method === 'POST' && $action === 'add_value') {
    RBAC::requirePermission($user, 'accounting.dimensions.manage');
    $body = api_json_body();
    api_require_fields($body, ['dimension_id', 'value_code', 'value_label']);
    $id = scopedInsert('accounting_dimension_values', [
        'dimension_id' => (int) $body['dimension_id'],
        'value_code'   => (string) $body['value_code'],
        'value_label'  => (string) $body['value_label'],
        'active'       => 1,
    ]);
    api_ok(['id' => $id]);
}

if ($method === 'GET' && $action === 'account_rules') {
    RBAC::requirePermission($user, 'accounting.dimensions.view');
    $accountId = (int) (api_query('account_id') ?? 0);
    if (!$accountId) api_error('account_id required', 422);
    $rules = accountingAccountDimRules($tenantId, $accountId);
    api_ok(['account_id' => $accountId, 'rules' => $rules]);
}

if ($method === 'POST' && $action === 'set_account_rule') {
    RBAC::requirePermission($user, 'accounting.dimensions.manage');
    $body = api_json_body();
    api_require_fields($body, ['account_id', 'dimension_id', 'requirement']);
    $req = (string) $body['requirement'];
    if (!in_array($req, ['required','optional','blocked'], true)) {
        api_error('requirement must be required|optional|blocked', 422);
    }
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "INSERT INTO accounting_account_dim_rules (tenant_id, account_id, dimension_id, requirement, created_at)
         VALUES (:t, :a, :d, :r, NOW())
         ON DUPLICATE KEY UPDATE requirement = VALUES(requirement)"
    );
    $stmt->execute([
        't' => $tenantId,
        'a' => (int) $body['account_id'],
        'd' => (int) $body['dimension_id'],
        'r' => $req,
    ]);
    api_ok(['ok' => true]);
}

api_error('Unknown method/action', 405);
