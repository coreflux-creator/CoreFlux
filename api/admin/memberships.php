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
    // Legacy persona types (pre-migration 100).
    'master_admin','tenant_admin','admin','manager','employee',
    'contractor','client','vendor','platform_staff','custom',
    // CPA-firm-side personas (migration 100). These let a CPA user be
    // modelled with the right "title" inside a client tenant so that
    // permission profiles + the future CPA portal can pivot off
    // persona_type rather than yet another column.
    'cpa','cpa_partner','cpa_staff',
    'bookkeeper','client_advisor','external_auditor',
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

if ($method === 'POST' && (string) (api_query('action') ?? '') === 'invite') {
    // Invite-by-email — RBAC B3/B4.
    //
    // Body:
    //   {
    //     email:           "controller@acme.com",       // required
    //     name:            "Jane Doe",                  // optional, splits → first/last
    //     persona_label:   "Controller",                // optional, default "Primary"
    //     persona_type:    "admin",                     // optional, default "employee"
    //     modules:         [{ module_key, access_level, sub_tenant_scope? }, ...], // optional starter grants
    //     ttl_minutes:     10080,                       // optional, default 7 days
    //     redirect_path:   "/admin/memberships",        // optional, where to land after consume
    //   }
    //
    // Behaviour:
    //   1. Find or JIT-create the user (no password — they sign in via magic link).
    //   2. Upsert tenant_memberships row with status='pending', invited_by, invited_at.
    //   3. Seed membership_module_access rows if `modules` is provided.
    //   4. Issue a magic link bound to the current tenant + invite redirect path.
    //   5. Send the invite mail via mailerSend() (Resend in prod, LogDriver in dev).
    //   6. Audit via RBACResolver::auditMembership('invited').
    //
    // Returns: { ok, membership_id, user_id, email, expires_at, magic_link_url?, mailer:{ok,driver,error?} }
    //   magic_link_url is only included for platform global admins so they can
    //   retrieve a fresh link out-of-band when an invitee can't find the email.
    require_once __DIR__ . '/../../core/magic_link.php';
    require_once __DIR__ . '/../../core/mailer.php';
    require_once __DIR__ . '/../../core/rbac/permission_profiles.php';

    $body  = api_json_body();
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('A valid email is required', 422);
    }

    $personaLabel = trim((string) ($body['persona_label'] ?? 'Primary')) ?: 'Primary';
    $personaType  = (string) ($body['persona_type']  ?? 'employee');
    if (!in_array($personaType, _ALLOWED_PERSONA_TYPES, true)) {
        api_error('Invalid persona_type', 422, ['allowed' => _ALLOWED_PERSONA_TYPES]);
    }
    $ttlMinutes   = max(15, min(60 * 24 * 30, (int) ($body['ttl_minutes'] ?? 60 * 24 * 7))); // 15min..30d, default 7d
    $redirectPath = (string) ($body['redirect_path'] ?? '/admin/memberships');
    $modules      = is_array($body['modules'] ?? null) ? $body['modules'] : [];
    $nameRaw      = trim((string) ($body['name'] ?? ''));
    [$firstName, $lastName] = (static function (string $n): array {
        if ($n === '') return ['', ''];
        $parts = preg_split('/\s+/', $n, 2);
        return [(string) $parts[0], (string) ($parts[1] ?? '')];
    })($nameRaw);

    // 1) Find or JIT-create the user.
    $st = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
    $st->execute(['e' => $email]);
    $existing = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    if ($existing) {
        $invitedUserId = (int) $existing['id'];
    } else {
        // Schema-tolerant insert: the canonical `users` table here carries a
        // single `name` column + `is_active` flag; some forks also have
        // `first_name`/`last_name`/`status`. Introspect once and INSERT only
        // the columns that actually exist.
        $colStmt = $pdo->query('SHOW COLUMNS FROM users');
        $cols    = array_column($colStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'Field');
        $cols    = array_map('strval', $cols);
        $row     = ['email' => $email];
        if (in_array('name', $cols, true)) {
            $row['name'] = trim($firstName . ' ' . $lastName) ?: $email;
        }
        if (in_array('first_name', $cols, true)) $row['first_name'] = $firstName;
        if (in_array('last_name',  $cols, true)) $row['last_name']  = $lastName;
        if (in_array('role', $cols, true)) {
            $row['role'] = in_array($personaType, ['tenant_admin','admin','manager','employee','contractor'], true)
                           ? $personaType : 'employee';
        }
        if (in_array('status',    $cols, true)) $row['status']    = 'active';
        if (in_array('is_active', $cols, true)) $row['is_active'] = 1;
        if (in_array('created_at', $cols, true)) $row['__created_at_now'] = true;
        // password/password_hash are NOT NULL in the legacy schema. Seed a
        // placeholder bcrypt of a 32-byte random secret so the row is valid
        // but unusable for password login (invitee must complete magic-link
        // sign-in and then set a real password via the profile flow).
        $placeholder = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        if (in_array('password',      $cols, true)) $row['password']      = $placeholder;
        if (in_array('password_hash', $cols, true)) $row['password_hash'] = $placeholder;

        $insertCols = []; $placeholders = []; $bind = [];
        foreach ($row as $k => $v) {
            if ($k === '__created_at_now') continue;
            $insertCols[]  = $k;
            $placeholders[] = ':' . $k;
            $bind[$k]      = $v;
        }
        if (!empty($row['__created_at_now'])) {
            $insertCols[]   = 'created_at';
            $placeholders[] = 'NOW()';
        }
        $sql = 'INSERT INTO users (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $ins = $pdo->prepare($sql);
        $ins->execute($bind);
        $invitedUserId = (int) $pdo->lastInsertId();
    }

    // 2) Upsert membership in 'pending' state.
    $up = $pdo->prepare(
        'INSERT INTO tenant_memberships
            (user_id, tenant_id, persona_label, persona_type, is_primary, status,
             invited_by_user_id, invited_at)
         VALUES (:u, :t, :pl, :pt, 0, "pending", :ib, NOW())
         ON DUPLICATE KEY UPDATE
            persona_type       = VALUES(persona_type),
            status             = IF(status = "revoked", "pending", status),
            invited_by_user_id = VALUES(invited_by_user_id),
            invited_at         = NOW()'
    );
    $up->execute([
        'u'  => $invitedUserId, 't' => $tenantId,
        'pl' => $personaLabel,  'pt' => $personaType,
        'ib' => $actorId,
    ]);
    $find = $pdo->prepare(
        'SELECT id FROM tenant_memberships
          WHERE user_id = :u AND tenant_id = :t AND persona_label = :pl LIMIT 1'
    );
    $find->execute(['u' => $invitedUserId, 't' => $tenantId, 'pl' => $personaLabel]);
    $membershipId = (int) $find->fetchColumn();

    // 3) Seed module grants if provided.
    foreach ($modules as $g) {
        if (!is_array($g)) continue;
        $mk = (string) ($g['module_key'] ?? '');
        $al = (string) ($g['access_level'] ?? 'none');
        if ($mk === '' || !in_array($al, ['none','read','write','admin'], true)) continue;
        if ($al === 'none') continue;
        $scope = isset($g['sub_tenant_scope']) && is_array($g['sub_tenant_scope'])
            ? array_values(array_map('intval', $g['sub_tenant_scope']))
            : null;
        try { RBACResolver::grantModule($membershipId, $mk, $al, $scope, $actorId); }
        catch (\Throwable $_) { /* ignore — invite still completes */ }
    }

    // 3b) Apply named permission profile if provided (RBAC B6 / migration 100).
    // Operators onboarding a CPA pick "cpa_partner.default" once and the
    // invite endpoint stamps every module grant in one shot — no need to
    // round-trip /membership_access.php for each module after the user
    // accepts the invite.
    $profileKey = trim((string) ($body['profile_key'] ?? ''));
    $profileApplied = null;
    if ($profileKey !== '' && $membershipId > 0) {
        try {
            $profile = PermissionProfileService::getByKey($profileKey, $tenantId);
            if ($profile) {
                $appliedCount = PermissionProfileService::apply(
                    $membershipId, (int) $profile['id'], $tenantId, $actorId, false, null
                );
                $profileApplied = [
                    'profile_key'    => $profile['profile_key'],
                    'profile_id'     => $profile['id'],
                    'grants_applied' => $appliedCount,
                ];
            }
        } catch (\Throwable $e) {
            // Surfaced on the response so the operator knows the invite
            // succeeded but the profile didn't seat — non-fatal.
            $profileApplied = ['profile_key' => $profileKey, 'error' => $e->getMessage()];
        }
    }

    // 4) Issue magic link.
    $link = magicLinkIssue(
        $email,
        $tenantId,
        $redirectPath,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
        $ttlMinutes
    );
    $linkUrl = magicLinkUrl($link['raw_token']);

    // 5) Resolve tenant name + inviter name for the email body.
    $tName  = (string) ($pdo->query('SELECT name FROM tenants WHERE id = ' . (int) $tenantId)->fetchColumn() ?: 'CoreFlux');
    $invStmt = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = :id LIMIT 1');
    $invStmt->execute(['id' => $actorId]);
    $inviter   = $invStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    $invName   = trim((string) ($inviter['first_name'] ?? '') . ' ' . (string) ($inviter['last_name'] ?? ''))
                 ?: ((string) ($inviter['email'] ?? 'A teammate'));
    $expiresIso = (string) $link['expires_at'];

    $subject  = "You've been invited to {$tName} on CoreFlux";
    $linkSafe = htmlspecialchars($linkUrl, ENT_QUOTES, 'UTF-8');
    $bodyHtml = "<p>Hi" . ($firstName !== '' ? ' ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') : '') . ",</p>"
              . "<p><strong>" . htmlspecialchars($invName, ENT_QUOTES, 'UTF-8') . "</strong> has invited you to join "
              . "<strong>" . htmlspecialchars($tName, ENT_QUOTES, 'UTF-8') . "</strong> on CoreFlux as "
              . "<em>" . htmlspecialchars($personaLabel, ENT_QUOTES, 'UTF-8') . " ({$personaType})</em>.</p>"
              . "<p><a href=\"{$linkSafe}\" style=\"display:inline-block;padding:10px 18px;background:#1f6feb;color:#fff;text-decoration:none;border-radius:6px;\">Accept invite & sign in</a></p>"
              . "<p style=\"font-size:12px;color:#666\">Or paste this link into your browser:<br><code>{$linkSafe}</code></p>"
              . "<p style=\"font-size:12px;color:#666\">This invite expires on {$expiresIso} (UTC). If you weren't expecting this, you can safely ignore it.</p>";
    $bodyText = "{$invName} has invited you to join {$tName} on CoreFlux as {$personaLabel} ({$personaType}).\n\n"
              . "Accept your invite:\n{$linkUrl}\n\n"
              . "This invite expires on {$expiresIso} (UTC).";

    $mailRes = mailerSend([
        'to'        => $email,
        'subject'   => $subject,
        'body_html' => $bodyHtml,
        'body_text' => $bodyText,
        'tenant_id' => $tenantId,
        'module'    => 'admin',
        'purpose'   => 'membership_invite',
    ]);

    RBACResolver::auditMembership($membershipId, 'invited', $actorId, [
        'email'         => $email,
        'persona_label' => $personaLabel,
        'persona_type'  => $personaType,
        'mailer_driver' => $mailRes['driver'] ?? null,
        'mailer_ok'     => (bool) ($mailRes['ok'] ?? false),
        'expires_at'    => $expiresIso,
    ]);
    RBACResolver::resetCache();

    $resp = [
        'ok'             => true,
        'membership_id'  => $membershipId,
        'user_id'        => $invitedUserId,
        'email'          => $email,
        'expires_at'     => $expiresIso,
        'mailer'         => [
            'ok'     => (bool) ($mailRes['ok'] ?? false),
            'driver' => (string) ($mailRes['driver'] ?? 'unknown'),
            'error'  => $mailRes['error'] ?? null,
        ],
    ];
    if ($profileApplied !== null) $resp['profile_applied'] = $profileApplied;
    if ($isGlobalAdmin) $resp['magic_link_url'] = $linkUrl;
    api_ok($resp, 201);
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

    // Optional permission profile application (RBAC B6).
    // Lets the admin onboard a CPA-staff / bookkeeper / cpa_partner in one
    // POST without a follow-up round-trip to /membership_access.php for
    // each module. Non-fatal: a bad profile_key just surfaces in the
    // response payload while the membership row itself still ships.
    $profileKey = trim((string) ($body['profile_key'] ?? ''));
    $profileApplied = null;
    if ($profileKey !== '' && $newId > 0) {
        require_once __DIR__ . '/../../core/rbac/permission_profiles.php';
        try {
            $profile = PermissionProfileService::getByKey($profileKey, $tenantId);
            if ($profile) {
                $appliedCount = PermissionProfileService::apply(
                    $newId, (int) $profile['id'], $tenantId, $actorId, false, null
                );
                $profileApplied = [
                    'profile_key'    => $profile['profile_key'],
                    'profile_id'     => $profile['id'],
                    'grants_applied' => $appliedCount,
                ];
            } else {
                $profileApplied = ['profile_key' => $profileKey, 'error' => 'profile_not_found'];
            }
        } catch (\Throwable $e) {
            $profileApplied = ['profile_key' => $profileKey, 'error' => $e->getMessage()];
        }
    }

    $resp = ['id' => $newId, 'created' => true];
    if ($profileApplied !== null) $resp['profile_applied'] = $profileApplied;
    api_ok($resp, 201);
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
