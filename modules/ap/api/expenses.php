<?php
/**
 * AP API — expense reports.
 *
 *   GET   /api/ap/expenses                → list own + (if ap.expense.approve) all pending
 *   GET   /api/ap/expenses?id=N           → detail
 *   POST  /api/ap/expenses                → create draft with lines
 *   PATCH /api/ap/expenses?id=N           → edit draft
 *   POST  /api/ap/expenses?action=submit&id=N
 *   POST  /api/ap/expenses?action=approve&id=N   → approves report + routes AP bill
 *   POST  /api/ap/expenses?action=reject&id=N    → body: {reason}
 *
 * SPEC: /app/modules/ap/SPEC.md §5.4, §3.6.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvExportService.php';
require_once __DIR__ . '/../../../core/export_service.php';
require_once __DIR__ . '/../../../core/StorageService.php';
require_once __DIR__ . '/../../../core/storage_register.php';
require_once __DIR__ . '/../lib/ap.php';
require_once __DIR__ . '/../lib/approval_router.php';

use Core\CsvExportService;
use Core\StorageService;

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';
$uid    = (int) ($user['id'] ?? 0);

if ($method === 'GET' && $action === 'export_selected') {
    rbac_legacy_require($user, 'ap.expense.submit');
    $idsRaw = (string) ($_GET['ids'] ?? '');
    $ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), fn ($x) => $x > 0));
    if (!$ids) api_error('ids required', 400);
    if (count($ids) > 500) api_error('too many ids (max 500)', 400);

    // Tenant-template-formatted CSV (preferred); falls back to the built-in
    // raw-dump format below if no template_id is supplied.
    $tplId = (int) ($_GET['template_id'] ?? 0);
    if ($tplId > 0) {
        try {
            exportTemplateStreamDatasetCsv(
                $tid,
                'expenses',
                $tplId,
                ['ids' => $ids],
                'expenses',
                $uid ?: null,
                null,
                ['ids' => $ids, 'filename_parts' => [date('Y-m-d')]]
            );
        } catch (ExportServiceException $e) { api_error($e->getMessage(), 422); }
        exit;
    }

    // CsvExportService emits Content-Type: text/csv for the selected export stream.
    $rows = exportDatasetFetchExpenses($tid, ['ids' => $ids]);
    exportDatasetAudit($tid, $uid ?: null, 'ap.expense.export_selected', null, exportDatasetAuditMeta([
        'dataset' => 'expenses',
        'format' => 'csv',
        'mode' => 'raw',
        'ids' => $ids,
        'rows' => count($rows),
    ], ['ids' => $ids]));

    $stamp = date('Y-m-d');
    (new CsvExportService([
        'report_id'               => 'report_id',
        'period_label'            => 'period_label',
        'submitter_user_id'       => 'submitter_user_id',
        'status'                  => 'status',
        'currency'                => 'currency',
        'bill_id'                 => 'bill_id',
        'created_at'              => 'created_at',
        'line_id'                 => 'line_id',
        'expense_date'            => 'expense_date',
        'merchant'                => 'merchant',
        'category'                => 'category',
        'amount'                  => 'amount',
        'gl_expense_account_code' => 'gl_account_code',
        'description'             => 'description',
    ]))->stream($rows, "expenses-{$stamp}.csv");
}

if ($method === 'GET' && $action === 'upload_url') {
    // Presigned S3 POST for an expense-line receipt.
    rbac_legacy_require($user, 'ap.expense.submit');
    $lineId = (int) ($_GET['line_id'] ?? 0);
    if ($lineId <= 0) api_error('line_id required', 400);
    $fileName = (string) ($_GET['file_name'] ?? 'receipt');
    $key  = StorageService::getInstance()->build_key('ap', $tid, 'expense_line', $lineId, $fileName);
    $post = StorageService::getInstance()->get_presigned_post($key);
    api_ok(['storage_key' => $key, 'upload' => $post]);
}

if ($method === 'POST' && $action === 'attach_line') {
    // Register an uploaded receipt onto a single ap_expense_report_lines row.
    rbac_legacy_require($user, 'ap.expense.submit');
    $lineId = (int) ($_GET['line_id'] ?? 0);
    if ($lineId <= 0) api_error('line_id required', 400);
    $line = getDB()->prepare(
        'SELECT erl.id, erl.expense_report_id, er.tenant_id
         FROM ap_expense_report_lines erl
         JOIN ap_expense_reports er ON er.id = erl.expense_report_id
         WHERE erl.id = :id AND er.tenant_id = :tid'
    );
    $line->execute(['id' => $lineId, 'tid' => $tid]);
    $r = $line->fetch(\PDO::FETCH_ASSOC);
    if (!$r) api_error('Not found', 404);
    $body = api_json_body();
    api_require_fields($body, ['storage_key','filename']);
    $sid = registerStorageObject(
        $tid, 'ap', 'expense_line', $lineId,
        (string) $body['storage_key'], (string) $body['filename'],
        $body['mime'] ?? null, isset($body['size_bytes']) ? (int) $body['size_bytes'] : null,
        $user['id'] ?? null
    );
    getDB()->prepare('UPDATE ap_expense_report_lines SET receipt_storage_object_id = :s WHERE id = :id')
        ->execute(['s' => $sid, 'id' => $lineId]);
    apAudit('ap.expense.line.attachment.added', [
        'expense_report_id' => (int) $r['expense_report_id'],
        'line_id'           => $lineId,
        'storage_object_id' => $sid,
    ], (int) $r['expense_report_id']);
    api_ok(['ok' => true, 'storage_object_id' => $sid]);
}

if ($method === 'POST' && $action === 'extract_receipt') {
    // AI-assist for an expense-line receipt — same shape as ap.bill.line.from_receipt
    // but maps onto the expense-line schema (date / category / merchant / amount).
    rbac_legacy_require($user, 'ap.expense.submit');
    rbac_legacy_require($user, 'ai.use');
    require_once __DIR__ . '/../../../core/ai_service.php';
    $body = api_json_body();
    api_require_fields($body, ['storage_key']);
    $signedUrl = StorageService::getInstance()->get_signed_url((string) $body['storage_key']);

    $schemaHint = <<<JSON
{
  "expense_date":   string|null,            // ISO YYYY-MM-DD
  "merchant":       string|null,
  "category":       "meals"|"travel"|"mileage"|"supplies"|"software"|"lodging"|"other",
  "amount":         number|null,
  "currency":       string|null,
  "description":    string|null,
  "gl_expense_account_code": string|null
}
JSON;

    try {
        $res = aiExtract([
            'feature_key' => 'ap.expense.line.from_receipt',
            'instruction' => 'Extract a single employee receipt into the JSON shape below. Pick the best category from the enum. Use the receipt total (post-tax) as amount. If the receipt is itemised, summarise it into one line and put detail in description.',
            'schema_hint' => $schemaHint,
            'images'      => [['url' => $signedUrl]],
        ]);
    } catch (\Throwable $e) { api_error('Extraction failed: ' . $e->getMessage(), 502); }
    apAudit('ap.expense.line.extracted_from_receipt', [
        'model'          => $res['model'],
        'interaction_id' => $res['interaction_id'],
    ]);
    api_ok([
        'draft'           => $res['data'],
        'model'           => $res['model'],
        'interaction_id'  => $res['interaction_id'],
        'review_required' => true,
    ]);
}

if ($method === 'GET' && !empty($_GET['id'])) {
    rbac_legacy_require($user, 'ap.expense.submit');
    $id = (int) $_GET['id'];
    $row = scopedFind('SELECT * FROM ap_expense_reports WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    $canApprove = rbac_legacy_can($user, 'ap.expense.approve');
    if (!$canApprove && (int) $row['submitter_user_id'] !== $uid) api_error('Forbidden', 403);
    $linesStmt = getDB()->prepare('SELECT * FROM ap_expense_report_lines WHERE expense_report_id = :id ORDER BY line_no');
    $linesStmt->execute(['id' => $id]);
    api_ok(['report' => $row, 'lines' => $linesStmt->fetchAll(\PDO::FETCH_ASSOC)]);
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'ap.expense.submit');
    $canApprove = rbac_legacy_can($user, 'ap.expense.approve');
    $where  = ['tenant_id = :tenant_id'];
    $params = [];
    if (!$canApprove) {
        $where[] = 'submitter_user_id = :uid';
        $params['uid'] = $uid;
    } elseif (!empty($_GET['mine'])) {
        $where[] = 'submitter_user_id = :uid';
        $params['uid'] = $uid;
    }
    if (!empty($_GET['status'])) { $where[] = 'status = :st'; $params['st'] = $_GET['status']; }
    $rows = scopedQuery(
        'SELECT * FROM ap_expense_reports WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 200',
        $params
    );
    api_ok(['rows' => $rows]);
}

if ($method === 'POST' && $action === '') {
    rbac_legacy_require($user, 'ap.expense.submit');
    $body = api_json_body();
    api_require_fields($body, ['period_label', 'lines']);
    if (empty($body['lines']) || !is_array($body['lines'])) api_error('lines must be a non-empty array', 422);

    $total = 0.0;
    foreach ($body['lines'] as $l) $total += round((float) ($l['amount'] ?? 0), 2);

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $id = scopedInsert('ap_expense_reports', [
            'tenant_id'         => $tid,
            'submitter_user_id' => $uid,
            'submitter_person_id' => !empty($body['submitter_person_id']) ? (int) $body['submitter_person_id'] : null,
            'period_label'      => (string) $body['period_label'],
            'status'            => 'draft',
            'total'             => round($total, 2),
            'currency'          => (string) ($body['currency'] ?? 'USD'),
            'notes'             => $body['notes'] ?? null,
        ]);
        $line_no = 1;
        foreach ($body['lines'] as $l) {
            $stmt = $pdo->prepare(
                'INSERT INTO ap_expense_report_lines
                  (expense_report_id, line_no, expense_date, category, merchant, amount, currency,
                   gl_expense_account_code, receipt_storage_object_id, description, billable_to_client_name)
                 VALUES
                  (:rid, :line_no, :d, :cat, :m, :a, :c, :gl, :r, :desc, :bill)'
            );
            $stmt->execute([
                'rid' => $id, 'line_no' => $line_no++,
                'd' => (string) ($l['expense_date'] ?? date('Y-m-d')),
                'cat'=> (string) ($l['category'] ?? 'other'),
                'm' => $l['merchant'] ?? null,
                'a' => round((float) ($l['amount'] ?? 0), 2),
                'c' => (string) ($l['currency'] ?? 'USD'),
                'gl'=> $l['gl_expense_account_code'] ?? null,
                'r' => !empty($l['receipt_storage_object_id']) ? (int) $l['receipt_storage_object_id'] : null,
                'desc' => $l['description'] ?? null,
                'bill' => $l['billable_to_client_name'] ?? null,
            ]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    apAudit('ap.expense.submitted', ['expense_report_id' => $id, 'status' => 'draft'], $id);
    api_ok(['id' => $id], 201);
}

if ($method === 'POST' && $action === 'submit') {
    rbac_legacy_require($user, 'ap.expense.submit');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM ap_expense_reports WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if ((int) $row['submitter_user_id'] !== $uid) api_error('Only submitter can submit', 403);
    if ($row['status'] !== 'draft') api_error('Only draft can be submitted', 409);
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    getDB()->prepare('UPDATE ap_expense_reports SET status = "submitted", submitted_at = NOW() WHERE id = :id')
        ->execute(['id' => $id]);
    apAudit('ap.expense.submitted', ['expense_report_id' => $id], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'approve') {
    rbac_legacy_require($user, 'ap.expense.approve');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM ap_expense_reports WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if ($row['status'] !== 'submitted') api_error('Only submitted reports can be approved', 409);
    if ((int) $row['submitter_user_id'] === $uid) api_error('Two-eye: cannot approve your own report', 403);

    $submitterUserId = (int) ($row['submitter_user_id'] ?? 0);
    $internalRef = apNextInternalRef($tid);
    $routing = null;
    $routingStatus = 'not_routed';
    $pdo = cf_begin_transaction();
    try {
        // Create the corresponding AP bill as pending: AP bill approval remains
        // owned by the common AP workflow, not by the expense-report approver.
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare('UPDATE ap_expense_reports SET status = "approved", approved_at = NOW(), approved_by_user_id = :u WHERE id = :id')
            ->execute(['u' => $uid, 'id' => $id]);

        $submitterName = 'Employee';
        if ($row['submitter_person_id']) {
            $pStmt = $pdo->prepare('SELECT first_name, last_name FROM people WHERE id = :id AND tenant_id = :t');
            $pStmt->execute(['id' => $row['submitter_person_id'], 't' => $tid]);
            $p = $pStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $submitterName = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?: $submitterName;
        } elseif ($row['submitter_user_id']) {
            $uStmt = $pdo->prepare('SELECT name FROM users WHERE id = :id');
            $uStmt->execute(['id' => $row['submitter_user_id']]);
            $submitterName = (string) ($uStmt->fetchColumn() ?: $submitterName);
        }

        $billId = scopedInsert('ap_bills', [
            'tenant_id'         => $tid,
            'bill_number'       => "EXP-{$id}",
            'internal_ref'      => $internalRef,
            'vendor_name'       => $submitterName,
            'vendor_type'       => 'other',
            'received_at'       => date('Y-m-d'),
            'bill_date'         => date('Y-m-d'),
            'due_date'          => date('Y-m-d', strtotime('+7 days')),
            'currency'          => (string) $row['currency'],
            'subtotal'          => (float) $row['total'],
            'tax_total'         => 0,
            'total'             => (float) $row['total'],
            'amount_due'        => (float) $row['total'],
            'status'            => 'pending_approval',
            'source'            => 'expense_report',
            'source_ref_id'     => $id,
            'created_by_user_id'=> $submitterUserId ?: null,
            'notes_internal'    => "From expense report #{$id}",
        ]);

        $linesStmt = $pdo->prepare('SELECT * FROM ap_expense_report_lines WHERE expense_report_id = :id ORDER BY line_no');
        $linesStmt->execute(['id' => $id]);
        $lineNo = 1;
        foreach ($linesStmt->fetchAll(\PDO::FETCH_ASSOC) as $l) {
            $pdo->prepare(
                'INSERT INTO ap_bill_lines
                  (bill_id, line_no, source_type, source_ref_id, description, quantity, unit, unit_price,
                   subtotal, tax_rate_pct, tax_amount, total, gl_expense_account_code)
                 VALUES
                  (:bill, :ln, "expense", :src, :desc, 1, "each", :amt, :amt2, 0, 0, :amt3, :gl)'
            )->execute([
                'bill' => $billId, 'ln' => $lineNo++, 'src' => $l['id'],
                'desc' => ($l['category'] . ' — ' . ($l['merchant'] ?? '')),
                'amt' => $l['amount'], 'amt2' => $l['amount'], 'amt3' => $l['amount'],
                'gl' => $l['gl_expense_account_code'],
            ]);
        }

        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare('UPDATE ap_expense_reports SET bill_id = :b WHERE id = :id')
            ->execute(['b' => $billId, 'id' => $id]);

        apAudit('ap.bill.created', [
            'bill_id'           => $billId,
            'internal_ref'      => $internalRef,
            'source'            => 'expense_report',
            'expense_report_id' => $id,
            'created_by_user_id'=> $submitterUserId ?: null,
        ], $billId);

        $billForRouting = [
            'id'                   => $billId,
            'tenant_id'            => $tid,
            'total'                => (float) $row['total'],
            'total_amount'         => (float) $row['total'],
            'currency'             => (string) $row['currency'],
            'vendor_type'          => 'other',
            'source'               => 'expense_report',
            'source_ref_id'        => $id,
            'created_by_user_id'   => $submitterUserId ?: null,
            'submitted_by_user_id' => $submitterUserId ?: null,
        ];
        $routing = apRouteBillForApproval($tid, $billForRouting, $submitterUserId ?: null);
        $hasRoute = !empty($routing['workflow_instance_id']) || !empty($routing['approval_ids']);
        if ($hasRoute) {
            $routingStatus = 'routed';
            apAudit('ap.expense.bill_routed_for_approval', [
                'expense_report_id'    => $id,
                'bill_id'              => $billId,
                'policy_id'            => $routing['policy_id'] ?? null,
                'approval_ids'         => $routing['approval_ids'] ?? [],
                'workflow_instance_id' => $routing['workflow_instance_id'] ?? null,
                'risk_level'           => $routing['risk']['level'] ?? null,
            ], $id);
        } else {
            $routingStatus = 'unmatched_policy';
            apAudit('ap.expense.bill_routing_failed', [
                'expense_report_id' => $id,
                'bill_id'           => $billId,
                'reason'            => 'no_matching_approval_policy',
                'matched'           => $routing['matched'] ?? false,
                'risk_level'        => $routing['risk']['level'] ?? null,
            ], $id);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    apAudit('ap.expense.approved', [
        'expense_report_id' => $id,
        'bill_id'           => $billId,
        'bill_status'       => 'pending_approval',
        'routing_status'    => $routingStatus,
    ], $id);
    api_ok(['ok' => true, 'bill_id' => $billId, 'bill_status' => 'pending_approval', 'routing' => $routing]);
}

if ($method === 'POST' && $action === 'reject') {
    rbac_legacy_require($user, 'ap.expense.approve');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM ap_expense_reports WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if ($row['status'] !== 'submitted') api_error('Only submitted reports can be rejected', 409);
    $body = api_json_body();
    $reason = trim((string) ($body['reason'] ?? ''));
    if ($reason === '') api_error('reason required', 422);
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    getDB()->prepare('UPDATE ap_expense_reports SET status = "rejected", rejected_reason = :r WHERE id = :id')
        ->execute(['r' => $reason, 'id' => $id]);
    apAudit('ap.expense.rejected', ['expense_report_id' => $id, 'reason' => $reason], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
