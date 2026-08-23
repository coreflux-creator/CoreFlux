<?php
/**
 * QBO inbound master-data worker.
 *
 * Scheduled incremental run (every 15 minutes):
 *   5,20,35,50 * * * * php /app/cron/qbo_sync_inbound.php
 *
 * Nightly full safety reconciliation:
 *   0 2 * * * php /app/cron/qbo_sync_inbound.php --full
 *
 * Pulls only workflows whose saved direction permits the operation.
 * Incremental runs overlap the previous successful checkpoint by five
 * minutes so an Intuit timestamp boundary cannot drop a change.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/qbo/client.php';
require_once __DIR__ . '/../core/qbo/sync_in.php';
require_once __DIR__ . '/../core/qbo/sync_accounts.php';
require_once __DIR__ . '/../core/qbo/sync_items.php';
require_once __DIR__ . '/../core/qbo/sync_schedule.php';
require_once __DIR__ . '/../core/qbo/sync_lock.php';

$full = in_array('--full', $argv ?? [], true);
$limitPerTenant = 2000;
$pdo = getDB();

try {
    qboSyncScheduleEnsure();
    $stmt = $pdo->query("SELECT tenant_id FROM qbo_connections WHERE status = 'active' ORDER BY tenant_id");
    $tenants = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
} catch (\Throwable $e) {
    fwrite(STDERR, "QBO inbound worker bootstrap failed — migration 052 not applied yet or checkpoint unavailable: {$e->getMessage()}\n");
    exit(1);
}
if (!$tenants) {
    fwrite(STDOUT, "QBO inbound worker: no active connections.\n");
    exit(0);
}

$summary = ['tenants_ok' => 0, 'tenants_err' => 0, 'workflows' => 0, 'pulled' => 0, 'failed' => 0];

foreach ($tenants as $tidRaw) {
    $tid = (int) $tidRaw;
    $tenantFailed = false;
    try {
        $cfg = qboSyncConfigRead($tid);
    } catch (\Throwable $e) {
        $summary['tenants_err']++;
        fwrite(STDERR, "tenant {$tid}: config failed: {$e->getMessage()}\n");
        continue;
    }

    $jobs = [];
    if (in_array($cfg['chart_of_accounts'] ?? 'off', ['pull', 'two_way'], true)) {
        $jobs['chart_of_accounts'] = static fn(array $opts) => qboSyncAccounts($tid, null, $opts);
    }
    if (in_array($cfg['invoices'] ?? 'off', ['push', 'two_way'], true)) {
        $jobs['items'] = static fn(array $opts) => qboSyncItems($tid, null, $opts);
    }
    if (in_array($cfg['customers'] ?? 'off', ['pull', 'two_way'], true)) {
        $jobs['customers'] = static fn(array $opts) => qboSyncCustomers($tid, null, $opts);
    }
    if (in_array($cfg['vendors'] ?? 'off', ['pull', 'two_way'], true)) {
        $jobs['vendors'] = static fn(array $opts) => qboSyncVendors($tid, null, $opts);
    }

    foreach ($jobs as $workflow => $runner) {
        $startedAt = date('Y-m-d H:i:s');
        $since = $full ? '' : qboSyncScheduleSince($tid, $workflow);
        $opts = ['limit' => $limitPerTenant, 'max_pages' => 20];
        if ($since !== '') $opts['modified_since'] = $since;
        $lockName = null;
        try {
            $lockName = qboSyncLockAcquire($tid, $workflow);
            $res = $runner($opts);
            $failed = (int) ($res['failed'] ?? count($res['import_errors'] ?? []));
            $workflowOk = $failed === 0;
            qboSyncScheduleMark(
                $tid,
                $workflow,
                $workflowOk,
                $startedAt,
                $workflowOk ? null : "{$failed} records failed"
            );
            $summary['workflows']++;
            $summary['pulled'] += (int) ($res['pulled'] ?? 0);
            $summary['failed'] += $failed;
            if (!$workflowOk) $tenantFailed = true;
            fwrite(STDOUT, sprintf(
                "tenant %d %s: pulled=%d failed=%d mode=%s\n",
                $tid,
                $workflow,
                (int) ($res['pulled'] ?? 0),
                $failed,
                $full ? 'full' : 'incremental'
            ));
        } catch (\Throwable $e) {
            $tenantFailed = true;
            $summary['failed']++;
            qboSyncScheduleMark($tid, $workflow, false, $startedAt, $e->getMessage());
            fwrite(STDERR, "tenant {$tid} {$workflow} failed: {$e->getMessage()}\n");
            qboAudit($tid, 'sync_inbound_cron_error', [
                'ok' => false,
                'entity_type' => $workflow,
                'direction' => 'pull',
                'detail' => ['error' => substr($e->getMessage(), 0, 500), 'full' => $full],
            ]);
        } finally {
            if ($lockName !== null) qboSyncLockRelease($lockName);
        }
    }

    $summary[$tenantFailed ? 'tenants_err' : 'tenants_ok']++;
}

fwrite(STDOUT, sprintf(
    "QBO inbound worker done: tenants_ok=%d tenants_err=%d workflows=%d pulled=%d failed=%d mode=%s\n",
    $summary['tenants_ok'], $summary['tenants_err'], $summary['workflows'],
    $summary['pulled'], $summary['failed'], $full ? 'full' : 'incremental'
));
exit($summary['tenants_err'] > 0 ? 1 : 0);
