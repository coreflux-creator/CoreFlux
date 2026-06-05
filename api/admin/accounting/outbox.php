<?php
/**
 * /api/admin/accounting/outbox.php — accounting outbox admin surface.
 *
 *   GET    ?status=…&limit=…   list outbox rows for the tenant
 *   GET    ?action=detail&id=N row detail (payload + provider_result)
 *   POST   ?action=retry       body: {id} — nudge a row back to retrying NOW
 *   POST   ?action=cancel      body: {id} — flip a queued/retrying row to dead_letter
 *
 * RBAC: accounting.connection.view for GET, accounting.commands.execute
 * for POST (retry/cancel are operational actions).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../../core/accounting/command_service.php';
require_once __DIR__ . '/../../../core/accounting/account_mapping_service.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET' && $action === '') {
    rbac_legacy_require($user, 'accounting.connection.view');
    $status = (string) ($_GET['status'] ?? '');
    $limit  = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
    $sql = "SELECT id, sub_tenant_id, provider, command_type, status,
                   attempts, max_attempts, idempotency_key,
                   error_code, error_message, next_retry_at,
                   posted_at, created_at, updated_at,
                   JSON_EXTRACT(command_payload, '$.coreflux_object_type') AS object_type,
                   JSON_EXTRACT(command_payload, '$.coreflux_object_id')   AS object_id
              FROM accounting_outbox_events
             WHERE tenant_id = :t";
    $params = ['t' => $tid];
    if ($status !== '' && in_array($status, ['queued','processing','posted','failed','retrying','dead_letter'], true)) {
        $sql .= ' AND status = :s';
        $params['s'] = $status;
    }
    $sql .= " ORDER BY id DESC LIMIT {$limit}";
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        foreach (['id','sub_tenant_id','attempts','max_attempts'] as $k) $r[$k] = (int) $r[$k];
        $r['object_type'] = $r['object_type'] ? trim((string) $r['object_type'], '"') : null;
        $r['object_id']   = $r['object_id']   !== null ? (int) trim((string) $r['object_id'], '"') : null;
    }
    unset($r);

    // Status rollup so the UI can show badges at a glance.
    $byStatus = [];
    $sum = getDB()->prepare(
        'SELECT status, COUNT(*) c FROM accounting_outbox_events
          WHERE tenant_id = :t GROUP BY status'
    );
    $sum->execute(['t' => $tid]);
    foreach ($sum->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
        $byStatus[$r['status']] = (int) $r['c'];
    }

    // Unmapped-account heads-up. The Jaz pushes that fail with
    // "account #N is not linked to Jaz" originate from CoreFlux
    // accounts that don't have a row in accounting_account_mappings
    // yet. Surface a count per (provider, sub_tenant) pair currently
    // active in the outbox so the operator can fix the mapping grid
    // BEFORE pushes start failing. Banner is purely informational —
    // the resolver fallback shipped in jaz_payload_mapper handles
    // the case where a mapping was already added.
    $unmappedByProvider = [];
    $activeProviders = getDB()->prepare(
        "SELECT DISTINCT provider, sub_tenant_id
           FROM accounting_outbox_events
          WHERE tenant_id = :t
            AND status IN ('queued','processing','retrying','failed','dead_letter')
          LIMIT 50"
    );
    $activeProviders->execute(['t' => $tid]);
    foreach ($activeProviders->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
        $prov = (string) $row['provider'];
        $st   = (int)    $row['sub_tenant_id'];
        $unm  = accountingAccountMappingsUnmapped($tid, $st, $prov);
        if (!isset($unmappedByProvider[$prov])) {
            $unmappedByProvider[$prov] = ['total' => 0, 'by_sub_tenant' => []];
        }
        $count = count($unm);
        $unmappedByProvider[$prov]['total'] += $count;
        $unmappedByProvider[$prov]['by_sub_tenant'][$st] = $count;
    }

    api_ok([
        'rows' => $rows,
        'by_status' => $byStatus,
        'unmapped_by_provider' => $unmappedByProvider,
    ]);
}

if ($method === 'GET' && $action === 'detail') {
    rbac_legacy_require($user, 'accounting.connection.view');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $cmd = accountingCommandGetStatus($tid, $id);
    if (!$cmd) api_error('not found', 404);
    api_ok(['command' => $cmd]);
}

if ($method === 'POST' && $action === 'retry') {
    rbac_legacy_require($user, 'accounting.commands.execute');
    $body = api_json_body();
    $id   = (int) ($body['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    // Pull current row to make sure it's eligible.
    $row = accountingCommandGetStatus($tid, $id);
    if (!$row) api_error('not found', 404);
    if (!in_array($row['status'], ['failed','retrying','dead_letter'], true)) {
        api_error("cannot retry from status '{$row['status']}'", 422);
    }
    // Reset state so the next worker tick (or execute_command call)
    // re-fires the adapter. attempts counter resets when retrying
    // from dead_letter so the operator gets a fresh 5-attempt budget.
    $resetAttempts = $row['status'] === 'dead_letter' ? 0 : (int) $row['attempts'];
    getDB()->prepare(
        "UPDATE accounting_outbox_events
            SET status        = 'retrying',
                next_retry_at = NOW(),
                attempts      = :a,
                error_code    = NULL,
                error_message = NULL
          WHERE id = :id AND tenant_id = :t"
    )->execute(['a' => $resetAttempts, 'id' => $id, 't' => $tid]);
    // Inline kick: don't make the operator wait a full cron tick.
    try {
        $after = accountingCommandExecute($tid, $id);
        api_ok(['command' => $after, 'kicked_inline' => true]);
    } catch (\Throwable $e) {
        api_ok(['command' => accountingCommandGetStatus($tid, $id), 'kicked_inline' => false,
                'kick_error' => substr($e->getMessage(), 0, 240)]);
    }
}

if ($method === 'POST' && $action === 'cancel') {
    rbac_legacy_require($user, 'accounting.commands.execute');
    $body = api_json_body();
    $id   = (int) ($body['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $row = accountingCommandGetStatus($tid, $id);
    if (!$row) api_error('not found', 404);
    if (!in_array($row['status'], ['queued','retrying','failed'], true)) {
        api_error("cannot cancel from status '{$row['status']}'", 422);
    }
    getDB()->prepare(
        "UPDATE accounting_outbox_events
            SET status        = 'dead_letter',
                error_code    = COALESCE(error_code, 'cancelled_by_operator'),
                error_message = COALESCE(error_message, 'Cancelled by operator')
          WHERE id = :id AND tenant_id = :t"
    )->execute(['id' => $id, 't' => $tid]);
    api_ok(['command' => accountingCommandGetStatus($tid, $id)]);
}

api_error("unknown action '{$action}' or wrong HTTP method", 400);
