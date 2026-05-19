<?php
/**
 * AP — Recurring Bills API.
 *
 *   GET    /api/ap/recurring                       — list active + paused
 *   POST   /api/ap/recurring                       — create schedule
 *   PATCH  /api/ap/recurring?id=N                  — update fields
 *   POST   /api/ap/recurring?id=N&action=pause     — pause schedule
 *   POST   /api/ap/recurring?id=N&action=resume    — resume schedule
 *   POST   /api/ap/recurring?id=N&action=end       — end (terminal)
 *   POST   /api/ap/recurring?action=generate_due   — sweep all due
 *
 * Generated bills land as `pending_review` (not auto-approved).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/ap.php';
require_once __DIR__ . '/../lib/recurring.php';

$ctx      = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
$user     = $ctx['user'];
$pdo      = getDB();
$method   = api_method();
$action   = (string) ($_GET['action'] ?? '');
$id       = (int) ($_GET['id'] ?? 0);

if ($method === 'GET') {
    rbac_legacy_require($user, 'ap.view');
    $where = ['tenant_id = :t'];
    $params = ['t' => $tenantId];
    if (!empty($_GET['status'])) {
        $where[] = 'status = :s';
        $params['s'] = (string) $_GET['status'];
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM ap_recurring_bills
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY status ASC, next_bill_date ASC LIMIT 500'
    );
    $stmt->execute($params);
    api_ok(['rows' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
}

if ($method === 'POST' && $action === 'generate_due') {
    rbac_legacy_require($user, 'ap.recurring.manage');
    $asOf = (string) ($_GET['as_of'] ?? date('Y-m-d'));
    $res = apRecurringGenerateDue($tenantId, $asOf);
    apAudit('ap.recurring.batch_generated', ['as_of' => $asOf, 'generated' => $res['generated']]);
    api_ok($res);
}

if ($method === 'POST' && $action === '') {
    rbac_legacy_require($user, 'ap.recurring.manage');
    $body = api_json_body();
    api_require_fields($body, ['vendor_name', 'description', 'amount', 'frequency', 'next_bill_date']);
    $freq = (string) $body['frequency'];
    if (!in_array($freq, ['weekly','biweekly','monthly','quarterly','yearly'], true)) {
        api_error('frequency invalid', 422);
    }
    $pdo->prepare(
        'INSERT INTO ap_recurring_bills
            (tenant_id, vendor_name, vendor_id, description, amount,
             frequency, day_of_period, next_bill_date, end_date,
             gl_expense_account_code, is_1099_eligible, item_type,
             status, created_by_user_id, notes)
         VALUES
            (:t, :vn, :vid, :desc, :amt,
             :fr, :dop, :nbd, :ed,
             :gl, :elig, :it,
             "active", :cby, :n)'
    )->execute([
        't' => $tenantId,
        'vn' => (string) $body['vendor_name'],
        'vid' => isset($body['vendor_id']) ? (int) $body['vendor_id'] : null,
        'desc' => (string) $body['description'],
        'amt' => round((float) $body['amount'], 2),
        'fr' => $freq,
        'dop' => isset($body['day_of_period']) ? (int) $body['day_of_period'] : 1,
        'nbd' => (string) $body['next_bill_date'],
        'ed' => $body['end_date'] ?? null,
        'gl' => $body['gl_expense_account_code'] ?? null,
        'elig' => !empty($body['is_1099_eligible']) ? 1 : 0,
        'it' => (string) ($body['item_type'] ?? 'subscription'),
        'cby' => (int) ($user['id'] ?? 0) ?: null,
        'n' => $body['notes'] ?? null,
    ]);
    $newId = (int) $pdo->lastInsertId();
    apAudit('ap.recurring.created', ['recurring_id' => $newId, 'vendor' => $body['vendor_name'], 'amount' => $body['amount']], $newId);
    api_ok(['id' => $newId], 201);
}

if ($id <= 0) api_error('id required', 422);

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'ap.recurring.manage');
    $body = api_json_body();
    $allowed = ['vendor_name','description','amount','frequency','day_of_period',
                'next_bill_date','end_date','gl_expense_account_code',
                'is_1099_eligible','item_type','notes'];
    $set = [];
    $params = ['t' => $tenantId, 'id' => $id];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $body)) {
            $set[] = "{$k} = :{$k}";
            $params[$k] = $body[$k];
        }
    }
    if (!$set) api_error('no fields supplied', 422);
    $pdo->prepare(
        'UPDATE ap_recurring_bills SET ' . implode(', ', $set) . '
          WHERE tenant_id = :t AND id = :id'
    )->execute($params);
    apAudit('ap.recurring.updated', ['recurring_id' => $id, 'fields' => array_keys($body)], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && in_array($action, ['pause','resume','end'], true)) {
    rbac_legacy_require($user, 'ap.recurring.manage');
    $newStatus = $action === 'pause' ? 'paused' : ($action === 'resume' ? 'active' : 'ended');
    $pdo->prepare('UPDATE ap_recurring_bills SET status = :s WHERE tenant_id = :t AND id = :id')
        ->execute(['s' => $newStatus, 't' => $tenantId, 'id' => $id]);
    apAudit("ap.recurring.{$action}d", ['recurring_id' => $id], $id);
    api_ok(['ok' => true, 'status' => $newStatus]);
}

api_error('Method not allowed', 405);
