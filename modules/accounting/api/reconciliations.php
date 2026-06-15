<?php
/**
 * Accounting API — Bank reconciliations (workflow + packet).
 *
 *   GET  /api/accounting/reconciliations?bank_account_id=N
 *   GET  /api/accounting/reconciliations?id=N                  → header + counts
 *   POST /api/accounting/reconciliations?action=open           body: {bank_account_id, period_end, statement_balance, gl_balance, notes?}
 *   POST /api/accounting/reconciliations?action=close&id=N     body: {statement_balance?, gl_balance?, notes?}
 *   POST /api/accounting/reconciliations?action=reopen&id=N    body: {reason}
 *   GET  /api/accounting/reconciliations?action=packet&id=N    → structured packet (matched + unmatched + totals)
 *   POST /api/accounting/reconciliations?action=generate_ai_narrative&id=N
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/accounting.php';
require_once __DIR__ . '/../lib/reconciliation_packet.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$uid    = (int) ($user['id'] ?? 0) ?: null;
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

$db = getDB();

// ── GET list ──────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === '' && empty($_GET['id'])) {
    rbac_legacy_require($user, 'accounting.bank.reconcile');
    $where  = ['tenant_id = :t']; $params = ['t' => $tid];
    if (!empty($_GET['bank_account_id'])) {
        $where[] = 'bank_account_id = :b';
        $params['b'] = (int) $_GET['bank_account_id'];
    }
    $stmt = $db->prepare(
        'SELECT id, bank_account_id, period_end, statement_balance, gl_balance, difference,
                status, opened_at, opened_by_user_id, closed_at, closed_by_user_id,
                reopened_at, reopened_by_user_id, reopen_reason, notes,
                ai_narrative_generated_at
         FROM accounting_reconciliations WHERE ' . implode(' AND ', $where) . '
         ORDER BY period_end DESC, id DESC LIMIT 200'
    );
    $stmt->execute($params);
    api_ok(['rows' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
}

// ── GET detail ────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === '' && !empty($_GET['id'])) {
    rbac_legacy_require($user, 'accounting.bank.reconcile');
    $id  = (int) $_GET['id'];
    $row = scopedFind('SELECT * FROM accounting_reconciliations WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    api_ok(['reconciliation' => $row]);
}

// ── action=packet ─────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'packet') {
    rbac_legacy_require($user, 'accounting.bank.reconcile');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    try {
        $packet = reconciliationPacketBuild($tid, $id);
    } catch (\RuntimeException $e) {
        api_error($e->getMessage(), 404);
    }
    accountingAudit('accounting.reconciliation.packet_built', [
        'reconciliation_id' => $id,
        'matched_count'     => count($packet['matched']),
        'unmatched_count'   => count($packet['unmatched']),
    ], $id);
    api_ok($packet);
}

// ── action=open ───────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'open') {
    rbac_legacy_require($user, 'accounting.bank.reconcile');
    $body = api_json_body();
    api_require_fields($body, ['bank_account_id','period_end']);
    $now  = date('Y-m-d H:i:s');
    $data = [
        'tenant_id'         => $tid,
        'bank_account_id'   => (int) $body['bank_account_id'],
        'period_end'        => (string) $body['period_end'],
        'statement_balance' => (float) ($body['statement_balance'] ?? 0),
        'gl_balance'        => (float) ($body['gl_balance']        ?? 0),
        'difference'        => (float) ($body['statement_balance'] ?? 0) - (float) ($body['gl_balance'] ?? 0),
        'status'            => 'open',
        'opened_at'         => $now,
        'opened_by_user_id' => $uid,
        'notes'             => $body['notes'] ?? null,
    ];
    $cols = array_keys($data);
    $ph   = array_map(fn($c) => ':' . $c, $cols);
    $db->prepare('INSERT INTO accounting_reconciliations (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $ph) . ')')
        ->execute($data);
    $newId = (int) $db->lastInsertId();
    accountingAudit('accounting.reconciliation.opened', [
        'reconciliation_id' => $newId,
        'bank_account_id'   => (int) $body['bank_account_id'],
        'period_end'        => (string) $body['period_end'],
    ], $newId);
    api_ok(['id' => $newId], 201);
}

// ── action=close ──────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'close') {
    rbac_legacy_require($user, 'accounting.bank.reconcile');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $row = scopedFind('SELECT * FROM accounting_reconciliations WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if ($row['status'] === 'closed') api_error('Already closed', 409);
    $body = api_json_body();
    $sb = isset($body['statement_balance']) ? (float) $body['statement_balance'] : (float) $row['statement_balance'];
    $gb = isset($body['gl_balance'])        ? (float) $body['gl_balance']        : (float) $row['gl_balance'];
    $db->prepare(
        'UPDATE accounting_reconciliations
         SET status = "closed", closed_at = :ts, closed_by_user_id = :u,
             statement_balance = :sb, gl_balance = :gb, difference = :df,
             notes = COALESCE(:notes, notes)
         WHERE id = :id AND tenant_id = :t'
    )->execute([
        'ts' => date('Y-m-d H:i:s'), 'u' => $uid,
        'sb' => $sb, 'gb' => $gb, 'df' => $sb - $gb,
        'notes' => $body['notes'] ?? null, 'id' => $id, 't' => $tid,
    ]);
    accountingAudit('accounting.reconciliation.closed', ['reconciliation_id' => $id, 'difference' => round($sb - $gb, 2)], $id);
    api_ok(['ok' => true, 'status' => 'closed']);
}

// ── action=reopen ─────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'reopen') {
    rbac_legacy_require($user, 'accounting.bank.reconcile');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    $reason = trim((string) ($body['reason'] ?? ''));
    if ($reason === '') api_error('reason required to reopen a closed reconciliation', 422);
    $row = scopedFind('SELECT * FROM accounting_reconciliations WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if ($row['status'] !== 'closed') api_error("Cannot reopen from status {$row['status']}", 409);
    $db->prepare(
        'UPDATE accounting_reconciliations
         SET status = "reopened", reopened_at = :ts, reopened_by_user_id = :u, reopen_reason = :r
         WHERE id = :id AND tenant_id = :t'
    )->execute(['ts' => date('Y-m-d H:i:s'), 'u' => $uid, 'r' => $reason, 'id' => $id, 't' => $tid]);
    accountingAudit('accounting.reconciliation.reopened', ['reconciliation_id' => $id, 'reason' => $reason], $id);
    api_ok(['ok' => true, 'status' => 'reopened']);
}

// ── action=generate_ai_narrative ─────────────────────────────────────────
if ($method === 'POST' && $action === 'generate_ai_narrative') {
    rbac_legacy_require($user, 'accounting.bank.reconcile');
    rbac_legacy_require($user, 'ai.use');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    try {
        $res = reconciliationPacketGenerateNarrative($tid, $id, $uid);
    } catch (\Throwable $e) {
        api_error('AI narrative failed: ' . $e->getMessage(), 500);
    }
    accountingAudit('accounting.reconciliation.ai_narrative_generated', ['reconciliation_id' => $id], $id);
    api_ok($res);
}

// ── action=save_ai_narrative ─────────────────────────────────────────────
// Persists the human-accepted narrative text (called by <AISuggestion />
// onAccepted callback — nothing persists until the user accepts).
if ($method === 'POST' && $action === 'save_ai_narrative') {
    rbac_legacy_require($user, 'accounting.bank.reconcile');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    $final = trim((string) ($body['final_content'] ?? ''));
    if ($final === '') api_error('final_content required', 422);
    reconciliationPacketSaveNarrative($tid, $id, $final);
    accountingAudit('accounting.reconciliation.ai_narrative_accepted', [
        'reconciliation_id' => $id,
        'length'            => strlen($final),
    ], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
