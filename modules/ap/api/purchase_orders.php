<?php
/**
 * AP — Purchase Orders API + three-way match endpoint.
 *
 *   GET    /api/ap/purchase_orders                  — list
 *   GET    /api/ap/purchase_orders?id=N             — detail (with lines + receipts)
 *   POST   /api/ap/purchase_orders                  — create header + lines
 *   PATCH  /api/ap/purchase_orders?id=N             — update header
 *   POST   /api/ap/purchase_orders?id=N&action=receive  — record receipt
 *   POST   /api/ap/purchase_orders?id=N&action=close   — close
 *   GET    /api/ap/purchase_orders?action=match&bill_id=N  — three-way match for a bill
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/ap.php';
require_once __DIR__ . '/../lib/three_way_match.php';

$ctx      = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
$user     = $ctx['user'];
$pdo      = getDB();
$method   = api_method();
$action   = (string) ($_GET['action'] ?? '');
$id       = (int) ($_GET['id'] ?? 0);

if ($method === 'GET' && $action === 'match') {
    rbac_legacy_require($user, 'ap.view');
    $billId = (int) ($_GET['bill_id'] ?? 0);
    if ($billId <= 0) api_error('bill_id required', 422);
    api_ok(apThreeWayMatch($tenantId, $billId));
}

if ($method === 'GET' && $id > 0) {
    rbac_legacy_require($user, 'ap.view');
    $po = $pdo->prepare('SELECT * FROM ap_purchase_orders WHERE tenant_id = :t AND id = :id');
    $po->execute(['t' => $tenantId, 'id' => $id]);
    $po = $po->fetch(\PDO::FETCH_ASSOC);
    if (!$po) api_error('Not found', 404);
    $lines = $pdo->prepare('SELECT * FROM ap_purchase_order_lines WHERE po_id = :p ORDER BY line_no ASC');
    $lines->execute(['p' => $id]);
    $receipts = $pdo->prepare(
        'SELECT r.id, r.received_date, r.note, u.name AS received_by_name
           FROM ap_po_receipts r
           LEFT JOIN users u ON u.id = r.received_by_user_id
          WHERE r.tenant_id = :t AND r.po_id = :p
          ORDER BY r.received_date DESC, r.id DESC'
    );
    $receipts->execute(['t' => $tenantId, 'p' => $id]);
    api_ok([
        'po' => $po,
        'lines' => $lines->fetchAll(\PDO::FETCH_ASSOC),
        'receipts' => $receipts->fetchAll(\PDO::FETCH_ASSOC),
    ]);
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'ap.view');
    $where = ['tenant_id = :t']; $params = ['t' => $tenantId];
    if (!empty($_GET['status']))      { $where[] = 'status = :s'; $params['s'] = (string) $_GET['status']; }
    if (!empty($_GET['vendor_name'])) { $where[] = 'vendor_name = :vn'; $params['vn'] = (string) $_GET['vendor_name']; }
    $stmt = $pdo->prepare(
        'SELECT id, po_number, vendor_name, issue_date, expected_date, total, status
           FROM ap_purchase_orders
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY issue_date DESC LIMIT 500'
    );
    $stmt->execute($params);
    api_ok(['rows' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
}

if ($method === 'POST' && $action === '') {
    rbac_legacy_require($user, 'ap.bill.create');
    $body = api_json_body();
    api_require_fields($body, ['po_number', 'vendor_name', 'issue_date']);
    $lines = is_array($body['lines'] ?? null) ? $body['lines'] : [];

    $subtotal = 0.0;
    foreach ($lines as $ln) {
        $subtotal += (float) ($ln['quantity'] ?? 0) * (float) ($ln['unit_price'] ?? 0);
    }
    $tax   = (float) ($body['tax_total'] ?? 0);
    $total = round($subtotal + $tax, 2);

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO ap_purchase_orders
                (tenant_id, po_number, vendor_name, vendor_id, issue_date, expected_date,
                 currency, subtotal, tax_total, total, status, notes, created_by_user_id)
             VALUES
                (:t, :pn, :vn, :vid, :id, :ed, :cur, :sub, :tax, :tot, "open", :n, :cby)'
        )->execute([
            't' => $tenantId,
            'pn' => (string) $body['po_number'],
            'vn' => (string) $body['vendor_name'],
            'vid' => isset($body['vendor_id']) ? (int) $body['vendor_id'] : null,
            'id' => (string) $body['issue_date'],
            'ed' => $body['expected_date'] ?? null,
            'cur' => (string) ($body['currency'] ?? 'USD'),
            'sub' => round($subtotal, 2),
            'tax' => round($tax, 2),
            'tot' => $total,
            'n' => $body['notes'] ?? null,
            'cby' => (int) ($user['id'] ?? 0) ?: null,
        ]);
        $poId = (int) $pdo->lastInsertId();
        $insLine = $pdo->prepare(
            'INSERT INTO ap_purchase_order_lines
                (po_id, line_no, description, quantity, unit, unit_price, total, gl_expense_account_code)
             VALUES (:p, :ln, :d, :q, :u, :up, :tot, :gl)'
        );
        $i = 0;
        foreach ($lines as $ln) {
            $i++;
            $q  = (float) ($ln['quantity'] ?? 0);
            $up = (float) ($ln['unit_price'] ?? 0);
            $insLine->execute([
                'p' => $poId, 'ln' => $i,
                'd' => (string) ($ln['description'] ?? '—'),
                'q' => $q,
                'u' => (string) ($ln['unit'] ?? 'each'),
                'up' => $up,
                'tot' => round($q * $up, 2),
                'gl' => $ln['gl_expense_account_code'] ?? null,
            ]);
        }
        $pdo->commit();
        apAudit('ap.po.created', ['po_id' => $poId, 'po_number' => $body['po_number'], 'total' => $total], $poId);
        api_ok(['id' => $poId], 201);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_error('Could not create PO: ' . $e->getMessage(), 500);
    }
}

if ($id <= 0) api_error('id required', 422);

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'ap.bill.create');
    $body = api_json_body();
    $allowed = ['po_number','vendor_name','vendor_id','issue_date','expected_date','currency','subtotal','tax_total','total','notes','status'];
    $set = [];
    $params = ['t' => $tenantId, 'id' => $id];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $body)) { $set[] = "{$k} = :{$k}"; $params[$k] = $body[$k]; }
    }
    if (!$set) api_error('no fields supplied', 422);
    $pdo->prepare('UPDATE ap_purchase_orders SET ' . implode(', ', $set) . ' WHERE tenant_id = :t AND id = :id')
        ->execute($params);
    apAudit('ap.po.updated', ['po_id' => $id], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'receive') {
    rbac_legacy_require($user, 'ap.bill.create');
    $body = api_json_body();
    $rd   = (string) ($body['received_date'] ?? date('Y-m-d'));
    $note = (string) ($body['note'] ?? '');
    $rls  = is_array($body['lines'] ?? null) ? $body['lines'] : [];

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO ap_po_receipts (tenant_id, po_id, received_date, received_by_user_id, note)
             VALUES (:t, :p, :rd, :u, :n)'
        )->execute(['t' => $tenantId, 'p' => $id, 'rd' => $rd, 'u' => (int) ($user['id'] ?? 0) ?: null, 'n' => $note]);
        $receiptId = (int) $pdo->lastInsertId();

        $insRl = $pdo->prepare('INSERT INTO ap_po_receipt_lines (receipt_id, po_line_id, quantity) VALUES (:r, :pl, :q)');
        $bumpLine = $pdo->prepare('UPDATE ap_purchase_order_lines SET quantity_received = quantity_received + :q WHERE id = :id AND po_id = :p');
        foreach ($rls as $ln) {
            $plId = (int) ($ln['po_line_id'] ?? 0);
            $q = (float) ($ln['quantity'] ?? 0);
            if ($plId <= 0 || $q <= 0) continue;
            $insRl->execute(['r' => $receiptId, 'pl' => $plId, 'q' => $q]);
            $bumpLine->execute(['q' => $q, 'id' => $plId, 'p' => $id]);
        }

        // Recompute PO status — if every line is fully received, mark received.
        $rem = $pdo->prepare(
            'SELECT SUM(quantity - quantity_received) AS rem
               FROM ap_purchase_order_lines WHERE po_id = :p'
        );
        $rem->execute(['p' => $id]);
        $remaining = (float) ($rem->fetchColumn() ?: 0);
        $newStatus = $remaining <= 0 ? 'received' : 'partially_received';
        $pdo->prepare('UPDATE ap_purchase_orders SET status = :s WHERE tenant_id = :t AND id = :id')
            ->execute(['s' => $newStatus, 't' => $tenantId, 'id' => $id]);

        $pdo->commit();
        apAudit('ap.po.receipt_recorded', ['po_id' => $id, 'receipt_id' => $receiptId, 'status' => $newStatus], $id);
        api_ok(['receipt_id' => $receiptId, 'po_status' => $newStatus]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_error('Could not record receipt: ' . $e->getMessage(), 500);
    }
}

if ($method === 'POST' && $action === 'close') {
    rbac_legacy_require($user, 'ap.bill.create');
    $pdo->prepare('UPDATE ap_purchase_orders SET status = "closed" WHERE tenant_id = :t AND id = :id')
        ->execute(['t' => $tenantId, 'id' => $id]);
    apAudit('ap.po.closed', ['po_id' => $id], $id);
    api_ok(['ok' => true, 'status' => 'closed']);
}

api_error('Method not allowed', 405);
