<?php
/**
 * Unified Exception Queue helper (Phase 1d — 2026-02-14).
 *
 * Public API:
 *   exceptionOpen($tenantId, $source, $args)
 *     Insert a new exception_queue row. $args = {
 *       title (required), severity?, subject_type?, subject_id?, payload?,
 *       opened_by_user_id?, assigned_user_id?
 *     }
 *     Returns ['id' => int, 'skipped' => bool].
 *
 *   exceptionList($tenantId, $filters = [])
 *     Reads the v_unified_exception_queue view. Filters:
 *       source, severity, subject_type, subject_id, feed, limit, offset.
 *
 *   exceptionResolve($tenantId, $queueId, $userId, $note)
 *   exceptionSnooze($tenantId, $queueId, $userId, $until)
 *   exceptionDismiss($tenantId, $queueId, $userId, $note)
 *   exceptionAssign($tenantId, $queueId, $assigneeUserId)
 *
 * Backwards-compat: every function returns gracefully when the
 * exception_queue table or view is missing (returns 0 / [] / false).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function _exceptionQueueTableExists(?\PDO $pdo = null): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    $pdo = $pdo ?: getDB();
    try {
        $pdo->query('SELECT 1 FROM exception_queue LIMIT 0');
        return $cache = true;
    } catch (\Throwable $_) {
        return $cache = false;
    }
}

function exceptionOpen(int $tenantId, string $source, array $args): array {
    if (!_exceptionQueueTableExists()) return ['id' => 0, 'skipped' => true];

    $title = trim((string) ($args['title'] ?? ''));
    if ($title === '') throw new \InvalidArgumentException('exceptionOpen: title required');

    $severity = (string) ($args['severity'] ?? 'warn');
    if (!in_array($severity, ['info','warn','high','critical'], true)) $severity = 'warn';

    $pdo = getDB();
    $pdo->prepare(
        "INSERT INTO exception_queue
            (tenant_id, source, severity, subject_type, subject_id,
             title, payload, opened_by_user_id, assigned_user_id)
         VALUES (:t, :s, :sev, :st, :sid, :title, :p, :ob, :au)"
    )->execute([
        't'    => $tenantId,
        's'    => $source,
        'sev'  => $severity,
        'st'   => $args['subject_type'] ?? null,
        'sid'  => isset($args['subject_id']) ? (int) $args['subject_id'] : null,
        'title'=> $title,
        'p'    => isset($args['payload']) ? json_encode($args['payload']) : null,
        'ob'   => isset($args['opened_by_user_id'])   ? (int) $args['opened_by_user_id']   : null,
        'au'   => isset($args['assigned_user_id'])    ? (int) $args['assigned_user_id']    : null,
    ]);
    return ['id' => (int) $pdo->lastInsertId(), 'skipped' => false];
}

function exceptionList(int $tenantId, array $filters = []): array {
    $pdo  = getDB();
    try {
        $where  = ['tenant_id = :t'];
        $params = ['t' => $tenantId];
        foreach (['source','severity','subject_type','feed'] as $k) {
            if (!empty($filters[$k])) {
                $where[]      = "{$k} = :{$k}";
                $params[$k]   = $filters[$k];
            }
        }
        if (!empty($filters['subject_id'])) {
            $where[]              = 'subject_id = :sid';
            $params['sid']        = (int) $filters['subject_id'];
        }
        $limit  = max(1, min(500, (int) ($filters['limit']  ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $stmt = $pdo->prepare(
            "SELECT * FROM v_unified_exception_queue
              WHERE " . implode(' AND ', $where) . "
              ORDER BY FIELD(severity, 'critical','high','warn','info'),
                       surfaced_at DESC
              LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $_) {
        return [];   // view missing — degrade gracefully
    }
}

function exceptionResolve(int $tenantId, int $queueId, int $userId, ?string $note = null): bool {
    if (!_exceptionQueueTableExists()) return false;
    $stmt = getDB()->prepare(
        "UPDATE exception_queue
            SET status = 'resolved', resolved_at = NOW(),
                resolved_by_user_id = :u, resolution_note = :n
          WHERE id = :id AND tenant_id = :t AND status IN ('open','snoozed')"
    );
    $stmt->execute(['id' => $queueId, 't' => $tenantId, 'u' => $userId, 'n' => $note]);
    return $stmt->rowCount() > 0;
}

function exceptionSnooze(int $tenantId, int $queueId, int $userId, string $until): bool {
    if (!_exceptionQueueTableExists()) return false;
    $stmt = getDB()->prepare(
        "UPDATE exception_queue
            SET status = 'snoozed', snoozed_until = :u, assigned_user_id = COALESCE(assigned_user_id, :uid)
          WHERE id = :id AND tenant_id = :t AND status = 'open'"
    );
    $stmt->execute(['id' => $queueId, 't' => $tenantId, 'u' => $until, 'uid' => $userId]);
    return $stmt->rowCount() > 0;
}

function exceptionDismiss(int $tenantId, int $queueId, int $userId, ?string $note = null): bool {
    if (!_exceptionQueueTableExists()) return false;
    $stmt = getDB()->prepare(
        "UPDATE exception_queue
            SET status = 'dismissed', resolved_at = NOW(),
                resolved_by_user_id = :u, resolution_note = :n
          WHERE id = :id AND tenant_id = :t AND status IN ('open','snoozed')"
    );
    $stmt->execute(['id' => $queueId, 't' => $tenantId, 'u' => $userId, 'n' => $note]);
    return $stmt->rowCount() > 0;
}

function exceptionAssign(int $tenantId, int $queueId, int $assigneeUserId): bool {
    if (!_exceptionQueueTableExists()) return false;
    $stmt = getDB()->prepare(
        "UPDATE exception_queue
            SET assigned_user_id = :a
          WHERE id = :id AND tenant_id = :t"
    );
    $stmt->execute(['id' => $queueId, 't' => $tenantId, 'a' => $assigneeUserId]);
    return $stmt->rowCount() > 0;
}

function exceptionSummary(int $tenantId): array {
    try {
        $stmt = getDB()->prepare(
            "SELECT severity, feed, COUNT(*) AS n
               FROM v_unified_exception_queue
              WHERE tenant_id = :t
           GROUP BY severity, feed"
        );
        $stmt->execute(['t' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $_) {
        return [];
    }
}
