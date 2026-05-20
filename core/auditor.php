<?php
/**
 * core/auditor.php — External Auditor token helpers.
 *
 * Generates, validates, and redeems read-only access tokens that let an
 * external auditor view a specific tenant's reports/financials without
 * holding a real user account. Tokens are stored hashed (sha256 hex), so
 * the live token is only visible at issue time and never recoverable from
 * the DB.
 *
 * Session shape after redemption:
 *   $_SESSION['user']              = synthetic auditor user (role='auditor')
 *   $_SESSION['tenant_id']         = the tenant the token is scoped to
 *   $_SESSION['auditor_mode']      = true        // gates every WRITE
 *   $_SESSION['auditor_token_id']  = <int>       // for the audit log
 *   $_SESSION['auditor_modules']   = ['reports','accounting',...]  (scope)
 *   $_SESSION['auditor_expires_at']= ISO date    // surfaced in UI banner
 *
 * The mutation gate lives in api_bootstrap.php (`api_require_auth()` 403s
 * any non-GET request when auditor_mode is true).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Modules an auditor sees by default when scope_modules is NULL. */
const AUDITOR_DEFAULT_MODULES = ['reports', 'accounting', 'cfo', 'ap', 'billing', 'treasury'];

/**
 * Generate a fresh 32-byte URL-safe token. Returns [plain, sha256_hex].
 * Caller stores the hash; the plain string is shown to the issuer once.
 */
function auditorGenerateToken(): array {
    $raw = random_bytes(32);
    $plain = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $hash  = hash('sha256', $plain);
    return [$plain, $hash];
}

/**
 * Look up a non-revoked, non-expired token by plain value. Returns row or null.
 */
function auditorFindActiveToken(string $plain): ?array {
    $pdo = getDB();
    if (!$pdo) return null;
    $hash = hash('sha256', $plain);
    // tenant-leak-allow: token lookup by sha256 hash — the token IS the
    // tenant binding (each row has its own tenant_id which the caller
    // then pins to the session). A pre-lookup tenant filter is impossible.
    $st = $pdo->prepare(
        'SELECT * FROM auditor_tokens
          WHERE token_hash = :h
            AND revoked_at IS NULL
            AND expires_at > NOW()
          LIMIT 1'
    );
    $st->execute(['h' => $hash]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Append a row to auditor_access_log. Best-effort; never throws.
 */
function auditorLog(int $tokenId, int $tenantId, string $action, ?string $path = null): void {
    try {
        $pdo = getDB();
        if (!$pdo) return;
        $pdo->prepare(
            'INSERT INTO auditor_access_log
                (token_id, tenant_id, action, path, ip, user_agent, occurred_at)
             VALUES (:tk, :t, :a, :p, :ip, :ua, NOW())'
        )->execute([
            'tk' => $tokenId,
            't'  => $tenantId,
            'a'  => $action,
            'p'  => $path !== null ? mb_substr($path, 0, 255) : null,
            'ip' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            'ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (\Throwable $_) { /* swallow */ }
}

/**
 * Redeem a token: validates it, seeds an auditor session, marks last_used_at,
 * logs the redeem event. Returns true on success.
 */
function auditorRedeemAndStart(string $plain): bool {
    $row = auditorFindActiveToken($plain);
    if (!$row) return false;
    $tid = (int) $row['tenant_id'];
    $modules = AUDITOR_DEFAULT_MODULES;
    if (!empty($row['scope_modules'])) {
        $decoded = json_decode((string) $row['scope_modules'], true);
        if (is_array($decoded) && $decoded) $modules = array_values($decoded);
    }

    // Synthetic identity. id=0 + role='auditor' so user-keyed audit trails
    // can still bucket the event without colliding with real users.
    $_SESSION['user'] = [
        'id'              => 0,
        'first_name'      => 'External',
        'last_name'       => 'Auditor',
        'name'            => 'External Auditor',
        'email'           => (string) ($row['email'] ?? ''),
        'role'            => 'auditor',
        'global_role'     => 'auditor',
        'is_global_admin' => 0,
        'auth_via'        => 'auditor_token',
        'platform_mode'   => false,
        'tenants'         => [], // header dropdown will be suppressed
    ];
    $_SESSION['tenant_id']          = $tid;
    $_SESSION['active_tenant_id']   = $tid;
    $_SESSION['auditor_mode']       = true;
    $_SESSION['auditor_token_id']   = (int) $row['id'];
    $_SESSION['auditor_modules']    = $modules;
    $_SESSION['auditor_expires_at'] = $row['expires_at'];
    $_SESSION['platform_mode']      = false;

    // Stamp last_used_at and log.
    try {
        $pdo = getDB();
        // tenant-leak-allow: stamping the row we just authenticated against;
        // tenant scope was just resolved from the same row.
        $pdo->prepare('UPDATE auditor_tokens SET last_used_at = NOW() WHERE id = :id')
            ->execute(['id' => (int) $row['id']]);
    } catch (\Throwable $_) { /* swallow */ }
    auditorLog((int) $row['id'], $tid, 'redeem');

    return true;
}

/**
 * Is the current session an auditor session?
 */
function auditorModeActive(): bool {
    return !empty($_SESSION['auditor_mode']);
}
