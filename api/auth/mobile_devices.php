<?php
/**
 * Mobile device registration / push-token update / revocation.
 *
 *   POST   /api/auth/mobile_devices         { device_id, platform, apns_token?, fcm_token?, app_version?, os_version?, locale? }
 *   DELETE /api/auth/mobile_devices?device_id=...
 *   GET    /api/auth/mobile_devices         → list this user's active devices
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$method   = api_method();

if ($method === 'GET') {
    $rows = scopedQuery(
        "SELECT id, device_id, platform, app_version, os_version, locale, last_seen_at, revoked_at, created_at
           FROM tenant_mobile_devices
          WHERE tenant_id = :tenant_id AND user_id = :u AND revoked_at IS NULL
          ORDER BY last_seen_at DESC",
        ['u' => (int) $user['id']]
    );
    api_ok(['devices' => $rows]);
}

if ($method === 'POST') {
    $body = api_json_body();
    api_require_fields($body, ['device_id', 'platform']);
    $platform = (string) $body['platform'];
    if (!in_array($platform, ['ios','android','web'], true)) api_error('platform must be ios|android|web', 422);
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "INSERT INTO tenant_mobile_devices
           (tenant_id, user_id, device_id, platform, apns_token, fcm_token, app_version, os_version, locale, last_seen_at, created_at)
         VALUES (:t, :u, :d, :p, :a, :f, :av, :ov, :lc, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           user_id = VALUES(user_id),
           platform = VALUES(platform),
           apns_token = VALUES(apns_token),
           fcm_token = VALUES(fcm_token),
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
        'd'  => (string) $body['device_id'],
        'p'  => $platform,
        'a'  => $body['apns_token'] ?? null,
        'f'  => $body['fcm_token']  ?? null,
        'av' => $body['app_version']?? null,
        'ov' => $body['os_version'] ?? null,
        'lc' => $body['locale']     ?? null,
    ]);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE') {
    $deviceId = api_query('device_id');
    if (!$deviceId) api_error('device_id required', 422);
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "UPDATE tenant_mobile_devices
            SET revoked_at = NOW()
          WHERE tenant_id = :t AND user_id = :u AND device_id = :d AND revoked_at IS NULL"
    );
    $stmt->execute(['t' => $tenantId, 'u' => (int) $user['id'], 'd' => (string) $deviceId]);
    // Also revoke any refresh tokens tied to this device.
    $rev = $pdo->prepare(
        "UPDATE auth_refresh_tokens
            SET revoked_at = NOW()
          WHERE tenant_id = :t AND user_id = :u AND device_id = :d AND revoked_at IS NULL"
    );
    $rev->execute(['t' => $tenantId, 'u' => (int) $user['id'], 'd' => (string) $deviceId]);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
