<?php
/**
 * Airtable sync — cron driver.
 *
 * Cron entry (Cloudways):
 *   Every 15 minutes:  H/15 * * * * php /home/master/applications/<app>/public_html/cron/airtable_sync.php
 *
 * Slice 5 — now runs both legs:
 *   - direction='pull' or 'both'  → airtableSyncTable() (pull leg)
 *   - direction='push' or 'both'  → airtablePushMapping() (push leg)
 *
 * Iterates every tenant with an active airtable_connections row and
 * at least one non-off mapping. Per-tenant + per-mapping failure is
 * caught — one bad mapping never tanks the cron.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/airtable/client.php';
require_once __DIR__ . '/../core/airtable/sync.php';
require_once __DIR__ . '/../core/airtable/sync_push.php';

$pdo = getDB();
try {
    $stmt = $pdo->query(
        "SELECT DISTINCT c.tenant_id
           FROM airtable_connections c
           JOIN airtable_table_mappings m ON m.tenant_id = c.tenant_id
          WHERE c.status = 'active'
            AND m.direction IN ('pull','push','both')
       ORDER BY c.tenant_id"
    );
    $tenants = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
} catch (\Throwable $e) {
    fwrite(STDERR, "Airtable cron: migration not applied yet — skipping. ({$e->getMessage()})\n");
    exit(0);
}
if (!$tenants) {
    fwrite(STDOUT, "Airtable cron: no active mappings, nothing to do.\n");
    exit(0);
}

$totalRecords = 0; $totalCreated = 0; $totalUpdated = 0; $totalUnchanged = 0; $totalFailed = 0;
$totalPushed = 0; $totalPushCreated = 0; $totalPushUpdated = 0; $totalPushErrored = 0;
$mappingsOk = 0; $mappingsErr = 0;

foreach ($tenants as $tid) {
    $tid = (int) $tid;
    try {
        $mappings = airtableMappingList($tid);
        foreach ($mappings as $m) {
            $dir = (string) ($m['direction'] ?? 'off');
            if (!in_array($dir, ['pull', 'push', 'both'], true)) continue;

            // Pull leg.
            if (in_array($dir, ['pull', 'both'], true)) {
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
                    fwrite(STDERR, "Airtable cron: tenant={$tid} mapping_id={$m['id']} pull failed: {$e->getMessage()}\n");
                }
            }

            // Push leg.
            if (in_array($dir, ['push', 'both'], true)) {
                // Skip push if reverse_field_map is empty — operator
                // hasn't configured it yet; the worker would throw.
                $rfm = $m['reverse_field_map'] ?? [];
                if (is_object($rfm)) $rfm = (array) $rfm;
                if (!is_array($rfm) || empty($rfm)) {
                    continue;
                }
                try {
                    $res = airtablePushMapping($tid, (int) $m['id'], []);
                    $totalPushed       += (int) ($res['pushed']  ?? 0);
                    $totalPushCreated  += (int) ($res['created'] ?? 0);
                    $totalPushUpdated  += (int) ($res['updated'] ?? 0);
                    $totalPushErrored  += (int) ($res['errored'] ?? 0);
                    $mappingsOk++;
                } catch (\Throwable $e) {
                    $mappingsErr++;
                    fwrite(STDERR, "Airtable cron: tenant={$tid} mapping_id={$m['id']} push failed: {$e->getMessage()}\n");
                }
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
        "Airtable cron done: %d legs ok / %d errored | pull: %d records (%d created · %d updated · %d unchanged · %d failed) | push: %d pushed (%d created · %d updated · %d errored)\n",
        $mappingsOk, $mappingsErr,
        $totalRecords, $totalCreated, $totalUpdated, $totalUnchanged, $totalFailed,
        $totalPushed, $totalPushCreated, $totalPushUpdated, $totalPushErrored
    )
);
exit(0);
