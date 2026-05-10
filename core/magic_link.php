<?php
/**
 * Core\MagicLink — passwordless email login.
 *
 * Security posture:
 *   • Raw token is `random_bytes(32)` → 256 bits, base64url-encoded.
 *   • DB stores SHA-256 of the token only. A DB read leaks nothing usable.
 *   • Verification: SHA-256 the candidate, look up by hash. `hash_equals` is
 *     not needed at the DB layer because we're doing an indexed equality
 *     match on a hash — the timing side-channel is on the SHA itself which
 *     is constant-time.
 *   • Single-use: `consumed_at` is stamped on first successful consume.
 *     Re-using the link 422s (intentionally vague error to clients).
 *   • Per-(ip, email) rate limit: 5 issues / hour, then a 1-hour lockout.
 *   • We never log the raw token. The link IS the credential.
 *   • Always return the same generic response from /request, regardless of
 *     whether the email exists. Prevents user enumeration.
 *
 * Tenant binding:
 *   A link may be bound to a `tenant_id`. On consume we set the active
 *   tenant in the session. Useful when a workflow email comes from a
 *   specific tenant (e.g. weekly timesheet reminder for Acme Corp).
 *
 * Workflow links (zero-friction one-tap):
 *   `redirect_path` is honoured on consume. So a timesheet email can send
 *     /api/auth/m/<token>?to=/modules/time/timesheets/123
 *   and the worker lands authenticated, on the right page.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const COREFLUX_MAGIC_LINK_TTL_MINUTES   = 15;
const COREFLUX_MAGIC_LINK_RATE_MAX      = 5;     // per hour per (ip,email)
const COREFLUX_MAGIC_LINK_RATE_WINDOW_S = 3600;
const COREFLUX_MAGIC_LINK_LOCK_S        = 3600;  // 1 hour cool-down

/**
 * Issue a new magic link.
 *
 * @return array{
 *   raw_token: string,        // include in URL, hand to MailService, NEVER log
 *   expires_at: string,
 *   email: string,
 *   tenant_id: ?int,
 *   redirect_path: string,
 * }
 *
 * @throws RuntimeException on rate-limit lockout.
 */
function magicLinkIssue(string $email, ?int $tenantId = null, string $redirectPath = '/', ?string $ip = null, ?string $userAgent = null, ?int $ttlMinutes = null): array {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid email');
    }
    if (strlen($redirectPath) > 500 || $redirectPath === '' || $redirectPath[0] !== '/') {
        $redirectPath = '/';
    }
    // Reject any redirect that smells like an open redirect (//evil.com,
    // protocol-relative, scheme-prefixed). Path-only.
    if (preg_match('#^(?://|https?:)#i', $redirectPath)) {
        $redirectPath = '/';
    }

    $ttl = $ttlMinutes !== null && $ttlMinutes > 0
        ? min($ttlMinutes, 60 * 24 * 14)   // hard cap 14 days
        : COREFLUX_MAGIC_LINK_TTL_MINUTES;

    $pdo = getDB();
    $ip      = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $rateKey = hash('sha256', $ip . '|' . $email);

    _magicLinkRateCheckOrThrow($pdo, $rateKey, $email);

    // 256-bit token, URL-safe.
    $rawToken  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = (new DateTimeImmutable('+' . $ttl . ' minutes'))->format('Y-m-d H:i:s');

    // Resolve user_id if known (best-effort — link works even for net-new users).
    $userId = null;
    try {
        $st = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
        $st->execute(['e' => $email]);
        $row = $st->fetch();
        if ($row) $userId = (int) $row['id'];
    } catch (Throwable $_) { /* schema-tolerant */ }

    $ipBin = @inet_pton($ip) ?: null;
    $uaHash = $userAgent ? hash('sha256', $userAgent) : null;

    $ins = $pdo->prepare(
        'INSERT INTO auth_magic_links
           (token_hash, email, tenant_id, user_id, redirect_path, ip_issued, ua_hash, expires_at)
         VALUES (:h, :e, :t, :u, :r, :ip, :ua, :exp)'
    );
    $ins->execute([
        'h'   => $tokenHash,
        'e'   => $email,
        't'   => $tenantId,
        'u'   => $userId,
        'r'   => $redirectPath,
        'ip'  => $ipBin,
        'ua'  => $uaHash,
        'exp' => $expiresAt,
    ]);

    _magicLinkRateBump($pdo, $rateKey, $email);

    return [
        'raw_token'     => $rawToken,
        'expires_at'    => $expiresAt,
        'email'         => $email,
        'tenant_id'     => $tenantId,
        'redirect_path' => $redirectPath,
    ];
}

/**
 * Verify and consume a magic link. Single-use.
 *
 * @return array{
 *   ok: bool,
 *   email?: string,
 *   user_id?: ?int,
 *   tenant_id?: ?int,
 *   redirect_path?: string,
 *   reason?: string,         // 'invalid' | 'expired' | 'consumed'
 * }
 */
function magicLinkConsume(string $rawToken, ?string $ip = null): array {
    if ($rawToken === '' || strlen($rawToken) > 200) {
        return ['ok' => false, 'reason' => 'invalid'];
    }

    $pdo = getDB();
    $hash = hash('sha256', $rawToken);

    $sel = $pdo->prepare(
        'SELECT id, email, tenant_id, user_id, redirect_path, expires_at, consumed_at
           FROM auth_magic_links
          WHERE token_hash = :h
          LIMIT 1'
    );
    $sel->execute(['h' => $hash]);
    $row = $sel->fetch();
    if (!$row) {
        return ['ok' => false, 'reason' => 'invalid'];
    }
    if (!empty($row['consumed_at'])) {
        return ['ok' => false, 'reason' => 'consumed'];
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return ['ok' => false, 'reason' => 'expired'];
    }

    // Atomic consume: only succeed if no one else got there first.
    $upd = $pdo->prepare(
        'UPDATE auth_magic_links
            SET consumed_at = NOW(), consumed_ip = :ip
          WHERE id = :id AND consumed_at IS NULL'
    );
    $upd->execute([
        'id' => (int) $row['id'],
        'ip' => $ip ? (@inet_pton($ip) ?: null) : null,
    ]);
    if ($upd->rowCount() !== 1) {
        return ['ok' => false, 'reason' => 'consumed'];
    }

    return [
        'ok'            => true,
        'email'         => (string) $row['email'],
        'user_id'       => $row['user_id'] !== null ? (int) $row['user_id'] : null,
        'tenant_id'     => $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null,
        'redirect_path' => (string) ($row['redirect_path'] ?: '/'),
    ];
}

/**
 * Build the absolute URL a magic link points to. Frontend route: /auth/m/<token>
 */
function magicLinkUrl(string $rawToken, ?string $base = null): string {
    if ($base === null) {
        $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base   = $scheme . '://' . $host;
    }
    return rtrim($base, '/') . '/auth/m/' . urlencode($rawToken);
}

/* ----- internal helpers ------------------------------------------------ */

function _magicLinkRateCheckOrThrow(PDO $pdo, string $rateKey, string $email): void {
    $st = $pdo->prepare(
        'SELECT attempts, first_attempt, locked_until
           FROM auth_magic_link_attempts
          WHERE ip_email_hash = :k LIMIT 1'
    );
    $st->execute(['k' => $rateKey]);
    $row = $st->fetch();
    if (!$row) return;

    $now = time();
    if (!empty($row['locked_until']) && strtotime((string) $row['locked_until']) > $now) {
        throw new RuntimeException('Too many requests. Try again later.');
    }
    // If window expired, the next bump will reset the counter.
    if (strtotime((string) $row['first_attempt']) + COREFLUX_MAGIC_LINK_RATE_WINDOW_S < $now) {
        return;
    }
    if ((int) $row['attempts'] >= COREFLUX_MAGIC_LINK_RATE_MAX) {
        // Set the cool-down on this row.
        $upd = $pdo->prepare(
            'UPDATE auth_magic_link_attempts
                SET locked_until = DATE_ADD(NOW(), INTERVAL :s SECOND)
              WHERE ip_email_hash = :k'
        );
        $upd->execute(['s' => COREFLUX_MAGIC_LINK_LOCK_S, 'k' => $rateKey]);
        throw new RuntimeException('Too many requests. Try again later.');
    }
}

function _magicLinkRateBump(PDO $pdo, string $rateKey, string $email): void {
    $st = $pdo->prepare(
        'INSERT INTO auth_magic_link_attempts
           (ip_email_hash, email, attempts, first_attempt, last_attempt)
         VALUES (:k, :e, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           attempts = IF(first_attempt + INTERVAL :w SECOND < NOW(), 1, attempts + 1),
           first_attempt = IF(first_attempt + INTERVAL :w2 SECOND < NOW(), NOW(), first_attempt),
           last_attempt = NOW(),
           locked_until = NULL'
    );
    $st->execute([
        'k'  => $rateKey,
        'e'  => $email,
        'w'  => COREFLUX_MAGIC_LINK_RATE_WINDOW_S,
        'w2' => COREFLUX_MAGIC_LINK_RATE_WINDOW_S,
    ]);
}
