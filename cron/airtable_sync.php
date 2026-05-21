<?php
/**
 * Airtable sync — cron driver.
 *
 * Cron entry (Cloudways):
 *   Every 15 minutes:  H/15 * * * * php /home/master/applications/<app>/public_html/cron/airtable_sync.php
 *
 * Iterates every tenant with an active airtable_connections row and a
 * sync direction of `pull` for one or more table mappings. Runs the
 * sync per mapping, captures aggregate counts to stdout, and continues
 * on per-tenant failure.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/airtable/client.php';
require_once __DIR__ . '/../core/airtable/sync.php';

$pdo = getDB();
try {
    $stmt = $pdo->query(
        "SELECT DISTINCT c.tenant_id
           FROM airtable_connections c
           JOIN airtable_table_mappings m ON m.tenant_id = c.tenant_id
          WHERE c.status = 'active'
            AND m.direction = 'pull'
       ORDER BY c.tenant_id"
    );
    $tenants = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
} catch (\Throwable $e) {
    fwrite(STDERR, "Airtable cron: migration 063 not applied yet — skipping. ({$e->getMessage()})\n");
    exit(0);
}
if (!$tenants) {
    fwrite(STDOUT, "Airtable cron: no active pull mappings, nothing to do.\n");
    exit(0);
}

$totalRecords = 0; $totalCreated = 0; $totalUpdated = 0; $totalUnchanged = 0; $totalFailed = 0;
$mappingsOk = 0; $mappingsErr = 0;

foreach ($tenants as $tid) {
    $tid = (int) $tid;
    try {
        $mappings = airtableMappingList($tid);
        foreach ($mappings as $m) {
            if (($m['direction'] ?? 'off') !== 'pull') continue;
            try {
                $res = airtableSyncTable($tid, (int) $m['id'], null);
                $totalRecords  += (int) ($res['records']  ?? 0);
                $totalCreated  += (int) ($res['created']  ?? 0);
                $totalUpdated  += (int) ($res['updated']  ?? 0);
                $totalUnchanged+= (int) ($res['unchanged']?? 0);
                $totalFailed   += (int) ($res['failed']   ?? 0);
                $mappingsOk++;
            } catch (\Throwable $e) {
                $mappingsErr++;
                fwrite(STDERR, "Airtable cron: tenant={$tid} mapping_id={$m['id']} failed: {$e->getMessage()}\n");
            }
        }
    } catch (\Throwable $e) {
        $mappingsErr++;
        fwrite(STDERR, "Airtable cron: tenant={$tid} listing failed: {$e->getMessage()}\n");
    }
}

fwrite(
    STDOUT,
    sprintf(
        "Airtable cron done: %d mappings ok / %d errored, %d records (%d created · %d updated · %d unchanged · %d failed)\n",
        $mappingsOk, $mappingsErr, $totalRecords, $totalCreated, $totalUpdated, $totalUnchanged, $totalFailed
    )
);
exit(0);
