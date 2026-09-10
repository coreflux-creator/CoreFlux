<?php
/** Tenant-level W-2 employer-cost defaults. */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/staffing_economics.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$method = api_method();
rbac_legacy_require($user, 'tenant.manage');

if ($method === 'GET') {
    api_ok(['defaults' => staffingEconomicsTenantW2Defaults($tenantId)]);
}

if ($method === 'PUT' || $method === 'POST') {
    $body = api_json_body();
    $fields = ['payroll_load_pct', 'workers_comp_pct', 'benefits_load_pct'];
    $values = [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $body) || !is_numeric($body[$field])) {
            api_error("{$field} must be a numeric decimal", 422);
        }
        $value = (float) $body[$field];
        if ($value < 0 || $value > 5) {
            api_error("{$field} must be between 0 and 5", 422);
        }
        $values[$field] = $value;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO tenant_staffing_economics_defaults
            (tenant_id, w2_payroll_load_pct, w2_workers_comp_pct, w2_benefits_load_pct, updated_by_user_id)
         VALUES
            (:tenant_id, :payroll_load_pct, :workers_comp_pct, :benefits_load_pct, :updated_by_user_id)
         ON DUPLICATE KEY UPDATE
            w2_payroll_load_pct = VALUES(w2_payroll_load_pct),
            w2_workers_comp_pct = VALUES(w2_workers_comp_pct),
            w2_benefits_load_pct = VALUES(w2_benefits_load_pct),
            updated_by_user_id = VALUES(updated_by_user_id)'
    );
    $stmt->execute($values + [
        'tenant_id' => $tenantId,
        'updated_by_user_id' => !empty($user['id']) ? (int) $user['id'] : null,
    ]);

    try {
        $pdo->prepare(
            'INSERT INTO audit_log
                (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)
             VALUES (:tenant_id, :actor, :event, :target_id, :meta_json, :ip, NOW())'
        )->execute([
            'tenant_id' => $tenantId,
            'actor' => $user['id'] ?? null,
            'event' => 'tenant.staffing_economics.updated',
            'target_id' => $tenantId,
            'meta_json' => json_encode($values, JSON_UNESCAPED_SLASHES),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log('[staffing_economics_settings] audit failed: ' . $e->getMessage());
    }

    api_ok(['ok' => true, 'defaults' => staffingEconomicsTenantW2Defaults($tenantId, $pdo)]);
}

api_error('Method not allowed', 405);

