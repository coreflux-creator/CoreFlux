<?php
/**
 * Treasury Payments API (Sprint 7c, spec section 15).
 *
 *   GET    /api/treasury_payments.php?status=&entity_id=&bank_account_id=&from=&to=
 *   POST   /api/treasury_payments.php                         create draft
 *   POST   /api/treasury_payments.php?action=submit&id=N       start approval workflow
 *   POST   /api/treasury_payments.php?action=approve&id=N      WorkflowGraph approval
 *   POST   /api/treasury_payments.php?action=reject&id=N       WorkflowGraph rejection
 *   POST   /api/treasury_payments.php?action=execute&id=N      post executed payment event
 *   POST   /api/treasury_payments.php?action=void&id=N         void non-executed payment
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/posting_engine/process.php';
require_once __DIR__ . '/../modules/treasury/lib/workflow.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) (api_query('action') ?? '');
$actorUserId = isset($user['id']) ? (int) $user['id'] : null;

if ($method === 'GET') {
    rbac_legacy_require($user, 'treasury.payment.view');
    $where = ['tenant_id = :t'];
    $p = ['t' => $tid];
    foreach (['status' => 'status', 'entity_id' => 'entity_id', 'bank_account_id' => 'bank_account_id'] as $q => $col) {
        $v = api_query($q);
        if ($v !== null && $v !== '') {
            $where[] = "{$col} = :{$q}";
            $p[$q] = $q === 'status' ? (string) $v : (int) $v;
        }
    }
    $from = api_query('from');
    if ($from !== null && $from !== '') {
        $where[] = 'payment_date >= :f';
        $p['f'] = (string) $from;
    }
    $to = api_query('to');
    if ($to !== null && $to !== '') {
        $where[] = 'payment_date <= :tt';
        $p['tt'] = (string) $to;
    }
    $limit = max(1, min(500, (int) (api_query('limit') ?? 100)));
    $offset = max(0, (int) (api_query('offset') ?? 0));

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, payment_number, entity_id, payee_type, payee_id, payee_name,
                amount, currency, payment_date, payment_method, bank_account_id,
                counterparty_account_id, memo, status, workflow_instance_id,
                journal_entry_id, external_ref, created_at, executed_at
           FROM treasury_payments
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY id DESC
          LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    $stmt->execute($p);
    api_ok(['rows' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'limit' => $limit, 'offset' => $offset]);
}

if ($method === 'POST' && $action === '') {
    rbac_legacy_require($user, 'treasury.create_payment');
    $body = api_json_body();
    api_require_fields($body, ['entity_id', 'payee_name', 'amount', 'payment_date', 'bank_account_id']);

    $pdo = getDB();
    $payNum = $body['payment_number'] ?? sprintf('TPY-%d-%s', $tid, substr(bin2hex(random_bytes(4)), 0, 8));
    $status = in_array($body['status'] ?? 'draft', ['draft', 'pending_approval'], true) ? (string) $body['status'] : 'draft';
    $stmt = $pdo->prepare(
        'INSERT INTO treasury_payments
            (tenant_id, entity_id, payment_number, payee_type, payee_id, payee_name,
             amount, currency, payment_date, payment_method, bank_account_id,
             counterparty_account_id, memo, status, created_by_user_id)
         VALUES (:t, :e, :n, :pt, :pid, :pname,
                 :amt, :cur, :pd, :pm, :ba, :cp, :memo, :st, :u)'
    );
    $stmt->execute([
        't' => $tid,
        'e' => (int) $body['entity_id'],
        'n' => $payNum,
        'pt' => (string) ($body['payee_type'] ?? 'vendor'),
        'pid' => isset($body['payee_id']) ? (int) $body['payee_id'] : null,
        'pname' => (string) $body['payee_name'],
        'amt' => round((float) $body['amount'], 2),
        'cur' => (string) ($body['currency'] ?? 'USD'),
        'pd' => (string) $body['payment_date'],
        'pm' => (string) ($body['payment_method'] ?? 'ach'),
        'ba' => (int) $body['bank_account_id'],
        'cp' => isset($body['counterparty_account_id']) ? (int) $body['counterparty_account_id'] : null,
        'memo' => $body['memo'] ?? null,
        'st' => $status,
        'u' => $actorUserId,
    ]);
    $paymentId = (int) $pdo->lastInsertId();
    treasuryWorkflowAudit($tid, $actorUserId, 'treasury.payment.created', [
        'payment_id' => $paymentId,
        'payment_number' => $payNum,
        'status' => $status,
        'payee_type' => (string) ($body['payee_type'] ?? 'vendor'),
        'amount' => round((float) $body['amount'], 2),
        'currency' => (string) ($body['currency'] ?? 'USD'),
    ], $paymentId);
    api_ok(['id' => $paymentId, 'payment_number' => $payNum, 'status' => $status], 201);
}

$id = (int) (api_query('id') ?? 0);
if ($id <= 0) api_error('id required', 400);

$pdo = getDB();
$row = $pdo->prepare('SELECT * FROM treasury_payments WHERE tenant_id = :t AND id = :id');
$row->execute(['t' => $tid, 'id' => $id]);
$payment = $row->fetch(\PDO::FETCH_ASSOC);
if (!$payment) api_error('Payment not found', 404);

if ($method === 'POST' && $action === 'submit') {
    rbac_legacy_require($user, 'treasury.create_payment');
    if ((string) $payment['status'] !== 'draft') {
        api_error("Cannot submit from status {$payment['status']}", 409);
    }
    $instanceId = (int) (treasuryPaymentWorkflowStart($tid, $id, $actorUserId) ?? 0);
    if ($instanceId <= 0) api_error('Could not start treasury payment approval workflow', 503);
    $updated = treasuryPaymentWorkflowRow($tid, $id) ?? $payment;
    treasuryWorkflowAudit($tid, $actorUserId, 'treasury.payment.submitted', [
        'payment_id' => $id,
        'workflow_instance_id' => $instanceId,
    ], $id);
    api_ok([
        'id' => $id,
        'status' => $updated['status'] ?? 'pending_approval',
        'workflow_instance_id' => $instanceId,
    ]);
}

if ($method === 'POST' && $action === 'approve') {
    rbac_legacy_require($user, 'treasury.approve_payment');
    try {
        $workflow = treasuryPaymentWorkflowAct($tid, $id, (int) ($actorUserId ?? 0), 'approve');
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $code = str_contains($msg, 'Separation of duties') || str_contains($msg, 'not an approver') ? 403 : 409;
        api_error($msg, $code);
    }
    if (empty($workflow['applied'])) api_error('Could not apply treasury payment approval workflow', 503);
    $updated = $workflow['payment'] ?? treasuryPaymentWorkflowRow($tid, $id) ?? $payment;
    api_ok([
        'id' => $id,
        'approved' => ($updated['status'] ?? null) === 'approved',
        'status' => $updated['status'] ?? $payment['status'],
        'workflow_instance_id' => $workflow['instance']['id'] ?? ($updated['workflow_instance_id'] ?? null),
        'workflow_status' => $workflow['instance']['status'] ?? null,
    ]);
}

if ($method === 'POST' && $action === 'reject') {
    rbac_legacy_require($user, 'treasury.approve_payment');
    $body = api_json_body();
    try {
        $workflow = treasuryPaymentWorkflowAct(
            $tid,
            $id,
            (int) ($actorUserId ?? 0),
            'reject',
            isset($body['reason']) ? (string) $body['reason'] : null
        );
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $code = str_contains($msg, 'Separation of duties') || str_contains($msg, 'not an approver') ? 403 : 409;
        api_error($msg, $code);
    }
    if (empty($workflow['applied'])) api_error('Could not apply treasury payment rejection workflow', 503);
    $updated = $workflow['payment'] ?? treasuryPaymentWorkflowRow($tid, $id) ?? $payment;
    api_ok([
        'id' => $id,
        'rejected' => ($updated['status'] ?? null) === 'rejected',
        'status' => $updated['status'] ?? $payment['status'],
        'workflow_instance_id' => $workflow['instance']['id'] ?? ($updated['workflow_instance_id'] ?? null),
        'workflow_status' => $workflow['instance']['status'] ?? null,
    ]);
}

if ($method === 'POST' && $action === 'execute') {
    rbac_legacy_require($user, 'treasury.execute_payment');
    if (!in_array($payment['status'], ['approved', 'scheduled'], true)) {
        api_error("Payment must be approved before execute (current: {$payment['status']})", 409);
    }

    $bankStmt = $pdo->prepare(
        'SELECT ba.gl_account_code, aa.id AS gl_account_id
           FROM accounting_bank_accounts ba
           LEFT JOIN accounting_accounts aa
             ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
          WHERE ba.tenant_id = :t AND ba.id = :id LIMIT 1'
    );
    $bankStmt->execute(['t' => $tid, 'id' => (int) $payment['bank_account_id']]);
    $bank = $bankStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

    $event = [
        'entity_id' => (int) $payment['entity_id'],
        'event_type' => 'treasury.payment.executed',
        'source_module' => 'treasury_payments',
        'source_record_id' => 'tpy:' . $payment['id'],
        'event_date' => (string) $payment['payment_date'],
        'payload' => [
            'payment_id' => (int) $payment['id'],
            'payment_number' => (string) $payment['payment_number'],
            'amount' => (float) $payment['amount'],
            'currency' => (string) $payment['currency'],
            'bank_account_id' => (int) $payment['bank_account_id'],
            'bank_gl_account_id' => isset($bank['gl_account_id']) ? (int) $bank['gl_account_id'] : null,
            'bank_gl_account_code' => $bank['gl_account_code'] ?? null,
            'counterparty_account_id' => $payment['counterparty_account_id'] ? (int) $payment['counterparty_account_id'] : null,
            'payee_type' => (string) $payment['payee_type'],
            'payee_name' => (string) $payment['payee_name'],
            'memo' => $payment['memo'],
        ],
    ];

    try {
        $r = accountingProcessEvent($tid, $event, $actorUserId);
    } catch (\Throwable $e) {
        $pdo->prepare('UPDATE treasury_payments SET status="failed", failure_reason=:f WHERE id=:id')
            ->execute(['f' => $e->getMessage(), 'id' => $id]);
        treasuryWorkflowAudit($tid, $actorUserId, 'treasury.payment.execution_failed', [
            'payment_id' => $id,
            'reason' => $e->getMessage(),
        ], $id);
        api_error('Execute failed: ' . $e->getMessage(), 422);
    }

    if ($r['status'] !== 'posted') {
        $msg = $r['error'] ?? 'event not posted';
        $pdo->prepare('UPDATE treasury_payments SET status="failed", failure_reason=:f, accounting_event_id=:ev WHERE id=:id')
            ->execute(['f' => $msg, 'ev' => $r['event_id'] ?? null, 'id' => $id]);
        treasuryWorkflowAudit($tid, $actorUserId, 'treasury.payment.execution_failed', [
            'payment_id' => $id,
            'event_id' => $r['event_id'] ?? null,
            'reason' => $msg,
        ], $id);
        api_error('Execute failed: ' . $msg, 422);
    }

    $pdo->prepare(
        'UPDATE treasury_payments
            SET status="executed", executed_by_user_id=:u, executed_at=NOW(),
                journal_entry_id=:je, accounting_event_id=:ev, failure_reason=NULL
          WHERE id=:id'
    )->execute([
        'u' => $actorUserId,
        'je' => (int) $r['journal_entry_id'],
        'ev' => $r['event_id'] ?? null,
        'id' => $id,
    ]);
    treasuryWorkflowAudit($tid, $actorUserId, 'treasury.payment.executed', [
        'payment_id' => $id,
        'journal_entry_id' => (int) $r['journal_entry_id'],
        'event_id' => $r['event_id'] ?? null,
    ], $id);
    api_ok([
        'id' => $id,
        'status' => 'executed',
        'journal_entry_id' => (int) $r['journal_entry_id'],
        'event_id' => $r['event_id'] ?? null,
    ]);
}

if ($method === 'POST' && $action === 'void') {
    rbac_legacy_require($user, 'treasury.execute_payment');
    if ($payment['status'] === 'executed') {
        api_error('Cannot void an executed payment - issue a reversal instead', 409);
    }
    if ($payment['status'] === 'voided') {
        api_ok(['id' => $id, 'status' => 'voided', 'idempotent_replay' => true]);
    }
    $pdo->prepare('UPDATE treasury_payments SET status="voided" WHERE id=:id')
        ->execute(['id' => $id]);
    treasuryWorkflowAudit($tid, $actorUserId, 'treasury.payment.voided', [
        'payment_id' => $id,
    ], $id);
    api_ok(['id' => $id, 'status' => 'voided']);
}

api_error('Method not allowed', 405);
