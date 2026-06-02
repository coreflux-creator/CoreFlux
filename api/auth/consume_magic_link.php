<?php
/**
 * POST /api/auth/consume_magic_link.php
 *
 *   body: { token: string }
 *   resp: { ok: true, redirect_path: string, user: {...} }
 *      or { ok: false, reason: 'invalid'|'expired'|'consumed' } with 401/410
 *
 * On success:
 *   • Looks up (or JIT-creates) the user
 *   • Sets PHP session ($_SESSION['user'], ['tenant_id'], ['modules'])
 *   • Returns redirect path so the SPA can push the route
 *
 * Bind to specific tenant if the link carried tenant_id; otherwise leave
 * tenant unset (TenantPicker will resolve).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/magic_link.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/modules.php';

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body  = api_json_body();
$token = (string) ($body['token'] ?? '');
if ($token === '') api_error('token required', 422);

$result = magicLinkConsume($token, $_SERVER['REMOTE_ADDR'] ?? null);
if (!$result['ok']) {
    $code = $result['reason'] === 'consumed' ? 410 : 401;
    api_error("Sign-in link is no longer valid ({$result['reason']}). Request a new one.", $code, [
        'reason' => $result['reason'],
    ]);
}

$email    = $result['email'];
$tenantId = $result['tenant_id'];
$userId   = $result['user_id'];

$pdo = getDB();

// JIT-create user row if no match and DB is available.
if (!$userId) {
    try {
        // Schema-tolerant: prod `users` carries `name` + `is_active`; legacy
        // forks may also have first_name/last_name/status. Insert only the
        // columns that actually exist. Password columns are NOT NULL on the
        // legacy schema → seed an unusable bcrypt placeholder so the row is
        // valid but only magic-link sign-in works until the user sets a
        // real password.
        $colStmt = $pdo->query('SHOW COLUMNS FROM users');
        $cols    = array_column($colStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'Field');
        $cols    = array_map('strval', $cols);
        $row     = ['email' => $email];
        if (in_array('name',       $cols, true)) $row['name']       = $email;
        if (in_array('first_name', $cols, true)) $row['first_name'] = '';
        if (in_array('last_name',  $cols, true)) $row['last_name']  = '';
        if (in_array('role',       $cols, true)) $row['role']       = 'employee';
        if (in_array('status',     $cols, true)) $row['status']     = 'active';
        if (in_array('is_active',  $cols, true)) $row['is_active']  = 1;
        $placeholder = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        if (in_array('password',      $cols, true)) $row['password']      = $placeholder;
        if (in_array('password_hash', $cols, true)) $row['password_hash'] = $placeholder;

        $insertCols = []; $bindParts = []; $bind = [];
        foreach ($row as $k => $v) {
            $insertCols[] = $k;
            $bindParts[]  = ':' . $k;
            $bind[$k]     = $v;
        }
        if (in_array('created_at', $cols, true)) { $insertCols[] = 'created_at'; $bindParts[] = 'NOW()'; }

        $sql = 'INSERT INTO users (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $bindParts) . ')';
        $pdo->prepare($sql)->execute($bind);
        $userId = (int) $pdo->lastInsertId();
    } catch (\Throwable $e) {
        api_error('Could not provision account: ' . $e->getMessage(), 500);
    }
}

// Hydrate the user row. SELECT *  so we don't trip on first_name/last_name
// columns that aren't in the legacy schema; downstream only needs id/email/role/status|is_active.
$st = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$st->execute(['id' => $userId]);
$user = $st->fetch();
if (!$user) api_error('User not found after JIT', 500);
// Accept either `status='disabled'` (legacy fork) or `is_active=0` (canonical).
if (($user['status'] ?? null) === 'disabled' || (isset($user['is_active']) && (int) $user['is_active'] === 0)) {
    api_error('Account disabled', 403);
}

// If link bound to a tenant, ensure membership rows exist (idempotent).
// provisionMembership() dual-writes both user_tenants + tenant_memberships.
if ($tenantId) {
    try {
        require_once __DIR__ . '/../../core/memberships.php';
        provisionMembership((int) $userId, (int) $tenantId, (string) ($user['role'] ?: 'user'), [
            'persona_label' => 'Primary',
            'status'        => 'active',
        ]);
    } catch (\Throwable $_) { /* link still valid even if attach fails */ }

    // RBAC B3/B4 — stamp accepted_at + audit when this consume completes a
    // pending invite. Best-effort: a missed audit never blocks sign-in.
    try {
        $acc = $pdo->prepare(
            'UPDATE tenant_memberships
                SET accepted_at = NOW(), status = "active"
              WHERE user_id = :u AND tenant_id = :t
                AND status IN ("pending","suspended")
                AND accepted_at IS NULL'
        );
        $acc->execute(['u' => (int) $userId, 't' => (int) $tenantId]);
        if ($acc->rowCount() > 0 && class_exists('RBACResolver')) {
            $find = $pdo->prepare(
                'SELECT id, persona_type FROM tenant_memberships
                  WHERE user_id = :u AND tenant_id = :t AND status = "active"
                  ORDER BY accepted_at DESC LIMIT 1'
            );
            $find->execute(['u' => (int) $userId, 't' => (int) $tenantId]);
            $row = $find->fetch(\PDO::FETCH_ASSOC) ?: [];
            $mid     = (int) ($row['id'] ?? 0);
            $persona = (string) ($row['persona_type'] ?? '');
            if ($mid > 0) {
                RBACResolver::auditMembership($mid, 'invite_accepted', (int) $userId, [
                    'email'     => $email,
                    'tenant_id' => $tenantId,
                ]);

                // RBAC B6 — auto-apply the matching default profile if the
                // accepted membership is an `external_auditor`. The auditor
                // tokenized URL flow seats them with this persona but no
                // module grants; without this auto-apply the auditor would
                // sign in to a totally empty SPA. Best-effort + non-fatal.
                if ($persona === 'external_auditor') {
                    try {
                        require_once __DIR__ . '/../../core/rbac/permission_profiles.php';
                        $profile = PermissionProfileService::getByKey('external_auditor.default', (int) $tenantId);
                        if ($profile) {
                            PermissionProfileService::apply(
                                $mid, (int) $profile['id'], (int) $tenantId, (int) $userId, false, null
                            );
                        }
                    } catch (\Throwable $_) { /* best effort — sign-in still succeeds */ }
                }
            }
        }
    } catch (\Throwable $_) { /* best effort */ }
}

// Session handoff. Mirror the shape used by core/auth.php password login.
// Schema-tolerant name handling: `name` is the canonical column on the prod
// schema; some forks carry `first_name`/`last_name` instead. Split or join
// as needed so the SPA always gets a consistent {first_name,last_name} shape.
initSession();
$displayName = (string) ($user['name'] ?? '');
$firstNm     = (string) ($user['first_name'] ?? '');
$lastNm      = (string) ($user['last_name']  ?? '');
if ($firstNm === '' && $lastNm === '' && $displayName !== '') {
    $parts   = preg_split('/\s+/', trim($displayName), 2);
    $firstNm = (string) ($parts[0] ?? '');
    $lastNm  = (string) ($parts[1] ?? '');
}
$sessionUser = [
    'id'         => (int) $user['id'],
    'first_name' => $firstNm,
    'last_name'  => $lastNm,
    'email'      => (string) $user['email'],
    'role'       => (string) ($user['role']       ?? 'employee'),
    'avatar'     => null,
];
$_SESSION['user']    = $sessionUser;
$_SESSION['modules'] = getUserModules($sessionUser['role']);
if ($tenantId) {
    $_SESSION['tenant_id']        = $tenantId;
    $_SESSION['active_tenant_id'] = $tenantId;
    $tn = $pdo->prepare('SELECT name FROM tenants WHERE id = :id LIMIT 1');
    $tn->execute(['id' => $tenantId]);
    $tr = $tn->fetch();
    $_SESSION['tenant'] = $tr['name'] ?? null;
}
$_SESSION['active_module'] = $_SESSION['modules'][0] ?? null;
$_SESSION['auth_method']   = 'magic_link';

api_ok([
    'ok'            => true,
    'redirect_path' => $result['redirect_path'] ?: '/',
    'user'          => $sessionUser,
]);
