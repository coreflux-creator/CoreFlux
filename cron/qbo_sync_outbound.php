<?php
/**
 * QBO outbound worker — every 15 minutes.
 *
 * Each configured workflow runs independently. A tenant does not need to
 * enable Journal Entries in order for Bills, Invoices, or Payments to run.
 * Advisory locks prevent overlap with an operator pressing "Sync now".
 *
 *   2,17,32,47 * * * * php /app/cron/qbo_sync_outbound.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/qbo/client.php';
require_once __DIR__ . '/../core/qbo/sync_je.php';
require_once __DIR__ . '/../core/qbo/sync_bills.php';
require_once __DIR__ . '/../core/qbo/sync_invoices.php';
require_once __DIR__ . '/../core/qbo/sync_payments.php';
require_once __DIR__ . '/../core/qbo/sync_lock.php';

$limitPerTenant = 100;
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

$summary = ['tenants_ok' => 0, 'tenants_err' => 0, 'workflows' => 0, 'pushed' => 0, 'skipped' => 0, 'failed' => 0];

foreach ($tenants as $tidRaw) {
    $tid = (int) $tidRaw;
    $tenantFailed = false;
    try {
        $cfg = qboSyncConfigRead($tid);
    } catch (\Throwable $e) {
        $summary['tenants_err']++;
        fwrite(STDERR, "tenant {$tid} config failed: {$e->getMessage()}\n");
        continue;
    }

    $jobs = [
        'journal_entries' => 'qboSyncJournalEntries',
        'bills'    => 'qboSyncBills',
        'invoices' => 'qboSyncInvoices',
        'payments' => 'qboSyncBillPayments',
    ];
    foreach ($jobs as $entity => $fn) {
        $dir = $cfg[$entity] ?? 'off';
        if (!in_array($dir, ['push', 'two_way'], true)) continue;
        $lockName = null;
        try {
            $lockName = qboSyncLockAcquire($tid, $entity);
            $res = $fn($tid, null, ['limit' => $limitPerTenant]);
            $pushed = (int) ($res['pushed'] ?? 0);
            $skipped = (int) ($res['skipped'] ?? $res['skipped_unmapped'] ?? 0);
            $failed = (int) ($res['failed'] ?? 0);
            $summary['workflows']++;
            $summary['pushed'] += $pushed;
            $summary['skipped'] += $skipped;
            $summary['failed'] += $failed;
            if ($failed > 0) $tenantFailed = true;
            fwrite(STDOUT, sprintf(
                "tenant %d %s: pushed=%d skipped=%d failed=%d considered=%d (%dms)\n",
                $tid, $entity, $pushed, $skipped, $failed,
                (int) ($res['considered'] ?? 0), (int) ($res['latency_ms'] ?? 0)
            ));
        } catch (\Throwable $e) {
            $tenantFailed = true;
            $summary['failed']++;
            fwrite(STDERR, "tenant {$tid} {$entity} failed: {$e->getMessage()}\n");
            qboAudit($tid, 'sync_outbound_cron_error', [
                'ok' => false,
                'entity_type' => $entity,
                'direction' => 'push',
                'detail' => ['error' => substr($e->getMessage(), 0, 500)],
            ]);
        } finally {
            if ($lockName !== null) qboSyncLockRelease($lockName);
        }
    }

    $summary[$tenantFailed ? 'tenants_err' : 'tenants_ok']++;
}

fwrite(STDOUT, sprintf(
    "QBO cron done: tenants_ok=%d tenants_err=%d workflows=%d pushed=%d skipped=%d failed=%d\n",
    $summary['tenants_ok'], $summary['tenants_err'], $summary['workflows'],
    $summary['pushed'], $summary['skipped'], $summary['failed']
));
exit($summary['tenants_err'] > 0 ? 1 : 0);
