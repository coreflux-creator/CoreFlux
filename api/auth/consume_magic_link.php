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
        $ins = $pdo->prepare(
            "INSERT INTO users (email, first_name, last_name, role, status, created_at)
             VALUES (:e, '', '', 'employee', 'active', NOW())"
        );
        $ins->execute(['e' => $email]);
        $userId = (int) $pdo->lastInsertId();
    } catch (\Throwable $e) {
        api_error('Could not provision account: ' . $e->getMessage(), 500);
    }
}

// Hydrate the user row.
$st = $pdo->prepare('SELECT id, email, first_name, last_name, role, status FROM users WHERE id = :id LIMIT 1');
$st->execute(['id' => $userId]);
$user = $st->fetch();
if (!$user) api_error('User not found after JIT', 500);
if (($user['status'] ?? 'active') === 'disabled') api_error('Account disabled', 403);

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
}

// Session handoff. Mirror the shape used by core/auth.php password login.
initSession();
$sessionUser = [
    'id'         => (int) $user['id'],
    'first_name' => (string) ($user['first_name'] ?? ''),
    'last_name'  => (string) ($user['last_name']  ?? ''),
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
