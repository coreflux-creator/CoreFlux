<?php
/**
 * core/accounting/close_runs.php — period-close orchestrator (Slice D).
 *
 * Spec §11 / scan §"Phase 4 — Close MVP":
 * `accounting_close_runs` ties together a single period-close attempt:
 *   - Checklist seed (accounting_close_tasks)
 *   - Progress tracking (total_tasks / completed_tasks counters)
 *   - Packet artifact (legacy accounting_close_packets row +
 *                       Slice A artifact_objects row)
 *   - Lock + reopen lifecycle
 *
 * Lifecycle: initiated → in_progress → packet_built → locked
 *                                  ↘ reopened (back to in_progress)
 *
 * Tenant-scoped on every call. Idempotent helpers.  Best-effort
 * artifact-layer integration — failures don't block the close.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../../modules/accounting/lib/close.php';
require_once __DIR__ . '/../ai/artifacts.php';

const CLOSE_RUN_STATUSES = ['initiated','in_progress','packet_built','locked','reopened'];

/**
 * Start a new close run for a period. If an OPEN run already exists
 * (status not in {locked,reopened}), returns it instead of creating
 * a duplicate.  Seeds the checklist via accountingSeedCloseChecklist
 * on first call so newly-opened periods land with a ready-made list.
 *
 * @return array  The run row.
 */
function closeRunStart(int $tenantId, int $periodId, ?int $actorUserId = null): array
{
    if ($tenantId <= 0) throw new \InvalidArgumentException('tenantId required');
    if ($periodId <= 0) throw new \InvalidArgumentException('periodId required');

    // Idempotent: if there's already an open run for this period, return it.
    $existing = closeRunGetActiveByPeriod($tenantId, $periodId);
    if ($existing) {
        // Make sure the checklist is seeded — accountingSeedCloseChecklist
        // is itself idempotent.
        accountingSeedCloseChecklist($tenantId, $periodId, $actorUserId);
        closeRunRefreshProgress($tenantId, (int) $existing['id']);
        return closeRunGet($tenantId, (int) $existing['id']);
    }

    $pdo = getDB();
    accountingSeedCloseChecklist($tenantId, $periodId, $actorUserId);

    $pdo->prepare(
        'INSERT INTO accounting_close_runs
            (tenant_id, period_id, status, started_at,
             started_by_user_id, total_tasks, completed_tasks,
             created_at, updated_at)
         VALUES
            (:t, :p, "initiated", NOW(), :u, 0, 0, NOW(), NOW())'
    )->execute(['t' => $tenantId, 'p' => $periodId, 'u' => $actorUserId]);
    $runId = (int) $pdo->lastInsertId();

    closeRunRefreshProgress($tenantId, $runId);
    return closeRunGet($tenantId, $runId);
}

/**
 * Read a run by id, recomputing the live `completed_tasks` count from
 * accounting_close_tasks (the stored counter is best-effort cache).
 */
function closeRunGet(int $tenantId, int $runId): ?array
{
    if ($tenantId <= 0 || $runId <= 0) return null;
    $stmt = getDB()->prepare(
        'SELECT * FROM accounting_close_runs
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['id' => $runId, 't' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ? closeRunNormalizeRow($row) : null;
}

/**
 * Return the active (non-locked, non-reopened) run for a period, if any.
 */
function closeRunGetActiveByPeriod(int $tenantId, int $periodId): ?array
{
    if ($tenantId <= 0 || $periodId <= 0) return null;
    $stmt = getDB()->prepare(
        'SELECT * FROM accounting_close_runs
          WHERE tenant_id = :t AND period_id = :p
            AND status NOT IN ("reopened")
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'p' => $periodId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ? closeRunNormalizeRow($row) : null;
}

/**
 * List runs newest-first, optionally filtered by status / period.
 */
function closeRunList(int $tenantId, array $filters = []): array
{
    if ($tenantId <= 0) return [];
    $where  = ['tenant_id = :t'];
    $params = ['t' => $tenantId];
    if (!empty($filters['status']) && in_array($filters['status'], CLOSE_RUN_STATUSES, true)) {
        $where[] = 'status = :s'; $params['s'] = (string) $filters['status'];
    }
    if (!empty($filters['period_id'])) {
        $where[] = 'period_id = :p'; $params['p'] = (int) $filters['period_id'];
    }
    $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
    $stmt = getDB()->prepare(
        'SELECT * FROM accounting_close_runs
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY id DESC LIMIT ' . $limit
    );
    $stmt->execute($params);
    return array_map('closeRunNormalizeRow', $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
}

/**
 * Recompute total_tasks / completed_tasks from accounting_close_tasks,
 * write back into the run row, and AUTO-BUMP status from `initiated`
 * to `in_progress` once any task has been touched (or all are done).
 *
 * Returns the fresh row.
 */
function closeRunRefreshProgress(int $tenantId, int $runId): array
{
    $row = closeRunGet($tenantId, $runId);
    if (!$row) throw new \RuntimeException("close run {$runId} not found");

    $periodId = (int) $row['period_id'];
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done
           FROM accounting_close_tasks
          WHERE tenant_id = :t AND period_id = :p"
    );
    $stmt->execute(['t' => $tenantId, 'p' => $periodId]);
    $agg  = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['total' => 0, 'done' => 0];
    $total = (int) $agg['total'];
    $done  = (int) ($agg['done'] ?? 0);

    // Auto-bump status:
    //   initiated → in_progress once any task is done
    //   keep packet_built / locked / reopened where they are
    $newStatus = $row['status'];
    if ($newStatus === 'initiated' && ($done > 0 || $total > 0)) {
        $newStatus = 'in_progress';
    }

    // Stamp completed_at when all tasks are done (and we're not locked
    // yet — locked is the terminal state).
    $completedAt = $row['completed_at'];
    if (!$completedAt && $total > 0 && $done === $total
        && in_array($newStatus, ['initiated','in_progress'], true)) {
        $completedAt = date('Y-m-d H:i:s');
    }

    $pdo->prepare(
        'UPDATE accounting_close_runs
            SET total_tasks = :tt,
                completed_tasks = :ct,
                status = :s,
                completed_at = :ca,
                updated_at = NOW()
          WHERE id = :id AND tenant_id = :t'
    )->execute([
        'tt' => $total, 'ct' => $done, 's' => $newStatus,
        'ca' => $completedAt, 'id' => $runId, 't' => $tenantId,
    ]);

    return closeRunGet($tenantId, $runId);
}

/**
 * Build the close-packet HTML for the run's period, persist it in
 * accounting_close_packets AND create a first-class artifact_objects
 * row of type `accounting_close_packet` so it shows up in the
 * Artifacts admin.  Idempotent: re-running returns the existing
 * artifact id.
 *
 * @return array {run_id, packet_id, artifact_id, status}
 */
function closeRunBuildPacket(int $tenantId, int $runId, ?int $actorUserId = null): array
{
    $run = closeRunGet($tenantId, $runId);
    if (!$run) throw new \RuntimeException("close run {$runId} not found");
    if ($run['status'] === 'locked') {
        throw new \RuntimeException("close run {$runId} is locked; reopen first to rebuild the packet");
    }

    $periodId = (int) $run['period_id'];
    $html = accountingBuildClosePacketHtml($tenantId, $periodId);

    $pdo = getDB();
    // Persist the legacy packet row (existing close-packet table).
    $pdo->prepare(
        'INSERT INTO accounting_close_packets
            (tenant_id, period_id, file_format, summary_json,
             built_by_user_id, built_at)
         VALUES (:t, :p, "html", :sj, :u, NOW())'
    )->execute([
        't'  => $tenantId, 'p' => $periodId,
        'sj' => json_encode([
            'run_id'       => $runId,
            'built_by'     => $actorUserId,
            'task_total'   => (int) $run['total_tasks'],
            'task_done'    => (int) $run['completed_tasks'],
            'html_length'  => strlen($html),
        ], JSON_UNESCAPED_SLASHES),
        'u'  => $actorUserId,
    ]);
    $packetId = (int) $pdo->lastInsertId();

    // Spec §2A — create a first-class artifact_objects row so the
    // packet shows up in the global artifact list with lifecycle +
    // lineage.  Best-effort: a failure here does NOT block the close.
    $artifactId = null;
    try {
        $artifact = artifactCreate($tenantId, 'accounting_close_packet', [
            'title'              => "Close packet · period #{$periodId}",
            'source_module'      => 'accounting',
            'source_record_type' => 'accounting_close_packets',
            'source_record_id'   => $packetId,
            'payload'            => [
                'run_id'      => $runId,
                'period_id'   => $periodId,
                'html_length' => strlen($html),
                'task_total'  => (int) $run['total_tasks'],
                'task_done'   => (int) $run['completed_tasks'],
            ],
            'created_by_user_id' => $actorUserId,
            'initial_status'     => 'review',
        ]);
        $artifactId = $artifact['id'] ?? null;
    } catch (\Throwable $e) {
        error_log('[close_runs] artifactCreate failed: ' . $e->getMessage());
    }

    // Stamp the run with the packet result + bump status.
    $pdo->prepare(
        'UPDATE accounting_close_runs
            SET status = "packet_built",
                packet_built_at = NOW(),
                packet_id = :pid,
                packet_artifact_id = :aid,
                updated_at = NOW()
          WHERE id = :id AND tenant_id = :t'
    )->execute([
        'pid' => $packetId, 'aid' => $artifactId,
        'id'  => $runId, 't' => $tenantId,
    ]);

    return [
        'run_id'      => $runId,
        'packet_id'   => $packetId,
        'artifact_id' => $artifactId,
        'status'      => 'packet_built',
    ];
}

/**
 * Lock the close run — terminal state. Refuses if a packet hasn't
 * been built yet (the packet IS the close artifact; lock without
 * packet means there's nothing to point auditors at).
 */
function closeRunLock(int $tenantId, int $runId, ?int $actorUserId = null): array
{
    $run = closeRunGet($tenantId, $runId);
    if (!$run) throw new \RuntimeException("close run {$runId} not found");
    if ($run['status'] === 'locked') return $run;        // idempotent
    if ($run['status'] !== 'packet_built') {
        throw new \RuntimeException("cannot lock run {$runId} from status '{$run['status']}' — build the packet first");
    }
    getDB()->prepare(
        'UPDATE accounting_close_runs
            SET status = "locked",
                locked_at = NOW(),
                locked_by_user_id = :u,
                updated_at = NOW()
          WHERE id = :id AND tenant_id = :t AND status = "packet_built"'
    )->execute(['u' => $actorUserId, 'id' => $runId, 't' => $tenantId]);

    // Transition the linked artifact to `final` so it locks too.
    if (!empty($run['packet_artifact_id'])) {
        try {
            artifactTransition($tenantId, (string) $run['packet_artifact_id'], 'approved', [
                'actor_user_id' => $actorUserId,
                'note'          => "Close run #{$runId} locked",
            ]);
            artifactTransition($tenantId, (string) $run['packet_artifact_id'], 'final', [
                'actor_user_id' => $actorUserId,
                'note'          => "Close packet finalized with run lock",
            ]);
        } catch (\Throwable $e) {
            error_log('[close_runs] lock-artifact-transition: ' . $e->getMessage());
        }
    }

    return closeRunGet($tenantId, $runId);
}

/**
 * Reopen a locked close run. Writes the supersede row (status='reopened'
 * on the OLD run) AND returns a freshly-started new run on the same
 * period.  The reopen reason is mandatory.
 */
function closeRunReopen(int $tenantId, int $runId, string $reason, ?int $actorUserId = null): array
{
    $run = closeRunGet($tenantId, $runId);
    if (!$run) throw new \RuntimeException("close run {$runId} not found");
    if ($run['status'] !== 'locked') {
        throw new \RuntimeException("only locked runs may be reopened (run {$runId} is '{$run['status']}')");
    }
    if (trim($reason) === '') throw new \InvalidArgumentException('reopen reason required');

    $pdo = getDB();
    $pdo->prepare(
        'UPDATE accounting_close_runs
            SET status = "reopened",
                reopened_at = NOW(),
                reopened_by_user_id = :u,
                reopen_reason = :r,
                updated_at = NOW()
          WHERE id = :id AND tenant_id = :t AND status = "locked"'
    )->execute(['u' => $actorUserId, 'r' => mb_substr($reason, 0, 500),
                'id' => $runId, 't' => $tenantId]);

    return closeRunStart($tenantId, (int) $run['period_id'], $actorUserId);
}

/**
 * List the checklist tasks for a run's period.  Used by the dashboard
 * drill-in panel to render the task table next to the run header.
 */
function closeRunTasks(int $tenantId, int $runId): array
{
    $run = closeRunGet($tenantId, $runId);
    if (!$run) return [];
    $stmt = getDB()->prepare(
        "SELECT id, task_key, title, description, sort_order,
                assignee_user_id, due_date, status, completed_at,
                completed_by_user_id, notes
           FROM accounting_close_tasks
          WHERE tenant_id = :t AND period_id = :p
          ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute(['t' => $tenantId, 'p' => (int) $run['period_id']]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']                   = (int) $r['id'];
        $r['sort_order']           = (int) $r['sort_order'];
        $r['assignee_user_id']     = $r['assignee_user_id']     !== null ? (int) $r['assignee_user_id']     : null;
        $r['completed_by_user_id'] = $r['completed_by_user_id'] !== null ? (int) $r['completed_by_user_id'] : null;
    } unset($r);
    return $rows;
}

/** Internal — coerce SQL string-ish ints into PHP ints / nulls. */
function closeRunNormalizeRow(?array $row): ?array
{
    if (!$row) return null;
    foreach (['id', 'tenant_id', 'sub_tenant_id', 'period_id',
              'total_tasks', 'completed_tasks',
              'started_by_user_id', 'locked_by_user_id', 'reopened_by_user_id',
              'packet_id'] as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null) {
            $row[$k] = (int) $row[$k];
        }
    }
    return $row;
}
