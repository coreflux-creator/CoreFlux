<?php
/**
 * /api/review_flags.php — flag/resolve report-row review items.
 *
 *   GET    /api/review_flags.php?entity_type=placement&entity_id=N
 *                                                  list flags for one entity
 *   GET    /api/review_flags.php?entity_type=placement&status=open
 *                                                  list flags for entity type
 *   POST   /api/review_flags.php                   { entity_type, entity_id,
 *                                                    reason_code, notes,
 *                                                    severity }  → flag
 *   PATCH  /api/review_flags.php?id=N              { status: 'resolved'|'dismissed',
 *                                                    notes? }
 *   DELETE /api/review_flags.php?id=N              soft-removes (status='dismissed')
 *
 * Permission: manager+ (same as exec dashboard).
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';

$ctx     = api_require_auth();
$user    = $ctx['user'];
$actorId = (int) ($user['id'] ?? 0);
$role    = $ctx['role'] ?? 'employee';
$tenantId = (int) (currentTenantId() ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

if (!in_array($role, ['master_admin', 'tenant_admin', 'admin', 'manager'], true)) {
    api_error('Forbidden — manager+ required', 403);
}

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

const _RF_ENTITY_TYPES = ['placement','invoice','bill','person','recruiter'];
const _RF_SEVERITIES   = ['info','warn','critical'];
const _RF_STATUSES     = ['open','resolved','dismissed'];
const _RF_REASON_CODES = [
    'low_margin','stale_unsigned_timesheet','rate_outdated',
    'overdue_invoice','vendor_dispute','missing_data','other',
];

$method = api_method();
$id     = (int) api_query('id', 0);

/* ---------- GET ---------- */
if ($method === 'GET') {
    $entityType = (string) api_query('entity_type', '');
    $entityId   = (int)    api_query('entity_id', 0);
    $status     = (string) api_query('status', '');

    if ($entityType !== '' && !in_array($entityType, _RF_ENTITY_TYPES, true)) {
        api_error('Unknown entity_type', 422);
    }

    $where  = ['rf.tenant_id = :t'];
    $params = ['t' => $tenantId];
    if ($entityType !== '') { $where[] = 'rf.entity_type = :et'; $params['et'] = $entityType; }
    if ($entityId)          { $where[] = 'rf.entity_id   = :ei'; $params['ei'] = $entityId; }
    if ($status !== '' && in_array($status, _RF_STATUSES, true)) {
        $where[] = 'rf.status = :s'; $params['s'] = $status;
    }

    $stmt = $pdo->prepare(
        "SELECT rf.id, rf.entity_type, rf.entity_id, rf.reason_code, rf.notes,
                rf.severity, rf.status, rf.flagged_by, rf.resolved_by,
                rf.ai_summary, rf.ai_confidence, rf.ai_source,
                rf.created_at, rf.resolved_at,
                fb.name AS flagged_by_name, rb.name AS resolved_by_name
           FROM review_flags rf
      LEFT JOIN users fb ON fb.id = rf.flagged_by
      LEFT JOIN users rb ON rb.id = rf.resolved_by
          WHERE " . implode(' AND ', $where) . "
       ORDER BY rf.created_at DESC
          LIMIT 500"
    );
    $stmt->execute($params);
    api_ok(['flags' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ---------- POST (flag) ---------- */
if ($method === 'POST') {
    $body = api_json_body();
    api_require_fields($body, ['entity_type', 'entity_id', 'reason_code']);

    $entityType = (string) $body['entity_type'];
    $entityId   = (int)    $body['entity_id'];
    $reason     = (string) $body['reason_code'];
    $severity   = (string) ($body['severity'] ?? 'warn');
    $notes      = trim((string) ($body['notes'] ?? ''));

    if (!in_array($entityType, _RF_ENTITY_TYPES, true)) api_error('Unknown entity_type', 422);
    if (!in_array($severity,   _RF_SEVERITIES,   true)) api_error('Unknown severity',   422);
    // reason_code is open vocabulary but we recommend the canonical list.
    if ($reason === '' || strlen($reason) > 64) api_error('reason_code required (≤64 chars)', 422);

    // Idempotency: if an open flag with the same (entity, reason) exists,
    // bump its notes/severity instead of creating a duplicate.
    $stmt = $pdo->prepare(
        "SELECT id FROM review_flags
          WHERE tenant_id = :t AND entity_type = :et AND entity_id = :ei
            AND reason_code = :r AND status = 'open' LIMIT 1"
    );
    $stmt->execute(['t' => $tenantId, 'et' => $entityType, 'ei' => $entityId, 'r' => $reason]);
    $existingId = (int) ($stmt->fetchColumn() ?: 0);
    if ($existingId) {
        $pdo->prepare(
            "UPDATE review_flags SET notes = :n, severity = :s, updated_at = NOW()
              WHERE id = :id"
        )->execute(['n' => $notes ?: null, 's' => $severity, 'id' => $existingId]);
        api_ok(['id' => $existingId, 'updated' => true]);
    }

    $pdo->prepare(
        "INSERT INTO review_flags
            (tenant_id, entity_type, entity_id, reason_code, notes, severity, flagged_by)
         VALUES (:t, :et, :ei, :r, :n, :s, :fb)"
    )->execute([
        't'  => $tenantId, 'et' => $entityType, 'ei' => $entityId,
        'r'  => $reason,   'n'  => $notes ?: null, 's' => $severity, 'fb' => $actorId,
    ]);

    api_ok(['id' => (int) $pdo->lastInsertId()], 201);
}

/* ---------- PATCH (resolve / dismiss) ---------- */
if ($method === 'PATCH' && $id) {
    $body = api_json_body();
    $newStatus = (string) ($body['status'] ?? '');
    if (!in_array($newStatus, ['resolved', 'dismissed'], true)) {
        api_error('status must be resolved or dismissed', 422);
    }

    $stmt = $pdo->prepare("SELECT id FROM review_flags WHERE id = :id AND tenant_id = :t LIMIT 1");
    $stmt->execute(['id' => $id, 't' => $tenantId]);
    if (!$stmt->fetchColumn()) api_error('Flag not found', 404);

    $sets = ["status = :s", "resolved_by = :rb", "resolved_at = NOW()"];
    $params = ['s' => $newStatus, 'rb' => $actorId, 'id' => $id];
    if (array_key_exists('notes', $body)) {
        $sets[] = 'notes = :n'; $params['n'] = trim((string) $body['notes']) ?: null;
    }

    $pdo->prepare("UPDATE review_flags SET " . implode(', ', $sets) . ", updated_at = NOW()
                    WHERE id = :id")->execute($params);
    api_ok(['id' => $id, 'status' => $newStatus]);
}

/* ---------- DELETE ---------- */
if ($method === 'DELETE' && $id) {
    $pdo->prepare(
        "UPDATE review_flags SET status = 'dismissed', resolved_by = :rb, resolved_at = NOW()
          WHERE id = :id AND tenant_id = :t"
    )->execute(['rb' => $actorId, 'id' => $id, 't' => $tenantId]);
    api_ok(['id' => $id, 'status' => 'dismissed']);
}

api_error('Method not allowed', 405);
