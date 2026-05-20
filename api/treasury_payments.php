<?php
/**
 * Treasury Payments API (Sprint 7c, spec §15).
 *
 *   GET    /api/treasury_payments.php?status=&entity_id=&bank_account_id=&from=&to=
 *   POST   /api/treasury_payments.php                          create draft
 *   POST   /api/treasury_payments.php?action=approve&id=N      requires treasury.approve_payment
 *   POST   /api/treasury_payments.php?action=execute&id=N      requires treasury.execute_payment
 *                                                              → emits treasury.payment.executed event
 *   POST   /api/treasury_payments.php?action=void&id=N         requires treasury.execute_payment
 *
 * Lifecycle: draft → pending_approval (on submit) → approved → executed
 *            (or failed / voided / rejected at any prior step).
 *
 * Execute does NOT itself talk to a bank rail — it emits an event into
 * the posting engine which matches a tenant rule and posts the JE.
 * Real ACH/wire integration ships separately when creds are available.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/posting_engine/process.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) (api_query('action') ?? '');

// ──────────────────────────────────────────────────────────────────
// GET — list with filters
// ──────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    rbac_legacy_require($user, 'treasury.view_bank_balances');
    $where = ['tenant_id = :t']; $p = ['t' => $tid];
    foreach (['status' => 'status', 'entity_id' => 'entity_id',
              'bank_account_id' => 'bank_account_id'] as $q => $col) {
        $v = api_query($q);
        if ($v !== null && $v !== '') { $where[] = "{$col} = :{$q}"; $p[$q] = $q === 'status' ? (string) $v : (int) $v; }
    }
    if ($f = api_query('from')) { $where[] = 'payment_date >= :f'; $p['f'] = (string) $f; }
    if ($t = api_query('to'))   { $where[] = 'payment_date <= :tt'; $p['tt'] = (string) $t; }
    $limit  = max(1, min(500, (int) (api_query('limit') ?? 100)));
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

// ──────────────────────────────────────────────────────────────────
// POST without action — create draft
// ──────────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === '') {
    rbac_legacy_require($user, 'treasury.create_payment');
    $body = api_json_body();
    api_require_fields($body, ['entity_id', 'payee_name', 'amount', 'payment_date', 'bank_account_id']);

    $pdo = getDB();
    $payNum = $body['payment_number'] ?? sprintf('TPY-%d-%s', $tid, substr(bin2hex(random_bytes(4)), 0, 8));
    $stmt = $pdo->prepare(
        'INSERT INTO treasury_payments
            (tenant_id, entity_id, payment_number, payee_type, payee_id, payee_name,
             amount, currency, payment_date, payment_method, bank_account_id,
             counterparty_account_id, memo, status, created_by_user_id)
         VALUES (:t, :e, :n, :pt, :pid, :pname,
                 :amt, :cur, :pd, :pm, :ba, :cp, :memo, :st, :u)'
    );
    $stmt->execute([
        't' => $tid, 'e' => (int) $body['entity_id'], 'n' => $payNum,
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
        'st' => in_array($body['status'] ?? 'draft', ['draft', 'pending_approval'], true) ? $body['status'] : 'draft',
        'u' => $user['id'] ?? null,
    ]);
    api_ok(['id' => (int) $pdo->lastInsertId(), 'payment_number' => $payNum, 'status' => $body['status'] ?? 'draft'], 201);
}

// All non-GET non-create actions require a target id.
$id = (int) (api_query('id') ?? 0);
if ($id <= 0) api_error('id required', 400);

$pdo = getDB();
$row = $pdo->prepare('SELECT * FROM treasury_payments WHERE tenant_id = :t AND id = :id');
$row->execute(['t' => $tid, 'id' => $id]);
$payment = $row->fetch(\PDO::FETCH_ASSOC);
if (!$payment) api_error('Payment not found', 404);

// ──────────────────────────────────────────────────────────────────
// approve
// ──────────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'approve') {
    rbac_legacy_require($user, 'treasury.approve_payment');
    if (!in_array($payment['status'], ['draft', 'pending_approval'], true)) {
        api_error("Cannot approve from status {$payment['status']}", 409);
    }
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare(
        'UPDATE treasury_payments
            SET status="approved", approved_by_user_id=:u, approved_at=NOW()
          WHERE id=:id'
    )->execute(['u' => $user['id'] ?? null, 'id' => $id]);
    api_ok(['id' => $id, 'status' => 'approved']);
}

// ──────────────────────────────────────────────────────────────────
// execute → emits accounting event → engine posts JE
// ──────────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'execute') {
    rbac_legacy_require($user, 'treasury.execute_payment');
    if (!in_array($payment['status'], ['approved', 'scheduled'], true)) {
        api_error("Payment must be approved before execute (current: {$payment['status']})", 409);
    }

    // Hydrate the bank's GL account so posting templates can target it.
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
        'entity_id'        => (int) $payment['entity_id'],
        'event_type'       => 'treasury.payment.executed',
        'source_module'    => 'treasury_payments',
        'source_record_id' => 'tpy:' . $payment['id'],
        'event_date'       => (string) $payment['payment_date'],
        'payload' => [
            'payment_id'             => (int) $payment['id'],
            'payment_number'         => (string) $payment['payment_number'],
            'amount'                 => (float) $payment['amount'],
            'currency'               => (string) $payment['currency'],
            'bank_account_id'        => (int) $payment['bank_account_id'],
            'bank_gl_account_id'     => isset($bank['gl_account_id']) ? (int) $bank['gl_account_id'] : null,
            'bank_gl_account_code'   => $bank['gl_account_code'] ?? null,
            'counterparty_account_id'=> $payment['counterparty_account_id'] ? (int) $payment['counterparty_account_id'] : null,
            'payee_type'             => (string) $payment['payee_type'],
            'payee_name'             => (string) $payment['payee_name'],
            'memo'                   => $payment['memo'],
        ],
    ];

    try {
        $r = accountingProcessEvent($tid, $event, $user['id'] ?? null);
    } catch (\Throwable $e) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare('UPDATE treasury_payments SET status="failed", failure_reason=:f WHERE id=:id')
            ->execute(['f' => $e->getMessage(), 'id' => $id]);
        api_error('Execute failed: ' . $e->getMessage(), 422);
    }

    if ($r['status'] !== 'posted') {
        $msg = $r['error'] ?? 'event not posted';
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare('UPDATE treasury_payments SET status="failed", failure_reason=:f, accounting_event_id=:ev WHERE id=:id')
            ->execute(['f' => $msg, 'ev' => $r['event_id'] ?? null, 'id' => $id]);
        api_error('Execute failed: ' . $msg, 422);
    }

    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare(
        'UPDATE treasury_payments
            SET status="executed", executed_by_user_id=:u, executed_at=NOW(),
                journal_entry_id=:je, accounting_event_id=:ev, failure_reason=NULL
          WHERE id=:id'
    )->execute([
        'u' => $user['id'] ?? null, 'je' => (int) $r['journal_entry_id'],
        'ev' => $r['event_id'] ?? null, 'id' => $id,
    ]);
    api_ok([
        'id' => $id, 'status' => 'executed',
        'journal_entry_id' => (int) $r['journal_entry_id'],
        'event_id' => $r['event_id'] ?? null,
    ]);
}

// ──────────────────────────────────────────────────────────────────
// void
// ──────────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'void') {
    rbac_legacy_require($user, 'treasury.execute_payment');
    if ($payment['status'] === 'executed') {
        api_error('Cannot void an executed payment — issue a reversal instead', 409);
    }
    if ($payment['status'] === 'voided') {
        api_ok(['id' => $id, 'status' => 'voided', 'idempotent_replay' => true]);
    }
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare('UPDATE treasury_payments SET status="voided" WHERE id=:id')
        ->execute(['id' => $id]);
    api_ok(['id' => $id, 'status' => 'voided']);
}

api_error('Method not allowed', 405);
