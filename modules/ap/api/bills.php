<?php
/**
 * AP API — bills.
 *
 *   GET    /api/ap/bills                        → list with filters
 *   GET    /api/ap/bills?id=N                   → detail (header + lines + allocations)
 *   POST   /api/ap/bills                        → manual create (pending_approval)
 *   POST   /api/ap/bills?action=from-time-bundle
 *          body: {period_id, placement_ids[], aggregation}
 *   PATCH  /api/ap/bills?id=N                   → edit (only inbox/pending_review/pending_approval)
 *   POST   /api/ap/bills?action=approve&id=N    → two-eye gate
 *   POST   /api/ap/bills?action=void&id=N       → body: {reason}
 *   POST   /api/ap/bills?action=dispute&id=N    → body: {reason}
 *   POST   /api/ap/bills?action=post&id=N       → GL post (stub until Accounting v1.0)
 *
 * SPEC: /app/modules/ap/SPEC.md §5.1, §9.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/ap.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && !empty($_GET['id'])) {
    RBAC::requirePermission($user, 'ap.view');
    $id = (int) $_GET['id'];
    $bill = scopedFind('SELECT * FROM ap_bills WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$bill) api_error('Not found', 404);
    $pdo = getDB();
    $linesStmt = $pdo->prepare('SELECT * FROM ap_bill_lines WHERE bill_id = :id ORDER BY line_no');
    $linesStmt->execute(['id' => $id]);
    $lines = $linesStmt->fetchAll(\PDO::FETCH_ASSOC);
    $allocStmt = $pdo->prepare(
        'SELECT appa.amount_applied, appa.applied_at, app.id AS payment_id, app.pay_date, app.method, app.reference, app.amount AS payment_amount, app.status AS payment_status
         FROM ap_payment_allocations appa
         JOIN ap_payments app ON app.id = appa.payment_id
         WHERE appa.bill_id = :id ORDER BY appa.applied_at DESC'
    );
    $allocStmt->execute(['id' => $id]);
    $allocations = $allocStmt->fetchAll(\PDO::FETCH_ASSOC);
    api_ok(['bill' => $bill, 'lines' => $lines, 'allocations' => $allocations]);
}

if ($method === 'GET') {
    RBAC::requirePermission($user, 'ap.view');
    $where  = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['vendor_name'])) { $where[] = 'vendor_name = :vn';  $params['vn'] = $_GET['vendor_name']; }
    if (!empty($_GET['status']))      { $where[] = 'status = :st';       $params['st'] = $_GET['status']; }
    if (!empty($_GET['source']))      { $where[] = 'source = :src';      $params['src'] = $_GET['source']; }
    if (!empty($_GET['due_before']))  { $where[] = 'due_date < :db';     $params['db'] = $_GET['due_before']; }
    if (!empty($_GET['placement_id'])) { $where[] = 'placement_id = :pid'; $params['pid'] = (int) $_GET['placement_id']; }
    $perPage = max(1, min(200, (int) ($_GET['per_page'] ?? 50)));
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;

    $rows = scopedQuery(
        'SELECT id, internal_ref, bill_number, vendor_name, vendor_type, bill_date, due_date, currency,
                subtotal, tax_total, total, amount_paid, amount_due, status, source, placement_id, created_at
         FROM ap_bills WHERE ' . implode(' AND ', $where) . '
         ORDER BY id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );
    $cnt  = scopedQuery('SELECT COUNT(*) AS c FROM ap_bills WHERE ' . implode(' AND ', $where), $params);
    api_ok(['rows' => $rows, 'total' => (int) ($cnt[0]['c'] ?? 0), 'page' => $page, 'per_page' => $perPage]);
}

if ($method === 'POST' && $action === 'from-time-bundle') {
    RBAC::requirePermission($user, 'ap.bill.create');
    $body = api_json_body();
    api_require_fields($body, ['period_id', 'placement_ids']);
    $periodId     = (int) $body['period_id'];
    $placementIds = array_values(array_filter(array_map('intval', (array) $body['placement_ids'])));
    $aggregation  = (string) ($body['aggregation'] ?? 'per_vendor');
    if (empty($placementIds)) api_error('placement_ids required', 422);

    $drafts = apBuildDraftFromBundle($tid, $periodId, $placementIds, $aggregation);
    if (empty($drafts)) api_error('No bills could be built (all bundles had zero billable hours)', 422);

    $pdo = getDB();
    $created = [];
    $pdo->beginTransaction();
    try {
        foreach ($drafts as $d) {
            $bill = $d['bill'];
            $bill['tenant_id']          = $tid;
            $bill['internal_ref']       = apNextInternalRef($tid);
            $bill['bill_number']        = $bill['internal_ref']; // vendor number == internal on auto-gen
            $bill['created_by_user_id'] = $user['id'] ?? null;

            $billId = scopedInsert('ap_bills', $bill);

            foreach ($d['lines'] as $l) {
                $l['bill_id'] = $billId;
                $stmt = $pdo->prepare(
                    'INSERT INTO ap_bill_lines
                      (bill_id, line_no, source_type, source_ref_id, placement_id, rate_snapshot_id,
                       description, quantity, unit, unit_price, subtotal, tax_rate_pct, tax_amount, total,
                       gl_expense_account_code, is_1099_eligible)
                     VALUES
                      (:bill_id, :line_no, :source_type, :source_ref_id, :placement_id, :rate_snapshot_id,
                       :description, :quantity, :unit, :unit_price, :subtotal, :tax_rate_pct, :tax_amount, :total,
                       :gl_expense_account_code, :is_1099_eligible)'
                );
                $stmt->execute($l);
            }

            foreach ($d['bundle_ids'] as $bid) {
                $pdo->prepare(
                    'UPDATE time_downstream_feed
                     SET status = "consumed", consumed_at = NOW(),
                         consumed_by_module = "ap", consumed_ref_id = :bid_id
                     WHERE id = :bid AND tenant_id = :tid AND status = "ready"'
                )->execute(['bid_id' => $billId, 'bid' => (int) $bid, 'tid' => $tid]);
            }

            // Upsert into vendors_index + companies directory for non-individuals
            $companyId = null;
            if (in_array($bill['vendor_type'], ['c2c_corp','w9_business','utility','other'], true)) {
                require_once __DIR__ . '/../../people/lib/companies.php';
                $companyId = companiesUpsertByName($tid, (string) $bill['vendor_name'], [
                    'created_by_user_id' => $user['id'] ?? null,
                ], ['vendor']);
                companiesBumpUsage($companyId);
                $pdo->prepare('UPDATE ap_bills SET vendor_company_id = :cid WHERE id = :id')
                    ->execute(['cid' => $companyId, 'id' => $billId]);
            }
            $pdo->prepare(
                'INSERT INTO ap_vendors_index (tenant_id, vendor_name, company_id, vendor_type, requires_1099, last_bill_at, placement_id_last)
                 VALUES (:t, :v, :cid, :vt, :r, NOW(), :pid)
                 ON DUPLICATE KEY UPDATE
                   company_id = COALESCE(VALUES(company_id), company_id),
                   vendor_type = VALUES(vendor_type),
                   requires_1099 = GREATEST(requires_1099, VALUES(requires_1099)),
                   last_bill_at = NOW(),
                   placement_id_last = VALUES(placement_id_last)'
            )->execute([
                't'  => $tid,
                'v'  => $bill['vendor_name'],
                'cid'=> $companyId,
                'vt' => $bill['vendor_type'],
                'r'  => $bill['vendor_type'] === '1099_individual' ? 1 : 0,
                'pid'=> !empty($d['lines'][0]['placement_id']) ? (int) $d['lines'][0]['placement_id'] : null,
            ]);

            apAudit('ap.bill.created', [
                'bill_id' => $billId, 'internal_ref' => $bill['internal_ref'],
                'vendor' => $bill['vendor_name'], 'source' => 'time_bundle',
                'period_id' => $periodId, 'bundle_ids' => $d['bundle_ids'],
                'aggregation' => $aggregation,
            ], $billId);

            $created[] = ['id' => $billId, 'internal_ref' => $bill['internal_ref'], 'vendor_name' => $bill['vendor_name'], 'total' => $bill['total']];
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    api_ok(['bills_created' => $created], 201);
}

if ($method === 'POST' && $action === '') {
    RBAC::requirePermission($user, 'ap.bill.create');
    $body = api_json_body();
    api_require_fields($body, ['vendor_name', 'lines']);
    if (empty($body['lines']) || !is_array($body['lines'])) api_error('lines must be a non-empty array', 422);

    $pdo = getDB();
    $taxStmt = $pdo->prepare('SELECT ap_default_terms FROM tenants WHERE id = :id');
    $taxStmt->execute(['id' => $tid]);
    $cfg = $taxStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    $netDays = preg_match('/^NET(\d+)$/i', (string) ($cfg['ap_default_terms'] ?? 'NET30'), $m) ? (int) $m[1] : 30;
    $taxPct  = (float) ($body['tax_rate_pct'] ?? 0);

    $computed = apComputeTotals($body['lines'], $taxPct);

    $pdo->beginTransaction();
    try {
        $internalRef = apNextInternalRef($tid);
        $vendorType = (string) ($body['vendor_type'] ?? 'other');
        $vendorCompanyId = !empty($body['vendor_company_id']) ? (int) $body['vendor_company_id'] : null;
        if (!$vendorCompanyId && in_array($vendorType, ['c2c_corp','w9_business','utility','other'], true)) {
            require_once __DIR__ . '/../../people/lib/companies.php';
            $vendorCompanyId = companiesUpsertByName($tid, (string) $body['vendor_name'], [
                'created_by_user_id' => $user['id'] ?? null,
            ], ['vendor']);
            companiesBumpUsage($vendorCompanyId);
        }
        $billId = scopedInsert('ap_bills', [
            'tenant_id'         => $tid,
            'bill_number'       => (string) ($body['bill_number'] ?? $internalRef),
            'internal_ref'      => $internalRef,
            'vendor_name'       => (string) $body['vendor_name'],
            'vendor_company_id' => $vendorCompanyId,
            'vendor_type'       => $vendorType,
            'received_at'       => (string) ($body['received_at'] ?? date('Y-m-d')),
            'bill_date'         => (string) ($body['bill_date']   ?? date('Y-m-d')),
            'due_date'          => (string) ($body['due_date']    ?? date('Y-m-d', strtotime("+{$netDays} days"))),
            'currency'          => (string) ($body['currency']    ?? 'USD'),
            'po_number'         => $body['po_number']     ?? null,
            'placement_id'      => !empty($body['placement_id']) ? (int) $body['placement_id'] : null,
            'notes_internal'    => $body['notes_internal'] ?? null,
            'subtotal'          => $computed['subtotal'],
            'tax_total'         => $computed['tax_total'],
            'total'             => $computed['total'],
            'amount_due'        => $computed['total'],
            'status'            => 'pending_approval',
            'source'            => 'manual',
            'created_by_user_id'=> $user['id'] ?? null,
        ]);
        $line_no = 1;
        foreach ($computed['lines'] as $l) {
            $stmt = $pdo->prepare(
                'INSERT INTO ap_bill_lines
                  (bill_id, line_no, source_type, description, quantity, unit, unit_price,
                   subtotal, tax_rate_pct, tax_amount, total, gl_expense_account_code, is_1099_eligible)
                 VALUES
                  (:bill_id, :line_no, "manual", :description, :quantity, :unit, :unit_price,
                   :subtotal, :tax_rate_pct, :tax_amount, :total, :gl, :is_1099)'
            );
            $stmt->execute([
                'bill_id'     => $billId,
                'line_no'     => $line_no++,
                'description' => $l['description'] ?? '',
                'quantity'    => $l['quantity']    ?? 0,
                'unit'        => $l['unit']        ?? 'each',
                'unit_price'  => $l['unit_price']  ?? 0,
                'subtotal'    => $l['subtotal'],
                'tax_rate_pct'=> $l['tax_rate_pct'],
                'tax_amount'  => $l['tax_amount'],
                'total'       => $l['total'],
                'gl'          => $l['gl_expense_account_code'] ?? null,
                'is_1099'     => !empty($l['is_1099_eligible']) ? 1 : 0,
            ]);
        }
        apAudit('ap.bill.created', ['bill_id' => $billId, 'internal_ref' => $internalRef, 'source' => 'manual'], $billId);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    api_ok(['id' => $billId, 'internal_ref' => $internalRef], 201);
}

if ($method === 'PATCH') {
    RBAC::requirePermission($user, 'ap.bill.create');
    $id = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM ap_bills WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!in_array($row['status'], ['inbox','pending_review','pending_approval'], true)) {
        api_error('Only inbox/pending_review/pending_approval bills can be edited', 409);
    }

    $body = api_json_body();
    $editable = ['vendor_name','vendor_company_id','vendor_type','bill_number','bill_date','due_date','po_number','notes_internal','placement_id'];
    $sets = []; $binds = ['id' => $id];
    foreach ($editable as $f) {
        if (array_key_exists($f, $body)) {
            $sets[] = "{$f} = :{$f}";
            $binds[$f] = $body[$f];
        }
    }
    if (!$sets) api_error('Nothing to update', 422);
    getDB()->prepare('UPDATE ap_bills SET ' . implode(',', $sets) . ' WHERE id = :id')->execute($binds);
    apAudit('ap.bill.updated', ['bill_id' => $id, 'fields' => array_keys(array_intersect_key($body, array_flip($editable)))], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'approve') {
    RBAC::requirePermission($user, 'ap.bill.approve');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM ap_bills WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!apBillTransitionAllowed($row['status'], 'approved')) api_error("Cannot approve from status {$row['status']}", 409);
    if ((int) ($row['created_by_user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
        api_error('Two-eye control: you cannot approve your own bill.', 403);
    }
    // Validation: refuse if any line total <= 0 (SPEC §9)
    $lineCheck = getDB()->prepare('SELECT MIN(total) AS min_total FROM ap_bill_lines WHERE bill_id = :id');
    $lineCheck->execute(['id' => $id]);
    $minTotal = (float) ($lineCheck->fetchColumn() ?? 0);
    if ($minTotal <= 0) api_error('All bill lines must have total > 0', 422);

    getDB()->prepare('UPDATE ap_bills SET status = "approved", approved_by_user_id = :u, approved_at = NOW() WHERE id = :id')
        ->execute(['u' => $user['id'] ?? null, 'id' => $id]);
    apAudit('ap.bill.approved', ['bill_id' => $id, 'internal_ref' => $row['internal_ref']], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'void') {
    RBAC::requirePermission($user, 'ap.bill.void');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM ap_bills WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if ($row['status'] === 'void') api_error('Already void', 409);
    $body = api_json_body();
    $reason = trim((string) ($body['reason'] ?? ''));
    if ($reason === '') api_error('reason required', 422);

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $allocCount = $pdo->prepare('SELECT COUNT(*) FROM ap_payment_allocations WHERE bill_id = :id');
        $allocCount->execute(['id' => $id]);
        $hasPayments = (int) $allocCount->fetchColumn() > 0;

        if (!$hasPayments) {
            $pdo->prepare(
                'UPDATE time_downstream_feed
                 SET status = "ready", consumed_at = NULL, consumed_by_module = NULL, consumed_ref_id = NULL
                 WHERE tenant_id = :t AND consumed_by_module = "ap" AND consumed_ref_id = :id'
            )->execute(['t' => $tid, 'id' => $id]);
        }

        $pdo->prepare(
            'UPDATE ap_bills SET status = "void", voided_at = NOW(),
             voided_by_user_id = :u, void_reason = :r WHERE id = :id'
        )->execute(['u' => $user['id'] ?? null, 'r' => $reason, 'id' => $id]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    apAudit('ap.bill.voided', [
        'bill_id' => $id, 'internal_ref' => $row['internal_ref'],
        'reason' => $reason, 'had_payments' => $hasPayments,
    ], $id);
    api_ok(['ok' => true, 'bundles_released' => !$hasPayments]);
}

if ($method === 'POST' && $action === 'dispute') {
    RBAC::requirePermission($user, 'ap.bill.approve');
    $id = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM ap_bills WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!apBillTransitionAllowed($row['status'], 'disputed')) api_error("Cannot dispute from status {$row['status']}", 409);
    $body = api_json_body();
    $reason = trim((string) ($body['reason'] ?? ''));
    if ($reason === '') api_error('reason required', 422);
    getDB()->prepare('UPDATE ap_bills SET status = "disputed", disputed_at = NOW(), dispute_reason = :r WHERE id = :id')
        ->execute(['r' => $reason, 'id' => $id]);
    apAudit('ap.bill.disputed', ['bill_id' => $id, 'internal_ref' => $row['internal_ref'], 'reason' => $reason], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'post') {
    // STUB: Accounting v1.0 not yet shipped. Emit audit + record intent.
    // Once Accounting lands, this will POST to /api/v1/accounting/journal-entries.
    RBAC::requirePermission($user, 'ap.bill.post');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM ap_bills WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!in_array($row['status'], ['approved','partially_paid','paid'], true)) {
        api_error("Cannot post from status {$row['status']}", 409);
    }
    apAudit('ap.bill.posted', [
        'bill_id' => $id, 'internal_ref' => $row['internal_ref'],
        'note' => 'GL posting stubbed until Accounting v1.0 ships',
    ], $id);
    api_ok(['ok' => true, 'gl_stubbed' => true, 'journal_entry_id' => null]);
}

api_error('Method not allowed', 405);
