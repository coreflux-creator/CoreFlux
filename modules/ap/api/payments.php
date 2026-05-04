<?php
/**
 * AP API — payments + allocations.
 *
 *   GET  /api/ap/payments                      → list with filters
 *   POST /api/ap/payments                      → create payment (draft by default)
 *   POST /api/ap/payments?action=allocate&id=N → body: {allocations} OR {auto:'fifo'}
 *   POST /api/ap/payments?action=send&id=N     → transition queued/draft → sent  (ap.payment.send; SoD-guarded)
 *   POST /api/ap/payments?action=clear&id=N    → mark cleared (manual bank rec)
 *   POST /api/ap/payments?action=void&id=N     → body: {reason}
 *
 * SPEC: /app/modules/ap/SPEC.md §5.3.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/payment_rails.php';
require_once __DIR__ . '/../../../core/payment_rails/originate_helpers.php';
require_once __DIR__ . '/../lib/ap.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    RBAC::requirePermission($user, 'ap.view');
    $where  = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['vendor_name'])) { $where[] = 'vendor_name = :vn'; $params['vn'] = $_GET['vendor_name']; }
    if (!empty($_GET['status']))      { $where[] = 'status = :st';      $params['st'] = $_GET['status']; }
    if (!empty($_GET['from']))        { $where[] = 'pay_date >= :df';   $params['df'] = $_GET['from']; }
    if (!empty($_GET['to']))          { $where[] = 'pay_date <= :dt';   $params['dt'] = $_GET['to']; }
    $rows = scopedQuery(
        'SELECT * FROM ap_payments WHERE ' . implode(' AND ', $where) . ' ORDER BY pay_date DESC, id DESC LIMIT 200',
        $params
    );
    api_ok(['rows' => $rows, 'plaid_enabled' => apPlaidConfigured()]);
}

if ($method === 'POST' && $action === '') {
    RBAC::requirePermission($user, 'ap.payment.create');
    $body = api_json_body();
    api_require_fields($body, ['vendor_name', 'pay_date', 'method', 'amount']);
    $amount = round((float) $body['amount'], 2);
    if ($amount <= 0) api_error('amount must be > 0', 422);

    $id = scopedInsert('ap_payments', [
        'tenant_id'          => $tid,
        'vendor_name'        => (string) $body['vendor_name'],
        'pay_date'           => (string) $body['pay_date'],
        'method'             => (string) $body['method'],
        'reference'          => $body['reference'] ?? null,
        'amount'             => $amount,
        'currency'           => (string) ($body['currency'] ?? 'USD'),
        'unallocated_amount' => $amount,
        'bank_account_id'    => !empty($body['bank_account_id']) ? (int) $body['bank_account_id'] : null,
        'status'             => 'draft',
        'notes'              => $body['notes'] ?? null,
        'created_by_user_id' => $user['id'] ?? null,
    ]);
    apAudit('ap.payment.drafted', [
        'payment_id' => $id, 'vendor_name' => $body['vendor_name'], 'amount' => $amount, 'method' => $body['method'],
    ], $id);

    if (!empty($body['auto_allocate'])) {
        try {
            $alloc = apAllocatePayment($id, ['auto' => 'fifo'], $user['id'] ?? null);
            return api_ok(['id' => $id, 'auto_allocation' => $alloc], 201);
        } catch (\Throwable $e) {
            return api_ok(['id' => $id, 'auto_allocation_error' => $e->getMessage()], 201);
        }
    }
    api_ok(['id' => $id], 201);
}

if ($method === 'POST' && $action === 'allocate') {
    RBAC::requirePermission($user, 'ap.payment.allocate');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $row = scopedFind('SELECT id FROM ap_payments WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);

    $body  = api_json_body();
    $alloc = apAllocatePayment($id, $body, $user['id'] ?? null);
    apAudit('ap.payment.allocated', ['payment_id' => $id, 'request' => $body, 'applied' => $alloc['applied']], $id);
    api_ok($alloc);
}

if ($method === 'POST' && $action === 'send') {
    RBAC::requirePermission($user, 'ap.payment.send');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM ap_payments WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!apPaymentTransitionAllowed($row['status'], 'sent')) api_error("Cannot send from status {$row['status']}", 409);

    // SoD: the same actor who created the payment cannot release it unless they
    // also approved the allocated bills (both roles separate recommended).
    if ((int) ($row['created_by_user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
        api_error('Segregation of duties: you cannot release your own payment.', 403);
    }

    // Refuse if any allocated bill is disputed or void.
    $pdo = getDB();
    $checkStmt = $pdo->prepare(
        'SELECT b.status, b.internal_ref
         FROM ap_payment_allocations a
         JOIN ap_bills b ON b.id = a.bill_id
         WHERE a.payment_id = :id AND b.status IN ("disputed","void")'
    );
    $checkStmt->execute(['id' => $id]);
    $bad = $checkStmt->fetchAll(\PDO::FETCH_ASSOC);
    if ($bad) api_error('Cannot release: bill ' . $bad[0]['internal_ref'] . ' is ' . $bad[0]['status'], 409);

    $pdo->prepare('UPDATE ap_payments SET status = "sent", sent_at = NOW(), sent_by_user_id = :u WHERE id = :id')
        ->execute(['u' => $user['id'] ?? null, 'id' => $id]);
    apAudit('ap.payment.sent', [
        'payment_id' => $id, 'vendor_name' => $row['vendor_name'], 'amount' => $row['amount'], 'method' => $row['method'],
        'rail' => $row['method'] === 'plaid' ? 'plaid_transfer' : 'manual',
    ], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'originate_batch') {
    RBAC::requirePermission($user, 'ap.payment.send');
    $body = api_json_body();
    $ids  = array_values(array_unique(array_filter(array_map('intval', (array) ($body['ids'] ?? [])))));
    if (!$ids)               api_error('ids[] required', 422);
    if (count($ids) > 500)   api_error('Batch limited to 500 payments per file', 422);

    $pdo = getDB();
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt  = $pdo->prepare(
        "SELECT * FROM ap_payments
         WHERE tenant_id = ? AND id IN ($place) ORDER BY id"
    );
    $stmt->execute(array_merge([$tid], $ids));
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    if (count($rows) !== count($ids)) {
        api_error('Some ids not found in this tenant', 404, [
            'requested' => $ids, 'found' => array_column($rows, 'id'),
        ]);
    }

    // Validate state transitions up-front so we never half-originate a batch.
    foreach ($rows as $r) {
        if ($r['status'] === 'sent' && empty($r['rail_external_ref'])) {
            // OK — already sent, just hasn't been originated yet.
        } elseif ($r['status'] === 'draft' || $r['status'] === 'queued') {
            // OK — will transition to sent as part of this batch.
        } else {
            api_error("Payment #{$r['id']} not eligible (status={$r['status']}, rail_ref="
                . ($r['rail_external_ref'] ?: 'none') . ')', 409);
        }
        if (!in_array($r['method'], ['ach','plaid'], true)) {
            api_error("Payment #{$r['id']} method={$r['method']} not eligible (must be ach|plaid)", 422);
        }
    }

    // Build all RailItems before opening the transaction. Any failure here =>
    // 422, no DB writes — the user retries after fixing vendor banking.
    $items   = [];
    $vendors = [];
    foreach ($rows as $r) {
        $vname = $r['vendor_name'];
        if (!isset($vendors[$vname])) {
            $v = scopedFind(
                'SELECT vendor_type, payment_routing_ct, payment_account_ct, payment_account_type
                 FROM ap_vendors_index WHERE tenant_id = :tenant_id AND vendor_name = :vn LIMIT 1',
                ['vn' => $vname]
            );
            if (!$v) api_error("Vendor '$vname' not found in vendor index (payment #{$r['id']})", 422);
            $vendors[$vname] = $v;
        }
        $v = $vendors[$vname];
        try {
            $bank = paymentRailsDecryptBank(
                $v['payment_routing_ct'] ?? null, $v['payment_account_ct'] ?? null,
                "vendor $vname (payment #{$r['id']})"
            );
        } catch (\Throwable $e) {
            api_error($e->getMessage(), 422);
        }
        $items[] = paymentRailsBuildItem([
            'external_ref'   => 'ap_payment:' . $r['id'],
            'recipient_name' => (string) $vname,
            'routing'        => $bank['routing'],
            'account'        => $bank['account'],
            'account_type'   => $v['payment_account_type'] ?: 'checking',
            'amount_cents'   => (int) round(((float) $r['amount']) * 100),
            'sec_code'       => $v['vendor_type'] === '1099_individual' ? 'ppd' : 'ccd',
            'description'    => 'AP-PAY',
        ]);
    }

    $settings = scopedFind('SELECT * FROM ap_settings WHERE tenant_id = :tenant_id LIMIT 1') ?: [];
    try {
        $res = paymentRailsDispatch('ap', ['effective_date' => date('Y-m-d', strtotime('+1 day'))], $settings, $items);
    } catch (PaymentRailsOriginateException $e) {
        apAudit('ap.payment.batch_originate_failed', [
            'count' => count($ids), 'ids' => $ids, 'error' => $e->getMessage(),
        ]);
        api_error($e->getMessage(), 422);
    }

    // Atomic persist: every payment in the batch flips together or none do.
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare(
            'UPDATE ap_payments
             SET status              = "sent",
                 disbursement_rail   = :r,
                 rail_external_ref   = :x,
                 rail_status         = :s,
                 rail_originated_at  = NOW()
             WHERE tenant_id = :t AND id = :id'
        );
        $byRef = [];
        foreach ($res['items'] as $it) $byRef[$it['external_ref']] = $it;
        foreach ($rows as $r) {
            $itemRes = $byRef['ap_payment:' . $r['id']] ?? ['status' => $res['status'] ?? 'submitted', 'rail_external_ref' => $res['batch_id']];
            $upd->execute([
                'r'  => $res['rail'],
                'x'  => $itemRes['rail_external_ref'] ?? $res['batch_id'],
                's'  => $itemRes['status']            ?? 'submitted',
                't'  => $tid,
                'id' => $r['id'],
            ]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        apAudit('ap.payment.batch_originate_failed', [
            'count' => count($ids), 'ids' => $ids, 'error' => 'persist: ' . $e->getMessage(),
        ]);
        api_error('Persist failed: ' . $e->getMessage(), 500);
    }

    apAudit('ap.payment.batch_originated', [
        'count'    => count($ids), 'ids' => $ids,
        'rail'     => $res['rail'], 'batch_id' => $res['batch_id'],
        'amount_total' => array_sum(array_map(fn($r) => (float) $r['amount'], $rows)),
    ]);

    $resp = [
        'ok'          => true,
        'rail'        => $res['rail'],
        'batch_id'    => $res['batch_id'],
        'item_count'  => count($items),
        'amount_total'=> array_sum(array_map(fn($r) => (float) $r['amount'], $rows)),
    ];
    if ($res['rail'] === 'nacha' && !empty($res['payload']['content'])) {
        $resp['nacha_file_b64'] = base64_encode((string) $res['payload']['content']);
        $resp['nacha_filename'] = $res['payload']['filename']
            ?? sprintf('ap-batch-%s-%d.ach', date('Ymd-His'), count($ids));
    }
    api_ok($resp);
}

if ($method === 'POST' && $action === 'originate') {
    RBAC::requirePermission($user, 'ap.payment.send');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM ap_payments WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if ($row['status'] !== 'sent') api_error('Originate requires status=sent', 409);
    if (!in_array($row['method'], ['ach','plaid'], true)) api_error('Originate only supports ach/plaid methods', 422);
    if (!empty($row['rail_external_ref']))      api_error('Already originated on rail ' . $row['disbursement_rail'], 409);

    // Pull vendor banking + tenant settings.
    $vendor = scopedFind(
        'SELECT id, vendor_name, vendor_type, vendor_category, payment_routing_ct,
                payment_account_ct, payment_account_type
         FROM ap_vendors_index WHERE tenant_id = :tenant_id AND vendor_name = :vn LIMIT 1',
        ['vn' => $row['vendor_name']]
    );
    if (!$vendor) api_error('Vendor not found in vendor index', 422);
    try {
        $bank = paymentRailsDecryptBank($vendor['payment_routing_ct'] ?? null,
                                        $vendor['payment_account_ct']  ?? null,
                                        'vendor ' . $vendor['vendor_name']);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    $settings = scopedFind('SELECT * FROM ap_settings WHERE tenant_id = :tenant_id LIMIT 1') ?: [];

    $item = paymentRailsBuildItem([
        'external_ref'   => 'ap_payment:' . $row['id'],
        'recipient_name' => (string) $row['vendor_name'],
        'routing'        => $bank['routing'],
        'account'        => $bank['account'],
        'account_type'   => $vendor['payment_account_type'] ?: 'checking',
        'amount_cents'   => (int) round(((float) $row['amount']) * 100),
        // CCD = corporate credit (vendor pay). PPD only for individual 1099 contractors.
        'sec_code'       => $vendor['vendor_type'] === '1099_individual' ? 'ppd' : 'ccd',
        'description'    => 'AP-PAY',
    ]);

    try {
        $res = paymentRailsDispatch('ap', $row, $settings, [$item]);
    } catch (PaymentRailsOriginateException $e) {
        apAudit('ap.payment.originate_failed', ['payment_id' => $id, 'error' => $e->getMessage()], $id);
        api_error($e->getMessage(), 422);
    }

    $itemRes = $res['items'][0] ?? ['status' => 'failed'];
    getDB()->prepare(
        'UPDATE ap_payments SET disbursement_rail = :r, rail_external_ref = :x,
                rail_status = :s, rail_originated_at = NOW()
         WHERE tenant_id = :t AND id = :id'
    )->execute([
        'r'  => $res['rail'],
        'x'  => $itemRes['rail_external_ref'] ?? $res['batch_id'],
        's'  => $itemRes['status'] ?? $res['status'],
        't'  => $tid,
        'id' => $id,
    ]);
    apAudit('ap.payment.originated', [
        'payment_id' => $id, 'rail' => $res['rail'], 'batch_id' => $res['batch_id'],
        'status' => $itemRes['status'] ?? $res['status'], 'amount' => $row['amount'],
    ], $id);

    // Surface NACHA file content for client download (driver-specific).
    $resp = [
        'ok'       => true,
        'rail'     => $res['rail'],
        'batch_id' => $res['batch_id'],
        'status'   => $itemRes['status'] ?? $res['status'],
    ];
    if ($res['rail'] === 'nacha' && !empty($res['payload']['content'])) {
        $resp['nacha_file_b64']   = base64_encode((string) $res['payload']['content']);
        $resp['nacha_filename']   = $res['payload']['filename'] ?? null;
    }
    api_ok($resp);
}

if ($method === 'POST' && $action === 'clear') {
    RBAC::requirePermission($user, 'ap.payment.send');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM ap_payments WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!apPaymentTransitionAllowed($row['status'], 'cleared')) api_error("Cannot clear from status {$row['status']}", 409);

    getDB()->prepare('UPDATE ap_payments SET status = "cleared", cleared_at = NOW() WHERE id = :id')
        ->execute(['id' => $id]);
    apAudit('ap.payment.cleared', ['payment_id' => $id], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'void') {
    RBAC::requirePermission($user, 'ap.payment.send');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM ap_payments WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if ($row['status'] === 'void') api_error('Already void', 409);
    $body = api_json_body();
    $reason = trim((string) ($body['reason'] ?? ''));
    if ($reason === '') api_error('reason required', 422);

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        // Reverse allocations: bump bills' amount_paid down and reset status if needed.
        $allocStmt = $pdo->prepare(
            'SELECT a.bill_id, a.amount_applied, b.total, b.status
             FROM ap_payment_allocations a JOIN ap_bills b ON b.id = a.bill_id
             WHERE a.payment_id = :id FOR UPDATE'
        );
        $allocStmt->execute(['id' => $id]);
        foreach ($allocStmt->fetchAll(\PDO::FETCH_ASSOC) as $a) {
            $newPaid = max(0, round((float) 0, 2)); // recompute below
        }
        // Recompute per-bill amount_paid from surviving allocations.
        $pdo->prepare(
            'UPDATE ap_bills b
             SET b.amount_paid = COALESCE((
                    SELECT SUM(a2.amount_applied) FROM ap_payment_allocations a2
                    JOIN ap_payments p2 ON p2.id = a2.payment_id
                    WHERE a2.bill_id = b.id AND p2.status != "void" AND p2.id != :id), 0),
                 b.amount_due  = b.total - COALESCE((
                    SELECT SUM(a2.amount_applied) FROM ap_payment_allocations a2
                    JOIN ap_payments p2 ON p2.id = a2.payment_id
                    WHERE a2.bill_id = b.id AND p2.status != "void" AND p2.id != :id2), 0),
                 b.status = CASE
                    WHEN b.status IN ("void","disputed") THEN b.status
                    WHEN b.total - COALESCE((SELECT SUM(a2.amount_applied) FROM ap_payment_allocations a2 JOIN ap_payments p2 ON p2.id = a2.payment_id WHERE a2.bill_id = b.id AND p2.status != "void" AND p2.id != :id3), 0) <= 0 THEN "paid"
                    WHEN COALESCE((SELECT SUM(a2.amount_applied) FROM ap_payment_allocations a2 JOIN ap_payments p2 ON p2.id = a2.payment_id WHERE a2.bill_id = b.id AND p2.status != "void" AND p2.id != :id4), 0) > 0 THEN "partially_paid"
                    ELSE "approved"
                 END
             WHERE b.id IN (SELECT bill_id FROM ap_payment_allocations WHERE payment_id = :id5)'
        )->execute(['id' => $id, 'id2' => $id, 'id3' => $id, 'id4' => $id, 'id5' => $id]);

        $pdo->prepare('UPDATE ap_payments SET status = "void", voided_at = NOW(), void_reason = :r WHERE id = :id')
            ->execute(['r' => $reason, 'id' => $id]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    apAudit('ap.payment.voided', ['payment_id' => $id, 'reason' => $reason], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
