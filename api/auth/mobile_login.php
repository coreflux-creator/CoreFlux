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
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/memberships.php';

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body = api_json_body();
api_require_fields($body, ['email', 'password']);

$email = strtolower(trim((string) $body['email']));
$pwd   = (string) $body['password'];

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

// Find the user by email. SELECT * keeps this endpoint schema-tolerant across
// canonical and legacy user tables.
$stmt = $pdo->prepare(
    "SELECT *
       FROM users
      WHERE LOWER(email) = LOWER(:e) LIMIT 1"
);
$stmt->execute(['e' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (
    !$user
    || (isset($user['is_active']) && (int) $user['is_active'] !== 1)
    || (($user['status'] ?? null) === 'disabled')
) {
    api_error('Invalid credentials', 401);
}

if (!authVerifyPassword($user, $pwd)) {
    api_error('Invalid credentials', 401);
}

try { healMembershipsForUser((int) $user['id']); } catch (\Throwable $e) {
    error_log('[mobile_login] healMembershipsForUser failed: ' . $e->getMessage());
}

// Resolve tenant. Prefer the requested tenant_code, otherwise pick the user's
// primary active mapping. Read through membershipReadSourceSql() so pending
// RBAC backfill does not strand mobile users.
$tenantCode = isset($body['tenant_code']) ? trim((string) $body['tenant_code']) : '';
$tenantCols = authTableColumns($pdo, 'tenants');
$codeExpr = in_array('code', $tenantCols, true)
    ? 't.code'
    : (in_array('subdomain', $tenantCols, true) ? 't.subdomain AS code' : 'CAST(t.id AS CHAR) AS code');
$whereCode = $tenantCode !== ''
    ? (in_array('code', $tenantCols, true)
        ? ' AND t.code = :c'
        : (in_array('subdomain', $tenantCols, true) ? ' AND t.subdomain = :c' : ' AND CAST(t.id AS CHAR) = :c'))
    : '';

$tStmt = $pdo->prepare(
    "SELECT t.id, {$codeExpr}, t.name
       FROM " . membershipReadSourceSql() . " src
       JOIN tenants t ON t.id = src.tenant_id
      WHERE src.user_id = :u{$whereCode}
      ORDER BY src.is_primary DESC, t.name ASC
      LIMIT 1"
);
$bind = ['u' => (int) $user['id']];
if ($tenantCode !== '') $bind['c'] = $tenantCode;
$tStmt->execute($bind);
$tenant = $tStmt->fetch(PDO::FETCH_ASSOC);
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
    'name'      => authUserDisplayName($user),
    'email'     => $user['email'],
    'role'      => $user['role'] ?? 'employee',
], $accessTtl);

[$refresh, $refreshExpires] = jwtIssueRefreshToken($tenantId, (int) $user['id'], $deviceId);

api_ok([
    'access_token'        => $accessToken,
    'refresh_token'       => $refresh,
    'expires_in'          => $accessTtl,
    'refresh_expires_at'  => $refreshExpires,
    'user'                => [
        'id'    => (int) $user['id'],
        'name'  => authUserDisplayName($user),
        'email' => $user['email'],
        'role'  => $user['role'] ?? 'employee',
    ],
    'tenant' => [
        'id'   => $tenantId,
        'code' => $tenant['code'],
        'name' => $tenant['name'],
    ],
]);
