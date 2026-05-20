<?php
/**
 * /api/admin/memberships.php — tenant_memberships CRUD for the admin UI.
 *
 *   GET    /api/admin/memberships.php
 *            ?user_id=N          (optional)
 *            &include_inactive=1 (default: active+pending only)
 *          Lists memberships for the active tenant. Joins users + access counts.
 *
 *   POST   /api/admin/memberships.php
 *          Body: { user_id, persona_label?, persona_type?, linked_entity_type?,
 *                  linked_entity_id?, is_primary?, status? }
 *          Creates a new membership (or upserts on the unique key).
 *
 *   PATCH  /api/admin/memberships.php?id=N
 *          Body: any subset of { persona_label, persona_type, is_primary,
 *                                status, linked_entity_type, linked_entity_id }
 *
 *   DELETE /api/admin/memberships.php?id=N
 *          Sets status='revoked' (soft delete). audited.
 *
 * Auth: master_admin, tenant_admin, or platform global admin.
 * All writes append to membership_audit via RBACResolver::auditMembership().
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
    $pdo->query('SELECT 1 FROM tenant_memberships LIMIT 0');
} catch (\Throwable $_) {
    api_error('Migration 055_rbac_memberships.sql has not been applied yet.', 503);
}

const _ALLOWED_PERSONA_TYPES = [
    'master_admin','tenant_admin','admin','manager','employee',
    'contractor','client','vendor','platform_staff','custom',
];
const _ALLOWED_STATUS = ['active','pending','suspended','revoked'];

$method = api_method();

if ($method === 'GET') {
    $userId          = api_query('user_id') !== null ? (int) api_query('user_id') : null;
    $includeInactive = (string) api_query('include_inactive') === '1';

    $sql = 'SELECT tm.id, tm.user_id, tm.tenant_id, tm.persona_label, tm.persona_type,
                   tm.linked_entity_type, tm.linked_entity_id, tm.is_primary, tm.status,
                   tm.invited_by_user_id, tm.invited_at, tm.accepted_at, tm.last_active_at,
                   tm.created_at, tm.updated_at,
                   u.name AS user_name, u.email, u.is_global_admin,
                   (SELECT COUNT(*) FROM membership_module_access mma WHERE mma.membership_id = tm.id) AS modules_count
              FROM tenant_memberships tm
              JOIN users u ON u.id = tm.user_id
             WHERE tm.tenant_id = :t';
    $bind = ['t' => $tenantId];
    if (!$includeInactive) { $sql .= ' AND tm.status IN ("active","pending")'; }
    if ($userId !== null) { $sql .= ' AND tm.user_id = :u'; $bind['u'] = $userId; }
    $sql .= ' ORDER BY tm.is_primary DESC, u.name ASC, tm.persona_label ASC';

    $st = $pdo->prepare($sql);
    $st->execute($bind);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']               = (int) $r['id'];
        $r['user_id']          = (int) $r['user_id'];
        $r['tenant_id']        = (int) $r['tenant_id'];
        $r['is_primary']       = (int) $r['is_primary'] === 1;
        $r['is_global_admin']  = (int) ($r['is_global_admin'] ?? 0) === 1;
        $r['modules_count']    = (int) $r['modules_count'];
        $r['linked_entity_id'] = $r['linked_entity_id'] !== null ? (int) $r['linked_entity_id'] : null;
    }
    api_ok(['memberships' => $rows]);
}

if ($method === 'POST') {
    $body = api_json_body();
    api_require_fields($body, ['user_id']);
    $userId        = (int) $body['user_id'];
    $personaLabel  = trim((string) ($body['persona_label'] ?? 'Primary')) ?: 'Primary';
    $personaType   = (string) ($body['persona_type'] ?? 'employee');
    if (!in_array($personaType, _ALLOWED_PERSONA_TYPES, true)) {
        api_error('Invalid persona_type', 422, ['allowed' => _ALLOWED_PERSONA_TYPES]);
    }
    $status = (string) ($body['status'] ?? 'active');
    if (!in_array($status, _ALLOWED_STATUS, true)) {
        api_error('Invalid status', 422, ['allowed' => _ALLOWED_STATUS]);
    }
    $linkedType = $body['linked_entity_type'] ?? null;
    $linkedId   = isset($body['linked_entity_id']) ? (int) $body['linked_entity_id'] : null;
    $isPrimary  = !empty($body['is_primary']) ? 1 : 0;

    // Verify the user actually exists.
    $u = $pdo->prepare('SELECT id FROM users WHERE id = :u LIMIT 1');
    $u->execute(['u' => $userId]);
    if (!$u->fetchColumn()) api_error('user_id not found', 404);

    $st = $pdo->prepare(
        'INSERT INTO tenant_memberships
            (user_id, tenant_id, persona_label, persona_type,
             linked_entity_type, linked_entity_id, is_primary, status,
             invited_by_user_id, invited_at, accepted_at)
         VALUES
            (:u, :t, :pl, :pt, :let, :lei, :ip, :s, :ib,
             CASE WHEN :s2 = "pending" THEN NOW() ELSE NULL END,
             CASE WHEN :s3 = "active"  THEN NOW() ELSE NULL END)
         ON DUPLICATE KEY UPDATE
            persona_type       = VALUES(persona_type),
            linked_entity_type = VALUES(linked_entity_type),
            linked_entity_id   = VALUES(linked_entity_id),
            is_primary         = VALUES(is_primary),
            status             = VALUES(status)'
    );
    $st->execute([
        'u' => $userId, 't' => $tenantId, 'pl' => $personaLabel, 'pt' => $personaType,
        'let' => $linkedType, 'lei' => $linkedId, 'ip' => $isPrimary, 's' => $status,
        's2' => $status, 's3' => $status, 'ib' => $actorId,
    ]);

    // Fetch the (possibly upserted) row id.
    $row = $pdo->prepare(
        'SELECT id FROM tenant_memberships
          WHERE user_id = :u AND tenant_id = :t AND persona_label = :pl LIMIT 1'
    );
    $row->execute(['u' => $userId, 't' => $tenantId, 'pl' => $personaLabel]);
    $newId = (int) $row->fetchColumn();

    // Enforce single-primary per (user, tenant) when is_primary=1.
    if ($isPrimary && $newId > 0) {
        $upd = $pdo->prepare(
            'UPDATE tenant_memberships SET is_primary = 0
              WHERE user_id = :u AND tenant_id = :t AND id <> :id'
        );
        $upd->execute(['u' => $userId, 't' => $tenantId, 'id' => $newId]);
    }

    RBACResolver::auditMembership($newId, 'created', $actorId, [
        'persona_label' => $personaLabel, 'persona_type' => $personaType,
        'status' => $status, 'is_primary' => $isPrimary,
    ]);
    RBACResolver::resetCache();
    api_ok(['id' => $newId, 'created' => true], 201);
}

if ($method === 'PATCH') {
    $id = (int) (api_query('id') ?? 0);
    if (!$id) api_error('id is required', 422);
    $body = api_json_body();

    // Confirm membership belongs to this tenant.
    $check = $pdo->prepare('SELECT user_id FROM tenant_memberships WHERE id = :id AND tenant_id = :t LIMIT 1');
    $check->execute(['id' => $id, 't' => $tenantId]);
    $userId = (int) ($check->fetchColumn() ?: 0);
    if (!$userId) api_error('Membership not found in this tenant', 404);

    $sets = []; $bind = ['id' => $id];
    foreach (['persona_label','persona_type','status','linked_entity_type'] as $field) {
        if (array_key_exists($field, $body)) {
            $sets[] = "{$field} = :{$field}";
            $bind[$field] = $body[$field];
        }
    }
    if (array_key_exists('linked_entity_id', $body)) {
        $sets[] = 'linked_entity_id = :linked_entity_id';
        $bind['linked_entity_id'] = $body['linked_entity_id'] !== null ? (int) $body['linked_entity_id'] : null;
    }
    if (array_key_exists('is_primary', $body)) {
        $sets[] = 'is_primary = :is_primary';
        $bind['is_primary'] = !empty($body['is_primary']) ? 1 : 0;
    }
    if (isset($bind['persona_type']) && !in_array($bind['persona_type'], _ALLOWED_PERSONA_TYPES, true)) {
        api_error('Invalid persona_type', 422, ['allowed' => _ALLOWED_PERSONA_TYPES]);
    }
    if (isset($bind['status']) && !in_array($bind['status'], _ALLOWED_STATUS, true)) {
        api_error('Invalid status', 422, ['allowed' => _ALLOWED_STATUS]);
    }
    if (!$sets) api_error('No fields to update', 422);

    $st = $pdo->prepare('UPDATE tenant_memberships SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $st->execute($bind);

    if (!empty($bind['is_primary'])) {
        $upd = $pdo->prepare(
            'UPDATE tenant_memberships SET is_primary = 0
              WHERE user_id = :u AND tenant_id = :t AND id <> :id'
        );
        $upd->execute(['u' => $userId, 't' => $tenantId, 'id' => $id]);
    }

    RBACResolver::auditMembership($id, 'updated', $actorId, $body);
    RBACResolver::resetCache();
    api_ok(['id' => $id, 'updated' => true]);
}

if ($method === 'DELETE') {
    $id = (int) (api_query('id') ?? 0);
    if (!$id) api_error('id is required', 422);

    $check = $pdo->prepare('SELECT 1 FROM tenant_memberships WHERE id = :id AND tenant_id = :t LIMIT 1');
    $check->execute(['id' => $id, 't' => $tenantId]);
    if (!$check->fetchColumn()) api_error('Membership not found in this tenant', 404);

    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $st = $pdo->prepare("UPDATE tenant_memberships SET status = 'revoked' WHERE id = :id");
    $st->execute(['id' => $id]);

    RBACResolver::auditMembership($id, 'revoked', $actorId, []);
    RBACResolver::resetCache();
    api_ok(['id' => $id, 'revoked' => true]);
}

api_error('Method not allowed', 405);
