<?php
/** Read-only cross-module business integrity report. */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/business_integrity.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
if (api_method() !== 'GET') api_error('Method not allowed', 405);

$globalRole = (string) ($user['global_role'] ?? '');
$role = (string) ($user['role'] ?? '');
if (!in_array($globalRole, ['master_admin', 'tenant_admin'], true)
    && !in_array($role, ['admin', 'manager', 'auditor', 'external_auditor'], true)) {
    api_error('Administrator or auditor access required', 403);
}

api_ok(businessIntegrityAudit($tenantId));
