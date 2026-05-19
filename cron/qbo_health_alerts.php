<?php
/**
 * QBO Slice 4a follow-on — Sync health alert cron.
 *
 * Suggested schedule: every 15 minutes.
 *   H/15 * * * * php /home/master/applications/<app>/public_html/cron/qbo_health_alerts.php
 *
 * For each active connection, calls qboHealthMaybeAlert(). The function
 * is idempotent on stable status — it only fires email + writes a row
 * when the status changes since the last alert.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/qbo/client.php';
require_once __DIR__ . '/../core/qbo/health_alerts.php';

$pdo = getDB();
try {
    $stmt = $pdo->query("SELECT tenant_id FROM qbo_connections ORDER BY tenant_id");
    $tenants = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
} catch (\Throwable $e) {
    fwrite(STDERR, "QBO health alerts cron: migration 052/053 not applied — skipping. ({$e->getMessage()})\n");
    exit(0);
}
if (!$tenants) { fwrite(STDOUT, "QBO health alerts cron: no connections.\n"); exit(0); }

$fired = 0; $ok = 0; $err = 0;
foreach ($tenants as $tid) {
    $tid = (int) $tid;
    try {
        $r = qboHealthMaybeAlert($tid);
        if ($r['fired']) { $fired++; if ($r['sent']) $ok++; else $err++; }
        fwrite(STDOUT, sprintf("tenant %d: %s → %s (fired=%s sent=%s)\n",
            $tid, $r['status_before'] ?? 'n/a', $r['status_after'],
            $r['fired'] ? 'yes' : 'no',
            $r['sent']  ? 'yes' : ($r['fired'] ? ('no:' . ($r['error'] ?? '')) : 'n/a')));
    } catch (\Throwable $e) {
        $err++;
        fwrite(STDERR, "tenant {$tid} alert failed: " . $e->getMessage() . "\n");
    }
}
fwrite(STDOUT, "QBO health alerts cron done: fired={$fired} sent_ok={$ok} sent_err={$err}\n");
exit(0);
