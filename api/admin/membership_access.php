<?php
/**
 * /api/admin/membership_access.php — per-module access grid for the B3 admin UI.
 *
 *   GET   /api/admin/membership_access.php?membership_id=N
 *         Returns every membership_module_access row for that membership.
 *
 *   POST  /api/admin/membership_access.php
 *         Three ops, selected via body.op:
 *
 *         1) grant  — { op:'grant', membership_id, module_key, access_level,
 *                       sub_tenant_scope?:[<int>,...] }
 *            Upsert. access_level ∈ none|read|write|admin. sub_tenant_scope NULL
 *            means "all sub-tenants under this tenant".
 *
 *         2) revoke — { op:'revoke', membership_id, module_key }
 *
 *         3) copy   — { op:'copy', from_membership_id, to_membership_id }
 *            Clones every grant from one membership onto another (same tenant).
 *            Returns { copied: <int> }.
 *
 * Auth: master_admin, tenant_admin, or platform global admin.
 * All writes append to membership_audit via RBACResolver helpers.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';

$ctx      = api_require_auth();
$tenantId = (int) ($ctx['tenant_id'] ?? 0);
$actorId  = (int) ($ctx['user']['id'] ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

$role          = (string) ($ctx['role'] ?? 'employee');
$isGlobalAdmin = (bool) ($ctx['is_global_admin'] ?? false);
if (!$isGlobalAdmin && !in_array($role, ['master_admin', 'tenant_admin'], true)) {
    api_error('Forbidden — admin only', 403);
}

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

try {
    $pdo->query('SELECT 1 FROM membership_module_access LIMIT 0');
} catch (\Throwable $_) {
    api_error('Migration 055_rbac_memberships.sql has not been applied yet.', 503);
}

const _ALLOWED_LEVELS = ['none','read','write','admin'];

/** Confirm the membership belongs to the current tenant. */
function _ma_membership_in_tenant(\PDO $pdo, int $membershipId, int $tenantId): bool {
    $st = $pdo->prepare('SELECT 1 FROM tenant_memberships WHERE id = :id AND tenant_id = :t LIMIT 1');
    $st->execute(['id' => $membershipId, 't' => $tenantId]);
    return (bool) $st->fetchColumn();
}

$method = api_method();

if ($method === 'GET') {
    $membershipId = (int) (api_query('membership_id') ?? 0);
    if (!$membershipId) api_error('membership_id is required', 422);
    if (!_ma_membership_in_tenant($pdo, $membershipId, $tenantId)) {
        api_error('Membership not found in this tenant', 404);
    }
    $st = $pdo->prepare(
        'SELECT id, membership_id, module_key, access_level, sub_tenant_scope,
                granted_by_user_id, granted_at
           FROM membership_module_access
          WHERE membership_id = :m
          ORDER BY module_key'
    );
    $st->execute(['m' => $membershipId]);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']            = (int) $r['id'];
        $r['membership_id'] = (int) $r['membership_id'];
        $r['sub_tenant_scope'] = $r['sub_tenant_scope'] !== null
            ? (json_decode((string) $r['sub_tenant_scope'], true) ?: [])
            : null;
    }
    api_ok(['membership_id' => $membershipId, 'access' => $rows]);
}

if ($method === 'POST') {
    $body = api_json_body();
    $op   = (string) ($body['op'] ?? '');

    if ($op === 'grant') {
        api_require_fields($body, ['membership_id', 'module_key', 'access_level']);
        $membershipId = (int) $body['membership_id'];
        $module       = (string) $body['module_key'];
        $level        = (string) $body['access_level'];
        if (!in_array($level, _ALLOWED_LEVELS, true)) {
            api_error('Invalid access_level', 422, ['allowed' => _ALLOWED_LEVELS]);
        }
        if (!_ma_membership_in_tenant($pdo, $membershipId, $tenantId)) {
            api_error('Membership not found in this tenant', 404);
        }
        $scope = null;
        if (array_key_exists('sub_tenant_scope', $body) && $body['sub_tenant_scope'] !== null) {
            if (!is_array($body['sub_tenant_scope'])) {
                api_error('sub_tenant_scope must be array of sub_tenant IDs or null', 422);
            }
            $scope = array_values(array_map('intval', $body['sub_tenant_scope']));
        }
        RBACResolver::grantModule($membershipId, $module, $level, $scope, $actorId);
        api_ok(['membership_id' => $membershipId, 'module_key' => $module, 'access_level' => $level, 'granted' => true]);
    }

    if ($op === 'revoke') {
        api_require_fields($body, ['membership_id', 'module_key']);
        $membershipId = (int) $body['membership_id'];
        $module       = (string) $body['module_key'];
        if (!_ma_membership_in_tenant($pdo, $membershipId, $tenantId)) {
            api_error('Membership not found in this tenant', 404);
        }
        RBACResolver::revokeModule($membershipId, $module, $actorId);
        api_ok(['membership_id' => $membershipId, 'module_key' => $module, 'revoked' => true]);
    }

    if ($op === 'copy') {
        api_require_fields($body, ['from_membership_id', 'to_membership_id']);
        $from = (int) $body['from_membership_id'];
        $to   = (int) $body['to_membership_id'];
        if (!_ma_membership_in_tenant($pdo, $from, $tenantId) ||
            !_ma_membership_in_tenant($pdo, $to,   $tenantId)) {
            api_error('Both memberships must exist in this tenant', 404);
        }
        try {
            $copied = RBACResolver::copyPermissions($from, $to, $actorId);
        } catch (\Throwable $e) {
            api_error($e->getMessage(), 422);
        }
        api_ok(['from_membership_id' => $from, 'to_membership_id' => $to, 'copied' => $copied]);
    }

    api_error('Unknown op — expected one of: grant, revoke, copy', 422);
}

api_error('Method not allowed', 405);
