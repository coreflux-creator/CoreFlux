<?php
/**
 * AP API — CSV exports.
 *
 *   GET /api/ap/export?type=bills&from=YYYY-MM-DD&to=YYYY-MM-DD
 *   GET /api/ap/export?type=payments&from=YYYY-MM-DD&to=YYYY-MM-DD
 *   GET /api/ap/export?type=expenses&from=YYYY-MM-DD&to=YYYY-MM-DD
 *   GET /api/ap/export?type=1099&tax_year=YYYY
 *   GET /api/ap/export?type=gusto_contractors&from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Streams text/csv with Content-Disposition: attachment.
 *
 * Gusto "Contractor payments" CSV columns (per Gusto bulk-import spec):
 *   first_name, last_name, type, hours, rate, wage, reimbursement, bonus
 *
 * SPEC: /app/modules/ap/SPEC.md §13 Phase A1.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/ap.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$type   = (string) ($_GET['type'] ?? '');

if ($method !== 'GET') api_error('Method not allowed', 405);
RBAC::requirePermission($user, 'ap.export.run');

$from   = $_GET['from'] ?? null;
$to     = $_GET['to']   ?? null;
$year   = (int) ($_GET['tax_year'] ?? date('Y'));

// Bulk-select: optional ?ids=1,2,3 — restricts to specific row ids.
// When present, from/to are still applied (defense in depth) but ids drive
// the result. Cap at 1000 to avoid pathological URLs.
$idsRaw = trim((string) ($_GET['ids'] ?? ''));
$ids    = [];
if ($idsRaw !== '') {
    foreach (explode(',', $idsRaw) as $tok) {
        $n = (int) trim($tok);
        if ($n > 0) $ids[] = $n;
    }
    $ids = array_values(array_unique($ids));
    if (count($ids) > 1000) api_error('ids list too large (max 1000)', 422);
}

/**
 * Stream a CSV directly to the client.
 * @param string $filename
 * @param string[] $headers
 * @param iterable<array<int|string, mixed>> $rows
 */
$emit = function (string $filename, array $headers, iterable $rows) use ($tid, $type): void {
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
    }
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    $count = 0;
    foreach ($rows as $r) {
        $line = [];
        foreach ($headers as $h) $line[] = $r[$h] ?? '';
        fputcsv($out, $line);
        $count++;
    }
    fclose($out);
    apAudit('ap.export.csv', ['type' => $type, 'rows' => $count, 'tenant_id' => $tid]);
    exit;
};

$db = getDB();

if ($type === 'bills') {
    $where  = ['tenant_id = :tid'];
    $params = ['tid' => $tid];
    if ($from) { $where[] = 'bill_date >= :from'; $params['from'] = $from; }
    if ($to)   { $where[] = 'bill_date <= :to';   $params['to']   = $to; }
    if ($ids) {
        $place  = [];
        foreach ($ids as $i => $n) { $key = "id$i"; $place[] = ":$key"; $params[$key] = $n; }
        $where[] = 'id IN (' . implode(',', $place) . ')';
    }
    $stmt = $db->prepare('SELECT id, bill_number, internal_ref, vendor_name, vendor_type, source,
                                 bill_date, due_date, currency, subtotal, tax_total, total,
                                 amount_paid, amount_due, status, po_number, placement_id, journal_entry_id
                          FROM ap_bills WHERE ' . implode(' AND ', $where) . ' ORDER BY bill_date, id');
    $stmt->execute($params);
    $emit("ap-bills-{$tid}-" . date('Ymd') . ".csv",
        ['id','bill_number','internal_ref','vendor_name','vendor_type','source','bill_date','due_date',
         'currency','subtotal','tax_total','total','amount_paid','amount_due','status','po_number',
         'placement_id','journal_entry_id'],
        $stmt);
}

if ($type === 'payments') {
    $where  = ['tenant_id = :tid'];
    $params = ['tid' => $tid];
    if ($from) { $where[] = 'pay_date >= :from'; $params['from'] = $from; }
    if ($to)   { $where[] = 'pay_date <= :to';   $params['to']   = $to; }
    if ($ids) {
        $place = [];
        foreach ($ids as $i => $n) { $key = "id$i"; $place[] = ":$key"; $params[$key] = $n; }
        $where[] = 'id IN (' . implode(',', $place) . ')';
    }
    $stmt = $db->prepare('SELECT id, vendor_name, pay_date, method, reference, amount, currency,
                                 bank_account_id, status, cleared_at, journal_entry_id
                          FROM ap_payments WHERE ' . implode(' AND ', $where) . ' ORDER BY pay_date, id');
    $stmt->execute($params);
    $emit("ap-payments-{$tid}-" . date('Ymd') . ".csv",
        ['id','vendor_name','pay_date','method','reference','amount','currency','bank_account_id',
         'status','cleared_at','journal_entry_id'],
        $stmt);
}

if ($type === 'expenses') {
    $where  = ['er.tenant_id = :tid'];
    $params = ['tid' => $tid];
    if ($from) { $where[] = 'erl.expense_date >= :from'; $params['from'] = $from; }
    if ($to)   { $where[] = 'erl.expense_date <= :to';   $params['to']   = $to; }
    if ($ids) {
        // ids are line_ids when restricting expenses (one row per line).
        $place = [];
        foreach ($ids as $i => $n) { $key = "id$i"; $place[] = ":$key"; $params[$key] = $n; }
        $where[] = 'erl.id IN (' . implode(',', $place) . ')';
    }
    $stmt = $db->prepare(
        'SELECT er.id AS report_id, er.period_label, er.status AS report_status, er.submitter_user_id,
                erl.id AS line_id, erl.expense_date, erl.category, erl.merchant, erl.amount, erl.currency,
                erl.gl_expense_account_code, erl.description, erl.billable_to_client_name
         FROM ap_expense_report_lines erl
         JOIN ap_expense_reports er ON er.id = erl.expense_report_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY erl.expense_date, erl.id'
    );
    $stmt->execute($params);
    $emit("ap-expenses-{$tid}-" . date('Ymd') . ".csv",
        ['report_id','period_label','report_status','submitter_user_id','line_id','expense_date',
         'category','merchant','amount','currency','gl_expense_account_code','description',
         'billable_to_client_name'],
        $stmt);
}

if ($type === '1099') {
    $stmt = $db->prepare('SELECT id, tax_year, vendor_name, vendor_type, tax_id_last4, total_paid,
                                 requires_1099_nec, submitted_to_irs_at
                          FROM ap_1099_ledger
                          WHERE tenant_id = :tid AND tax_year = :y
                          ORDER BY vendor_name');
    $stmt->execute(['tid' => $tid, 'y' => $year]);
    $emit("ap-1099-{$tid}-{$year}.csv",
        ['id','tax_year','vendor_name','vendor_type','tax_id_last4','total_paid',
         'requires_1099_nec','submitted_to_irs_at'],
        $stmt);
}

if ($type === 'gusto_contractors') {
    // Gusto bulk contractor-payments import format.
    // We sum cleared payments grouped by vendor (1099_individual / c2c_corp) over the date range.
    // Type=Fixed (Gusto term for fixed-amount contractor payments). Hours/Rate left blank.
    $where  = ['p.tenant_id = :tid', "p.status IN ('sent','cleared')",
               "EXISTS (SELECT 1 FROM ap_vendors_index v WHERE v.tenant_id = p.tenant_id
                        AND v.vendor_name = p.vendor_name
                        AND v.vendor_type IN ('1099_individual','c2c_corp'))"];
    $params = ['tid' => $tid];
    if ($from) { $where[] = 'p.pay_date >= :from'; $params['from'] = $from; }
    if ($to)   { $where[] = 'p.pay_date <= :to';   $params['to']   = $to; }
    $stmt = $db->prepare(
        'SELECT p.vendor_name, SUM(p.amount) AS wage
         FROM ap_payments p
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY p.vendor_name
         ORDER BY p.vendor_name'
    );
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt as $row) {
        // Split vendor_name into first / last on the first space; if only one token,
        // keep it in last_name (Gusto requires at least one of the name fields).
        $parts = preg_split('/\s+/', trim((string) $row['vendor_name']), 2);
        $first = count($parts) === 2 ? $parts[0] : '';
        $last  = count($parts) === 2 ? $parts[1] : ($parts[0] ?? '');
        $rows[] = [
            'first_name'    => $first,
            'last_name'     => $last,
            'type'          => 'Fixed',
            'hours'         => '',
            'rate'          => '',
            'wage'          => number_format((float) $row['wage'], 2, '.', ''),
            'reimbursement' => '',
            'bonus'         => '',
        ];
    }
    $emit("ap-gusto-contractors-{$tid}-" . date('Ymd') . ".csv",
        ['first_name','last_name','type','hours','rate','wage','reimbursement','bonus'],
        $rows);
}

api_error('Unknown export type: ' . $type, 400);
