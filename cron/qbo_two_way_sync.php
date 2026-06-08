<?php
/**
 * QBO two-way sync cron.
 *
 * Runs all five inbound pulls in sequence per active tenant:
 *   AR : Invoice → Payment → Deposit
 *   AP : Bill    → BillPayment
 *
 * Order matters: pulling Invoice + Bill BEFORE their settlement
 * entities ensures the link-resolution step (linked_invoice_ids /
 * linked_bill_ids) finds the local mapping rows already populated.
 *
 * Incremental pulls use MetaData.LastUpdatedTime via the `since`
 * timestamp stored on `tenant_qbo_two_way_state`. First run pulls
 * everything; subsequent runs only pull what changed since the last
 * successful timestamp.
 *
 * Suggested schedule: every 30 minutes.
 *   *\/30 * * * * php /home/master/applications/<app>/public_html/cron/qbo_two_way_sync.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/qbo/sync_in_arap.php';

$pdo = getDB();

// Ensure the lightweight state table exists. Cheap & idempotent — if
// the DBA prefers it as a migration, drop it in.
try {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tenant_qbo_two_way_state (
            tenant_id     INT UNSIGNED NOT NULL,
            last_pull_at  DATETIME     NULL,
            last_error    VARCHAR(500) NULL,
            updated_at    DATETIME     NOT NULL,
            PRIMARY KEY (tenant_id)
        )'
    );
} catch (\Throwable $e) {
    fwrite(STDERR, "qbo_two_way_sync: state table create failed: {$e->getMessage()}\n");
    exit(1);
}

try {
    $rows = $pdo->query(
        "SELECT tenant_id FROM qbo_connections WHERE status = 'active' ORDER BY tenant_id"
    )->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    fwrite(STDERR, "qbo_two_way_sync: connections table missing — migration not applied. ({$e->getMessage()})\n");
    exit(0);
}
if (!$rows) {
    fwrite(STDOUT, "qbo_two_way_sync: no active connections.\n");
    exit(0);
}

$summary = ['tenants' => 0, 'ok' => 0, 'fail' => 0, 'drift_rows' => 0];

foreach ($rows as $r) {
    $tid = (int) $r['tenant_id'];
    $summary['tenants']++;

    // Load the last-successful pull timestamp for incremental pulls.
    $st = $pdo->prepare('SELECT last_pull_at FROM tenant_qbo_two_way_state WHERE tenant_id = :t');
    $st->execute(['t' => $tid]);
    $state = $st->fetch(\PDO::FETCH_ASSOC);
    $since = $state['last_pull_at'] ?? null;
    // First run: pull everything. Subsequent runs: only what changed
    // since 5 minutes BEFORE last_pull_at (overlap window to absorb
    // any boundary races on Intuit's side).
    $sinceArg = $since ? date('Y-m-d\TH:i:s', strtotime($since) - 300) : '';

    $startedAt = date('Y-m-d H:i:s');
    $tenantDrift = 0;
    $tenantOk    = true;
    $tenantErr   = '';

    foreach (['qboPullInvoices', 'qboPullPayments', 'qboPullDeposits',
              'qboPullBills',    'qboPullBillPayments'] as $fn) {
        try {
            $res = $fn($tid, ['modified_since' => $sinceArg, 'limit' => 2000, 'max_pages' => 20]);
            $tenantDrift += (int) ($res['drift_rows_written'] ?? 0);
        } catch (\Throwable $e) {
            $tenantOk  = false;
            $tenantErr = $fn . ': ' . substr($e->getMessage(), 0, 220);
            fwrite(STDERR, "tenant {$tid}: {$tenantErr}\n");
            break; // don't keep hammering this tenant on the same cron pass
        }
    }
    $summary[$tenantOk ? 'ok' : 'fail']++;
    $summary['drift_rows'] += $tenantDrift;

    // Upsert state row.
    $now = date('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO tenant_qbo_two_way_state (tenant_id, last_pull_at, last_error, updated_at)
         VALUES (:t, :lp, :le, :ua)
         ON DUPLICATE KEY UPDATE
             last_pull_at = IF(:ok = 1, :lp2, last_pull_at),
             last_error   = :le2,
             updated_at   = :ua2'
    )->execute([
        't'=>$tid,
        'lp'  => $tenantOk ? $startedAt : null,
        'lp2' => $startedAt,
        'le'  => $tenantOk ? null : $tenantErr,
        'le2' => $tenantOk ? null : $tenantErr,
        'ok'  => $tenantOk ? 1 : 0,
        'ua'  => $now, 'ua2' => $now,
    ]);
}

fwrite(STDOUT, sprintf(
    "qbo_two_way_sync done: tenants=%d ok=%d fail=%d drift_rows=%d\n",
    $summary['tenants'], $summary['ok'], $summary['fail'], $summary['drift_rows']
));
exit(0);
