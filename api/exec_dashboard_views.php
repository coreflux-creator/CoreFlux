<?php
/**
 * /api/exec_dashboard_views.php — saved-view bookmarks for the exec dashboard.
 *
 *   GET    /api/exec_dashboard_views.php                list (own + shared)
 *   GET    /api/exec_dashboard_views.php?slug=xxx       fetch one by slug
 *   POST   /api/exec_dashboard_views.php                create
 *   PATCH  /api/exec_dashboard_views.php?id=N           update (name / filters / shared / default)
 *   DELETE /api/exec_dashboard_views.php?id=N           remove
 *
 * Permission model:
 *   - manager+ (same gate as the dashboard itself).
 *   - Owners can edit/delete their own views.
 *   - Shared views can additionally be edited/deleted by tenant_admin /
 *     master_admin (so admins can curate the team-wide bookmarks).
 *   - Setting `is_default` is per-user; flipping it on one view auto-clears
 *     it from the user's other views.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';

$ctx       = api_require_auth();
$user      = $ctx['user'];
$userId    = (int) ($user['id'] ?? 0);
$role      = $ctx['role'] ?? 'employee';
$tenantId  = (int) (currentTenantId() ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

if (!in_array($role, ['master_admin', 'tenant_admin', 'admin', 'manager'], true)) {
    api_error('Forbidden — exec dashboard requires manager+', 403);
}

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

$method = api_method();
$id     = (int) api_query('id', 0);
$slug   = (string) api_query('slug', '');

const _EXEC_VIEW_FILTER_KEYS = ['weeks','client_id','recruiter_id','placement_type','worksite_state'];

function _execViewSlugify(string $name): string {
    $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
    $s = trim($s, '-');
    return $s !== '' ? substr($s, 0, 80) : 'view-' . substr(bin2hex(random_bytes(3)), 0, 6);
}

function _execViewSanitiseFilters(array $raw): array {
    $clean = [];
    foreach (_EXEC_VIEW_FILTER_KEYS as $k) {
        if (!array_key_exists($k, $raw)) continue;
        $v = $raw[$k];
        if ($v === '' || $v === null) continue;
        if ($k === 'weeks') {
            $v = max(1, min(104, (int) $v));
        } else {
            $v = (string) $v;
        }
        $clean[$k] = $v;
    }
    return $clean;
}

function _execViewCanModify(array $row, int $userId, string $role): bool {
    if ((int) $row['user_id'] === $userId) return true;
    return $row['is_shared']
        && in_array($role, ['master_admin', 'tenant_admin', 'admin'], true);
}

function _execViewSerialize(array $row): array {
    $filters = json_decode((string) ($row['filters_json'] ?? '{}'), true) ?: [];
    $widgets = json_decode((string) ($row['widget_config_json'] ?? 'null'), true);
    return [
        'id'            => (int) $row['id'],
        'name'          => (string) $row['name'],
        'slug'          => (string) $row['slug'],
        'filters'       => $filters,
        'widget_config' => is_array($widgets) ? $widgets : null,
        'is_default'    => (int) ($row['is_default'] ?? 0) === 1,
        'is_shared'     => (int) ($row['is_shared']  ?? 0) === 1,
        'is_owner'      => (int) ($row['_is_owner']  ?? 0) === 1,
        'owner_name'    => $row['owner_name'] ?? null,
        'updated_at'    => $row['updated_at'] ?? null,
    ];
}

/** Widget config is free-form per-view JSON (visibility / order / per-widget
 * time-window overrides). We just lightly cap the size to prevent abuse. */
function _execViewSanitiseWidgetConfig($raw): ?string {
    if (!is_array($raw)) return null;
    $json = json_encode($raw);
    if ($json === false) return null;
    if (strlen($json) > 32768) return null;   // 32 KB hard ceiling
    return $json;
}

/* ---------- GET ---------- */
if ($method === 'GET') {
    if ($slug !== '') {
        $stmt = $pdo->prepare(
            "SELECT v.*, u.name AS owner_name,
                    CASE WHEN v.user_id = :u_case THEN 1 ELSE 0 END AS _is_owner
               FROM exec_dashboard_views v
          LEFT JOIN users u ON u.id = v.user_id
              WHERE v.tenant_id = :t
                AND v.slug = :s
                AND (v.user_id = :u_where OR v.is_shared = 1)
              LIMIT 1"
        );
        $stmt->execute(['t' => $tenantId, 's' => $slug, 'u_case' => $userId, 'u_where' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) api_error('View not found', 404);
        api_ok(['view' => _execViewSerialize($row)]);
    }

    $stmt = $pdo->prepare(
        "SELECT v.*, u.name AS owner_name,
                CASE WHEN v.user_id = :u_case THEN 1 ELSE 0 END AS _is_owner
           FROM exec_dashboard_views v
      LEFT JOIN users u ON u.id = v.user_id
          WHERE v.tenant_id = :t
            AND (v.user_id = :u_where OR v.is_shared = 1)
       ORDER BY _is_owner DESC, v.is_default DESC, v.name ASC"
    );
    $stmt->execute(['t' => $tenantId, 'u_case' => $userId, 'u_where' => $userId]);
    $views = array_map('_execViewSerialize', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    api_ok(['views' => $views]);
}

/* ---------- POST (create) ---------- */
if ($method === 'POST') {
    $body = api_json_body();
    api_require_fields($body, ['name', 'filters']);

    $name = trim((string) $body['name']);
    if ($name === '') api_error('name required', 422);
    if (mb_strlen($name) > 120) api_error('name too long (max 120)', 422);

    $filters  = is_array($body['filters']) ? _execViewSanitiseFilters($body['filters']) : [];
    $widgets  = array_key_exists('widget_config', $body) ? _execViewSanitiseWidgetConfig($body['widget_config']) : null;
    $shared   = (int) (bool) ($body['is_shared']  ?? 0);
    $default  = (int) (bool) ($body['is_default'] ?? 0);

    // Build a unique slug for this owner.
    $base = _execViewSlugify($name);
    $slug = $base;
    // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
    $stmt = $pdo->prepare("SELECT 1 FROM exec_dashboard_views WHERE user_id = :u AND slug = :s");
    $i = 1;
    while (true) {
        $stmt->execute(['u' => $userId, 's' => $slug]);
        if (!$stmt->fetchColumn()) break;
        $slug = $base . '-' . (++$i);
        if ($i > 50) { $slug = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6); break; }
    }

    $pdo->beginTransaction();
    try {
        if ($default === 1) {
            $pdo->prepare(
                "UPDATE exec_dashboard_views SET is_default = 0
                  WHERE tenant_id = :t AND user_id = :u"
            )->execute(['t' => $tenantId, 'u' => $userId]);
        }
        $pdo->prepare(
            "INSERT INTO exec_dashboard_views
                (tenant_id, user_id, name, slug, filters_json, widget_config_json, is_default, is_shared)
             VALUES (:t, :u, :n, :s, :f, :w, :d, :sh)"
        )->execute([
            't' => $tenantId, 'u' => $userId,
            'n' => $name, 's' => $slug,
            'f' => json_encode($filters),
            'w' => $widgets,
            'd' => $default, 'sh' => $shared,
        ]);
        $newId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        api_error('Save failed: ' . $e->getMessage(), 500);
    }

    api_ok(['id' => $newId, 'slug' => $slug], 201);
}

/* ---------- PATCH ---------- */
if ($method === 'PATCH' && $id) {
    $stmt = $pdo->prepare(
        "SELECT * FROM exec_dashboard_views WHERE id = :id AND tenant_id = :t LIMIT 1"
    );
    $stmt->execute(['id' => $id, 't' => $tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) api_error('View not found', 404);
    if (!_execViewCanModify($row, $userId, $role)) api_error('Forbidden', 403);

    $body = api_json_body();
    $sets = []; $params = ['id' => $id];

    if (array_key_exists('name', $body)) {
        $name = trim((string) $body['name']);
        if ($name === '') api_error('name required', 422);
        $sets[] = 'name = :name'; $params['name'] = $name;
    }
    if (array_key_exists('filters', $body) && is_array($body['filters'])) {
        $sets[] = 'filters_json = :f';
        $params['f'] = json_encode(_execViewSanitiseFilters($body['filters']));
    }
    if (array_key_exists('widget_config', $body)) {
        $sets[] = 'widget_config_json = :w';
        $params['w'] = _execViewSanitiseWidgetConfig($body['widget_config']);
    }
    if (array_key_exists('is_shared', $body)) {
        $sets[] = 'is_shared = :sh'; $params['sh'] = (int) (bool) $body['is_shared'];
    }
    if (array_key_exists('is_default', $body)) {
        $on = (int) (bool) $body['is_default'];
        if ($on === 1) {
            $pdo->prepare(
                "UPDATE exec_dashboard_views SET is_default = 0
                  WHERE tenant_id = :t AND user_id = :u AND id != :id"
            )->execute(['t' => $tenantId, 'u' => (int) $row['user_id'], 'id' => $id]);
        }
        $sets[] = 'is_default = :d'; $params['d'] = $on;
    }
    if (!$sets) api_error('No updatable fields', 422);

    $pdo->prepare(
        "UPDATE exec_dashboard_views SET " . implode(', ', $sets) .
        ", updated_at = NOW() WHERE id = :id"
    )->execute($params);

    api_ok(['id' => $id]);
}

/* ---------- DELETE ---------- */
if ($method === 'DELETE' && $id) {
    $stmt = $pdo->prepare(
        "SELECT * FROM exec_dashboard_views WHERE id = :id AND tenant_id = :t LIMIT 1"
    );
    $stmt->execute(['id' => $id, 't' => $tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) api_error('View not found', 404);
    if (!_execViewCanModify($row, $userId, $role)) api_error('Forbidden', 403);

    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare("DELETE FROM exec_dashboard_views WHERE id = :id")->execute(['id' => $id]);
    api_ok(['id' => $id, 'deleted' => true]);
}

api_error('Method not allowed', 405);
