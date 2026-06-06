<?php
/**
 * cron/accounting_outbox_worker.php — accounting outbox tick.
 *
 * Cron: every 1 minute.
 *   * * * * * php /home/master/applications/<app>/public_html/cron/accounting_outbox_worker.php
 *
 * Pulls all `accounting_outbox_events` rows in 'queued' OR 'retrying'
 * state where next_retry_at <= NOW() (or NULL for queued). Dispatches
 * each via accountingCommandExecute() which:
 *   - marks 'processing' (single-tick lock against double-firing)
 *   - calls the right adapter method based on command_type
 *   - on success → 'posted' + writes accounting_destination_links
 *   - on failure → 'retrying' with exponential backoff
 *                 (60s × 2^attempts), 'dead_letter' at max_attempts=5
 *
 * Per-row error isolation: one failed command never tanks the cron.
 * The whole tick is bounded by --max-rows (default 100) so a backlog
 * doesn't blow the timeout — the next tick picks up the rest.
 *
 * Flags:
 *   --tenant=N        Restrict to one tenant (handy for backfills)
 *   --max-rows=N      Cap per-tick (default 100)
 *   --dry-run         Print what would execute, never call adapters
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/accounting/command_service.php';
require_once __DIR__ . '/../core/accounting/provider_adapter.php';

// ---- CLI args ----------------------------------------------------
$opts = getopt('', ['tenant::', 'max-rows::', 'dry-run']);
$onlyTenant = isset($opts['tenant']) ? (int) $opts['tenant'] : 0;
$maxRows    = isset($opts['max-rows']) ? max(1, (int) $opts['max-rows']) : 100;
$dryRun     = array_key_exists('dry-run', $opts);

// ---- pull eligible rows ------------------------------------------
$pdo = getDB();
try {
    $sql = "SELECT id, tenant_id, sub_tenant_id, provider, command_type,
                   status, attempts, max_attempts, next_retry_at
              FROM accounting_outbox_events
             WHERE status IN ('queued','retrying')
               AND (next_retry_at IS NULL OR next_retry_at <= NOW())";
    $params = [];
    if ($onlyTenant > 0) {
        $sql .= " AND tenant_id = :t";
        $params['t'] = $onlyTenant;
    }
    $sql .= " ORDER BY id ASC LIMIT {$maxRows}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    // Migration not applied yet → silent skip (cron stays green).
    fwrite(STDERR, "[accounting_outbox_worker] schema not ready: {$e->getMessage()}\n");
    exit(0);
}

if (!$rows) {
    fwrite(STDOUT, "[accounting_outbox_worker] no eligible rows.\n");
    exit(0);
}

$started = microtime(true);
$ok = 0; $err = 0; $skipped = 0;

foreach ($rows as $r) {
    $cid = (int) $r['id'];
    $tid = (int) $r['tenant_id'];
    if ($dryRun) {
        $skipped++;
        fwrite(STDOUT, sprintf(
            "  DRY-RUN  command=%d tenant=%d provider=%s type=%s status=%s attempts=%d/%d\n",
            $cid, $tid, $r['provider'], $r['command_type'], $r['status'],
            (int) $r['attempts'], (int) $r['max_attempts']
        ));
        continue;
    }
    try {
        $after = accountingCommandExecute($tid, $cid);
        $statusAfter = (string) ($after['status'] ?? 'unknown');
        if ($statusAfter === 'posted') {
            $ok++;
            fwrite(STDOUT, "  OK       command={$cid} tenant={$tid} → posted\n");
        } elseif ($statusAfter === 'posted_unverified') {
            // Create succeeded on the provider side but the post-push
            // verification found a downstream-state mismatch. Don't
            // retry — the entity exists; the operator needs to look.
            $ok++;
            $reason = (string) ($after['provider_result']['verify']['reason'] ?? '?');
            fwrite(STDOUT, "  WARN     command={$cid} tenant={$tid} → posted_unverified [{$reason}]\n");
        } elseif (in_array($statusAfter, ['retrying','dead_letter'], true)) {
            $err++;
            $code = (string) ($after['error_code'] ?? '');
            fwrite(STDOUT, "  FAIL     command={$cid} tenant={$tid} → {$statusAfter} [{$code}]\n");
        } else {
            // 'processing' (race) / 'posted' / something else — treat as ok.
            $ok++;
            fwrite(STDOUT, "  OK?      command={$cid} tenant={$tid} → {$statusAfter}\n");
        }
    } catch (\Throwable $e) {
        // Hard exception from execute itself — log and continue. The
        // command stays in 'processing' until the next tick where
        // accountingCommandExecute sees it and tries again (status
        // 'processing' isn't a halt state — execute also accepts
        // 'queued'|'retrying'; 'processing' will be skipped, so we
        // need to nudge it back to retrying for the next tick).
        $err++;
        try {
            $pdo->prepare(
                "UPDATE accounting_outbox_events
                    SET status = 'retrying',
                        next_retry_at = DATE_ADD(NOW(), INTERVAL 60 SECOND),
                        error_code = 'worker_exception',
                        error_message = :em
                  WHERE id = :id AND tenant_id = :t AND status = 'processing'"
            )->execute([
                'em' => substr($e->getMessage(), 0, 240),
                'id' => $cid, 't' => $tid,
            ]);
        } catch (\Throwable $_) { /* best effort */ }
        fwrite(STDERR, "  EXCEPT   command={$cid} tenant={$tid}: {$e->getMessage()}\n");
    }
}

$elapsed = (int) ((microtime(true) - $started) * 1000);
fwrite(
    STDOUT,
    sprintf(
        "[accounting_outbox_worker] %d processed | %d ok / %d failed / %d skipped | %dms\n",
        count($rows), $ok, $err, $skipped, $elapsed
    )
);
exit(0);
