<?php
/**
 * Mobile login — issues JWT access + refresh tokens.
 *
 *   POST /api/auth/mobile_login
 *     { email, password, tenant_code?, device_id?, platform?, app_version? }
 *
 * Returns:
 *   { access_token, refresh_token, expires_in, user, tenant }
 *
 * The web SPA continues to use the session-cookie login at /api/login.php;
 * this endpoint exists for native (Expo) and any external API clients.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/jwt.php';
require_once __DIR__ . '/../../core/db.php';

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body = api_json_body();
api_require_fields($body, ['email', 'password']);

$email = strtolower(trim((string) $body['email']));
$pwd   = (string) $body['password'];

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

// Find the user by email. Schema: users.password_hash (preferred) with users.password as legacy.
$stmt = $pdo->prepare(
    "SELECT id, name, email, password, password_hash, role, is_active
       FROM users
      WHERE email = :e LIMIT 1"
);
$stmt->execute(['e' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user || !$user['is_active']) {
    api_error('Invalid credentials', 401);
}

$hash = (string) ($user['password_hash'] ?? $user['password'] ?? '');
if (!$hash || !password_verify($pwd, $hash)) {
    api_error('Invalid credentials', 401);
}

// Resolve tenant. Prefer the requested tenant_code, otherwise pick the user's first active mapping.
$tenantId   = null;
$tenantCode = $body['tenant_code'] ?? null;
if ($tenantCode) {
    $tStmt = $pdo->prepare(
        "SELECT t.id, t.code, t.name FROM tenants t
           JOIN user_tenants ut ON ut.tenant_id = t.id
          WHERE ut.user_id = :u AND t.code = :c AND ut.status = 'active'
          LIMIT 1"
    );
    $tStmt->execute(['u' => (int) $user['id'], 'c' => (string) $tenantCode]);
    $tenant = $tStmt->fetch(PDO::FETCH_ASSOC);
} else {
    $tStmt = $pdo->prepare(
        "SELECT t.id, t.code, t.name FROM tenants t
           JOIN user_tenants ut ON ut.tenant_id = t.id
          WHERE ut.user_id = :u AND ut.status = 'active'
          ORDER BY ut.created_at ASC
          LIMIT 1"
    );
    $tStmt->execute(['u' => (int) $user['id']]);
    $tenant = $tStmt->fetch(PDO::FETCH_ASSOC);
}
if (!$tenant) api_error('No tenant assigned', 403);
$tenantId = (int) $tenant['id'];

// Optional device registration.
$deviceId = $body['device_id'] ?? null;
$platform = (string) ($body['platform'] ?? 'web');
if ($deviceId) {
    $stmt = $pdo->prepare(
        "INSERT INTO tenant_mobile_devices
           (tenant_id, user_id, device_id, platform, app_version, os_version, locale, last_seen_at, created_at)
         VALUES (:t, :u, :d, :p, :av, :ov, :lc, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           user_id = VALUES(user_id),
           platform = VALUES(platform),
           app_version = VALUES(app_version),
           os_version = VALUES(os_version),
           locale = VALUES(locale),
           last_seen_at = NOW(),
           revoked_at = NULL,
           updated_at = NOW()"
    );
    $stmt->execute([
        't'  => $tenantId,
        'u'  => (int) $user['id'],
        'd'  => (string) $deviceId,
        'p'  => in_array($platform, ['ios','android','web'], true) ? $platform : 'web',
        'av' => $body['app_version'] ?? null,
        'ov' => $body['os_version']  ?? null,
        'lc' => $body['locale']      ?? null,
    ]);
}

$accessTtl = 8 * 60 * 60;
$accessToken = jwtSign([
    'user_id'   => (int) $user['id'],
    'tenant_id' => $tenantId,
    'name'      => $user['name'],
    'email'     => $user['email'],
    'role'      => $user['role'],
], $accessTtl);

[$refresh, $refreshExpires] = jwtIssueRefreshToken($tenantId, (int) $user['id'], $deviceId);

api_ok([
    'access_token'        => $accessToken,
    'refresh_token'       => $refresh,
    'expires_in'          => $accessTtl,
    'refresh_expires_at'  => $refreshExpires,
    'user'                => [
        'id'    => (int) $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ],
    'tenant' => [
        'id'   => $tenantId,
        'code' => $tenant['code'],
        'name' => $tenant['name'],
    ],
]);
