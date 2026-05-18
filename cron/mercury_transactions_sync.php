<?php
/**
 * Mercury transactions nightly sync (cron driver).
 *
 * Cron: 0 3 * * * php /home/master/applications/<app>/public_html/cron/mercury_transactions_sync.php
 *
 * Iterates every tenant with an active mercury_connections row, refreshes
 * the accounts cache, then pulls the latest N transactions per account.
 * Idempotent (UNIQUE on tenant_id + mercury_txn_id).
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/mercury_service.php';

$LIMIT_PER_ACCOUNT = 200;

$pdo = getDB();
try {
    $stmt = $pdo->query("SELECT tenant_id FROM mercury_connections WHERE status = 'active' ORDER BY tenant_id");
    $tenants = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
} catch (\Throwable $e) {
    fwrite(STDERR, "Mercury cron: migration 048 not applied yet — skipping. ({$e->getMessage()})\n");
    exit(0);
}

if (!$tenants) {
    fwrite(STDOUT, "Mercury cron: no active connections, nothing to do.\n");
    exit(0);
}

$totalFetched  = 0;
$totalInserted = 0;
$tenantsOk     = 0;
$tenantsFailed = 0;

foreach ($tenants as $tid) {
    $tid = (int) $tid;
    try {
        // Refresh accounts cache first.
        mercurySyncAccounts($tid);
        $acctStmt = $pdo->prepare('SELECT id FROM mercury_accounts WHERE tenant_id = :t');
        $acctStmt->execute(['t' => $tid]);
        $acctIds = $acctStmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        foreach ($acctIds as $apk) {
            $res = mercurySyncAccountTransactions($tid, (int) $apk, ['limit' => $LIMIT_PER_ACCOUNT]);
            $totalFetched  += (int) $res['fetched'];
            $totalInserted += (int) $res['inserted'];
        }
        $tenantsOk++;
        fwrite(STDOUT, "tenant {$tid}: " . count($acctIds) . " accounts synced\n");
    } catch (\Throwable $e) {
        $tenantsFailed++;
        fwrite(STDERR, "tenant {$tid}: FAILED — {$e->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf(
    "Mercury cron done: tenants_ok=%d tenants_failed=%d total_fetched=%d total_inserted=%d\n",
    $tenantsOk, $tenantsFailed, $totalFetched, $totalInserted
));
exit($tenantsFailed > 0 ? 1 : 0);
