<?php
/**
 * Cross-tenant Intercompany Expire Worker (cron driver).
 *
 * Cron: 0 9 * * * php /home/master/applications/<app>/public_html/cron/intercompany_xtenant_expire_worker.php
 *  (09:00 daily — after treasury_sweep so any morning-of approvals
 *   have a chance to land before TTL kicks in.)
 *
 * Walks `intercompany_xtenant_queue` for pending rows whose
 * `expires_at` has elapsed, marks them `expired`, and posts a
 * compensating reversal on the source leg so the source tenant's
 * books never carry an orphan IC receivable beyond its TTL.
 *
 * Idempotent: rerunning the worker on the same minute is a no-op once
 * status has flipped to `expired`.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/accounting/lib/cross_tenant_intercompany.php';

$now = new \DateTimeImmutable('now');
fwrite(STDOUT, "[intercompany_xtenant_expire_worker] starting at " . $now->format(\DateTimeImmutable::ATOM) . "\n");

try {
    $summary = accountingExpireCrossTenantIntercompanyPending(null, $now);
} catch (\Throwable $e) {
    fwrite(STDERR, "[intercompany_xtenant_expire_worker] FATAL: " . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "[intercompany_xtenant_expire_worker] scanned={$summary['scanned']} expired={$summary['expired']}\n");
foreach (($summary['errors'] ?? []) as $err) {
    fwrite(STDERR, "[intercompany_xtenant_expire_worker]   error on queue {$err['queue_id']} ({$err['ref']}): {$err['error']}\n");
}
fwrite(STDOUT, "[intercompany_xtenant_expire_worker] complete.\n");
exit(0);
