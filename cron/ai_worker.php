#!/usr/bin/env php
<?php
/**
 * cron/ai_worker.php — Slice 7A AI Worker Runtime CLI loop.
 *
 * Usage:
 *   php cron/ai_worker.php [--queue=default,close_agent] [--max-jobs=N]
 *                          [--tools=coreflux.close_packet,...]
 *                          [--label="Cloudways-1"] [--once]
 *
 * Behaviour:
 *   1. Register the process in `ai_workers` (worker_key = "host:pid").
 *   2. Loop:
 *        - heartbeat
 *        - claim ≤ 1 job from the requested queues
 *        - mark running, dispatch via aiToolInvoke()
 *        - on success: aiWorkerComplete(...)
 *        - on throw:  aiWorkerFail(... retryable=true)  →  exponential backoff requeue
 *        - sleep ~2 sec when idle
 *   3. Exits cleanly on SIGINT / SIGTERM (flushes the current job first).
 *
 * Designed to be supervised by systemd / supervisord — restart on
 * crash, one process per server.  Multiple instances are SAFE because
 * the claim path uses a `FOR UPDATE` transaction.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/ai/worker.php';
require_once __DIR__ . '/../core/ai/tool_gateway.php';

// ── CLI args ───────────────────────────────────────────────────────────
$opts = getopt('', ['queue::', 'tools::', 'max-jobs::', 'label::', 'once', 'verbose']);
$queues   = isset($opts['queue']) && $opts['queue'] !== ''
              ? array_map('trim', explode(',', (string) $opts['queue']))
              : [];
$toolAllowlist = isset($opts['tools']) && $opts['tools'] !== ''
              ? array_values(array_filter(array_map('trim', explode(',', (string) $opts['tools'])), fn ($v) => $v !== ''))
              : ['*'];
$maxJobs  = isset($opts['max-jobs']) ? max(1, (int) $opts['max-jobs']) : PHP_INT_MAX;
$label    = isset($opts['label']) ? (string) $opts['label'] : null;
$once     = array_key_exists('once', $opts);
$verbose  = array_key_exists('verbose', $opts);

$workerKey = sprintf('%s:%d', gethostname() ?: 'unknown', getmypid());

// ── Register + heartbeat setup ─────────────────────────────────────────
$workerId = aiWorkerRegister($workerKey, $label, [
    'queues'          => $queues ?: ['default'],
    'tool_allowlist'  => $toolAllowlist,
    'max_concurrency' => 1,
], gethostbyname(gethostname() ?: 'localhost'));
$running    = true;
$lastBeat   = 0;
$jobsRun    = 0;

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,  function () use (&$running) { $running = false; });
    pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
}

logLine("[ai_worker] registered as #{$workerId} (worker_key={$workerKey}, queues=" . implode(',', $queues ?: ['*']) . ", tools=" . implode(',', $toolAllowlist) . ")");

// ── Main loop ──────────────────────────────────────────────────────────
while ($running && $jobsRun < $maxJobs) {
    // Heartbeat every AI_WORKER_HEARTBEAT_SEC.
    if (time() - $lastBeat >= AI_WORKER_HEARTBEAT_SEC) {
        aiWorkerHeartbeat($workerId);
        $lastBeat = time();
    }

    // Claim up to one job for this worker.
    $jobs = [];
    try {
        $jobs = aiWorkerClaim($workerId, $queues, 1);
    } catch (\Throwable $e) {
        logLine("[ai_worker] claim error: " . $e->getMessage());
        sleep(5);
        continue;
    }

    if (!$jobs) {
        if ($once) break;
        sleep(2);
        continue;
    }

    foreach ($jobs as $job) {
        $jobsRun++;
        $jobId = (int) $job['id'];
        aiWorkerMarkRunning($jobId);
        $verbose && logLine("[ai_worker] claimed job #{$jobId} tool={$job['tool_name']} attempt={$job['attempt']}");

        try {
            $payload = $job['payload'] ?? [];
            $env = aiToolInvoke(
                (int) $job['tenant_id'],
                $job['sub_tenant_id'] !== null ? (int) $job['sub_tenant_id'] : null,
                (int) ($job['enqueued_by_user_id'] ?? 0) ?: null,
                'ai-worker-' . $jobId,                                 // sessionId
                (string) $job['tool_name'],
                is_array($payload['args'] ?? null) ? $payload['args'] : [],
                ['_worker_id' => $workerId, '_worker_job_id' => $jobId]  // callerCtx
            );

            if (($env['ok'] ?? false) === true || isset($env['status']) && $env['status'] === 'success') {
                aiWorkerComplete($jobId, $env, $env['ai_run_id'] ?? null);
                $verbose && logLine("[ai_worker] job #{$jobId} succeeded");
            } else {
                $msg = $env['error']['message'] ?? 'tool returned non-ok envelope';
                $code = $env['error']['code']   ?? 'tool_error';
                // Most tool envelope errors (bad_args / not_found / approval_*)
                // are NOT retryable — they will fail the same way next attempt.
                $retryable = !in_array($code, ['bad_args','not_found','approval_required','approval_invalid','permission_denied'], true);
                aiWorkerFail($jobId, $msg, $code, $retryable);
                $verbose && logLine("[ai_worker] job #{$jobId} failed: $code · $msg (retryable=" . ($retryable ? '1' : '0') . ")");
            }
        } catch (\Throwable $e) {
            // Unexpected exception — retryable by default.
            aiWorkerFail($jobId, $e->getMessage(), 'exception', true);
            logLine("[ai_worker] job #{$jobId} EXCEPTION: " . $e->getMessage());
        }
    }
}

logLine("[ai_worker] shutting down (jobs_run={$jobsRun})");
exit(0);

function logLine(string $msg): void
{
    fwrite(STDOUT, '[' . date('c') . '] ' . $msg . "\n");
}
