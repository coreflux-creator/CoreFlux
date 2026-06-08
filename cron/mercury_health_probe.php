<?php
/**
 * Mercury connection liveness probe cron.
 *
 * Parallel to `cron/qbo_token_refresh.php` — Mercury uses static API
 * tokens (not OAuth) so there is no "refresh" to do, but a token can
 * still go bad three ways:
 *
 *   1. Tenant admin rotated / deleted it in Mercury's dashboard.
 *   2. The token's IP allowlist now excludes the CoreFlux egress IP.
 *   3. The token's permissions were narrowed and "Send Payments" is
 *      no longer granted.
 *
 * Every probe failure here flips `mercury_connections.status` to
 * 'error' (last_probe_error populated) so the IntegrationsHealthPanel
 * shows a red pill immediately, and downstream mpAdvance() calls
 * short-circuit to Failed instead of hammering the dead token.
 *
 * Suggested schedule: every 30 minutes.
 *   *\/30 * * * * php /home/master/applications/<app>/public_html/cron/mercury_health_probe.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/mercury_service.php';
require_once __DIR__ . '/../core/mercury_adapter.php';

$pdo = getDB();
try {
    $stmt = $pdo->query(
        "SELECT tenant_id FROM mercury_connections
          WHERE status IN ('active', 'error')
       ORDER BY tenant_id"
    );
    $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
} catch (\Throwable $e) {
    fwrite(STDERR, "mercury_health_probe: migration 048 not applied — skipping. ({$e->getMessage()})\n");
    exit(0);
}
if (!$rows) {
    fwrite(STDOUT, "mercury_health_probe: no connections to probe.\n");
    exit(0);
}

$ok = 0; $fail = 0; $skipped = 0; $recovered = 0;

foreach ($rows as $row) {
    $tid = (int) $row['tenant_id'];
    $conn = mercuryGetConnection($tid);
    if (!$conn || $conn['api_token'] === '') {
        $skipped++;
        continue;
    }
    $wasError = ($conn['status'] === 'error');
    try {
        $accounts = mercuryListAccounts($conn['api_token']);
        // 1+ accounts returned means the token is alive and permitted
        // for at least the "Read Accounts" scope. We can't probe send
        // permission without actually originating a $0 payment, which
        // Mercury refuses, so this is the best non-destructive check.
        if (!is_array($accounts)) {
            throw new \RuntimeException('list_accounts returned non-array');
        }
        $stmt = $pdo->prepare(
            "UPDATE mercury_connections
                SET status = 'active',
                    last_probe_at = :now,
                    last_probe_error = NULL,
                    updated_at = :now2
              WHERE tenant_id = :t"
        );
        $stmt->execute(['now' => date('Y-m-d H:i:s'), 'now2' => date('Y-m-d H:i:s'), 't' => $tid]);
        $ok++;
        if ($wasError) {
            $recovered++;
            fwrite(STDOUT, "tenant {$tid}: recovered (was error)\n");
        }
    } catch (\Throwable $e) {
        $fail++;
        $msg = substr($e->getMessage(), 0, 480);
        $stmt = $pdo->prepare(
            "UPDATE mercury_connections
                SET status = 'error',
                    last_probe_at = :now,
                    last_probe_error = :err,
                    updated_at = :now2
              WHERE tenant_id = :t"
        );
        $stmt->execute(['now' => date('Y-m-d H:i:s'), 'err' => $msg, 'now2' => date('Y-m-d H:i:s'), 't' => $tid]);
        fwrite(STDERR, "tenant {$tid}: probe failed: {$msg}\n");
    }
}

fwrite(STDOUT, "mercury_health_probe done: ok={$ok} fail={$fail} recovered={$recovered} skipped={$skipped}\n");
exit(0);
