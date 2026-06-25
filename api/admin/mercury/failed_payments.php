<?php
/**
 * GET  /api/admin/mercury/failed_payments.php
 *      → List Failed (and optionally Returned) payment_instructions
 *        enriched with the mercury error playbook entry so operators
 *        get a one-line "Suggested fix" without grepping mp_events.
 *
 * POST /api/admin/mercury/failed_payments.php
 *      → Requeue a Failed PI back to Approved. Body:
 *           { tenant_id, instruction_id, reason }
 *      → SoD note: the original two-eye approval is preserved; this is
 *        a remediation re-run, not a fresh approval. RBAC gates to
 *        master_admin / tenant_admin only.
 *
 * Parallel to /api/admin/qbo/dead_letters.php — same UX pattern, same
 * playbook-shape enrichment, same audit semantics.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../../core/mercury_payments.php';
require_once __DIR__ . '/../../../core/mercury/error_playbook.php';

$ctx = api_require_auth();
rbac_legacy_require_any($currentUser ?? $ctx, ['master_admin', 'tenant_admin', '*']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $tenantId = isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : (int) ($ctx['tenant_id'] ?? 0);
    if ($tenantId <= 0) { http_response_code(400); api_error('tenant_id required', 400); }

    $stateFilter = $_GET['state'] ?? 'Failed'; // Failed | Returned | all
    $limit       = max(1, min(200, (int) ($_GET['limit'] ?? 50)));

    $sql = 'SELECT id, tenant_id, sub_tenant_id, state, state_reason,
                   recipient_id, amount_cents, currency, source_module, source_ref,
                   funding_mercury_txn_id, funding_mercury_status,
                   payout_mercury_txn_id,  payout_mercury_status,
                   state_changed_at, created_at
              FROM payment_instructions
             WHERE tenant_id = :t';
    $params = ['t' => $tenantId];
    if ($stateFilter !== 'all') {
        $sql .= ' AND state = :s';
        $params['s'] = $stateFilter;
    } else {
        $sql .= " AND state IN ('Failed','Returned')";
    }
    $sql .= ' ORDER BY state_changed_at DESC LIMIT ' . (int) $limit;

    try {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $rows = [];
    }

    // For each PI, find the most recent mp_event with vendor_raw / error_code
    // and pull the playbook entry. The state_reason is a truncated copy;
    // mp_events carries the full vendor body (charter primitive #6).
    foreach ($rows as &$r) {
        $err = null;
        try {
            // tenant-leak-allow: payment_instruction_audit is a child of
            // payment_instructions, scoped via FK. The PI was already
            // tenant-filtered in the parent SELECT above (line ~62).
            $evStmt = getDB()->prepare(
                'SELECT meta_json FROM payment_instruction_audit
                  WHERE tenant_id = :t
                    AND instruction_id = :id
                    AND meta_json LIKE :pat
               ORDER BY id DESC LIMIT 1'
            );
            $evStmt->execute(['t' => $tenantId, 'id' => (int) $r['id'], 'pat' => '%vendor_raw%']);
            $ev = $evStmt->fetch(\PDO::FETCH_ASSOC);
            if ($ev) {
                $meta = json_decode((string) $ev['meta_json'], true) ?: [];
                $err = [
                    'vendor_error_code' => $meta['vendor_error_code'] ?? null,
                    'http_status'       => $meta['http_status'] ?? null,
                    'vendor_raw'        => $meta['vendor_raw'] ?? null,
                    'stage'             => $meta['stage'] ?? null,
                ];
            }
        } catch (\Throwable $_) { /* table missing — leave $err null */ }

        $r['last_error']  = $err;
        $r['playbook']    = mercuryErrorPlaybookLookup($err['vendor_error_code'] ?? null);
        $r['can_requeue'] = ($r['state'] === 'Failed');
    }
    unset($r);

    api_ok([
        'items'        => $rows,
        'filters'      => ['tenant_id' => $tenantId, 'state' => $stateFilter, 'limit' => $limit],
        'generated_at' => gmdate('c'),
    ]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $tenantId      = (int) ($body['tenant_id'] ?? ($ctx['tenant_id'] ?? 0));
    $instructionId = (int) ($body['instruction_id'] ?? 0);
    $reason        = trim((string) ($body['reason'] ?? ''));

    if (!$tenantId || !$instructionId || $reason === '') {
        http_response_code(400);
        api_error('tenant_id, instruction_id and reason are required', 400);
    }
    $userId = (int) ($ctx['user']['id'] ?? $ctx['user_id'] ?? 0);
    if ($userId <= 0) { http_response_code(401); api_error('Authenticated user id missing', 401); }

    try {
        $ok = mpRequeueFailed($tenantId, $instructionId, $userId, $reason);
    } catch (\Throwable $e) {
        http_response_code(409);
        api_error('Requeue refused: ' . substr($e->getMessage(), 0, 220), 409);
    }
    if (!$ok) {
        http_response_code(500);
        api_error('Requeue transition refused by state machine', 500);
    }
    api_ok([
        'requeued'       => true,
        'instruction_id' => $instructionId,
        'new_state'      => 'Approved',
        'message'        => 'Payment instruction reset to Approved; next mpAdvance cron will originate fresh.',
    ]);
}

http_response_code(405);
api_error('GET or POST only', 405);
