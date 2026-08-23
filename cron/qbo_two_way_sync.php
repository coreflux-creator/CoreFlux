<?php
/**
 * QBO transactional inbound worker — every 15 minutes.
 *
 * Pull order is Invoice → Bill → Payments/Deposits/BillPayments so link
 * resolution sees document mappings before settlement records. Each saved
 * workflow direction is honored and each workflow owns an independent,
 * five-minute-overlapped incremental checkpoint.
 *
 *   8,23,38,53 * * * * php /app/cron/qbo_two_way_sync.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/qbo/client.php';
require_once __DIR__ . '/../core/qbo/sync_in_arap.php';
require_once __DIR__ . '/../core/qbo/auto_reconcile.php';
require_once __DIR__ . '/../core/qbo/sync_schedule.php';
require_once __DIR__ . '/../core/qbo/sync_lock.php';

$pdo = getDB();
try {
    qboSyncScheduleEnsure();
    $rows = $pdo->query(
        "SELECT tenant_id FROM qbo_connections WHERE status = 'active' ORDER BY tenant_id"
    )->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    fwrite(STDERR, "qbo_two_way_sync: bootstrap failed: {$e->getMessage()}\n");
    exit(1);
}
if (!$rows) {
    fwrite(STDOUT, "qbo_two_way_sync: no active connections.\n");
    exit(0);
}

$summary = [
    'tenants' => 0, 'ok' => 0, 'fail' => 0, 'workflows' => 0,
    'drift_rows' => 0, 'auto_reconciled' => 0, 'auto_payments_created' => 0,
];

foreach ($rows as $row) {
    $tid = (int) $row['tenant_id'];
    $summary['tenants']++;
    $tenantOk = true;
    $ranAny = false;
    try {
        $cfg = qboSyncConfigRead($tid);
    } catch (\Throwable $e) {
        $summary['fail']++;
        fwrite(STDERR, "tenant {$tid}: config failed: {$e->getMessage()}\n");
        continue;
    }

    $workflows = [
        'invoices' => ['direction' => $cfg['invoices'] ?? 'off', 'pullers' => ['qboPullInvoices']],
        'bills'    => ['direction' => $cfg['bills'] ?? 'off',    'pullers' => ['qboPullBills']],
        'payments' => [
            'direction' => $cfg['payments'] ?? 'off',
            'pullers' => ['qboPullPayments', 'qboPullDeposits', 'qboPullBillPayments'],
        ],
    ];

    foreach ($workflows as $workflow => $spec) {
        if (!in_array($spec['direction'], ['pull', 'two_way'], true)) continue;
        $ranAny = true;
        $startedAt = date('Y-m-d H:i:s');
        $since = qboSyncScheduleSince($tid, $workflow);
        $opts = ['limit' => 2000, 'max_pages' => 20];
        if ($since !== '') $opts['modified_since'] = $since;

        $workflowOk = true;
        $workflowError = '';
        $lockName = null;
        try {
            $lockName = qboSyncLockAcquire($tid, $workflow);
            foreach ($spec['pullers'] as $fn) {
                $res = $fn($tid, $opts);
                $summary['drift_rows'] += (int) ($res['drift_rows_written'] ?? 0);
                if ((int) ($res['failed'] ?? 0) > 0) {
                    throw new \RuntimeException((int) $res['failed'] . ' records failed');
                }
            }
        } catch (\Throwable $e) {
            $workflowOk = false;
            $workflowError = ($fn ?? $workflow) . ': ' . substr($e->getMessage(), 0, 350);
            fwrite(STDERR, "tenant {$tid} {$workflow}: {$workflowError}\n");
        } finally {
            if ($lockName !== null) qboSyncLockRelease($lockName);
        }
        qboSyncScheduleMark($tid, $workflow, $workflowOk, $startedAt, $workflowError);
        if ($workflowOk) {
            $summary['workflows']++;
        } else {
            $tenantOk = false;
        }
    }

    // Reconcile only after every enabled inbound workflow completed. A
    // partial snapshot should never drive automatic accounting changes.
    if ($ranAny && $tenantOk) {
        try {
            $arc = qboAutoReconcileTenant($tid, null);
            $summary['auto_reconciled'] += (int) ($arc['drift_rows_closed'] ?? 0);
            $summary['auto_payments_created'] += (int) ($arc['payments_created'] ?? 0);
            if (!empty($arc['errors'])) {
                fwrite(STDERR, "tenant {$tid}: auto-reconcile errors: " . implode(' | ', $arc['errors']) . "\n");
            }
        } catch (\Throwable $e) {
            // Auto-reconcile remains best-effort and never invalidates the
            // successfully captured QBO snapshots/checkpoints.
            fwrite(STDERR, "tenant {$tid}: auto-reconcile fatal: " . substr($e->getMessage(), 0, 240) . "\n");
        }
    }

    $summary[$tenantOk ? 'ok' : 'fail']++;
}

fwrite(STDOUT, sprintf(
    "qbo_two_way_sync done: tenants=%d ok=%d fail=%d workflows=%d drift_rows=%d auto_reconciled=%d auto_payments=%d\n",
    $summary['tenants'], $summary['ok'], $summary['fail'], $summary['workflows'],
    $summary['drift_rows'], $summary['auto_reconciled'], $summary['auto_payments_created']
));
exit($summary['fail'] > 0 ? 1 : 0);
