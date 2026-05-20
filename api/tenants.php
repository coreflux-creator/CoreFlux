<?php
/**
 * /api/tenants.php — master-tenant lifecycle (top-level only).
 *
 *   GET    /api/tenants.php                      list master tenants
 *   GET    /api/tenants.php?id=N                 fetch one
 *   POST   /api/tenants.php                      create master tenant
 *   PATCH  /api/tenants.php?id=N                 update (name/branding/domain)
 *   DELETE /api/tenants.php?id=N                 soft-deactivate
 *
 * Strictly master_admin only — sub-tenants are managed at /api/sub_tenants.php.
 *
 * This endpoint replaces the legacy `core/views/admin/tenant_edit.php` PHP
 * form that was the last non-SPA admin surface.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/sub_tenants.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$userId   = (int) ($user['id'] ?? 0);
$role     = $ctx['role'] ?? 'employee';

if ($role !== 'master_admin') {
    api_error('Forbidden — master_admin only', 403);
}

$method = api_method();
$pdo    = getDB();
if (!$pdo) api_error('No database connection', 500);

if ($method === 'GET') {
    $id = (int) api_query('id', 0);
    if ($id) {
        $stmt = $pdo->prepare(
            "SELECT id, name, slug, domain, subdomain, parent_id, tenant_type,
                    is_active, landing_enabled, logo_url, primary_color,
                    hero_title, hero_subtitle, login_cta, created_at, updated_at
               FROM tenants WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) api_error('Tenant not found', 404);
        api_ok(['tenant' => $row]);
    }

    $stmt = $pdo->query(
        "SELECT t.id, t.name, t.slug, t.domain, t.subdomain, t.tenant_type,
                t.is_active, t.landing_enabled, t.primary_color, t.created_at,
                (SELECT COUNT(DISTINCT user_id) FROM " . membershipReadSourceSql() . " ut
                  WHERE ut.tenant_id = t.id) AS user_count,
                (SELECT COUNT(*) FROM tenants st
                  WHERE st.parent_id = t.id AND st.tenant_type = 'sub') AS sub_count
           FROM tenants t
          WHERE t.parent_id IS NULL OR t.tenant_type = 'master'
          ORDER BY t.is_active DESC, t.name ASC"
    );
    api_ok(['tenants' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'POST') {
    $body = api_json_body();
    api_require_fields($body, ['name']);

    $name = trim((string) $body['name']);
    if ($name === '') api_error('name is required', 422);
    $slug = trim((string) ($body['slug'] ?? '')) ?: subTenantSlugify($name);

    if (_tenantSlugConflict($pdo, $slug, 0)) {
        api_error("slug '{$slug}' already in use", 409);
    }

    $pdo->prepare(
        "INSERT INTO tenants
            (name, slug, domain, subdomain, parent_id, tenant_type, is_active,
             landing_enabled, logo_url, primary_color,
             hero_title, hero_subtitle, login_cta, created_at)
         VALUES (:n, :s, :d, :sd, NULL, 'master', 1,
                 :le, :lu, :pc, :ht, :hs, :cta, NOW())"
    )->execute([
        'n'  => $name,
        's'  => $slug,
        'd'  => trim((string) ($body['domain']    ?? '')) ?: null,
        'sd' => trim((string) ($body['subdomain'] ?? '')) ?: null,
        'le' => (int) (bool) ($body['landing_enabled'] ?? 1),
        'lu' => trim((string) ($body['logo_url']      ?? '')) ?: null,
        'pc' => trim((string) ($body['primary_color'] ?? '')) ?: null,
        'ht' => trim((string) ($body['hero_title']    ?? '')) ?: null,
        'hs' => trim((string) ($body['hero_subtitle'] ?? '')) ?: null,
        'cta'=> trim((string) ($body['login_cta']     ?? '')) ?: null,
    ]);
    $newId = (int) $pdo->lastInsertId();

    subTenantAudit(0, $newId, $userId, 'master_tenant.created', [
        'name' => $name, 'slug' => $slug,
    ]);

    api_ok(['id' => $newId], 201);
}

if ($method === 'PATCH') {
    $id = (int) api_query('id', 0);
    if (!$id) api_error('id required', 422);
    $body = api_json_body();

    $stmt = $pdo->prepare('SELECT id, slug, tenant_type FROM tenants WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) api_error('Tenant not found', 404);

    if (isset($body['slug']) && $body['slug'] !== $existing['slug']) {
        if (_tenantSlugConflict($pdo, (string) $body['slug'], $id)) {
            api_error('slug already in use', 409);
        }
    }

    $sets = []; $params = ['id' => $id];
    foreach ([
        'name','slug','domain','subdomain','logo_url',
        'primary_color','hero_title','hero_subtitle','login_cta',
    ] as $f) {
        if (array_key_exists($f, $body)) {
            $sets[]      = "`$f` = :$f";
            $params[$f]  = (string) $body[$f] === '' ? null : (string) $body[$f];
        }
    }
    foreach (['landing_enabled','is_active'] as $f) {
        if (array_key_exists($f, $body)) {
            $sets[]      = "`$f` = :$f";
            $params[$f]  = (int) (bool) $body[$f];
        }
    }
    if (!$sets) api_error('No updatable fields supplied', 422);

    $pdo->prepare('UPDATE tenants SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id')
        ->execute($params);

    subTenantAudit(0, $id, $userId, 'master_tenant.updated', $body);
    api_ok(['id' => $id]);
}

if ($method === 'DELETE') {
    $id = (int) api_query('id', 0);
    if (!$id) api_error('id required', 422);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM tenants WHERE parent_id = :id AND tenant_type = 'sub' AND is_active = 1"
    );
    $stmt->execute(['id' => $id]);
    $childCount = (int) $stmt->fetchColumn();
    if ($childCount > 0) {
        api_error("Cannot deactivate — {$childCount} active sub-tenants underneath. Deactivate them first.", 409);
    }

    $pdo->prepare('UPDATE tenants SET is_active = 0, updated_at = NOW() WHERE id = :id')
        ->execute(['id' => $id]);

    subTenantAudit(0, $id, $userId, 'master_tenant.deactivated', null);
    api_ok(['id' => $id, 'is_active' => 0]);
}

api_error('Method not allowed', 405);

function _tenantSlugConflict(PDO $pdo, string $slug, int $excludeId): bool {
    if ($slug === '') return false;
    $stmt = $pdo->prepare('SELECT id FROM tenants WHERE slug = :s AND id != :e LIMIT 1');
    $stmt->execute(['s' => $slug, 'e' => $excludeId]);
    return (bool) $stmt->fetchColumn();
}
