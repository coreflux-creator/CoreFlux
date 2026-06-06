<?php
/**
 * One-shot rescue: convert all CF-originated Jaz draft journals to
 * active. Runs only against the existing destination_links rows
 * tagged as `journal/draft` so we don't accidentally finalize a
 * draft that Jaz's own user created in their UI.
 *
 * Usage:
 *   php scripts/jaz_finalize_orphan_drafts.php --tenant=7 [--dry-run] [--limit=N]
 *
 * After deploy of jaz_adapter.php's saveAsDraft:false fix, this
 * script unblocks the two-or-three JEs that landed in Drafts before
 * the fix shipped. Idempotent — re-running on already-finalized
 * journals is a no-op because Jaz returns 200 with status:active.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/accounting/jaz_adapter.php';
require_once __DIR__ . '/../core/accounting/jaz_http.php';

$opts = getopt('', ['tenant:', 'dry-run', 'limit::']);
$tenantId = (int) ($opts['tenant'] ?? 0);
$dryRun   = array_key_exists('dry-run', $opts);
$limit    = (int) ($opts['limit'] ?? 100);

if ($tenantId <= 0) {
    fwrite(STDERR, "usage: php scripts/jaz_finalize_orphan_drafts.php --tenant=<id> [--dry-run] [--limit=100]\n");
    exit(2);
}

$pdo = getDB();
$rows = $pdo->prepare(
    "SELECT id, tenant_id, sub_tenant_id, provider_object_id, coreflux_object_id, sync_status
       FROM accounting_destination_links
      WHERE tenant_id = :t
        AND provider = 'jaz'
        AND coreflux_object_type = 'journal'
        AND provider_object_id != ''
      ORDER BY id DESC
      LIMIT {$limit}"
);
$rows->execute(['t' => $tenantId]);
$candidates = $rows->fetchAll(\PDO::FETCH_ASSOC);

if (!$candidates) {
    echo "no Jaz journal links found for tenant {$tenantId}\n";
    exit(0);
}

$adapter = new JazProviderAdapter();
$conv = 0; $skip = 0; $fail = 0;
foreach ($candidates as $link) {
    $subTenantId = (int) $link['sub_tenant_id'];
    $rid         = (string) $link['provider_object_id'];
    if ($dryRun) {
        echo "  DRY-RUN  link#{$link['id']} → would convert journal/{$rid}\n";
        continue;
    }
    try {
        $adapter->postObject($tenantId, $subTenantId, 'journal', $rid);
        echo "  OK       link#{$link['id']} → journal/{$rid} now active\n";
        $conv++;
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'already active') ||
            str_contains($e->getMessage(), 'not_a_draft')) {
            echo "  SKIP     link#{$link['id']} → already active\n";
            $skip++;
        } else {
            echo "  FAIL     link#{$link['id']} → {$e->getMessage()}\n";
            $fail++;
        }
    }
}
echo "\nconverted={$conv} skipped={$skip} failed={$fail}\n";
exit($fail > 0 ? 1 : 0);
