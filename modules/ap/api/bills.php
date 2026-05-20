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
require_once __DIR__ . '/../../../core/StorageService.php';
require_once __DIR__ . '/../../../core/storage_register.php';
require_once __DIR__ . '/../lib/ap.php';

use Core\StorageService;

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'POST' && $action === 'extract_receipt') {
    // AI-assist for a single bill line — read the uploaded receipt image/PDF
    // and return suggested fields (item_type, qty, unit, unit_price, GL guess).
    // The user must accept each value; the response is suggestion, not authoritative.
    rbac_legacy_require($user, 'ap.bill.create');
    require_once __DIR__ . '/../../../core/ai_service.php';
    $lineId = (int) ($_GET['line_id'] ?? 0);
    if ($lineId <= 0) api_error('line_id required', 400);
    $line = getDB()->prepare(
        'SELECT bl.id FROM ap_bill_lines bl JOIN ap_bills b ON b.id = bl.bill_id
         WHERE bl.id = :id AND b.tenant_id = :tid'
    );
    $line->execute(['id' => $lineId, 'tid' => $tid]);
    if (!$line->fetch(\PDO::FETCH_ASSOC)) api_error('Not found', 404);
    $body = api_json_body();
    api_require_fields($body, ['storage_key']);
    $signedUrl = StorageService::getInstance()->get_signed_url((string) $body['storage_key']);

    $schemaHint = <<<JSON
{
  "merchant":      string|null,
  "transaction_date": string|null,         // ISO YYYY-MM-DD
  "description":   string|null,            // concise line description
  "item_type":     "expense"|"materials"|"mileage"|"per_diem"|"reimbursement"|"other",
  "quantity":      number|null,
  "unit":          string|null,            // each|mile|day|gallon|...
  "unit_price":    number|null,
  "subtotal":      number|null,
  "tax_amount":    number|null,
  "total":         number|null,
  "currency":      string|null
}
JSON;

    try {
        $res = aiExtract([
            'feature_key' => 'ap.bill.line.from_receipt',
            'instruction' => 'Extract a single expense receipt into the JSON shape below. Pick the best item_type from the enum. If multiple items appear on the receipt, summarise into one line and put detail in description.',
            'schema_hint' => $schemaHint,
            'images'      => [['url' => $signedUrl]],
        ]);
    } catch (\Throwable $e) { api_error('Extraction failed: ' . $e->getMessage(), 502); }
    apAudit('ap.bill.line.extracted_from_receipt', ['line_id' => $lineId, 'model' => $res['model'], 'interaction_id' => $res['interaction_id']], $lineId);
    api_ok(['draft' => $res['data'], 'model' => $res['model'], 'interaction_id' => $res['interaction_id'], 'review_required' => true]);
}

if ($method === 'POST' && $action === 'extract_from_pdf') {
    // AI-assist: read the uploaded vendor invoice (PDF or image) and return
    // a structured draft the BillCreate form can pre-fill. The user remains
    // responsible for reviewing every field — the response is suggestion,
    // not authoritative data.
    rbac_legacy_require($user, 'ap.bill.create');
    require_once __DIR__ . '/../../../core/ai_service.php';
    $body = api_json_body();
    api_require_fields($body, ['storage_key']);
    $key = (string) $body['storage_key'];

    // Generate a short-lived signed URL the LLM can fetch directly.
    $signedUrl = StorageService::getInstance()->get_signed_url($key);

    $instruction =
        'Extract the vendor invoice / bill into a JSON draft. ' .
        'If the document has multiple pages, combine line items in order. ' .
        'Map ambiguous categories conservatively. Be aggressive about ' .
        'splitting bundled descriptions into separate line items when ' .
        'unit prices and quantities are visible.';

    $schemaHint = <<<JSON
{
  "vendor_name":      string|null,           // billing entity exactly as printed
  "bill_number":      string|null,           // vendor's invoice/bill number
  "bill_date":        string|null,           // ISO YYYY-MM-DD
  "due_date":         string|null,           // ISO YYYY-MM-DD
  "po_number":        string|null,
  "currency":         string|null,           // 3-letter ISO (default USD if not stated)
  "subtotal":         number|null,
  "tax_total":        number|null,
  "total":            number|null,
  "notes":            string|null,
  "lines": [
    {
      "item_type":     "labor"|"expense"|"materials"|"fixed_fee"|"milestone"|"discount"|"subscription"|"mileage"|"per_diem"|"reimbursement"|"other",
      "description":   string,
      "quantity":      number|null,
      "unit":          string|null,          // hour|each|mile|day|month|...
      "unit_price":    number|null,
      "subtotal":      number|null
    }
  ]
}
JSON;

    try {
        $res = aiExtract([
            'feature_key' => 'ap.bill.from_pdf',
            'instruction' => $instruction,
            'schema_hint' => $schemaHint,
            'images'      => [['url' => $signedUrl, 'mime' => 'application/pdf']],
            'max_output_tokens' => 2000,
        ]);
    } catch (\Throwable $e) {
        api_error('Extraction failed: ' . $e->getMessage(), 502, ['extractor' => 'gpt']);
    }
    apAudit('ap.bill.extracted_from_pdf', [
        'storage_key' => $key,
        'model'       => $res['model'],
        'latency_ms'  => $res['latency_ms'],
        'lines'       => is_array($res['data']['lines'] ?? null) ? count($res['data']['lines']) : 0,
        'interaction_id' => $res['interaction_id'],
    ]);
    api_ok([
        'draft'           => $res['data'],
        'model'           => $res['model'],
        'latency_ms'      => $res['latency_ms'],
        'interaction_id'  => $res['interaction_id'],
        'review_required' => true,
    ]);
}

if ($method === 'GET' && $action === 'upload_url') {
    // Generate a presigned S3 POST for a bill or bill-line attachment.
    //   ?id=N            → bill-level (vendor invoice PDF)
    //   ?line_id=N       → per-line (e.g. expense receipt)
    rbac_legacy_require($user, 'ap.bill.create');
    $billId = (int) ($_GET['id'] ?? 0);
    $lineId = (int) ($_GET['line_id'] ?? 0);
    if ($billId <= 0 && $lineId <= 0) api_error('id or line_id required', 400);
    $fileName = (string) ($_GET['file_name'] ?? 'attachment');
    $entityType = $lineId ? 'bill_line' : 'bill';
    $entityId   = $lineId ?: $billId;
    $key  = StorageService::getInstance()->build_key('ap', $tid, $entityType, $entityId, $fileName);
    $post = StorageService::getInstance()->get_presigned_post($key);
    api_ok(['storage_key' => $key, 'upload' => $post]);
}

if ($method === 'POST' && $action === 'attach') {
    // Register an uploaded vendor invoice PDF on the bill header.
    rbac_legacy_require($user, 'ap.bill.create');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $row = scopedFind('SELECT id FROM ap_bills WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    $body = api_json_body();
    api_require_fields($body, ['storage_key','filename']);
    $sid = registerStorageObject(
        $tid, 'ap', 'bill', $id,
        (string) $body['storage_key'], (string) $body['filename'],
        $body['mime'] ?? null, isset($body['size_bytes']) ? (int) $body['size_bytes'] : null,
        $user['id'] ?? null
    );
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    getDB()->prepare('UPDATE ap_bills SET attachment_storage_object_id = :s WHERE id = :id')
        ->execute(['s' => $sid, 'id' => $id]);
    apAudit('ap.bill.attachment.added', ['bill_id' => $id, 'storage_object_id' => $sid, 'filename' => $body['filename']], $id);
    api_ok(['ok' => true, 'storage_object_id' => $sid]);
}

if ($method === 'POST' && $action === 'attach_line') {
    // Attach a receipt to a single ap_bill_lines row (expense / reimbursement / materials).
    rbac_legacy_require($user, 'ap.bill.create');
    $lineId = (int) ($_GET['line_id'] ?? 0);
    if ($lineId <= 0) api_error('line_id required', 400);
    $line = getDB()->prepare(
        'SELECT bl.id, bl.bill_id, b.tenant_id
         FROM ap_bill_lines bl JOIN ap_bills b ON b.id = bl.bill_id
         WHERE bl.id = :id AND b.tenant_id = :tid'
    );
    $line->execute(['id' => $lineId, 'tid' => $tid]);
    $r = $line->fetch(\PDO::FETCH_ASSOC);
    if (!$r) api_error('Not found', 404);
    $body = api_json_body();
    api_require_fields($body, ['storage_key','filename']);
    $sid = registerStorageObject(
        $tid, 'ap', 'bill_line', $lineId,
        (string) $body['storage_key'], (string) $body['filename'],
        $body['mime'] ?? null, isset($body['size_bytes']) ? (int) $body['size_bytes'] : null,
        $user['id'] ?? null
    );
    getDB()->prepare('UPDATE ap_bill_lines SET attachment_storage_object_id = :s WHERE id = :id')
        ->execute(['s' => $sid, 'id' => $lineId]);
    apAudit('ap.bill.line.attachment.added', ['bill_id' => (int) $r['bill_id'], 'line_id' => $lineId, 'storage_object_id' => $sid], (int) $r['bill_id']);
    api_ok(['ok' => true, 'storage_object_id' => $sid]);
}

if ($method === 'GET' && $action === 'attachment_url') {
    // Sign + return a download URL for the bill or bill-line attachment.
    rbac_legacy_require($user, 'ap.view');
    $sid = (int) ($_GET['storage_object_id'] ?? 0);
    if ($sid <= 0) api_error('storage_object_id required', 400);
    $obj = scopedFind('SELECT s3_key, filename FROM storage_objects WHERE id = :id AND tenant_id = :tenant_id', ['id' => $sid]);
    if (!$obj) api_error('Not found', 404);
    $url = StorageService::getInstance()->get_signed_url($obj['s3_key']);
    api_ok(['signed_url' => $url, 'filename' => $obj['filename']]);
}

if ($method === 'GET' && !empty($_GET['id'])) {
    rbac_legacy_require($user, 'ap.view');
    $id = (int) $_GET['id'];
    $bill = scopedFind('SELECT * FROM ap_bills WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$bill) api_error('Not found', 404);
    $pdo = getDB();
    $linesStmt = $pdo->prepare('SELECT * FROM ap_bill_lines WHERE bill_id = :id ORDER BY line_no');
    $linesStmt->execute(['id' => $id]);
    $lines = $linesStmt->fetchAll(\PDO::FETCH_ASSOC);
    // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
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
    rbac_legacy_require($user, 'ap.view');
    $where  = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['vendor_name'])) { $where[] = 'vendor_name = :vn';  $params['vn'] = $_GET['vendor_name']; }
    if (!empty($_GET['status']))      { $where[] = 'status = :st';       $params['st'] = $_GET['status']; }
    if (!empty($_GET['source']))      { $where[] = 'source = :src';      $params['src'] = $_GET['source']; }
    if (!empty($_GET['due_before']))  { $where[] = 'due_date < :db';     $params['db'] = $_GET['due_before']; }
    if (!empty($_GET['placement_id'])) { $where[] = 'placement_id = :pid'; $params['pid'] = (int) $_GET['placement_id']; }
    // Sprint 6c — respect the header's multi-entity switcher.
    if (!empty($_GET['entity_id']))    { $where[] = 'entity_id = :eid';   $params['eid'] = (int) $_GET['entity_id']; }
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
    rbac_legacy_require($user, 'ap.bill.create');
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
                $l['bill_id']   = $billId;
                $l['item_type'] = apNormalizeItemType($l['item_type'] ?? null, $l['source_type'] ?? 'time');
                $stmt = $pdo->prepare(
                    'INSERT INTO ap_bill_lines
                      (bill_id, line_no, source_type, item_type, source_ref_id, placement_id, rate_snapshot_id,
                       description, quantity, unit, unit_price, subtotal, tax_rate_pct, tax_amount, total,
                       gl_expense_account_code, is_1099_eligible)
                     VALUES
                      (:bill_id, :line_no, :source_type, :item_type, :source_ref_id, :placement_id, :rate_snapshot_id,
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
                // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
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
    rbac_legacy_require($user, 'ap.bill.create');
    $body = api_json_body();
    api_require_fields($body, ['vendor_name', 'lines']);
    if (empty($body['lines']) || !is_array($body['lines'])) api_error('lines must be a non-empty array', 422);

    $pdo = getDB();
    $taxStmt = $pdo->prepare('SELECT ap_default_terms FROM tenants WHERE id = :id');
    $taxStmt->execute(['id' => $tid]);
    $cfg = $taxStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    $netDays = preg_match('/^NET(\d+)$/i', (string) ($cfg['ap_default_terms'] ?? 'NET30'), $m) ? (int) $m[1] : 30;

    // Per-vendor override: if the companies row (or ap_vendors_index) has
    // a non-null payment_terms_days, use that. Look up by vendor_company_id
    // first; fall back to name match.
    try {
        $vc = !empty($body['vendor_company_id']) ? (int) $body['vendor_company_id'] : 0;
        if ($vc > 0) {
            $vt = $pdo->prepare("SELECT payment_terms_days FROM companies WHERE tenant_id = :t AND id = :id AND payment_terms_days IS NOT NULL LIMIT 1");
            $vt->execute(['t' => $tid, 'id' => $vc]);
            $perVendor = $vt->fetchColumn();
        } else {
            $vt = $pdo->prepare("SELECT payment_terms_days FROM companies WHERE tenant_id = :t AND name = :n AND payment_terms_days IS NOT NULL LIMIT 1");
            $vt->execute(['t' => $tid, 'n' => (string) $body['vendor_name']]);
            $perVendor = $vt->fetchColumn();
        }
        if ($perVendor !== false && $perVendor !== null && (int) $perVendor > 0) {
            $netDays = (int) $perVendor;
        }
    } catch (\Throwable $_) { /* companies.payment_terms_days may not exist yet — fall through */ }
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
            'entity_id'         => !empty($body['entity_id'])    ? (int) $body['entity_id']    : null,
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
                  (bill_id, line_no, source_type, item_type, description, quantity, unit, unit_price,
                   subtotal, tax_rate_pct, tax_amount, total, gl_expense_account_code, is_1099_eligible)
                 VALUES
                  (:bill_id, :line_no, "manual", :item_type, :description, :quantity, :unit, :unit_price,
                   :subtotal, :tax_rate_pct, :tax_amount, :total, :gl, :is_1099)'
            );
            $stmt->execute([
                'bill_id'     => $billId,
                'line_no'     => $line_no++,
                'item_type'   => apNormalizeItemType($l['item_type'] ?? null, 'manual'),
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
    rbac_legacy_require($user, 'ap.bill.create');
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
    rbac_legacy_require($user, 'ap.bill.approve');
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

    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    getDB()->prepare('UPDATE ap_bills SET status = "approved", approved_by_user_id = :u, approved_at = NOW() WHERE id = :id')
        ->execute(['u' => $user['id'] ?? null, 'id' => $id]);
    apAudit('ap.bill.approved', ['bill_id' => $id, 'internal_ref' => $row['internal_ref']], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'void') {
    rbac_legacy_require($user, 'ap.bill.void');
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

        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
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
    rbac_legacy_require($user, 'ap.bill.approve');
    $id = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM ap_bills WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!apBillTransitionAllowed($row['status'], 'disputed')) api_error("Cannot dispute from status {$row['status']}", 409);
    $body = api_json_body();
    $reason = trim((string) ($body['reason'] ?? ''));
    if ($reason === '') api_error('reason required', 422);
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    getDB()->prepare('UPDATE ap_bills SET status = "disputed", disputed_at = NOW(), dispute_reason = :r WHERE id = :id')
        ->execute(['r' => $reason, 'id' => $id]);
    apAudit('ap.bill.disputed', ['bill_id' => $id, 'internal_ref' => $row['internal_ref'], 'reason' => $reason], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'post') {
    rbac_legacy_require($user, 'ap.bill.post');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM ap_bills WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!in_array($row['status'], ['approved','partially_paid','paid'], true)) {
        api_error("Cannot post from status {$row['status']}", 409);
    }
    if (!empty($row['journal_entry_id'])) {
        api_ok(['ok' => true, 'journal_entry_id' => (int) $row['journal_entry_id'], 'idempotent_replay' => true]);
    }

    require_once __DIR__ . '/../../accounting/lib/accounting.php';
    require_once __DIR__ . '/../../../core/posting_engine/process.php';

    $pdo = getDB();
    $linesStmt = $pdo->prepare('SELECT * FROM ap_bill_lines WHERE bill_id = :id ORDER BY line_no');
    $linesStmt->execute(['id' => $id]);
    $billLines = $linesStmt->fetchAll(\PDO::FETCH_ASSOC);

    // Build the JE shape — N expense lines (debits) + 1 AP credit.
    $payloadLines = [];
    foreach ($billLines as $bl) {
        $acct = $bl['gl_expense_account_code'] ?? '5000';
        $payloadLines[] = [
            'account_code' => $acct,
            'debit'        => (float) $bl['total'],
            'credit'       => 0,
            'description'  => $bl['description'],
            'counterparty_company_id' => !empty($row['vendor_company_id']) ? (int) $row['vendor_company_id'] : null,
        ];
    }
    $payloadLines[] = [
        'account_code' => '2000',
        'debit'        => 0,
        'credit'       => (float) $row['total'],
        'description'  => "AP " . $row['internal_ref'] . " — " . $row['vendor_name'],
        'counterparty_company_id' => !empty($row['vendor_company_id']) ? (int) $row['vendor_company_id'] : null,
    ];

    $jeLines = $payloadLines; // reused if we have to fall back to legacy direct post

    // Sprint 7e — preferred path: emit ap.bill.approved into the event
    // layer; the engine renders + posts via the seed-pack passthrough
    // template, writes the accounting_subledger_links row, and stamps
    // accounting_events.posted_at.  Falls back to the legacy direct post
    // when no rule matches (so pre-Sprint-7e tenants keep working).
    $eventResult = null; $eventError = null;
    try {
        $eventResult = accountingProcessEvent($tid, [
            'entity_id'        => !empty($row['entity_id']) ? (int) $row['entity_id'] : 0,
            'event_type'       => 'ap.bill.approved',
            'source_module'    => 'ap',
            'source_record_id' => 'ap_bill:' . $id,
            'event_date'       => (string) $row['bill_date'],
            'payload'          => [
                'bill_id'      => (int) $id,
                'internal_ref' => (string) $row['internal_ref'],
                'vendor_name'  => (string) $row['vendor_name'],
                'vendor_company_id' => !empty($row['vendor_company_id']) ? (int) $row['vendor_company_id'] : null,
                'amount'       => (float) $row['total'],
                'currency'     => (string) $row['currency'],
                'lines'        => $payloadLines,
            ],
        ], $user['id'] ?? null);
    } catch (\Throwable $e) {
        $eventError = $e->getMessage();
    }

    if ($eventResult && ($eventResult['status'] ?? null) === 'posted') {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare('UPDATE ap_bills SET journal_entry_id = :j WHERE id = :id')
            ->execute(['j' => $eventResult['journal_entry_id'], 'id' => $id]);
        apAudit('ap.bill.posted', [
            'bill_id' => $id, 'internal_ref' => $row['internal_ref'],
            'journal_entry_id' => (int) $eventResult['journal_entry_id'],
            'accounting_event_id' => (int) ($eventResult['event_id'] ?? 0),
            'idempotent_replay' => !empty($eventResult['idempotent_replay']),
            'via' => 'event_layer',
        ], $id);
        api_ok([
            'ok' => true,
            'journal_entry_id' => (int) $eventResult['journal_entry_id'],
            'je_number' => $eventResult['je_number'] ?? null,
            'idempotent_replay' => !empty($eventResult['idempotent_replay']),
            'accounting_event_id' => (int) ($eventResult['event_id'] ?? 0),
            'via' => 'event_layer',
        ]);
    }

    // ── Fallback path: legacy direct posting ────────────────────────
    // Triggered when (a) the engine returned 'ignored' (no rule matched
    // for this tenant — they haven't seeded yet), or (b) it threw before
    // resolving a rule.  Either way, we still post the JE and mark the
    // event row so the audit trail isn't lost.
    require_once __DIR__ . '/../../../core/module_emission_discipline.php';
    moduleEmissionDisciplineLog('ap', 'ap.bill.approved', [
        'bill_id'      => (int) $id,
        'event_error'  => $eventError,
        'event_status' => $eventResult['status'] ?? null,
    ]);
    try {
        $res = accountingPostJe($tid, [
            'posting_date'    => $row['bill_date'],
            'currency'        => $row['currency'],
            'source_module'   => 'ap',
            'source_ref_type' => 'ap_bill',
            'source_ref_id'   => $id,
            'idempotency_key' => sprintf('ap:bill:%d:post', $id),
            'memo'            => "AP Bill {$row['internal_ref']} / {$row['vendor_name']}",
            'lines'           => $jeLines,
        ], $user['id'] ?? null, true);
    } catch (\Throwable $e) {
        api_error('GL post failed: ' . $e->getMessage()
                . ($eventError ? ' | event-layer error: ' . $eventError : ''), 422);
    }

    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare('UPDATE ap_bills SET journal_entry_id = :j WHERE id = :id')
        ->execute(['j' => $res['je_id'], 'id' => $id]);

    // Sprint 7e fallback also writes a subledger_links row + flips any
    // 'ignored' event to 'posted' so the audit trail is intact.
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:t, "ap", :sr, :je, "primary")'
        )->execute([
            't'  => $tid,
            'sr' => 'ap_bill:' . $id,
            'je' => (int) $res['je_id'],
        ]);
        if ($eventResult && !empty($eventResult['event_id'])) {
            // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
            $pdo->prepare(
                'UPDATE accounting_events
                    SET status = "posted", journal_entry_id = :je, posted_at = NOW(),
                        error_message = "fallback: legacy direct post (no rule matched)"
                  WHERE id = :id AND status IN ("ignored","failed","received","mapped")'
            )->execute(['je' => (int) $res['je_id'], 'id' => (int) $eventResult['event_id']]);
        }
    } catch (\Throwable $_) { /* tables absent in pre-7b tenants — non-fatal */ }

    apAudit('ap.bill.posted', [
        'bill_id' => $id, 'internal_ref' => $row['internal_ref'],
        'journal_entry_id' => $res['je_id'], 'je_number' => $res['je_number'],
        'idempotent_replay' => $res['idempotent_replay'],
        'via' => 'legacy_direct',
        'event_layer_status' => $eventResult['status'] ?? null,
    ], $id);
    api_ok([
        'ok' => true,
        'journal_entry_id' => $res['je_id'],
        'je_number' => $res['je_number'],
        'idempotent_replay' => $res['idempotent_replay'],
        'via' => 'legacy_direct',
    ]);
}

// =======================================================================
// Post with intercompany split — uses the shared IC engine to emit one
// balanced JE per target entity instead of a single-entity Dr/Cr pair.
//
// Body shape mirrors intercompany.php?action=post_split's `splits[]`:
//   {
//     entity_id:  <source/AP-owning entity id (defaults to tenant default)>,
//     ap_account_code: "2000",
//     splits: [
//       {entity_id:1, account_code:"5100", amount:700, memo:"..."},
//       {entity_id:2, account_code:"5100", amount:300, memo:"..."},
//     ]
//   }
// Splits.total must equal bill.total.
// Idempotency: ic:bill:<id>  (different from ap:bill:<id>:post so you
// can't accidentally double-post the same bill one way and the other).
// =======================================================================
if ($method === 'POST' && $action === 'post_with_ic_split') {
    rbac_legacy_require($user, 'ap.bill.post');
    rbac_legacy_require($user, 'accounting.je.post');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM ap_bills WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!in_array($row['status'], ['approved','partially_paid','paid'], true)) {
        api_error("Cannot post from status {$row['status']}", 409);
    }
    if (!empty($row['journal_entry_id'])) {
        api_ok([
            'ok' => true,
            'journal_entry_id'       => (int) $row['journal_entry_id'],
            'intercompany_group_id'  => $row['intercompany_group_id'] ?? null,
            'idempotent_replay'      => true,
        ]);
    }

    require_once __DIR__ . '/../../accounting/lib/accounting.php';
    require_once __DIR__ . '/../../accounting/lib/intercompany.php';

    $body = api_json_body();
    // Accept either the dialog-native shape (source.entity_id + source.offset_line)
    // or a slim shape (entity_id + ap_account_code + splits).
    $source = $body['source'] ?? null;
    if ($source && !empty($source['entity_id'])) {
        $sourceEntityId = (int) $source['entity_id'];
        $apAccount      = (string) ($source['offset_line']['account_code'] ?? '2000');
    } else {
        $sourceEntityId = !empty($body['entity_id']) ? (int) $body['entity_id']
                                                      : (int) accountingDefaultEntity($tid)['id'];
        $apAccount      = trim((string) ($body['ap_account_code'] ?? '2000'));
    }
    $splits = $body['splits'] ?? [];
    if (!is_array($splits) || !$splits) api_error('splits[] required', 422);

    try {
        $res = intercompanyPostSplit($tid, [
            'posting_date' => $row['bill_date'],
            'memo'         => 'AP Bill ' . $row['internal_ref'] . ' / ' . $row['vendor_name'],
            'group_id'     => null,
            'idempotency_prefix' => sprintf('ic:bill:%d', $id),
            'source'       => [
                'entity_id'   => $sourceEntityId,
                'offset_line' => [
                    'account_code' => $apAccount,
                    'amount'       => (float) $row['total'],
                    'side'         => 'credit',
                    'memo'         => 'AP ' . $row['internal_ref'],
                ],
            ],
            'splits' => array_map(fn ($s) => [
                'entity_id'    => (int) $s['entity_id'],
                'account_code' => (string) $s['account_code'],
                'amount'       => (float) $s['amount'],
                'memo'         => $s['memo'] ?? null,
                'ic_override'  => $s['ic_override'] ?? null,
            ], $splits),
        ], $user['id'] ?? null);
    } catch (\Throwable $e) {
        api_error('GL post failed: ' . $e->getMessage(), 422);
    }

    $sourceLeg = null;
    foreach ($res['jes'] as $leg) if ($leg['role'] === 'source') { $sourceLeg = $leg; break; }
    if (!$sourceLeg) $sourceLeg = $res['jes'][0] ?? null;

    getDB()->prepare(
        'UPDATE ap_bills SET journal_entry_id = :j, intercompany_group_id = :g WHERE id = :id AND tenant_id = :t'
    )->execute([
        'j'  => $sourceLeg['je_id'], 'g' => $res['group_id'], 'id' => $id, 't' => $tid,
    ]);

    apAudit('ap.bill.posted_ic', [
        'bill_id'               => $id, 'internal_ref' => $row['internal_ref'],
        'journal_entry_id'      => (int) $sourceLeg['je_id'],
        'intercompany_group_id' => $res['group_id'],
        'leg_count'             => count($res['jes']),
    ], $id);
    api_ok([
        'ok' => true,
        'journal_entry_id'       => (int) $sourceLeg['je_id'],
        'intercompany_group_id'  => $res['group_id'],
        'jes'                    => $res['jes'],
    ]);
}

api_error('Method not allowed', 405);