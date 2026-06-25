<?php
/**
 * Treasury Transfers API (Sprint 7c, spec section 15).
 *
 *   GET    /api/treasury_transfers.php?status=&kind=&from=&to=
 *   POST   /api/treasury_transfers.php                         create draft
 *   POST   /api/treasury_transfers.php?action=submit&id=N       start approval workflow
 *   POST   /api/treasury_transfers.php?action=approve&id=N      WorkflowGraph approval
 *   POST   /api/treasury_transfers.php?action=reject&id=N       WorkflowGraph rejection
 *   POST   /api/treasury_transfers.php?action=execute&id=N      post transfer event
 *   POST   /api/treasury_transfers.php?action=void&id=N         void non-executed transfer
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
    foreach (['status' => 'status', 'kind' => 'transfer_kind'] as $q => $col) {
        $v = api_query($q);
        if ($v !== null && $v !== '') {
            $where[] = "{$col} = :{$q}";
            $p[$q] = (string) $v;
        }
    }
    $from = api_query('from');
    if ($from !== null && $from !== '') {
        $where[] = 'transfer_date >= :f';
        $p['f'] = (string) $from;
    }
    $to = api_query('to');
    if ($to !== null && $to !== '') {
        $where[] = 'transfer_date <= :tt';
        $p['tt'] = (string) $to;
    }
    $limit = max(1, min(500, (int) (api_query('limit') ?? 100)));
    $offset = max(0, (int) (api_query('offset') ?? 0));

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, transfer_number, transfer_kind, source_bank_account_id, destination_bank_account_id,
                source_entity_id, destination_entity_id, amount, currency, transfer_date, memo,
                status, workflow_instance_id, source_journal_entry_id, destination_journal_entry_id, executed_at
           FROM treasury_transfers
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY id DESC
          LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    $stmt->execute($p);
    api_ok(['rows' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'limit' => $limit, 'offset' => $offset]);
}

if ($method === 'POST' && $action === '') {
    rbac_legacy_require($user, 'treasury.create_transfer');
    $body = api_json_body();
    api_require_fields($body, ['source_bank_account_id', 'destination_bank_account_id', 'amount', 'transfer_date']);

    $pdo = getDB();
    $srcId = (int) $body['source_bank_account_id'];
    $dstId = (int) $body['destination_bank_account_id'];
    if ($srcId === $dstId) api_error('source and destination cannot be the same', 422);

    $oneStmt = $pdo->prepare('SELECT id, entity_id FROM accounting_bank_accounts WHERE tenant_id = :t AND id = :id');
    $oneStmt->execute(['t' => $tid, 'id' => $srcId]);
    $src = $oneStmt->fetch(\PDO::FETCH_ASSOC);
    $oneStmt->execute(['t' => $tid, 'id' => $dstId]);
    $dst = $oneStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$src || !$dst) api_error('Bank account not found', 404);
    $srcEntity = (int) ($src['entity_id'] ?? 0);
    $dstEntity = (int) ($dst['entity_id'] ?? 0);
    $kind = ($srcEntity && $dstEntity && $srcEntity !== $dstEntity) ? 'intercompany' : 'internal';

    $num = $body['transfer_number'] ?? sprintf('TXF-%d-%s', $tid, substr(bin2hex(random_bytes(4)), 0, 8));
    $stmt = $pdo->prepare(
        'INSERT INTO treasury_transfers
            (tenant_id, transfer_number, transfer_kind,
             source_bank_account_id, destination_bank_account_id,
             source_entity_id, destination_entity_id,
             amount, currency, transfer_date, memo, status, created_by_user_id)
         VALUES (:t, :n, :k, :sba, :dba, :se, :de, :amt, :cur, :td, :memo, "draft", :u)'
    );
    $stmt->execute([
        't' => $tid,
        'n' => $num,
        'k' => $kind,
        'sba' => $srcId,
        'dba' => $dstId,
        'se' => $srcEntity ?: 0,
        'de' => $dstEntity ?: $srcEntity ?: 0,
        'amt' => round((float) $body['amount'], 2),
        'cur' => (string) ($body['currency'] ?? 'USD'),
        'td' => (string) $body['transfer_date'],
        'memo' => $body['memo'] ?? null,
        'u' => $actorUserId,
    ]);
    $transferId = (int) $pdo->lastInsertId();
    $created = treasuryTransferWorkflowRow($tid, $transferId) ?? [];
    treasuryWorkflowAudit($tid, $actorUserId, 'treasury.transfer.created', [
        'transfer_id' => $transferId,
        'transfer_number' => $num,
        'transfer_kind' => $kind,
        'amount' => round((float) $body['amount'], 2),
        'currency' => (string) ($body['currency'] ?? 'USD'),
    ], $transferId, [
        'after' => $created,
    ]);
    api_ok([
        'id' => $transferId,
        'transfer_number' => $num,
        'transfer_kind' => $kind,
        'status' => 'draft',
    ], 201);
}

$id = (int) (api_query('id') ?? 0);
if ($id <= 0) api_error('id required', 400);

$pdo = getDB();
$rowStmt = $pdo->prepare('SELECT * FROM treasury_transfers WHERE tenant_id = :t AND id = :id');
$rowStmt->execute(['t' => $tid, 'id' => $id]);
$xfer = $rowStmt->fetch(\PDO::FETCH_ASSOC);
if (!$xfer) api_error('Transfer not found', 404);

if ($method === 'POST' && $action === 'submit') {
    rbac_legacy_require($user, 'treasury.create_transfer');
    if ((string) $xfer['status'] !== 'draft') {
        api_error("Cannot submit from status {$xfer['status']}", 409);
    }
    $instanceId = (int) (treasuryTransferWorkflowStart($tid, $id, $actorUserId) ?? 0);
    if ($instanceId <= 0) api_error('Could not start treasury transfer approval workflow', 503);
    $updated = treasuryTransferWorkflowRow($tid, $id) ?? $xfer;
    treasuryWorkflowAudit($tid, $actorUserId, 'treasury.transfer.submitted', [
        'transfer_id' => $id,
        'workflow_instance_id' => $instanceId,
    ], $id, [
        'before' => $xfer,
        'after' => $updated,
    ]);
    api_ok([
        'id' => $id,
        'status' => $updated['status'] ?? 'pending_approval',
        'workflow_instance_id' => $instanceId,
    ]);
}

if ($method === 'POST' && $action === 'approve') {
    rbac_legacy_require($user, 'treasury.approve_transfer');
    try {
        $workflow = treasuryTransferWorkflowAct($tid, $id, (int) ($actorUserId ?? 0), 'approve');
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $code = str_contains($msg, 'Separation of duties') || str_contains($msg, 'not an approver') ? 403 : 409;
        api_error($msg, $code);
    }
    if (empty($workflow['applied'])) api_error('Could not apply treasury transfer approval workflow', 503);
    $updated = $workflow['transfer'] ?? treasuryTransferWorkflowRow($tid, $id) ?? $xfer;
    api_ok([
        'id' => $id,
        'approved' => ($updated['status'] ?? null) === 'approved',
        'status' => $updated['status'] ?? $xfer['status'],
        'workflow_instance_id' => $workflow['instance']['id'] ?? ($updated['workflow_instance_id'] ?? null),
        'workflow_status' => $workflow['instance']['status'] ?? null,
    ]);
}

if ($method === 'POST' && $action === 'reject') {
    rbac_legacy_require($user, 'treasury.approve_transfer');
    $body = api_json_body();
    try {
        $workflow = treasuryTransferWorkflowAct(
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
    if (empty($workflow['applied'])) api_error('Could not apply treasury transfer rejection workflow', 503);
    $updated = $workflow['transfer'] ?? treasuryTransferWorkflowRow($tid, $id) ?? $xfer;
    api_ok([
        'id' => $id,
        'rejected' => ($updated['status'] ?? null) === 'rejected',
        'status' => $updated['status'] ?? $xfer['status'],
        'workflow_instance_id' => $workflow['instance']['id'] ?? ($updated['workflow_instance_id'] ?? null),
        'workflow_status' => $workflow['instance']['status'] ?? null,
    ]);
}

if ($method === 'POST' && $action === 'execute') {
    rbac_legacy_require($user, 'treasury.execute_payment');
    if (!in_array($xfer['status'], ['approved', 'scheduled'], true)) {
        api_error("Transfer must be approved before execute (current: {$xfer['status']})", 409);
    }

    $eventType = $xfer['transfer_kind'] === 'intercompany'
        ? 'treasury.intercompany.transfer.completed'
        : 'treasury.transfer.completed';

    $bankStmt = $pdo->prepare(
        'SELECT ba.id, ba.gl_account_code, aa.id AS gl_account_id
           FROM accounting_bank_accounts ba
           LEFT JOIN accounting_accounts aa
             ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
          WHERE ba.tenant_id = :t AND ba.id = :id LIMIT 1'
    );
    $bankStmt->execute(['t' => $tid, 'id' => (int) $xfer['source_bank_account_id']]);
    $srcBank = $bankStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    $bankStmt->execute(['t' => $tid, 'id' => (int) $xfer['destination_bank_account_id']]);
    $dstBank = $bankStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

    $event = [
        'entity_id' => (int) $xfer['source_entity_id'],
        'event_type' => $eventType,
        'source_module' => 'treasury_transfers',
        'source_record_id' => 'txf:' . $xfer['id'],
        'event_date' => (string) $xfer['transfer_date'],
        'payload' => [
            'transfer_id' => (int) $xfer['id'],
            'transfer_number' => (string) $xfer['transfer_number'],
            'transfer_kind' => (string) $xfer['transfer_kind'],
            'amount' => (float) $xfer['amount'],
            'currency' => (string) $xfer['currency'],
            'source_bank_account_id' => (int) $xfer['source_bank_account_id'],
            'destination_bank_account_id' => (int) $xfer['destination_bank_account_id'],
            'source_bank_gl_account_id' => isset($srcBank['gl_account_id']) ? (int) $srcBank['gl_account_id'] : null,
            'source_bank_gl_account_code' => $srcBank['gl_account_code'] ?? null,
            'destination_bank_gl_account_id' => isset($dstBank['gl_account_id']) ? (int) $dstBank['gl_account_id'] : null,
            'destination_bank_gl_account_code' => $dstBank['gl_account_code'] ?? null,
            'source_entity_id' => (int) $xfer['source_entity_id'],
            'destination_entity_id' => (int) $xfer['destination_entity_id'],
            'memo' => $xfer['memo'],
        ],
    ];

    try {
        $r = accountingProcessEvent($tid, $event, $actorUserId);
    } catch (\Throwable $e) {
        $pdo->prepare('UPDATE treasury_transfers SET status="failed", failure_reason=:f WHERE tenant_id=:t AND id=:id')
            ->execute(['f' => $e->getMessage(), 't' => $tid, 'id' => $id]);
        $failed = treasuryTransferWorkflowRow($tid, $id) ?? $xfer;
        treasuryWorkflowAudit($tid, $actorUserId, 'treasury.transfer.execution_failed', [
            'transfer_id' => $id,
            'event_type' => $eventType,
            'reason' => $e->getMessage(),
        ], $id, [
            'before' => $xfer,
            'after' => $failed,
        ]);
        api_error('Execute failed: ' . $e->getMessage(), 422);
    }

    if ($r['status'] !== 'posted') {
        $msg = $r['error'] ?? 'event not posted';
        $pdo->prepare('UPDATE treasury_transfers SET status="failed", failure_reason=:f, accounting_event_id=:ev WHERE tenant_id=:t AND id=:id')
            ->execute(['f' => $msg, 'ev' => $r['event_id'] ?? null, 't' => $tid, 'id' => $id]);
        $failed = treasuryTransferWorkflowRow($tid, $id) ?? $xfer;
        treasuryWorkflowAudit($tid, $actorUserId, 'treasury.transfer.execution_failed', [
            'transfer_id' => $id,
            'event_type' => $eventType,
            'event_id' => $r['event_id'] ?? null,
            'reason' => $msg,
        ], $id, [
            'before' => $xfer,
            'after' => $failed,
        ]);
        api_error('Execute failed: ' . $msg, 422);
    }

    $pdo->prepare(
        'UPDATE treasury_transfers
            SET status="executed", executed_by_user_id=:u, executed_at=NOW(),
                source_journal_entry_id=:je, accounting_event_id=:ev, failure_reason=NULL
          WHERE tenant_id=:t AND id=:id'
    )->execute([
        'u' => $actorUserId,
        'je' => (int) $r['journal_entry_id'],
        'ev' => $r['event_id'] ?? null,
        't' => $tid,
        'id' => $id,
    ]);
    $executed = treasuryTransferWorkflowRow($tid, $id) ?? $xfer;
    treasuryWorkflowAudit($tid, $actorUserId, 'treasury.transfer.executed', [
        'transfer_id' => $id,
        'transfer_kind' => (string) $xfer['transfer_kind'],
        'event_type' => $eventType,
        'source_journal_entry_id' => (int) $r['journal_entry_id'],
        'event_id' => $r['event_id'] ?? null,
    ], $id, [
        'before' => $xfer,
        'after' => $executed,
    ]);
    api_ok([
        'id' => $id,
        'status' => 'executed',
        'transfer_kind' => (string) $xfer['transfer_kind'],
        'source_journal_entry_id' => (int) $r['journal_entry_id'],
        'event_id' => $r['event_id'] ?? null,
    ]);
}

if ($method === 'POST' && $action === 'void') {
    rbac_legacy_require($user, 'treasury.execute_payment');
    if ($xfer['status'] === 'executed') {
        api_error('Cannot void an executed transfer - issue a reversal instead', 409);
    }
    if ($xfer['status'] === 'voided') {
        api_ok(['id' => $id, 'status' => 'voided', 'idempotent_replay' => true]);
    }
    $pdo->prepare('UPDATE treasury_transfers SET status="voided" WHERE tenant_id=:t AND id=:id')
        ->execute(['t' => $tid, 'id' => $id]);
    $voided = treasuryTransferWorkflowRow($tid, $id) ?? $xfer;
    treasuryWorkflowAudit($tid, $actorUserId, 'treasury.transfer.voided', [
        'transfer_id' => $id,
    ], $id, [
        'before' => $xfer,
        'after' => $voided,
    ]);
    api_ok(['id' => $id, 'status' => 'voided']);
}

api_error('Method not allowed', 405);
