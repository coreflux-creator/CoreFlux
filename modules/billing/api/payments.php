<?php
/**
 * Billing API — payments + allocations.
 *
 *   GET  /api/billing/payments                    → list with filters
 *   POST /api/billing/payments                    → record, allocate and post a receipt
 *   POST /api/billing/payments?action=post&id=N   → finish an imported manual receipt
 *   POST /api/billing/payments?action=correct&id=N → reverse a posted manual receipt
 *
 * SPEC: /app/modules/billing/SPEC.md §5.4.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/billing.php';
require_once __DIR__ . '/../lib/posted_receipts.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'eligible_invoices') {
    rbac_legacy_require($user, 'billing.view');
    $where = [
        'i.tenant_id = :tenant_id',
        'i.status IN ("approved", "sent", "partially_paid")',
        'i.amount_due > 0',
        'je.status = "posted"',
    ];
    $params = [];
    if (trim((string) ($_GET['q'] ?? '')) !== '') {
        $where[] = '(i.client_name LIKE :client_q OR i.invoice_number LIKE :invoice_q)';
        $params['client_q'] = '%' . trim((string) $_GET['q']) . '%';
        $params['invoice_q'] = $params['client_q'];
    }
    $rows = scopedQuery(
        'SELECT i.id, i.invoice_number, i.client_name, i.issue_date, i.due_date,
                i.amount_due, i.currency, i.entity_id
           FROM billing_invoices i
           JOIN accounting_journal_entries je
             ON je.tenant_id = i.tenant_id AND je.id = i.journal_entry_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY i.client_name, i.due_date, i.id LIMIT 500',
        $params
    );
    api_ok(['rows' => $rows]);
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'billing.view');
    $where  = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['client_name'])) { $where[] = 'client_name = :cn';   $params['cn'] = $_GET['client_name']; }
    if (!empty($_GET['from']))        { $where[] = 'received_at >= :df'; $params['df'] = $_GET['from']; }
    if (!empty($_GET['to']))          { $where[] = 'received_at <= :dt'; $params['dt'] = $_GET['to']; }
    $rows = scopedQuery(
        'SELECT * FROM billing_payments WHERE ' . implode(' AND ', $where) . ' ORDER BY received_at DESC, id DESC LIMIT 200',
        $params
    );
    foreach ($rows as &$row) {
        $row['receipt_state'] = $row['voided_at'] !== null ? 'corrected'
            : (!empty($row['journal_entry_id']) || str_starts_with((string) ($row['external_id'] ?? ''), 'bank-line:')
                ? 'posted' : 'pending');
        $row['can_correct'] = $row['voided_at'] === null && !empty($row['journal_entry_id'])
            && $row['source_system'] === 'manual'
            && !str_starts_with((string) ($row['external_id'] ?? ''), 'bank-line:');
    }
    unset($row);
    api_ok(['rows' => $rows]);
}

if ($method === 'POST' && $action === '') {
    rbac_legacy_require($user, 'billing.payments.record');
    rbac_legacy_require($user, 'accounting.je.create');
    $body = api_json_body();
    $body['auto'] = !empty($body['auto_allocate']) ? 'fifo' : ($body['auto'] ?? null);
    try {
        $result = billingPostReceivedPayment($tid, $body, $user['id'] ?? null);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        api_error($e->getMessage(), 409);
    }
    if (empty($result['idempotent_replay'])) {
        billingAudit('billing.payment.recorded', [
            'payment_id' => $result['id'], 'journal_entry_id' => $result['journal_entry_id'],
        ], $result['id']);
    }
    api_ok($result, 201);
}

if ($method === 'POST' && $action === 'post') {
    rbac_legacy_require($user, 'billing.payments.record');
    rbac_legacy_require($user, 'accounting.je.create');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $row = scopedFind('SELECT id FROM billing_payments WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    $body = api_json_body();
    try {
        $result = billingPostReceivedPayment($tid, $body, $user['id'] ?? null, $id);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        api_error($e->getMessage(), 409);
    }
    billingAudit('billing.payment.posted', [
        'payment_id' => $id, 'journal_entry_id' => $result['journal_entry_id'],
    ], $id);
    api_ok($result);
}

if ($method === 'POST' && $action === 'allocate') {
    api_error('Choose Post payment and a bank account to apply this receipt to invoices and the ledger together.', 409);
}

if ($method === 'POST' && $action === 'correct') {
    rbac_legacy_require($user, 'billing.payments.record');
    rbac_legacy_require($user, 'accounting.je.reverse');
    rbac_legacy_require($user, 'accounting.bank.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    try {
        $result = billingCorrectPostedPayment($tid, $id, (string) ($body['reason'] ?? ''), $user['id'] ?? null);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        api_error($e->getMessage(), 409);
    }
    billingAudit('billing.payment.corrected', $result + ['reason' => (string) $body['reason']], $id);
    api_ok($result);
}

api_error('Method not allowed', 405);
