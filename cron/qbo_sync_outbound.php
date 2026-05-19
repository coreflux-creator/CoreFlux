<?php
/**
 * QBO outbound sync — cron driver.
 *
 * Cron entry (Cloudways):
 *   Every 15 minutes:  H/15 * * * * php /home/master/applications/<app>/public_html/cron/qbo_sync_outbound.php
 *
 * Iterates every tenant with an active qbo_connections row and a sync
 * direction of `push` or `two_way` for journal entries, runs the JE push,
 * captures aggregate counts to stdout, and continues on per-tenant
 * failure. Idempotent: the driver itself short-circuits via
 * `external_entity_mappings` (already-shipped JEs are excluded by the
 * SELECT).
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/qbo/client.php';
require_once __DIR__ . '/../core/qbo/sync_je.php';

$LIMIT_PER_TENANT = 100;

$pdo = getDB();
try {
    $stmt = $pdo->query("SELECT tenant_id FROM qbo_connections WHERE status = 'active' ORDER BY tenant_id");
    $tenants = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
} catch (\Throwable $e) {
    fwrite(STDERR, "QBO cron: migration 052 not applied yet — skipping. ({$e->getMessage()})\n");
    exit(0);
}
if (!$tenants) {
    fwrite(STDOUT, "QBO cron: no active connections, nothing to do.\n");
    exit(0);
}

$totalPushed = 0; $totalSkipped = 0; $totalFailed = 0;
$tenantsOk   = 0; $tenantsErr  = 0;

foreach ($tenants as $tid) {
    $tid = (int) $tid;
    try {
        $cfg = qboSyncConfigRead($tid);
        $dir = $cfg['journal_entries'] ?? 'off';
        if (!in_array($dir, ['push', 'two_way'], true)) {
            fwrite(STDOUT, "tenant {$tid}: journal_entries direction='{$dir}', skipping.\n");
            continue;
        }
        $res = qboSyncJournalEntries($tid, null, ['limit' => $LIMIT_PER_TENANT]);
        $totalPushed  += $res['pushed'];
        $totalSkipped += $res['skipped_unmapped'];
        $totalFailed  += $res['failed'];
        $tenantsOk++;
        fwrite(STDOUT, sprintf(
            "tenant %d: pushed=%d skipped=%d failed=%d considered=%d (%dms)\n",
            $tid, $res['pushed'], $res['skipped_unmapped'], $res['failed'], $res['considered'], $res['latency_ms']
        ));
    } catch (\Throwable $e) {
        $tenantsErr++;
        fwrite(STDERR, "tenant {$tid} failed: " . $e->getMessage() . "\n");
        qboAudit($tid, 'sync_je_cron_error', [
            'ok' => false, 'detail' => ['error' => substr($e->getMessage(), 0, 500)],
        ]);
    }
}

fwrite(STDOUT, sprintf(
    "QBO cron done: tenants_ok=%d tenants_err=%d pushed=%d skipped=%d failed=%d\n",
    $tenantsOk, $tenantsErr, $totalPushed, $totalSkipped, $totalFailed
));
exit(0);
