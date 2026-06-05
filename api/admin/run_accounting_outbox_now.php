<?php
/**
 * /api/admin/run_accounting_outbox_now.php
 *
 * On-demand kick for the accounting outbox worker. Mirrors
 * `cron/accounting_outbox_worker.php` but exposed over HTTP so an
 * operator can flush queued provider commands (Jaz, QBO, etc.) without
 * SSH or waiting for the every-minute cron.
 *
 * RBAC: master_admin only.  This is a state-mutating diagnostic
 * surface — every invocation processes up to `max_rows` queued/retrying
 * outbox rows and triggers real provider calls. Not a read-only probe.
 *
 * Query params:
 *   ?tenant=N         Restrict to one tenant (handy when a single
 *                     tenant's queue is backed up).
 *   ?max_rows=N       Cap per-tick (default 50, hard ceiling 200).
 *   ?dry_run=1        Report what WOULD run but don't call adapters.
 *
 * Returns JSON:
 *   {
 *     ok: true,
 *     processed: N,
 *     succeeded: N,
 *     failed:    N,
 *     skipped:   N,
 *     elapsed_ms: N,
 *     rows: [{command_id, tenant_id, provider, command_type,
 *             status_before, status_after, error_code, error_message}]
 *   }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/accounting/command_service.php';
require_once __DIR__ . '/../../core/accounting/provider_adapter.php';

$ctx           = api_require_auth();
$role          = (string) ($ctx['role'] ?? 'employee');
$isGlobalAdmin = (bool) ($ctx['is_global_admin'] ?? false);
$user          = $ctx['user'] ?? [];

if (!$isGlobalAdmin && $role !== 'master_admin') {
    api_error('Forbidden — master_admin only', 403);
}
if (api_method() !== 'POST') {
    api_error('Method not allowed — use POST (state-mutating)', 405);
}

$onlyTenant = isset($_GET['tenant'])   ? max(0, (int) $_GET['tenant'])   : 0;
$maxRows    = isset($_GET['max_rows']) ? max(1, min(200, (int) $_GET['max_rows'])) : 50;
$dryRun     = !empty($_GET['dry_run']);

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
    api_error('Outbox schema not ready: ' . $e->getMessage(), 500);
}

$started   = microtime(true);
$succeeded = 0; $failed = 0; $skipped = 0;
$report    = [];

foreach ($rows as $r) {
    $cid = (int) $r['id'];
    $tid = (int) $r['tenant_id'];

    if ($dryRun) {
        $skipped++;
        $report[] = [
            'command_id'    => $cid,
            'tenant_id'     => $tid,
            'provider'      => (string) $r['provider'],
            'command_type'  => (string) $r['command_type'],
            'status_before' => (string) $r['status'],
            'status_after'  => 'dry_run_skipped',
            'attempts'      => (int) $r['attempts'],
        ];
        continue;
    }

    try {
        $after       = accountingCommandExecute($tid, $cid);
        $statusAfter = (string) ($after['status'] ?? 'unknown');
        $isSuccess   = $statusAfter === 'posted';
        if ($isSuccess) {
            $succeeded++;
        } elseif (in_array($statusAfter, ['retrying', 'dead_letter'], true)) {
            $failed++;
        } else {
            // 'processing' / unknown — treat as ok for the counter.
            $succeeded++;
        }
        $report[] = [
            'command_id'    => $cid,
            'tenant_id'     => $tid,
            'provider'      => (string) $r['provider'],
            'command_type'  => (string) $r['command_type'],
            'status_before' => (string) $r['status'],
            'status_after'  => $statusAfter,
            'error_code'    => (string) ($after['error_code']    ?? ''),
            'error_message' => (string) ($after['error_message'] ?? ''),
        ];
    } catch (\Throwable $e) {
        $failed++;
        // Mirror the cron worker's "nudge processing → retrying" recovery
        // so a future tick (cron OR this endpoint re-invoked) picks it up.
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
        } catch (\Throwable $_) { /* best-effort */ }
        $report[] = [
            'command_id'    => $cid,
            'tenant_id'     => $tid,
            'provider'      => (string) $r['provider'],
            'command_type'  => (string) $r['command_type'],
            'status_before' => (string) $r['status'],
            'status_after'  => 'worker_exception',
            'error_code'    => 'worker_exception',
            'error_message' => substr($e->getMessage(), 0, 240),
        ];
    }
}

$elapsedMs = (int) ((microtime(true) - $started) * 1000);

api_ok([
    'ok'         => true,
    'tenant'     => $onlyTenant > 0 ? $onlyTenant : null,
    'max_rows'   => $maxRows,
    'dry_run'    => $dryRun,
    'processed'  => count($rows),
    'succeeded'  => $succeeded,
    'failed'     => $failed,
    'skipped'    => $skipped,
    'elapsed_ms' => $elapsedMs,
    'rows'       => $report,
    'next_step'  => count($rows) === 0
        ? 'Outbox is empty — no queued or retrying rows. If a JE was posted recently and isn\'t here, check that accountingCommandQueue() is being called from the post path.'
        : ($failed > 0
            ? 'Some rows failed — inspect the error_message field per row and the corresponding provider (Jaz/QBO) connection.'
            : 'All processed rows succeeded. Verify they show up in the destination provider UI.'),
]);
