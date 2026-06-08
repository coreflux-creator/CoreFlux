<?php
/**
 * core/qbo/retry_queue.php
 *
 * Charter "QBO push retry + dead-letter queue" — helpers used by the
 * procedural QBO sync drivers (sync_je / sync_bills / sync_invoices)
 * to track per-entity failure state in `qbo_push_failures`.
 *
 * Public API:
 *
 *   qboPushFailureCheck($tid, $type, $id) → 'go' | 'skip_backoff' | 'skip_dead_letter'
 *       Called BEFORE pushing. Returns 'go' if the entity may be pushed
 *       now (no failure row, or backoff has elapsed). Returns one of
 *       the two skip codes if the cron should leave it alone.
 *
 *   qboPushFailureRecord($tid, $type, $id, $exception)
 *       Called inside the catch block when a push fails. Increments
 *       attempts, computes the next retry timestamp, and stamps DLQ
 *       once max_attempts is hit. Carries the full vendor body from
 *       QboApiException::$raw[body] (charter primitive #6).
 *
 *   qboPushFailureClear($tid, $type, $id)
 *       Called after a successful push. Stamps cleared_at so the row
 *       is removed from any auto-retry pickup and stays as an audit
 *       trail of past failures.
 *
 * Backoff schedule (matches the adapter-based outbox in
 * core/accounting/command_service.php):
 *   attempt 1 → +30s
 *   attempt 2 → +1m
 *   attempt 3 → +2m
 *   attempt 4 → +4m
 *   attempt 5 → +8m
 *   attempt 6 → dead_letter (no further auto-retries)
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/client.php'; // for QboApiException

const QBO_PUSH_MAX_ATTEMPTS       = 5;
const QBO_PUSH_BACKOFF_BASE_SEC   = 30; // first retry after 30s, doubles each time

function qboPushFailureCheck(int $tenantId, string $entityType, int $sourceId, ?int $subTenantId = null): string
{
    $sql = 'SELECT status, next_retry_at FROM qbo_push_failures
             WHERE tenant_id = :t AND entity_type = :e AND source_id = :s AND cleared_at IS NULL';
    $params = ['t' => $tenantId, 'e' => $entityType, 's' => $sourceId];
    if ($subTenantId === null) {
        $sql .= ' AND sub_tenant_id IS NULL';
    } else {
        $sql .= ' AND sub_tenant_id = :st';
        $params['st'] = $subTenantId;
    }
    $sql .= ' LIMIT 1';
    try {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $_) {
        // Table missing (migration 113 not yet applied) — fail-open so
        // the legacy push behaviour still runs.
        return 'go';
    }
    if (!$row) return 'go';
    if ($row['status'] === 'dead_letter') return 'skip_dead_letter';
    $next = $row['next_retry_at'] ? strtotime((string) $row['next_retry_at']) : 0;
    if ($next > time()) return 'skip_backoff';
    return 'go';
}

function qboPushFailureRecord(int $tenantId, string $entityType, int $sourceId, \Throwable $e, ?int $subTenantId = null): void
{
    $now = date('Y-m-d H:i:s');
    $http  = ($e instanceof QboApiException) ? (int)    $e->httpStatus : null;
    $code  = ($e instanceof QboApiException) ? (string) ($e->errorCode ?? '') : null;
    $raw   = ($e instanceof QboApiException && is_array($e->raw))
                ? (string) ($e->raw['body'] ?? '')
                : null;
    $msg   = substr($e->getMessage(), 0, 500);

    try {
        $pdo = getDB();
        // Fetch existing row for attempts count.
        $sel = 'SELECT id, attempts, max_attempts FROM qbo_push_failures
                 WHERE tenant_id = :t AND entity_type = :e AND source_id = :s';
        $selParams = ['t' => $tenantId, 'e' => $entityType, 's' => $sourceId];
        if ($subTenantId === null) {
            $sel .= ' AND sub_tenant_id IS NULL';
        } else {
            $sel .= ' AND sub_tenant_id = :st';
            $selParams['st'] = $subTenantId;
        }
        $sel .= ' LIMIT 1';
        $stmt = $pdo->prepare($sel);
        $stmt->execute($selParams);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $attempts = $row ? ((int) $row['attempts'] + 1) : 1;
        $max      = $row ? (int) $row['max_attempts'] : QBO_PUSH_MAX_ATTEMPTS;
        $dead     = $attempts >= $max;
        $nextRetryAt = $dead
            ? null
            : date('Y-m-d H:i:s', time() + (int) (QBO_PUSH_BACKOFF_BASE_SEC * (2 ** ($attempts - 1))));
        $status = $dead ? 'dead_letter' : 'retrying';

        if ($row) {
            // tenant-leak-allow: defense-in-depth — primary id from tenant-scoped query above
            $pdo->prepare(
                'UPDATE qbo_push_failures
                    SET attempts = :a, status = :st,
                        last_error_code = :ec, last_error_message = :em,
                        vendor_raw = :raw, last_http_status = :http,
                        next_retry_at = :nr, last_failed_at = :lf, updated_at = :ua
                  WHERE id = :id'
            )->execute([
                'a' => $attempts, 'st' => $status,
                'ec' => $code, 'em' => $msg, 'raw' => $raw, 'http' => $http,
                'nr' => $nextRetryAt, 'lf' => $now, 'ua' => $now, 'id' => (int) $row['id'],
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO qbo_push_failures
                    (tenant_id, sub_tenant_id, entity_type, source_id,
                     attempts, max_attempts, status,
                     last_error_code, last_error_message, vendor_raw, last_http_status,
                     next_retry_at, first_failed_at, last_failed_at,
                     created_at, updated_at)
                 VALUES (:t, :st, :e, :s, :a, :m, :status, :ec, :em, :raw, :http,
                         :nr, :ff, :lf, :ca, :ua)'
            )->execute([
                't' => $tenantId, 'st' => $subTenantId,
                'e' => $entityType, 's' => $sourceId,
                'a' => $attempts, 'm' => QBO_PUSH_MAX_ATTEMPTS, 'status' => $status,
                'ec' => $code, 'em' => $msg, 'raw' => $raw, 'http' => $http,
                'nr' => $nextRetryAt,
                'ff' => $now, 'lf' => $now, 'ca' => $now, 'ua' => $now,
            ]);
        }
    } catch (\Throwable $_) {
        // Failure-of-the-failure-recorder MUST never block the sync
        // driver — the original audit log already captured the error.
    }
}

function qboPushFailureClear(int $tenantId, string $entityType, int $sourceId, ?int $subTenantId = null): void
{
    $sql = 'UPDATE qbo_push_failures
               SET cleared_at = :ca, updated_at = :ua,
                   status = "retrying", next_retry_at = NULL
             WHERE tenant_id = :t AND entity_type = :e AND source_id = :s AND cleared_at IS NULL';
    $now = date('Y-m-d H:i:s');
    $params = ['ca' => $now, 'ua' => $now, 't' => $tenantId, 'e' => $entityType, 's' => $sourceId];
    if ($subTenantId === null) {
        $sql .= ' AND sub_tenant_id IS NULL';
    } else {
        $sql .= ' AND sub_tenant_id = :st';
        $params['st'] = $subTenantId;
    }
    try {
        getDB()->prepare($sql)->execute($params);
    } catch (\Throwable $_) { /* table missing — no-op */ }
}

/**
 * Operator action: requeue a dead-lettered entity for one more push pass.
 * Used by the admin DLQ UI. Resets attempts to 0 and clears status back
 * to 'retrying' with no backoff (push happens on the next sync cron).
 */
function qboPushFailureRequeue(int $tenantId, string $entityType, int $sourceId, ?int $subTenantId = null): bool
{
    $sql = 'UPDATE qbo_push_failures
               SET attempts = 0, status = "retrying",
                   next_retry_at = NULL, updated_at = :now
             WHERE tenant_id = :t AND entity_type = :e AND source_id = :s AND status = "dead_letter"';
    $params = ['now' => date('Y-m-d H:i:s'), 't' => $tenantId, 'e' => $entityType, 's' => $sourceId];
    if ($subTenantId === null) {
        $sql .= ' AND sub_tenant_id IS NULL';
    } else {
        $sql .= ' AND sub_tenant_id = :st';
        $params['st'] = $subTenantId;
    }
    try {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    } catch (\Throwable $_) { return false; }
}
