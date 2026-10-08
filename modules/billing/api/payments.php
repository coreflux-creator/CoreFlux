<?php
/**
 * Billing API — payments + allocations.
 *
 *   GET  /api/billing/payments                    → list with filters
 *   POST /api/billing/payments                    → record, allocate and post a receipt
 *   POST /api/billing/payments?action=post&id=N   → finish a pending imported receipt
 *   POST /api/billing/payments?action=correct&id=N → reverse a Billing-posted receipt
 *   POST /api/billing/payments?action=apply_deposit&id=N → apply held cash to an invoice
 *   POST /api/billing/payments?action=refund_deposit&id=N → record an external cash refund
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

if ($method === 'GET' && $action === 'deposit_activity') {
    rbac_legacy_require($user, 'billing.view');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $payment = scopedFind(
        'SELECT id, client_name, currency, amount, unallocated_amount
           FROM billing_payments WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    if (!$payment) api_error('Payment not found', 404);
    $applications = scopedQuery(
        'SELECT a.id, a.invoice_id, i.invoice_number, a.amount, a.applied_at,
                a.journal_entry_id, a.reversed_at, a.reversal_je_id, a.reversal_reason
           FROM billing_deposit_applications a
           LEFT JOIN billing_invoices i ON i.tenant_id = a.tenant_id AND i.id = a.invoice_id
          WHERE a.tenant_id = :tenant_id AND a.payment_id = :payment_id
          ORDER BY a.id DESC',
        ['payment_id' => $id]
    );
    $refunds = scopedQuery(
        'SELECT r.id, r.amount, r.currency, r.refunded_at, r.reference, b.name AS bank_name,
                r.journal_entry_id, r.reversed_at, r.reversal_je_id, r.reversal_reason
           FROM billing_deposit_refunds r
           LEFT JOIN accounting_bank_accounts b ON b.tenant_id = r.tenant_id AND b.id = r.bank_account_id
          WHERE r.tenant_id = :tenant_id AND r.payment_id = :payment_id
          ORDER BY r.id DESC',
        ['payment_id' => $id]
    );
    api_ok(['payment' => $payment, 'applications' => $applications, 'refunds' => $refunds]);
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'billing.view');
    $where  = ['p.tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['client_name'])) { $where[] = 'p.client_name = :cn';   $params['cn'] = $_GET['client_name']; }
    if (!empty($_GET['from']))        { $where[] = 'p.received_at >= :df'; $params['df'] = $_GET['from']; }
    if (!empty($_GET['to']))          { $where[] = 'p.received_at <= :dt'; $params['dt'] = $_GET['to']; }
    $search = trim((string) ($_GET['q'] ?? ''));
    if (strlen($search) > 120) api_error('Payment search is too long', 422);
    if ($search !== '') {
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        $where[] = '(p.client_name LIKE :client_search ESCAPE "!"
                 OR p.reference LIKE :reference_search ESCAPE "!"
                 OR p.external_id LIKE :source_search ESCAPE "!")';
        $params['client_search'] = $like;
        $params['reference_search'] = $like;
        $params['source_search'] = $like;
    }
    $whereSql = implode(' AND ', $where);
    $totalRow = scopedFind('SELECT COUNT(*) AS total FROM billing_payments p WHERE ' . $whereSql, $params);
    $total = (int) ($totalRow['total'] ?? 0);
    $perPage = min(200, max(1, (int) ($_GET['per_page'] ?? 50)));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
    $offset = ($page - 1) * $perPage;
    $rows = scopedQuery(
        'SELECT p.*, je.status AS journal_status, je.source_module AS journal_source_module,
                je.source_ref_type AS journal_source_ref_type, je.source_ref_id AS journal_source_ref_id
           FROM billing_payments p
           LEFT JOIN accounting_journal_entries je
             ON je.tenant_id = p.tenant_id AND je.id = p.journal_entry_id
          WHERE ' . $whereSql . '
          ORDER BY p.received_at DESC, p.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
        $params
    );
    $applicationStmt = getDB()->prepare(
        'SELECT payment_id, MAX(reversed_at IS NULL) AS has_active
           FROM billing_deposit_applications WHERE tenant_id = :tenant_id GROUP BY payment_id'
    );
    $applicationStmt->execute(['tenant_id' => $tid]);
    $hasLaterApplications = [];
    $hasAnyApplications = [];
    foreach ($applicationStmt->fetchAll(PDO::FETCH_ASSOC) as $application) {
        $hasAnyApplications[(int) $application['payment_id']] = true;
        if ($application['has_active']) $hasLaterApplications[(int) $application['payment_id']] = true;
    }
    $refundStmt = getDB()->prepare(
        'SELECT payment_id, ROUND(COALESCE(SUM(CASE WHEN reversed_at IS NULL THEN amount ELSE 0 END), 0), 2) AS refunded_amount
           FROM billing_deposit_refunds WHERE tenant_id = :tenant_id GROUP BY payment_id'
    );
    $refundStmt->execute(['tenant_id' => $tid]);
    $refundAmounts = [];
    $hasAnyRefunds = [];
    foreach ($refundStmt->fetchAll(PDO::FETCH_ASSOC) as $refund) {
        $refundAmounts[(int) $refund['payment_id']] = (float) $refund['refunded_amount'];
        $hasAnyRefunds[(int) $refund['payment_id']] = true;
    }
    foreach ($rows as &$row) {
        $row['receipt_state'] = $row['voided_at'] !== null ? 'corrected'
            : (!empty($row['journal_entry_id']) || str_starts_with((string) ($row['external_id'] ?? ''), 'bank-line:')
                ? 'posted' : 'pending');
        $billingPosted = $row['receipt_state'] === 'posted' && !empty($row['bank_account_id'])
            && $row['journal_status'] === 'posted'
            && $row['journal_source_module'] === 'billing'
            && $row['journal_source_ref_type'] === 'billing_payment'
            && (int) $row['journal_source_ref_id'] === (int) $row['id']
            && !str_starts_with((string) ($row['external_id'] ?? ''), 'bank-line:');
        $row['can_correct'] = $billingPosted
            && empty($hasLaterApplications[$row['id']])
            && empty($refundAmounts[$row['id']]);
        $row['can_apply_deposit'] = $billingPosted
            && (float) $row['unallocated_amount'] > 0.005;
        $row['can_refund_deposit'] = $row['can_apply_deposit'];
        $row['refunded_amount'] = $refundAmounts[$row['id']] ?? 0;
        $row['has_deposit_activity'] = !empty($hasAnyApplications[$row['id']])
            || !empty($hasAnyRefunds[$row['id']]);
    }
    unset($row);
    api_ok(['rows' => $rows, 'total' => $total, 'page' => $page,
        'per_page' => $perPage, 'pages' => $pages]);
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

if ($method === 'POST' && $action === 'apply_deposit') {
    rbac_legacy_require($user, 'billing.payments.record');
    rbac_legacy_require($user, 'accounting.je.create');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    try {
        $result = billingApplyCustomerDeposit($tid, $id, $body, $user['id'] ?? null);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        api_error($e->getMessage(), 409);
    }
    if (empty($result['idempotent_replay'])) {
        billingAudit('billing.deposit.applied', $result, $id);
    }
    api_ok($result);
}

if ($method === 'POST' && $action === 'refund_deposit') {
    rbac_legacy_require($user, 'billing.payments.record');
    rbac_legacy_require($user, 'accounting.je.create');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    try {
        $result = billingRecordCustomerDepositRefund($tid, $id, $body, $user['id'] ?? null);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        api_error($e->getMessage(), 409);
    }
    if (empty($result['idempotent_replay'])) {
        billingAudit('billing.deposit.refund_recorded', [
            'payment_id' => $id, 'refund_id' => $result['refund_id'],
            'journal_entry_id' => $result['journal_entry_id'],
        ], $id);
    }
    api_ok($result, 201);
}

if ($method === 'POST' && $action === 'correct_deposit_application') {
    rbac_legacy_require($user, 'billing.payments.record');
    rbac_legacy_require($user, 'accounting.je.reverse');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    try {
        $result = billingCorrectCustomerDepositApplication($tid, $id, (string) ($body['reason'] ?? ''), $user['id'] ?? null);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        api_error($e->getMessage(), 409);
    }
    billingAudit('billing.deposit.application_corrected', $result + ['reason' => (string) $body['reason']], $result['payment_id']);
    api_ok($result);
}

if ($method === 'POST' && $action === 'correct_deposit_refund') {
    rbac_legacy_require($user, 'billing.payments.record');
    rbac_legacy_require($user, 'accounting.je.reverse');
    rbac_legacy_require($user, 'accounting.bank.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    try {
        $result = billingCorrectCustomerDepositRefund($tid, $id, (string) ($body['reason'] ?? ''), $user['id'] ?? null);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        api_error($e->getMessage(), 409);
    }
    billingAudit('billing.deposit.refund_corrected', $result + ['reason' => (string) $body['reason']], $result['payment_id']);
    api_ok($result);
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
