<?php
/**
 * Mobile refresh — exchange a refresh_token for a fresh access_token (and rotate refresh).
 *
 *   POST /api/auth/mobile_refresh
 *     { refresh_token, device_id? }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/jwt.php';
require_once __DIR__ . '/../../core/db.php';

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body = api_json_body();
api_require_fields($body, ['refresh_token']);

$row = jwtConsumeRefreshToken((string) $body['refresh_token']);
if (!$row) api_error('Invalid or expired refresh token', 401);

$pdo = getDB();
$u = $pdo->prepare("SELECT id, name, email, role, is_active FROM users WHERE id = :id");
$u->execute(['id' => (int) $row['user_id']]);
$user = $u->fetch(PDO::FETCH_ASSOC);
if (!$user || !$user['is_active']) {
    jwtRevokeRefreshToken((string) $body['refresh_token']);
    api_error('User no longer active', 401);
}

$t = $pdo->prepare("SELECT id, code, name FROM tenants WHERE id = :id");
$t->execute(['id' => (int) $row['tenant_id']]);
$tenant = $t->fetch(PDO::FETCH_ASSOC);
if (!$tenant) api_error('Tenant not found', 401);

// Rotate refresh token.
jwtRevokeRefreshToken((string) $body['refresh_token']);
[$newRefresh, $newExp] = jwtIssueRefreshToken((int) $tenant['id'], (int) $user['id'], $row['device_id'] ?? null);

$accessTtl = 8 * 60 * 60;
$accessToken = jwtSign([
    'user_id'   => (int) $user['id'],
    'tenant_id' => (int) $tenant['id'],
    'name'      => $user['name'],
    'email'     => $user['email'],
    'role'      => $user['role'],
], $accessTtl);

api_ok([
    'access_token'       => $accessToken,
    'refresh_token'      => $newRefresh,
    'expires_in'         => $accessTtl,
    'refresh_expires_at' => $newExp,
]);
