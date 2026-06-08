<?php
/**
 * QBO OAuth token resilience cron — proactive access-token refresh.
 *
 * Charter "QBO OAuth token expiry resilience" follow-up.
 *
 * QBO access tokens expire after 60 minutes. The reactive refresh
 * path inside `qboCall()` works fine for active tenants, but two
 * failure modes leak through:
 *
 *   (1) A tenant whose first push of the day lands at 09:00 but whose
 *       token expired at 03:00 hits a 401 cliff in front of the user
 *       (single retry recovers it, but the latency spike is visible).
 *
 *   (2) QBO's refresh tokens (`x_refresh_token_expires_in`) themselves
 *       expire after ~101 days of inactivity. If a tenant never pushes
 *       for >100 days, the refresh token dies silently and the next
 *       attempt requires a full reconnect.
 *
 * This cron solves both by:
 *
 *   - Refreshing any active token expiring within REFRESH_WITHIN_SEC.
 *     We rotate the QBO refresh-token clock at the same time (Intuit
 *     issues a new refresh_token on every refresh request), so the
 *     ~101d clock resets to ~101d-from-now after each pass.
 *
 *   - Warning operators (via the health alert pipe) when a refresh
 *     token is within REFRESH_TOKEN_WARN_SEC of expiry. That gives
 *     them a multi-day window to reconnect before the connection
 *     drops to 'needs_reconnect'.
 *
 * Suggested schedule: every 15 minutes.
 *   H/15 * * * * php /home/master/applications/<app>/public_html/cron/qbo_token_refresh.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/qbo/client.php';

// Refresh access tokens expiring within the next 30 minutes. With a
// 60-min token life, this means the cron picks each tenant up at
// least once per token lifetime even at the every-15-min cadence.
const REFRESH_WITHIN_SEC = 30 * 60;

// Warn when refresh-token expiry is within 7 days.
const REFRESH_TOKEN_WARN_SEC = 7 * 24 * 60 * 60;

$pdo = getDB();
try {
    $stmt = $pdo->query(
        "SELECT tenant_id, access_token_exp, refresh_token_exp, status
           FROM qbo_connections
          WHERE status = 'active'
       ORDER BY tenant_id"
    );
    $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
} catch (\Throwable $e) {
    fwrite(STDERR, "qbo_token_refresh: migration 052/053 not applied — skipping. ({$e->getMessage()})\n");
    exit(0);
}
if (!$rows) {
    fwrite(STDOUT, "qbo_token_refresh: no active connections.\n");
    exit(0);
}

$now      = time();
$refreshed = 0;
$skipped   = 0;
$failed    = 0;
$warnings  = 0;

foreach ($rows as $row) {
    $tid       = (int) $row['tenant_id'];
    $accessExp = $row['access_token_exp'] ? strtotime((string) $row['access_token_exp']) : 0;
    $refresExp = $row['refresh_token_exp'] ? strtotime((string) $row['refresh_token_exp']) : 0;

    // Refresh-token health warning. We don't block the refresh — Intuit
    // will rotate it for free during the access-token refresh below — but
    // we surface it for any tenant whose token expires soon (e.g. dormant
    // tenants that haven't pushed in 95+ days).
    if ($refresExp > 0 && ($refresExp - $now) < REFRESH_TOKEN_WARN_SEC) {
        $warnings++;
        $daysLeft = max(0, (int) floor(($refresExp - $now) / 86400));
        qboAudit($tid, 'token_refresh_warn', [
            'detail' => [
                'refresh_token_days_left' => $daysLeft,
                'refresh_token_exp'       => $row['refresh_token_exp'],
            ],
        ]);
        fwrite(STDERR, "tenant {$tid}: refresh token expires in {$daysLeft}d — flag for reconnect\n");
    }

    // Skip if the access token is already fresh enough.
    if ($accessExp > 0 && ($accessExp - $now) > REFRESH_WITHIN_SEC) {
        $skipped++;
        continue;
    }

    try {
        qboRefreshAccessToken($tid);
        $refreshed++;
        fwrite(STDOUT, "tenant {$tid}: access token refreshed (was expiring in "
            . max(0, $accessExp - $now) . "s)\n");
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDERR, "tenant {$tid}: proactive refresh failed: " . $e->getMessage() . "\n");
    }
}

fwrite(STDOUT, "qbo_token_refresh done: refreshed={$refreshed} skipped={$skipped} failed={$failed} warnings={$warnings}\n");
exit(0);
