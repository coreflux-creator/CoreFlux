<?php
/**
 * QBO inbound sync — cron driver (Slice 3).
 *
 * Cron entry (Cloudways):
 *   Nightly: 0 2 * * * php /home/master/applications/<app>/public_html/cron/qbo_sync_inbound.php
 *
 * For every tenant with an active qbo_connections row, runs Customer
 * and Vendor pulls when their sync_config direction is `pull` or
 * `two_way`. Idempotent via `external_entity_mappings`. Continues on
 * per-tenant failure; logs counts to stdout / errors to stderr.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/qbo/client.php';
require_once __DIR__ . '/../core/qbo/sync_in.php';

$LIMIT_PER_TENANT = 2000;

$pdo = getDB();
try {
    $stmt = $pdo->query("SELECT tenant_id FROM qbo_connections WHERE status = 'active' ORDER BY tenant_id");
    $tenants = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
} catch (\Throwable $e) {
    fwrite(STDERR, "QBO inbound cron: migration 052 not applied yet — skipping. ({$e->getMessage()})\n");
    exit(0);
}
if (!$tenants) {
    fwrite(STDOUT, "QBO inbound cron: no active connections, nothing to do.\n");
    exit(0);
}

$grand = ['customers' => 0, 'vendors' => 0, 'failed' => 0];
$tenantsOk = 0; $tenantsErr = 0;

foreach ($tenants as $tid) {
    $tid = (int) $tid;
    try {
        $cfg = qboSyncConfigRead($tid);

        if (in_array($cfg['customers'] ?? 'off', ['pull', 'two_way'], true)) {
            $res = qboSyncCustomers($tid, null, ['limit' => $LIMIT_PER_TENANT]);
            $grand['customers'] += ($res['created'] + $res['updated']);
            $grand['failed']    += $res['failed'];
            fwrite(STDOUT, sprintf(
                "tenant %d customers: created=%d updated=%d unchanged=%d failed=%d (%dms)\n",
                $tid, $res['created'], $res['updated'], $res['unchanged'], $res['failed'], $res['latency_ms']
            ));
        }
        if (in_array($cfg['vendors'] ?? 'off', ['pull', 'two_way'], true)) {
            $res = qboSyncVendors($tid, null, ['limit' => $LIMIT_PER_TENANT]);
            $grand['vendors'] += ($res['created'] + $res['updated']);
            $grand['failed']  += $res['failed'];
            fwrite(STDOUT, sprintf(
                "tenant %d vendors: created=%d updated=%d unchanged=%d failed=%d (%dms)\n",
                $tid, $res['created'], $res['updated'], $res['unchanged'], $res['failed'], $res['latency_ms']
            ));
        }
        $tenantsOk++;
    } catch (\Throwable $e) {
        $tenantsErr++;
        fwrite(STDERR, "tenant {$tid} failed: " . $e->getMessage() . "\n");
        qboAudit($tid, 'sync_inbound_cron_error', [
            'ok' => false, 'detail' => ['error' => substr($e->getMessage(), 0, 500)],
        ]);
    }
}

fwrite(STDOUT, sprintf(
    "QBO inbound cron done: tenants_ok=%d tenants_err=%d customers=%d vendors=%d failed=%d\n",
    $tenantsOk, $tenantsErr, $grand['customers'], $grand['vendors'], $grand['failed']
));
exit(0);
