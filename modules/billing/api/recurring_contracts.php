<?php
/**
 * Billing API — Recurring invoice contracts (flat-fee, MRR).
 *
 *   GET    /api/billing/recurring_contracts[?status=active|paused|ended]
 *   POST   /api/billing/recurring_contracts                       (create)
 *   GET    /api/billing/recurring_contracts?id=N                  (detail + next 3 due)
 *   POST   /api/billing/recurring_contracts?action=update&id=N    (edit)
 *   POST   /api/billing/recurring_contracts?action=pause&id=N
 *   POST   /api/billing/recurring_contracts?action=resume&id=N
 *   POST   /api/billing/recurring_contracts?action=end&id=N
 *   POST   /api/billing/recurring_contracts?action=generate_now&id=N
 *
 * Permission: read = billing.view, write = billing.invoice.create.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/recurring.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET' && empty($_GET['id'])) {
    rbac_legacy_require($user, 'billing.view');
    $where = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['status'])) {
        $st = (string) $_GET['status'];
        if (!in_array($st, ['active', 'paused', 'ended'], true)) api_error('invalid status', 422);
        $where[] = 'status = :s'; $params['s'] = $st;
    }
    $rows = scopedQuery(
        'SELECT * FROM billing_invoice_contracts WHERE ' . implode(' AND ', $where)
        . ' ORDER BY status ASC, next_due_at ASC, id ASC LIMIT 500',
        $params
    );
    foreach ($rows as &$r) {
        $r['preview_next_3'] = billingRecurringPreviewNextN($r, 3);
    }
    unset($r);
    api_ok(['rows' => $rows]);
}

if ($method === 'GET' && !empty($_GET['id'])) {
    rbac_legacy_require($user, 'billing.view');
    $row = scopedFind('SELECT * FROM billing_invoice_contracts WHERE tenant_id = :tenant_id AND id = :id', ['id' => (int) $_GET['id']]);
    if (!$row) api_error('Not found', 404);
    $row['preview_next_3'] = billingRecurringPreviewNextN($row, 3);
    api_ok($row);
}

if ($method === 'POST' && $action === '') {
    rbac_legacy_require($user, 'billing.invoice.create');
    $body = api_json_body();
    api_require_fields($body, ['client_name', 'contract_name', 'frequency', 'amount', 'start_date']);
    if (!in_array($body['frequency'], ['monthly','quarterly','annual'], true)) api_error('invalid frequency', 422);
    $amount = round((float) $body['amount'], 2);
    if ($amount <= 0) api_error('amount must be > 0', 422);

    $id = scopedInsert('billing_invoice_contracts', [
        'tenant_id'        => $tid,
        'client_name'      => (string) $body['client_name'],
        'contract_name'    => (string) $body['contract_name'],
        'description'      => $body['description'] ?? null,
        'frequency'        => (string) $body['frequency'],
        'day_of_period'    => max(1, min(31, (int) ($body['day_of_period'] ?? 1))),
        'amount'           => $amount,
        'currency'         => strtoupper((string) ($body['currency'] ?? 'USD')),
        'gl_account_id'    => isset($body['gl_account_id']) ? (int) $body['gl_account_id'] : null,
        'start_date'       => (string) $body['start_date'],
        'end_date'         => $body['end_date'] ?? null,
        'status'           => 'active',
        'proration_policy' => in_array($body['proration_policy'] ?? 'full', ['full','prorate','skip_first'], true)
                                 ? (string) $body['proration_policy'] : 'full',
        'bill_to_email'    => $body['bill_to_email'] ?? null,
        'bill_to_json'     => isset($body['bill_to']) ? json_encode($body['bill_to']) : null,
        'po_number'        => $body['po_number'] ?? null,
        'notes_internal'   => $body['notes_internal'] ?? null,
        'created_by_user_id'=> $user['id'] ?? null,
    ]);

    // Materialise next_due_at = start_date on creation so the queue UI
    // can sort + the cron knows when to fire.
    getDB()->prepare('UPDATE billing_invoice_contracts SET next_due_at = start_date WHERE id = :id')
           ->execute(['id' => $id]);

    api_ok(['id' => $id], 201);
}

if ($method === 'POST' && in_array($action, ['update','pause','resume','end'], true)) {
    rbac_legacy_require($user, 'billing.invoice.create');
    $id = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM billing_invoice_contracts WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);

    if ($action === 'pause')  $update = ['status' => 'paused'];
    if ($action === 'resume') $update = ['status' => 'active'];
    if ($action === 'end')    $update = ['status' => 'ended', 'end_date' => date('Y-m-d')];
    if ($action === 'update') {
        $body = api_json_body();
        $allowed = ['contract_name','description','frequency','day_of_period','amount','currency','gl_account_id',
                    'end_date','proration_policy','bill_to_email','po_number','notes_internal'];
        $update = array_intersect_key($body, array_flip($allowed));
        if (isset($update['amount']))        $update['amount'] = round((float) $update['amount'], 2);
        if (isset($update['day_of_period'])) $update['day_of_period'] = max(1, min(31, (int) $update['day_of_period']));
        if (empty($update)) api_error('no editable fields supplied', 422);
    }

    $sets = []; $params = ['id' => $id, 't' => $tid];
    foreach ($update as $k => $v) { $sets[] = "{$k} = :{$k}"; $params[$k] = $v; }
    $pdo = getDB();
    $pdo->prepare('UPDATE billing_invoice_contracts SET ' . implode(', ', $sets) . ' WHERE id = :id AND tenant_id = :t')
        ->execute($params);
    api_ok(['ok' => true, 'action' => $action]);
}

if ($method === 'POST' && $action === 'generate_now') {
    rbac_legacy_require($user, 'billing.invoice.create');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM billing_invoice_contracts WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if ($row['status'] !== 'active') api_error("Cannot generate from status '{$row['status']}'", 409);

    $forDate = $row['next_due_at'] ?: $row['start_date'];
    try {
        $res = billingRecurringGenerateInvoice($tid, $row, $forDate, $user['id'] ?? null);
    } catch (\Throwable $e) {
        api_error('generate failed: ' . $e->getMessage(), 500);
    }
    api_ok($res, $res['existed'] ? 200 : 201);
}

api_error('Method/action not allowed', 405);
