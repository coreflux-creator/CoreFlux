<?php
/**
 * /api/admin/auditor_tokens.php — CRUD for External Auditor tokens.
 *
 *   GET    /api/admin/auditor_tokens.php?tenant_id=N
 *         → { tokens: [...], active_count, expired_count }
 *
 *   POST   /api/admin/auditor_tokens.php
 *         body { tenant_id, label, email?, days?, scope_modules?[] }
 *         → { id, token, expires_at }   // token only returned ONCE
 *
 *   PATCH  /api/admin/auditor_tokens.php?id=N&action=revoke
 *         → { id, revoked_at }
 *
 *   DELETE /api/admin/auditor_tokens.php?id=N
 *         → hard-deletes the row (audit log retained)
 *
 * Permission gate:
 *   - master_admin / is_global_admin → any tenant
 *   - tenant_admin / admin           → tenant they admin (or its sub-tenants)
 *   - everyone else                  → 403
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/auditor.php';
require_once __DIR__ . '/../../core/sub_tenants.php';

$ctx        = api_require_auth();
$user       = $ctx['user'];
$actorId    = (int) ($user['id'] ?? 0);
$role       = (string) ($ctx['role'] ?? 'employee');
$globalRole = (string) ($ctx['global_role'] ?? $role);
$isGlobalAdm= (bool)   ($ctx['is_global_admin'] ?? false);
$activeTid  = $ctx['tenant_id'] ?? null;

$isPlatformMA = ($globalRole === 'master_admin') || $isGlobalAdm;
if (!$isPlatformMA && !in_array($role, ['tenant_admin', 'admin'], true)) {
    api_error('Forbidden — only master_admin or tenant_admin can issue auditor tokens', 403);
}

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

$method = api_method();

/** Tenant-scope guard: tenant_admins can only act on their tenant or its subs. */
function _auditorTenantAllowed(?int $tid, bool $isPlatformMA, ?int $activeTid): bool {
    if (!$tid) return false;
    if ($isPlatformMA) return true;
    if (!$activeTid) return false;
    if ($tid === $activeTid) return true;
    $t = subTenantLookup($tid);
    return $t && (int) ($t['parent_id'] ?? 0) === $activeTid;
}

// ----------------------------------------------------------------- GET ---
if ($method === 'GET') {
    // Per-token session log (?action=log&id=N) — accessed by the admin UI to
    // drill into a single auditor's activity. Stats + capped event list.
    $action = (string) api_query('action', '');
    if ($action === 'log') {
        $id = (int) api_query('id', 0);
        if (!$id) api_error('id required', 422);
        $row = _auditorFetch($pdo, $id);
        if (!$row) api_error('Token not found', 404);
        if (!_auditorTenantAllowed((int) $row['tenant_id'], $isPlatformMA, $activeTid)) {
            api_error('Forbidden', 403);
        }

        // Aggregate stats.
        // tenant-leak-allow: filtered by token_id; tenant scope was just
        // authorised on the token row via _auditorTenantAllowed() above.
        $st = $pdo->prepare(
            "SELECT COUNT(*)                           AS hits,
                    COUNT(DISTINCT path)               AS unique_paths,
                    MIN(occurred_at)                   AS first_seen,
                    MAX(occurred_at)                   AS last_seen,
                    SUM(action='redeem')               AS redeems,
                    SUM(action='view')                 AS views,
                    COUNT(DISTINCT ip)                 AS unique_ips
               FROM auditor_access_log
              WHERE token_id = :id"
        );
        $st->execute(['id' => $id]);
        $stats = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        // Most-visited paths (top 15, with hit counts and last visit).
        // tenant-leak-allow: same justification — token_id keyed lookup
        // after tenant authorisation upstream.
        $st = $pdo->prepare(
            "SELECT path, COUNT(*) AS hits, MAX(occurred_at) AS last_seen
               FROM auditor_access_log
              WHERE token_id = :id AND action = 'view' AND path IS NOT NULL
           GROUP BY path
           ORDER BY hits DESC, last_seen DESC
              LIMIT 15"
        );
        $st->execute(['id' => $id]);
        $topPaths = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Raw event list (most recent 200 — UI shows them in reverse-chrono).
        // tenant-leak-allow: token_id keyed; tenant scope authorised above.
        $st = $pdo->prepare(
            "SELECT id, action, path, ip, user_agent, occurred_at
               FROM auditor_access_log
              WHERE token_id = :id
           ORDER BY occurred_at DESC
              LIMIT 200"
        );
        $st->execute(['id' => $id]);
        $events = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($events as &$e) { $e['id'] = (int) $e['id']; }
        unset($e);
        foreach ($topPaths as &$p) { $p['hits'] = (int) $p['hits']; }
        unset($p);
        foreach (['hits','unique_paths','redeems','views','unique_ips'] as $k) {
            if (isset($stats[$k])) $stats[$k] = (int) $stats[$k];
        }

        api_ok([
            'token'      => [
                'id'         => (int) $row['id'],
                'tenant_id'  => (int) $row['tenant_id'],
                'label'      => $row['label'],
                'email'      => $row['email'],
                'expires_at' => $row['expires_at'],
                'revoked_at' => $row['revoked_at'],
            ],
            'stats'      => $stats,
            'top_paths'  => $topPaths,
            'events'     => $events,
        ]);
    }

    $tenantFilter = (int) api_query('tenant_id', 0);
    if (!$tenantFilter && !$isPlatformMA) $tenantFilter = (int) $activeTid;

    $sql = "SELECT at.id, at.tenant_id, at.label, at.email, at.expires_at,
                   at.last_used_at, at.revoked_at, at.scope_modules, at.created_at,
                   t.name AS tenant_name,
                   (at.revoked_at IS NULL AND at.expires_at > NOW()) AS is_active
              FROM auditor_tokens at
              JOIN tenants t ON t.id = at.tenant_id";
    $params = [];
    if ($tenantFilter) {
        if (!_auditorTenantAllowed($tenantFilter, $isPlatformMA, $activeTid)) {
            api_error('Forbidden — you do not manage this tenant', 403);
        }
        $sql .= " WHERE at.tenant_id = :t";
        $params['t'] = $tenantFilter;
    } elseif (!$isPlatformMA) {
        // Defensive: non-platform user with no filter should still be scoped.
        $sql .= " WHERE at.tenant_id = :t";
        $params['t'] = (int) $activeTid;
    }
    $sql .= " ORDER BY at.revoked_at IS NULL DESC, at.expires_at DESC LIMIT 500";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $active = 0; $expired = 0;
    foreach ($rows as &$r) {
        $r['id']           = (int) $r['id'];
        $r['tenant_id']    = (int) $r['tenant_id'];
        $r['is_active']    = (int) $r['is_active'] === 1;
        $r['scope_modules']= $r['scope_modules'] ? json_decode((string) $r['scope_modules'], true) : null;
        if ($r['is_active']) $active++;
        elseif ($r['revoked_at'] === null) $expired++;
    }
    unset($r);
    api_ok(['tokens' => $rows, 'active_count' => $active, 'expired_count' => $expired]);
}

// ---------------------------------------------------------------- POST ---
if ($method === 'POST') {
    $body = api_json_body();
    api_require_fields($body, ['label']);

    $tenantId = (int) ($body['tenant_id'] ?? $activeTid ?? 0);
    $label    = trim((string) $body['label']);
    $email    = isset($body['email']) ? trim((string) $body['email']) : null;
    $days     = max(1, min(90, (int) ($body['days'] ?? 7)));   // 1..90, default 7
    $scope    = isset($body['scope_modules']) && is_array($body['scope_modules'])
              ? array_values(array_filter(array_map('strval', $body['scope_modules'])))
              : null;

    if ($label === '') api_error('label is required', 422);
    if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('Invalid email', 422);
    }
    if (!_auditorTenantAllowed($tenantId, $isPlatformMA, $activeTid)) {
        api_error('Forbidden — you do not manage that tenant', 403);
    }

    [$plain, $hash] = auditorGenerateToken();
    $expiresAt = (new DateTimeImmutable("+{$days} days"))->format('Y-m-d H:i:s');

    $pdo->prepare(
        'INSERT INTO auditor_tokens
            (tenant_id, label, email, token_hash, scope_modules,
             expires_at, created_by_user, created_at)
         VALUES (:t, :l, :e, :h, :sc, :ex, :a, NOW())'
    )->execute([
        't'  => $tenantId,
        'l'  => $label,
        'e'  => $email ?: null,
        'h'  => $hash,
        'sc' => $scope ? json_encode($scope) : null,
        'ex' => $expiresAt,
        'a'  => $actorId,
    ]);
    $id = (int) $pdo->lastInsertId();

    // Token is shown ONCE — never persisted in plain.
    api_ok([
        'id'         => $id,
        'token'      => $plain,
        'expires_at' => $expiresAt,
        'url'        => '/auditor.php?token=' . urlencode($plain),
    ], 201);
}

// --------------------------------------------------------------- PATCH ---
if ($method === 'PATCH') {
    $id = (int) api_query('id', 0);
    $action = (string) api_query('action', '');
    if (!$id) api_error('id required', 422);

    $row = _auditorFetch($pdo, $id);
    if (!$row) api_error('Token not found', 404);
    if (!_auditorTenantAllowed((int) $row['tenant_id'], $isPlatformMA, $activeTid)) {
        api_error('Forbidden', 403);
    }
    if ($action === 'revoke') {
        if ($row['revoked_at'] !== null) {
            api_ok(['id' => $id, 'revoked_at' => $row['revoked_at']]);
        }
        // tenant-leak-allow: tenant scope was authorised via
        // _auditorTenantAllowed() on this row's own tenant_id above.
        $pdo->prepare('UPDATE auditor_tokens SET revoked_at = NOW() WHERE id = :id')
            ->execute(['id' => $id]);
        auditorLog($id, (int) $row['tenant_id'], 'revoked_by_admin');
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        api_ok(['id' => $id, 'revoked_at' => $now]);
    }
    api_error("Unknown action '{$action}'", 422);
}

// -------------------------------------------------------------- DELETE ---
if ($method === 'DELETE') {
    $id = (int) api_query('id', 0);
    if (!$id) api_error('id required', 422);
    $row = _auditorFetch($pdo, $id);
    if (!$row) api_error('Token not found', 404);
    if (!_auditorTenantAllowed((int) $row['tenant_id'], $isPlatformMA, $activeTid)) {
        api_error('Forbidden', 403);
    }
    // tenant-leak-allow: tenant scope was just authorised via
    // _auditorTenantAllowed() against the row's own tenant_id above.
    $pdo->prepare('DELETE FROM auditor_tokens WHERE id = :id')->execute(['id' => $id]);
    api_ok(['id' => $id, 'deleted' => true]);
}

api_error('Method not allowed', 405);

function _auditorFetch(PDO $pdo, int $id): ?array {
    // tenant-leak-allow: caller (PATCH / DELETE) immediately runs
    // _auditorTenantAllowed() on the row's tenant_id before mutating.
    $st = $pdo->prepare('SELECT * FROM auditor_tokens WHERE id = :id LIMIT 1');
    $st->execute(['id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
