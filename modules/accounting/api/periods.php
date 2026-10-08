<?php
/**
 * Accounting API — Periods + close workflow
 *
 *   GET    /api/accounting/periods?entity_id=N&from=YYYY-MM-DD&to=YYYY-MM-DD
 *   POST   /api/accounting/periods?action=soft_close&id=N
 *   POST   /api/accounting/periods?action=close&id=N
 *   POST   /api/accounting/periods?action=lock&id=N         body: {reason}
 *   POST   /api/accounting/periods?action=reopen&id=N      body: {reason}
 *
 * Lifecycle per SPEC §6:
 *   open → soft_closed (reportable, draftable, no posting) → closed → locked
 *                                                             ↓        ↓
 *                                                           reopen    reopen
 *                                                                  (master_admin only)
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/accounting.php';
require_once __DIR__ . '/../lib/close.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.coa.view');
    $where  = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['entity_id'])) { $where[] = 'entity_id = :e'; $params['e'] = (int) $_GET['entity_id']; }
    $statusFilter = (string) ($_GET['status'] ?? '');
    if ($statusFilter === 'ready_to_close') {
        $where[] = "status = 'open' AND end_date < CURRENT_DATE";
    }
    if (!empty($_GET['from']))      { $where[] = 'end_date   >= :f'; $params['f'] = $_GET['from']; }
    if (!empty($_GET['to']))        { $where[] = 'start_date <= :t'; $params['t'] = $_GET['to']; }
    $rows = scopedQuery(
        'SELECT id, entity_id, period_number, start_date, end_date, status, close_cycle, closed_at, closed_by_user_id, reopened_at, reopen_reason
         FROM accounting_periods WHERE ' . implode(' AND ', $where) . '
         ORDER BY start_date DESC LIMIT 200',
        $params
    );
    api_ok(['entity_id' => !empty($_GET['entity_id']) ? (int) $_GET['entity_id'] : null,
        'status_filter' => $statusFilter, 'rows' => $rows]);
}

if ($method === 'POST' && $action === 'create') {
    // Explicit period creation — operators wanted "Define a period" UI
    // alongside the auto-create-on-post path that `accountingResolvePeriod`
    // uses. Closing and reopening must use their audited actions below.
    rbac_legacy_require($user, 'accounting.period.close'); // same gate as close — admin-ish
    $body = api_json_body();
    $entityRaw = trim((string) ($body['entity_id'] ?? ''));
    $startDate = trim((string) ($body['start_date'] ?? ''));
    $endDate   = trim((string) ($body['end_date']   ?? ''));
    $statusIn  = trim((string) ($body['status']     ?? 'open'));
    $pnumRaw   = trim((string) ($body['period_number'] ?? ''));
    if (!ctype_digit($entityRaw) || (int) $entityRaw <= 0) {
        api_error('entity_id must be a positive whole number', 422);
    }
    if ($pnumRaw !== '' && (!ctype_digit($pnumRaw) || (int) $pnumRaw < 1 || (int) $pnumRaw > 53)) {
        api_error('period_number must be 1 to 53', 422);
    }
    $entityId = (int) $entityRaw;
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
    if (!$start || $start->format('Y-m-d') !== $startDate) api_error('start_date must be a real YYYY-MM-DD date', 422);
    if (!$end || $end->format('Y-m-d') !== $endDate) api_error('end_date must be a real YYYY-MM-DD date', 422);
    if ($startDate > $endDate)                          api_error('start_date must be <= end_date', 422);
    if (!in_array($statusIn, ['open', 'future'], true)) {
        api_error('New periods must be open or future; use the close workflow to change status', 422);
    }
    $periodNumber = $pnumRaw !== '' ? (int) $pnumRaw : (int) $end->format('n');

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        // The entity row is the serialization point for period creation by API and CSV.
        $entity = $pdo->prepare(
            'SELECT id FROM accounting_entities
             WHERE tenant_id = :t AND id = :e AND active = 1 FOR UPDATE'
        );
        $entity->execute(['t' => $tid, 'e' => $entityId]);
        if (!$entity->fetchColumn()) {
            $pdo->rollBack();
            api_error('Choose an active legal entity in this workspace', 422);
        }
        $overlap = $pdo->prepare(
            'SELECT id, period_number, start_date, end_date, status
               FROM accounting_periods
              WHERE tenant_id = :t AND entity_id = :e
                AND start_date <= :ed AND end_date >= :sd
              LIMIT 1 FOR UPDATE'
        );
        $overlap->execute(['t' => $tid, 'e' => $entityId, 'sd' => $startDate, 'ed' => $endDate]);
        if ($existing = $overlap->fetch(\PDO::FETCH_ASSOC)) {
            $pdo->rollBack();
            api_error('Overlapping period already defined', 409, ['existing' => $existing]);
        }
        $pdo->prepare(
            'INSERT INTO accounting_periods
               (tenant_id, entity_id, period_number, start_date, end_date, status)
             VALUES (:t, :e, :n, :s, :x, :st)'
        )->execute([
            't'  => $tid, 'e' => $entityId, 'n' => $periodNumber,
            's'  => $startDate, 'x' => $endDate, 'st' => $statusIn,
        ]);
        $newId = (int) $pdo->lastInsertId();
        accountingAudit('accounting.period.created', [
            'period_id' => $newId, 'entity_id' => $entityId,
            'start_date' => $startDate, 'end_date' => $endDate, 'status' => $statusIn,
        ], $newId);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[accounting.period.create] ' . $e->getMessage());
        api_error('Could not create the period. Check its dates and try again.', 500);
    }
    api_ok(['id' => $newId, 'period_number' => $periodNumber, 'status' => $statusIn], 201);
}

if ($method === 'POST' && in_array($action, ['soft_close','close','lock','reopen'], true)) {
    $perm = $action === 'reopen' ? 'accounting.period.reopen'
          : ($action === 'lock'  ? 'accounting.period.lock'
          : 'accounting.period.close');
    rbac_legacy_require($user, $perm);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $row = scopedFind('SELECT * FROM accounting_periods WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);

    $body   = api_json_body();
    $reason = trim((string) ($body['reason'] ?? ''));

    $pdo = getDB();
    $now = date('Y-m-d H:i:s');
    $reject = static function (string $message, int $status, array $extra = []) use ($pdo): void {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_error($message, $status, $extra);
    };

    try {
        $pdo->beginTransaction();
        // Journals lock the legal entity before resolving the period. Use the
        // same order so close and posting cannot pass each other's status check.
        $entityLock = $pdo->prepare('SELECT id FROM accounting_entities
            WHERE tenant_id = :t AND id = :e FOR UPDATE');
        $entityLock->execute(['t' => $tid, 'e' => (int) $row['entity_id']]);
        if (!$entityLock->fetchColumn()) $reject('Legal entity not found', 404);
        $periodLock = $pdo->prepare('SELECT * FROM accounting_periods
            WHERE tenant_id = :t AND id = :id FOR UPDATE');
        $periodLock->execute(['t' => $tid, 'id' => $id]);
        $lockedRow = $periodLock->fetch(\PDO::FETCH_ASSOC);
        if (!$lockedRow || (int) $lockedRow['entity_id'] !== (int) $row['entity_id']) {
            $reject('Period changed while closing; refresh and retry', 409);
        }
        $row = $lockedRow;

        if (in_array($action, ['soft_close', 'close'], true)) {
            $reviewCount = $pdo->prepare('SELECT COUNT(*) FROM accounting_close_tasks
                WHERE tenant_id = :t AND period_id = :p
                    AND task_key NOT IN ("lock_period", "build_packet")');
            $reviewCount->execute(['t' => $tid, 'p' => $id]);
            if ((int) $reviewCount->fetchColumn() === 0) {
                $reject('Start the month-end checklist before closing this period', 409,
                    ['code' => 'close_checklist_missing']);
            }
        }

    if ($action === 'soft_close') {
        if (!in_array($row['status'], ['open','reopened'], true)) {
            $reject("Cannot soft-close from status {$row['status']}", 409);
        }
        // P1.8 — close-packet blocking gate. Spec re-audit:
        // "Accounting close packets must be wired into the actual close
        //  workflow (not just data files). Owners + due-dates + blocking
        //  gates active." We refuse soft-close while any close task
        //  is still pending / in_progress / blocked. Tasks explicitly
        //  marked 'skipped' or 'done' don't block. Override is allowed
        //  with body.close_with_open_tasks=true + reason — audit-logged.
        $blockers = $pdo->prepare(
            "SELECT id, task_key, title, status, assignee_user_id, due_date
               FROM accounting_close_tasks
              WHERE tenant_id = :t AND period_id = :p
                AND task_key NOT IN ('lock_period','build_packet')
                AND status IN ('pending','in_progress','blocked')
              ORDER BY due_date IS NULL, due_date ASC, sort_order ASC"
        );
        $blockers->execute(['t' => $tid, 'p' => $id]);
        $openTasks = $blockers->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if ($openTasks) {
            $override = !empty($body['close_with_open_tasks']);
            if (!$override || $reason === '') {
                $reject(
                    'Soft-close blocked: ' . count($openTasks)
                    . ' close task(s) still open. Resolve them, mark them skipped,'
                    . ' OR re-submit with close_with_open_tasks=true + reason.',
                    409,
                    ['code' => 'close_tasks_open', 'open_tasks' => $openTasks]
                );
            }
            accountingAudit('accounting.period.soft_close_open_tasks_override', [
                'period_id' => $id, 'open_count' => count($openTasks),
                'reason'    => $reason, 'task_keys' => array_column($openTasks, 'task_key'),
            ], $id);
        }
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE accounting_periods
             SET status = "soft_closed", closed_at = :ts, closed_by_user_id = :u
             WHERE id = :id AND tenant_id = :t'
        )->execute(['ts' => $now, 'u' => $user['id'] ?? null, 'id' => $id, 't' => $tid]);
        accountingAudit('accounting.period.soft_closed', ['period_id' => $id, 'period_number' => (int) $row['period_number']], $id);
    }
    if ($action === 'close') {
        if (!in_array($row['status'], ['open','soft_closed','reopened'], true)) {
            $reject("Cannot close from status {$row['status']}", 409);
        }
        // P1.8 — same blocking gate on hard close.
        $blockers = $pdo->prepare(
            "SELECT id, task_key, title, status, assignee_user_id, due_date
               FROM accounting_close_tasks
              WHERE tenant_id = :t AND period_id = :p
                AND task_key NOT IN ('lock_period','build_packet')
                AND status IN ('pending','in_progress','blocked')
              ORDER BY due_date IS NULL, due_date ASC, sort_order ASC"
        );
        $blockers->execute(['t' => $tid, 'p' => $id]);
        $openTasks = $blockers->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if ($openTasks) {
            $override = !empty($body['close_with_open_tasks']);
            if (!$override || $reason === '') {
                $reject(
                    'Period close blocked: ' . count($openTasks)
                    . ' close task(s) still open. Resolve them, mark them skipped,'
                    . ' OR re-submit with close_with_open_tasks=true + reason.',
                    409,
                    ['code' => 'close_tasks_open', 'open_tasks' => $openTasks]
                );
            }
            accountingAudit('accounting.period.close_open_tasks_override', [
                'period_id' => $id, 'open_count' => count($openTasks),
                'reason'    => $reason, 'task_keys' => array_column($openTasks, 'task_key'),
            ], $id);
        }
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE accounting_periods
             SET status = "closed", closed_at = :ts, closed_by_user_id = :u,
                 close_cycle = close_cycle + 1
             WHERE id = :id AND tenant_id = :t'
        )->execute(['ts' => $now, 'u' => $user['id'] ?? null, 'id' => $id, 't' => $tid]);
        accountingAudit('accounting.period.closed', ['period_id' => $id, 'period_number' => (int) $row['period_number']], $id);
    }
    if ($action === 'lock') {
        if ($reason === '') $reject('reason required to lock a period', 422);
        if ($row['status'] !== 'closed') {
            $reject("Period must be 'closed' before lock; current: {$row['status']}", 409);
        }
        $packet = $pdo->prepare('SELECT p.id FROM accounting_close_packets p
            JOIN accounting_close_packet_snapshots s ON s.packet_id = p.id
                AND s.tenant_id = p.tenant_id AND s.period_id = p.period_id
            WHERE p.tenant_id = :t AND p.period_id = :p AND p.close_cycle = :cycle
            ORDER BY p.id DESC LIMIT 1');
        $packet->execute(['t' => $tid, 'p' => $id, 'cycle' => (int) $row['close_cycle']]);
        $packetId = (int) $packet->fetchColumn();
        if (!$packetId) {
            $reject('Save a close packet before locking this period', 409,
                ['code' => 'close_packet_missing']);
        }
        try {
            accountingLoadRecordedClosePacket($tid, $id, $packetId);
        } catch (\RuntimeException $e) {
            $reject('Saved close packet failed its integrity check', 409,
                ['code' => 'close_packet_integrity']);
        }
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE accounting_periods
             SET status = "locked", locked_at = :ts, locked_by_user_id = :u
             WHERE id = :id AND tenant_id = :t'
        )->execute(['ts' => $now, 'u' => $user['id'] ?? null, 'id' => $id, 't' => $tid]);
        $pdo->prepare('UPDATE accounting_close_tasks
            SET status = "done", completed_at = :ts, completed_by_user_id = :u
            WHERE tenant_id = :t AND period_id = :p AND task_key = "lock_period"
                AND status <> "done"')
            ->execute(['ts' => $now, 'u' => $user['id'] ?? null, 't' => $tid, 'p' => $id]);
        accountingAudit('accounting.period.locked', [
            'period_id' => $id, 'period_number' => (int) $row['period_number'], 'reason' => $reason,
        ], $id);
    }
    if ($action === 'reopen') {
        if ($reason === '') $reject('reason required to reopen a closed period', 422);
        if (!in_array($row['status'], ['closed','soft_closed','locked'], true)) {
            $reject("Cannot reopen from status {$row['status']}", 409);
        }
        // Reopening a locked period requires master_admin per spec §6.
        if ($row['status'] === 'locked' && ($user['role'] ?? '') !== 'master_admin') {
            $reject('Only master_admin can reopen a locked period', 403);
        }
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE accounting_periods
             SET status = "reopened", reopened_at = :ts, reopened_by_user_id = :u, reopen_reason = :r
             WHERE id = :id AND tenant_id = :t'
        )->execute(['ts' => $now, 'u' => $user['id'] ?? null, 'r' => $reason, 'id' => $id, 't' => $tid]);
        accountingAudit('accounting.period.reopened', ['period_id' => $id, 'period_number' => (int) $row['period_number'], 'reason' => $reason], $id);

        // Auto-reverse every locked consolidation run whose period_to
        // falls inside the reopened period. Audit trail is preserved —
        // no deletion, just status = reversed.
        require_once __DIR__ . '/../lib/consolidation.php';
        $runsStmt = $pdo->prepare(
            'SELECT id FROM accounting_consolidation_runs
             WHERE tenant_id = :t AND status = "locked"
               AND period_to >= :sd AND period_to <= :ed'
        );
        $runsStmt->execute(['t' => $tid, 'sd' => $row['start_date'], 'ed' => $row['end_date']]);
        $affected = 0;
        foreach ($runsStmt->fetchAll(\PDO::FETCH_ASSOC) as $rr) {
            try {
                consolidationReverseRun($tid, (int) $rr['id'], 'Period reopened: ' . $reason, $user['id'] ?? null);
                $affected++;
            } catch (\Throwable $_) { /* already reversed */ }
        }
        if ($affected > 0) {
            accountingAudit('accounting.consolidation.runs_auto_reversed', [
                'period_id' => $id, 'reversed_count' => $affected,
            ], $id);
        }
    }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[accounting.period.transition] ' . $e->getMessage());
        api_error('Could not change period status. Refresh and try again.', 500);
    }
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
