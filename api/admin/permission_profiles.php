<?php
/**
 * /api/admin/permission_profiles.php — CRUD + apply for RBAC permission
 * profiles (RBAC B6, migration 100).
 *
 *   GET    /api/admin/permission_profiles.php
 *          ?persona=cpa            (optional — filter by applies_to_persona)
 *      List every profile visible to the active tenant (system + global custom
 *      + tenant-private). Tenant-private shadows system rows that share the
 *      same profile_key. Response: { profiles: [...] }
 *
 *   GET    /api/admin/permission_profiles.php?id=N
 *      Fetch one profile (visibility-checked).
 *
 *   POST   /api/admin/permission_profiles.php?action=save
 *          Body: { id?, profile_key, label, description?, applies_to_persona?,
 *                  grants: [{ module_key, access_level }, ...] }
 *      Upsert a tenant-private profile. Returns { id, created|updated:true }.
 *
 *   POST   /api/admin/permission_profiles.php?action=apply
 *          Body: { profile_id, membership_id, overwrite?:0|1, sub_tenant_scope?:[ids] }
 *      Apply the profile's grants_json onto the membership. Returns
 *      { applied: <count> }.
 *
 *   DELETE /api/admin/permission_profiles.php?id=N
 *      Delete a tenant-private profile. SYSTEM profiles cannot be deleted.
 *
 * Auth: master_admin, tenant_admin, or platform global admin.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/rbac/permission_profiles.php';

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
    $pdo->query('SELECT 1 FROM rbac_permission_profiles LIMIT 0');
} catch (\Throwable $_) {
    api_error('Migration 100_rbac_cpa_personas_and_profiles.sql has not been applied yet.', 503);
}

$method = api_method();
$action = (string) (api_query('action') ?? '');

// ─────────────────────────────────────────────────────────────── GET (list / get)
if ($method === 'GET') {
    $id = (int) (api_query('id') ?? 0);
    if ($id > 0) {
        $row = PermissionProfileService::getForTenant($id, $tenantId);
        if (!$row) api_error('Profile not found', 404);
        api_ok(['profile' => $row]);
    }
    $persona = (string) (api_query('persona') ?? '');
    $profiles = PermissionProfileService::listForTenant($tenantId);
    if ($persona !== '') {
        $profiles = array_values(array_filter(
            $profiles,
            fn($p) => ($p['applies_to_persona'] ?? null) === $persona
                  || ($p['applies_to_persona'] ?? null) === null
        ));
    }
    api_ok(['profiles' => $profiles]);
}

// ─────────────────────────────────────────────────────────────── POST save
if ($method === 'POST' && $action === 'save') {
    $body = api_json_body();
    try {
        $id = PermissionProfileService::upsertForTenant($body, $tenantId, $actorId);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (\Throwable $e) {
        api_error('Profile save failed: ' . $e->getMessage(), 500);
    }
    api_ok(['id' => $id, 'saved' => true], 201);
}

// ─────────────────────────────────────────────────────────────── POST apply
if ($method === 'POST' && $action === 'apply') {
    $body         = api_json_body();
    $profileId    = (int) ($body['profile_id']    ?? 0);
    $membershipId = (int) ($body['membership_id'] ?? 0);
    $overwrite    = !empty($body['overwrite']);
    $rawScope     = $body['sub_tenant_scope'] ?? null;
    $scope        = null;
    if (is_array($rawScope)) {
        $scope = array_values(array_filter(
            array_map('intval', $rawScope),
            fn($v) => $v > 0
        ));
        if (!$scope) $scope = null;
    }
    if (!$profileId || !$membershipId) {
        api_error('profile_id and membership_id are required', 422);
    }
    try {
        $applied = PermissionProfileService::apply(
            $membershipId, $profileId, $tenantId, $actorId, $overwrite, $scope
        );
    } catch (\RuntimeException $e) {
        api_error($e->getMessage(), 404);
    } catch (\Throwable $e) {
        api_error('Profile apply failed: ' . $e->getMessage(), 500);
    }
    api_ok(['applied' => $applied]);
}

// ─────────────────────────────────────────────────────────────── DELETE
if ($method === 'DELETE') {
    $id = (int) (api_query('id') ?? 0);
    if (!$id) api_error('id is required', 422);
    try {
        $ok = PermissionProfileService::deleteForTenant($id, $tenantId, $actorId);
    } catch (\RuntimeException $e) {
        api_error($e->getMessage(), 403);
    } catch (\Throwable $e) {
        api_error('Profile delete failed: ' . $e->getMessage(), 500);
    }
    if (!$ok) api_error('Profile not found', 404);
    api_ok(['id' => $id, 'deleted' => true]);
}

api_error('Method not allowed', 405);
