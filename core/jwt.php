<?php
/**
 * Lightweight HS256 JWT helper. No external library so the PHP backend
 * stays dependency-free per the platform contract.
 *
 * Used by:
 *   • /api/auth/mobile_login.php     — issue access + refresh tokens
 *   • /api/auth/mobile_refresh.php   — rotate refresh, mint new access
 *   • core/api_bootstrap.php         — accept Authorization: Bearer alongside session cookie
 *
 * Secret: env JWT_SECRET (or fallback to APP_KEY for first-run convenience).
 * Access TTL: 8h. Refresh TTL: 30d (server-side revocable in auth_refresh_tokens).
 */
declare(strict_types=1);

function jwtSecret(): string {
    $s = getenv('JWT_SECRET');
    if (!$s) $s = getenv('APP_KEY');
    if (!$s) $s = 'coreflux-dev-jwt-secret-CHANGE-ME';
    return (string) $s;
}

function jwtBase64UrlEncode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function jwtBase64UrlDecode(string $s): string {
    $r = base64_decode(strtr($s, '-_', '+/'));
    return $r === false ? '' : $r;
}

/**
 * Sign a payload with HS256. Adds iat/exp automatically.
 */
function jwtSign(array $payload, int $ttlSeconds = 28800): string {
    $now = time();
    $payload['iat'] = $now;
    $payload['exp'] = $now + max(60, $ttlSeconds);
    $header  = ['typ' => 'JWT', 'alg' => 'HS256'];
    $segs    = [
        jwtBase64UrlEncode(json_encode($header,  JSON_UNESCAPED_SLASHES)),
        jwtBase64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
    ];
    $sigInput = implode('.', $segs);
    $sig      = hash_hmac('sha256', $sigInput, jwtSecret(), true);
    $segs[]   = jwtBase64UrlEncode($sig);
    return implode('.', $segs);
}

/**
 * Verify + decode. Returns payload array on success, null on any failure.
 */
function jwtVerify(string $jwt): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $expected = jwtBase64UrlEncode(hash_hmac('sha256', "{$h}.{$p}", jwtSecret(), true));
    if (!hash_equals($expected, $s)) return null;
    $payload = json_decode(jwtBase64UrlDecode($p), true);
    if (!is_array($payload)) return null;
    if (!isset($payload['exp']) || time() >= (int) $payload['exp']) return null;
    return $payload;
}

/**
 * Parse Bearer token from a request's Authorization header.
 * Returns the decoded payload or null. Used by api_require_auth() to
 * accept JWTs alongside session cookies.
 */
function jwtFromRequest(): ?array {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('getallheaders')) {
        $hs = getallheaders();
        $h  = $hs['Authorization'] ?? $hs['authorization'] ?? '';
    }
    if (!$h || stripos($h, 'Bearer ') !== 0) return null;
    return jwtVerify(trim(substr($h, 7)));
}

/**
 * Mint a refresh token (random 32 bytes, URL-safe), store its sha256 hash.
 * Returns [plaintext_token, expires_at_iso].
 */
function jwtIssueRefreshToken(int $tenantId, int $userId, ?string $deviceId = null, int $ttlDays = 30): array {
    $raw  = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    $exp  = (new DateTimeImmutable("+{$ttlDays} days"))->format('Y-m-d H:i:s');
    $pdo  = getDB();
    if ($pdo) {
        $stmt = $pdo->prepare(
            "INSERT INTO auth_refresh_tokens
              (tenant_id, user_id, device_id, token_hash, expires_at, user_agent, ip, issued_at)
             VALUES
              (:t, :u, :d, :h, :e, :ua, :ip, NOW())"
        );
        $stmt->execute([
            't'  => $tenantId,
            'u'  => $userId,
            'd'  => $deviceId,
            'h'  => $hash,
            'e'  => $exp,
            'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'ip' => substr((string) ($_SERVER['REMOTE_ADDR']     ?? ''), 0,  64),
        ]);
    }
    return [$raw, $exp];
}

/**
 * Validate a refresh token, mark last_used. Returns row or null.
 */
function jwtConsumeRefreshToken(string $raw): ?array {
    $pdo = getDB();
    if (!$pdo) return null;
    $hash = hash('sha256', $raw);
    $stmt = $pdo->prepare(
        "SELECT * FROM auth_refresh_tokens
          WHERE token_hash = :h AND revoked_at IS NULL AND expires_at > NOW()
          LIMIT 1"
    );
    $stmt->execute(['h' => $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($row) {
        $upd = $pdo->prepare("UPDATE auth_refresh_tokens SET last_used_at = NOW() WHERE id = :id");
        $upd->execute(['id' => (int) $row['id']]);
    }
    return $row;
}

/**
 * Revoke a refresh token (logout, password change, device removed).
 */
function jwtRevokeRefreshToken(string $raw): bool {
    $pdo = getDB();
    if (!$pdo) return false;
    $hash = hash('sha256', $raw);
    $stmt = $pdo->prepare(
        "UPDATE auth_refresh_tokens SET revoked_at = NOW() WHERE token_hash = :h AND revoked_at IS NULL"
    );
    return (bool) $stmt->execute(['h' => $hash]);
}
