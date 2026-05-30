<?php
/**
 * /api/admin/mail_health.php
 *
 * Tenant-scoped mail health rollup. Powers the "Mail health" tile on
 * IntegrationsHub so admins can spot a Resend outage / domain
 * misconfiguration / surge of bounces without scrolling the mail
 * outbox by hand.
 *
 * Returns:
 *   {
 *     resend_configured: bool,
 *     default_driver:    'resend'|'log',
 *     from_email:        string,
 *     window: { hours: 24, since: 'YYYY-MM-DD HH:MM:SS' },
 *     rollup_24h: {
 *       total: int, sent: int, failed: int, queued: int,
 *       bounced: int, complaint: int,
 *       failure_pct: float,
 *       drivers: { resend: int, log: int, phpmailer_smtp: int, … }
 *     },
 *     daily_7d: [ { day: 'YYYY-MM-DD', sent: int, failed: int }, … ],
 *     top_purposes_24h: [ { purpose: '...', sent: int, failed: int }, … ],
 *     recent_failures: [ { id, purpose, driver, to, error, created_at }, … ],
 *     status: 'healthy' | 'degraded' | 'critical' | 'silent',
 *     hint:   string,
 *   }
 *
 * RBAC: tenant_admin.integrations (same gate as mail_status.php so the
 * IntegrationsHub doesn't need a separate permission).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) ($ctx['tenant_id'] ?? 0);
rbac_legacy_require($user, 'tenant_admin.integrations');

if (api_method() !== 'GET') api_error('Method not allowed', 405);
if ($tid <= 0)              api_error('No active tenant', 400);

// ---------- Resend configuration probe (no key leakage) -----------
$resendKey = (string) getenv('RESEND_API_KEY');
if ($resendKey === '' && defined('RESEND_API_KEY')) {
    $resendKey = (string) constant('RESEND_API_KEY');
}
$resendConfigured = $resendKey !== '';
$fromEmail = (string) getenv('RESEND_FROM_EMAIL');
if ($fromEmail === '' && defined('RESEND_FROM_EMAIL')) {
    $fromEmail = (string) constant('RESEND_FROM_EMAIL');
}

// ---------- DB pull (degrades gracefully if table absent) ---------
$pdo = getDB();
$rollup = [
    'total' => 0, 'sent' => 0, 'failed' => 0, 'queued' => 0,
    'bounced' => 0, 'complaint' => 0,
    'failure_pct' => 0.0,
    'drivers' => [],
];
$daily7d         = [];
$topPurposes24h  = [];
$recentFailures  = [];
$tableMissing    = false;

if ($pdo) {
    try {
        // 24h rollup ----------------------------------------------------
        $st = $pdo->prepare(
            "SELECT status, COUNT(*) AS n
               FROM mail_outbox
              WHERE tenant_id = :t
                AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
           GROUP BY status"
        );
        $st->execute(['t' => $tid]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $rollup[(string) $r['status']] = (int) $r['n'];
            $rollup['total']              += (int) $r['n'];
        }
        if ($rollup['total'] > 0) {
            $rollup['failure_pct'] = round(
                100.0 * ($rollup['failed'] + $rollup['bounced'] + $rollup['complaint'])
                      / $rollup['total'],
                1
            );
        }

        // Driver split (24h) -------------------------------------------
        $st = $pdo->prepare(
            "SELECT driver, COUNT(*) AS n
               FROM mail_outbox
              WHERE tenant_id = :t
                AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
           GROUP BY driver"
        );
        $st->execute(['t' => $tid]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $rollup['drivers'][(string) $r['driver']] = (int) $r['n'];
        }

        // 7-day daily series ------------------------------------------
        $st = $pdo->prepare(
            "SELECT DATE(created_at) AS day,
                    SUM(CASE WHEN status = 'sent'                                        THEN 1 ELSE 0 END) AS sent,
                    SUM(CASE WHEN status IN ('failed','bounced','complaint')             THEN 1 ELSE 0 END) AS failed
               FROM mail_outbox
              WHERE tenant_id = :t
                AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
           GROUP BY DATE(created_at)
           ORDER BY day ASC"
        );
        $st->execute(['t' => $tid]);
        $observed = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $observed[(string) $r['day']] = [
                'sent'   => (int) $r['sent'],
                'failed' => (int) $r['failed'],
            ];
        }
        for ($i = 6; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', strtotime("-{$i} days"));
            $daily7d[] = [
                'day'    => $d,
                'sent'   => $observed[$d]['sent']   ?? 0,
                'failed' => $observed[$d]['failed'] ?? 0,
            ];
        }

        // Top purposes (24h) ------------------------------------------
        $st = $pdo->prepare(
            "SELECT purpose,
                    SUM(CASE WHEN status = 'sent'                                        THEN 1 ELSE 0 END) AS sent,
                    SUM(CASE WHEN status IN ('failed','bounced','complaint')             THEN 1 ELSE 0 END) AS failed
               FROM mail_outbox
              WHERE tenant_id = :t
                AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
           GROUP BY purpose
           ORDER BY (sent + failed) DESC
              LIMIT 5"
        );
        $st->execute(['t' => $tid]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $topPurposes24h[] = [
                'purpose' => (string) $r['purpose'],
                'sent'    => (int)    $r['sent'],
                'failed'  => (int)    $r['failed'],
            ];
        }

        // Last 5 failures (any age — useful when 24h is silent) -------
        $st = $pdo->prepare(
            "SELECT id, purpose, driver, status, to_addresses_json, error, created_at
               FROM mail_outbox
              WHERE tenant_id = :t
                AND status IN ('failed','bounced','complaint')
           ORDER BY id DESC
              LIMIT 5"
        );
        $st->execute(['t' => $tid]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $to = json_decode((string) $r['to_addresses_json'], true);
            $recentFailures[] = [
                'id'         => (int) $r['id'],
                'purpose'    => (string) $r['purpose'],
                'driver'     => (string) $r['driver'],
                'status'     => (string) $r['status'],
                'to'         => is_array($to) ? array_slice($to, 0, 3) : [],
                'error'      => substr((string) ($r['error'] ?? ''), 0, 220),
                'created_at' => (string) $r['created_at'],
            ];
        }
    } catch (\Throwable $e) {
        // Most likely: mail_outbox table not deployed in this env.
        // Keep the endpoint useful (returns config + status) so the
        // tile can still flag "table not present" instead of 500ing.
        $tableMissing = true;
    }
}

// ---------- Derived status banner ---------------------------------
$status = 'silent';
$hint   = '';
if (!$resendConfigured) {
    $status = 'critical';
    $hint   = 'RESEND_API_KEY is not set — outbound mail will fall through to the log driver. Add the key to /app/core/config.local.php (or set the env var) to deliver via Resend.';
} elseif ($tableMissing) {
    $status = 'silent';
    $hint   = 'mail_outbox table is missing in this environment — run migrations/003_mail_service.sql to start auditing sends.';
} elseif ($rollup['total'] === 0) {
    $status = 'silent';
    $hint   = 'No mail activity in the last 24 hours. Send a test from Mail Settings to confirm wiring.';
} elseif ($rollup['failure_pct'] >= 25) {
    $status = 'critical';
    $hint   = sprintf('%.1f%% failure rate in last 24h (%d failed / %d total). Inspect recent failures below.',
                       $rollup['failure_pct'], $rollup['failed'] + $rollup['bounced'] + $rollup['complaint'], $rollup['total']);
} elseif ($rollup['failure_pct'] >= 5) {
    $status = 'degraded';
    $hint   = sprintf('%.1f%% failure rate in last 24h — review recent failures to identify the bad recipient(s).',
                       $rollup['failure_pct']);
} else {
    $status = 'healthy';
    $hint   = sprintf('%d email(s) delivered in last 24h; %.1f%% failure rate.',
                       $rollup['sent'], $rollup['failure_pct']);
}

api_ok([
    'resend_configured' => $resendConfigured,
    'default_driver'    => $resendConfigured ? 'resend' : 'log',
    'from_email'        => $fromEmail !== '' ? $fromEmail : null,
    'window'            => [
        'hours' => 24,
        'since' => gmdate('Y-m-d H:i:s', time() - 24 * 3600),
    ],
    'rollup_24h'       => $rollup,
    'daily_7d'         => $daily7d,
    'top_purposes_24h' => $topPurposes24h,
    'recent_failures'  => $recentFailures,
    'status'           => $status,
    'hint'             => $hint,
    'table_missing'    => $tableMissing,
]);
