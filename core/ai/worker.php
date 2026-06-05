<?php
/**
 * core/ai/worker.php — Slice 7A AI Worker Runtime helpers.
 *
 * Spec §2 ("AI Worker Runtime"). Synchronous tool calls hit the gateway
 * directly; async / long-running calls (close packet, cash forecast,
 * AP extraction) go through this queue.
 *
 * Lifecycle:
 *   queued → claimed → running → succeeded
 *                            ↘ failed (retryable; bumped attempt,
 *                                       requeued with backoff)
 *                            ↘ dead   (max_attempts hit)
 *                            ↘ cancelled
 *
 * Idempotency: aiWorkerEnqueue is keyed by (tenant_id, idempotency_key).
 * Re-enqueueing the same key returns the existing job row.
 *
 * Worker process — see cron/ai_worker.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

const AI_WORKER_DEFAULT_QUEUE  = 'default';
const AI_WORKER_HEARTBEAT_SEC  = 30;
const AI_WORKER_STALL_AFTER    = 120;   // seconds without heartbeat
const AI_WORKER_BACKOFF_BASE   = 30;    // seconds; doubles per attempt
const AI_WORKER_MAX_BACKOFF    = 1800;  // 30 min

/**
 * Register (or refresh) a worker process. Returns the worker row id.
 * Idempotent on worker_key.
 */
function aiWorkerRegister(string $workerKey, ?string $label = null,
                          array $capabilities = [], ?string $ip = null): int
{
    if (trim($workerKey) === '') throw new \InvalidArgumentException('worker_key required');
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO ai_workers
            (worker_key, label, capabilities_json, status, last_heartbeat_at,
             registered_at, last_seen_ip)
         VALUES
            (:k, :l, :c, "online", NOW(), NOW(), :ip)
         ON DUPLICATE KEY UPDATE
            label             = COALESCE(VALUES(label), label),
            capabilities_json = VALUES(capabilities_json),
            status            = "online",
            last_heartbeat_at = NOW(),
            last_seen_ip      = VALUES(last_seen_ip)'
    )->execute([
        'k'  => $workerKey,
        'l'  => $label,
        'c'  => $capabilities ? json_encode($capabilities, JSON_UNESCAPED_SLASHES) : null,
        'ip' => $ip,
    ]);
    $row = aiWorkerGetByKey($workerKey);
    if (!$row) throw new \RuntimeException('worker registration failed unexpectedly');
    return (int) $row['id'];
}

/** Stamp a fresh heartbeat. Returns true on hit, false if the row vanished. */
function aiWorkerHeartbeat(int $workerId): bool
{
    if ($workerId <= 0) return false;
    $stmt = getDB()->prepare(
        'UPDATE ai_workers
            SET last_heartbeat_at = NOW(),
                status = IF(status = "stalled", "online", status)
          WHERE id = :id'
    );
    $stmt->execute(['id' => $workerId]);
    return $stmt->rowCount() > 0;
}

/** Lookup helpers. */
function aiWorkerGet(int $workerId): ?array
{
    $stmt = getDB()->prepare('SELECT * FROM ai_workers WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $workerId]);
    return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
}
function aiWorkerGetByKey(string $key): ?array
{
    $stmt = getDB()->prepare('SELECT * FROM ai_workers WHERE worker_key = :k LIMIT 1');
    $stmt->execute(['k' => $key]);
    return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
}

/**
 * List workers; sweeps stalled rows (last_heartbeat_at > AI_WORKER_STALL_AFTER
 * ago) → status='stalled' before returning so the admin UI is accurate.
 */
function aiWorkerList(): array
{
    aiWorkerSweepStalled();
    $rows = getDB()->query('SELECT * FROM ai_workers ORDER BY id DESC LIMIT 200')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['capabilities'] = $r['capabilities_json']
            ? (json_decode((string) $r['capabilities_json'], true) ?: [])
            : [];
    }
    return $rows;
}

/** Flip stalled workers (no heartbeat in AI_WORKER_STALL_AFTER seconds). */
function aiWorkerSweepStalled(): int
{
    $stmt = getDB()->prepare(
        'UPDATE ai_workers
            SET status = "stalled"
          WHERE status IN ("online","draining")
            AND last_heartbeat_at < (NOW() - INTERVAL :sec SECOND)'
    );
    $stmt->execute(['sec' => AI_WORKER_STALL_AFTER]);
    return $stmt->rowCount();
}

/**
 * Enqueue a job. Idempotent on (tenant_id, idempotency_key) when the
 * caller supplies one — re-enqueueing returns the existing row.
 *
 * @return array  The job row.
 */
function aiWorkerEnqueue(int $tenantId, string $toolName, array $payload, array $opts = []): array
{
    if ($tenantId <= 0)             throw new \InvalidArgumentException('tenantId required');
    if (trim($toolName) === '')     throw new \InvalidArgumentException('tool_name required');

    $idempKey = isset($opts['idempotency_key']) && trim((string) $opts['idempotency_key']) !== ''
        ? mb_substr((string) $opts['idempotency_key'], 0, 120)
        : null;

    // Idempotent replay path.
    if ($idempKey !== null) {
        $existing = aiWorkerJobGetByIdempotencyKey($tenantId, $idempKey);
        if ($existing) return $existing;
    }

    $queue       = mb_substr((string) ($opts['queue'] ?? AI_WORKER_DEFAULT_QUEUE), 0, 80);
    $maxAttempts = max(1, (int) ($opts['max_attempts'] ?? 3));
    $scheduledAt = $opts['scheduled_at'] ?? null;

    $pdo = getDB();
    try {
        $pdo->prepare(
            'INSERT INTO ai_worker_jobs
                (tenant_id, sub_tenant_id, queue, tool_name, payload_json,
                 status, attempt, max_attempts, scheduled_at,
                 idempotency_key, enqueued_by_user_id, created_at, updated_at)
             VALUES
                (:t, :st, :q, :tn, :p, "queued", 0, :ma,
                 COALESCE(:sched, NOW()), :ik, :u, NOW(), NOW())'
        )->execute([
            't'    => $tenantId,
            'st'   => isset($opts['sub_tenant_id']) ? (int) $opts['sub_tenant_id'] : null,
            'q'    => $queue,
            'tn'   => $toolName,
            'p'    => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'ma'   => $maxAttempts,
            'sched'=> $scheduledAt,
            'ik'   => $idempKey,
            'u'    => isset($opts['enqueued_by_user_id']) ? (int) $opts['enqueued_by_user_id'] : null,
        ]);
        $id = (int) $pdo->lastInsertId();
    } catch (\PDOException $e) {
        // Duplicate idempotency_key race — fetch + return.
        if ($idempKey !== null
            && (str_contains($e->getMessage(), 'Duplicate entry')
                || str_contains($e->getMessage(), '1062'))) {
            $existing = aiWorkerJobGetByIdempotencyKey($tenantId, $idempKey);
            if ($existing) return $existing;
        }
        throw $e;
    }
    return aiWorkerJobGet($tenantId, $id);
}

/**
 * Atomically claim up to N jobs for a worker.
 *
 * Uses an UPDATE-with-LIMIT-by-id pattern so concurrent workers don't
 * grab the same row (MySQL's UPDATE + ORDER BY + LIMIT is atomic at
 * the row level under default isolation).
 *
 * @param string[] $queues  Empty = all queues.
 * @return array[]  Claimed job rows.
 */
function aiWorkerClaim(int $workerId, array $queues = [], int $limit = 1): array
{
    if ($workerId <= 0) throw new \InvalidArgumentException('workerId required');
    $limit = max(1, min(50, $limit));
    $pdo = getDB();

    // Pick candidate ids in a tight transaction.
    $where = ["status = 'queued'", 'scheduled_at <= NOW()'];
    $params = [];
    if ($queues) {
        $place = [];
        foreach ($queues as $i => $q) { $place[] = ":q$i"; $params["q$i"] = (string) $q; }
        $where[] = 'queue IN (' . implode(',', $place) . ')';
    }
    $sql = 'SELECT id FROM ai_worker_jobs WHERE ' . implode(' AND ', $where)
         . ' ORDER BY scheduled_at ASC, id ASC LIMIT ' . $limit . ' FOR UPDATE';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $ids = array_map(fn ($r) => (int) $r['id'], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
        if (!$ids) { $pdo->commit(); return []; }

        $place = implode(',', array_map(fn ($i) => ":i$i", array_keys($ids)));
        $upd = $pdo->prepare(
            'UPDATE ai_worker_jobs
                SET status = "claimed",
                    claimed_by_worker_id = :w,
                    claimed_at = NOW(),
                    attempt = attempt + 1,
                    updated_at = NOW()
              WHERE id IN (' . $place . ') AND status = "queued"'
        );
        $bind = ['w' => $workerId];
        foreach ($ids as $i => $id) $bind["i$i"] = $id;
        $upd->execute($bind);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Return the fresh rows.
    $rows = [];
    foreach ($ids as $id) {
        $row = aiWorkerJobGetById($id);
        if ($row && $row['claimed_by_worker_id'] === $workerId) $rows[] = $row;
    }
    return $rows;
}

/** Mark a claimed job as running.  Idempotent. */
function aiWorkerMarkRunning(int $jobId): void
{
    if ($jobId <= 0) return;
    // tenant-leak-allow: worker hot-path; jobId is unique cross-tenant
    getDB()->prepare(
        'UPDATE ai_worker_jobs
            SET status = "running",
                started_at = COALESCE(started_at, NOW()),
                updated_at = NOW()
          WHERE id = :id AND status = "claimed"'
    )->execute(['id' => $jobId]);
}

/** Mark a job complete with the result payload. */
function aiWorkerComplete(int $jobId, array $result, ?string $aiRunId = null): void
{
    if ($jobId <= 0) throw new \InvalidArgumentException('jobId required');
    // tenant-leak-allow: worker hot-path; jobId is unique cross-tenant
    getDB()->prepare(
        'UPDATE ai_worker_jobs
            SET status     = "succeeded",
                result_json= :r,
                ai_run_id  = COALESCE(:ai, ai_run_id),
                finished_at= NOW(),
                updated_at = NOW(),
                error_message = NULL,
                error_code    = NULL
          WHERE id = :id'
    )->execute([
        'r'  => json_encode($result, JSON_UNESCAPED_SLASHES),
        'ai' => $aiRunId,
        'id' => $jobId,
    ]);
}

/**
 * Mark a job failed.  If attempt < max_attempts, requeue with
 * exponential backoff. Otherwise mark `dead`.
 */
function aiWorkerFail(int $jobId, string $errorMessage, ?string $errorCode = null, bool $retryable = true): void
{
    if ($jobId <= 0) throw new \InvalidArgumentException('jobId required');
    $row = aiWorkerJobGetById($jobId);
    if (!$row) return;

    $attempt     = (int) $row['attempt'];
    $maxAttempts = (int) $row['max_attempts'];
    $isDead = !$retryable || $attempt >= $maxAttempts;

    if ($isDead) {
        // tenant-leak-allow: worker hot-path; jobId is unique cross-tenant
        getDB()->prepare(
            'UPDATE ai_worker_jobs
                SET status = "dead",
                    error_message = :em,
                    error_code    = :ec,
                    finished_at   = NOW(),
                    updated_at    = NOW()
              WHERE id = :id'
        )->execute(['em' => mb_substr($errorMessage, 0, 2000), 'ec' => $errorCode, 'id' => $jobId]);
        return;
    }

    // Exponential backoff: base * 2^(attempt-1), capped.
    $backoff = min(AI_WORKER_MAX_BACKOFF, AI_WORKER_BACKOFF_BASE * (1 << max(0, $attempt - 1)));
    // tenant-leak-allow: worker hot-path; jobId is unique cross-tenant, tenant context lives on the job row itself
    getDB()->prepare(
        'UPDATE ai_worker_jobs
            SET status          = "queued",
                claimed_by_worker_id = NULL,
                claimed_at      = NULL,
                started_at      = NULL,
                next_attempt_at = (NOW() + INTERVAL :b1 SECOND),
                scheduled_at    = (NOW() + INTERVAL :b2 SECOND),
                error_message   = :em,
                error_code      = :ec,
                updated_at      = NOW()
          WHERE id = :id'
    )->execute([
        'b1' => $backoff, 'b2' => $backoff,
        'em' => mb_substr($errorMessage, 0, 2000),
        'ec' => $errorCode, 'id' => $jobId,
    ]);
}

/** Manually cancel a queued or failed-but-not-dead job. */
function aiWorkerCancel(int $tenantId, int $jobId, ?string $reason = null): bool
{
    if ($tenantId <= 0 || $jobId <= 0) return false;
    $stmt = getDB()->prepare(
        'UPDATE ai_worker_jobs
            SET status = "cancelled",
                error_message = :r,
                finished_at = NOW(),
                updated_at  = NOW()
          WHERE id = :id AND tenant_id = :t
            AND status IN ("queued","claimed","failed")'
    );
    $stmt->execute(['r' => $reason, 'id' => $jobId, 't' => $tenantId]);
    return $stmt->rowCount() > 0;
}

/** Resurrect a dead/cancelled job for one more shot. */
function aiWorkerRetry(int $tenantId, int $jobId): bool
{
    if ($tenantId <= 0 || $jobId <= 0) return false;
    $stmt = getDB()->prepare(
        'UPDATE ai_worker_jobs
            SET status = "queued",
                attempt = 0,
                claimed_by_worker_id = NULL,
                claimed_at = NULL,
                started_at = NULL,
                finished_at = NULL,
                next_attempt_at = NULL,
                scheduled_at = NOW(),
                error_message = NULL,
                error_code    = NULL,
                updated_at    = NOW()
          WHERE id = :id AND tenant_id = :t
            AND status IN ("dead","cancelled","failed")'
    );
    $stmt->execute(['id' => $jobId, 't' => $tenantId]);
    return $stmt->rowCount() > 0;
}

/** Read helpers. */
function aiWorkerJobGet(int $tenantId, int $jobId): ?array
{
    if ($tenantId <= 0 || $jobId <= 0) return null;
    $stmt = getDB()->prepare(
        'SELECT * FROM ai_worker_jobs
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['id' => $jobId, 't' => $tenantId]);
    return aiWorkerJobNormalize($stmt->fetch(\PDO::FETCH_ASSOC) ?: null);
}
function aiWorkerJobGetById(int $jobId): ?array
{
    // tenant-leak-allow: worker hot-path; jobId is unique cross-tenant; tenant scope lives on the row
    $stmt = getDB()->prepare('SELECT * FROM ai_worker_jobs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $jobId]);
    return aiWorkerJobNormalize($stmt->fetch(\PDO::FETCH_ASSOC) ?: null);
}
function aiWorkerJobGetByIdempotencyKey(int $tenantId, string $idemKey): ?array
{
    if ($tenantId <= 0 || $idemKey === '') return null;
    $stmt = getDB()->prepare(
        'SELECT * FROM ai_worker_jobs
          WHERE tenant_id = :t AND idempotency_key = :k LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'k' => $idemKey]);
    return aiWorkerJobNormalize($stmt->fetch(\PDO::FETCH_ASSOC) ?: null);
}

/** List newest jobs, optionally filtered by tenant + status + queue. */
function aiWorkerJobList(?int $tenantId, array $filters = []): array
{
    $where = [];
    $params = [];
    if ($tenantId !== null) { $where[] = 'tenant_id = :t'; $params['t'] = $tenantId; }
    if (!empty($filters['status'])) {
        $where[] = 'status = :s'; $params['s'] = (string) $filters['status'];
    }
    if (!empty($filters['queue'])) {
        $where[] = 'queue = :q'; $params['q'] = (string) $filters['queue'];
    }
    $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
    $sql = 'SELECT * FROM ai_worker_jobs'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY id DESC LIMIT ' . $limit;
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return array_map('aiWorkerJobNormalize', $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
}

/** Aggregate queue depth by status for the admin dashboard. */
function aiWorkerQueueDepth(?int $tenantId = null): array
{
    $sql = "SELECT status, COUNT(*) AS n FROM ai_worker_jobs"
         . ($tenantId !== null ? ' WHERE tenant_id = :t' : '')
         . ' GROUP BY status';
    $stmt = getDB()->prepare($sql);
    if ($tenantId !== null) $stmt->bindValue(':t', $tenantId, \PDO::PARAM_INT);
    $stmt->execute();
    $out = ['queued' => 0, 'claimed' => 0, 'running' => 0,
            'succeeded' => 0, 'failed' => 0, 'dead' => 0, 'cancelled' => 0];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
        $out[$r['status']] = (int) $r['n'];
    }
    return $out;
}

/** Internal — type-coerce + JSON-decode helper. */
function aiWorkerJobNormalize(?array $row): ?array
{
    if (!$row) return null;
    foreach (['id','tenant_id','sub_tenant_id','attempt','max_attempts',
              'claimed_by_worker_id','enqueued_by_user_id'] as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null) $row[$k] = (int) $row[$k];
    }
    $row['payload'] = $row['payload_json']
        ? (json_decode((string) $row['payload_json'], true) ?: [])
        : [];
    $row['result']  = !empty($row['result_json'])
        ? (json_decode((string) $row['result_json'], true) ?: [])
        : null;
    return $row;
}
