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
require_once __DIR__ . '/../../../core/CsvExportService.php';
require_once __DIR__ . '/../../../core/export_service.php';
require_once __DIR__ . '/../lib/ap.php';

use Core\CsvExportService;

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$type   = (string) ($_GET['type'] ?? '');
$uid    = (int) ($user['id'] ?? 0);

if ($method !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'ap.export.run');

$from   = $_GET['from'] ?? null;
$to     = $_GET['to']   ?? null;
$year   = (int) ($_GET['tax_year'] ?? date('Y'));
$tplId  = (int) ($_GET['template_id'] ?? 0);

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

$governedExports = [
    'bills' => [
        'dataset' => 'ap_bills',
        'prefix'  => 'ap-bills',
        'filename' => "ap-bills-{$tid}-" . date('Ymd') . '.csv',
        'columns' => [
            'bill_id'          => 'id',
            'bill_number'      => 'bill_number',
            'internal_ref'     => 'internal_ref',
            'vendor_name'      => 'vendor_name',
            'vendor_type'      => 'vendor_type',
            'source'           => 'source',
            'bill_date'        => 'bill_date',
            'due_date'         => 'due_date',
            'currency'         => 'currency',
            'subtotal'         => 'subtotal',
            'tax_total'        => 'tax_total',
            'total'            => 'total',
            'amount_paid'      => 'amount_paid',
            'amount_due'       => 'amount_due',
            'status'           => 'status',
            'po_number'        => 'po_number',
            'placement_id'     => 'placement_id',
            'journal_entry_id' => 'journal_entry_id',
        ],
    ],
    'payments' => [
        'dataset' => 'ap_payments',
        'prefix'  => 'ap-payments',
        'filename' => "ap-payments-{$tid}-" . date('Ymd') . '.csv',
        'columns' => [
            'payment_id'       => 'id',
            'vendor_name'      => 'vendor_name',
            'pay_date'         => 'pay_date',
            'method'           => 'method',
            'reference'        => 'reference',
            'amount'           => 'amount',
            'currency'         => 'currency',
            'bank_account_id'  => 'bank_account_id',
            'status'           => 'status',
            'cleared_at'       => 'cleared_at',
            'journal_entry_id' => 'journal_entry_id',
        ],
    ],
    'expenses' => [
        'dataset' => 'expenses',
        'prefix'  => 'ap-expenses',
        'filename' => "ap-expenses-{$tid}-" . date('Ymd') . '.csv',
        'columns' => [
            'report_id'               => 'report_id',
            'period_label'            => 'period_label',
            'report_status'           => 'report_status',
            'submitter_user_id'       => 'submitter_user_id',
            'line_id'                 => 'line_id',
            'expense_date'            => 'expense_date',
            'category'                => 'category',
            'merchant'                => 'merchant',
            'amount'                  => 'amount',
            'currency'                => 'currency',
            'gl_expense_account_code' => 'gl_expense_account_code',
            'description'             => 'description',
            'billable_to_client_name' => 'billable_to_client_name',
        ],
    ],
];

$datasetOptionsForType = function (string $exportType) use ($ids, $from, $to): array {
    $opts = ['limit' => 10000];
    if ($from) $opts['from'] = (string) $from;
    if ($to) $opts['to'] = (string) $to;
    if (!empty($_GET['status'])) $opts['status'] = (string) $_GET['status'];
    if (!empty($_GET['vendor_name']) && $exportType !== 'expenses') {
        $opts['vendor_name'] = (string) $_GET['vendor_name'];
    }
    if ($ids) {
        if ($exportType === 'expenses') {
            // Historical /api/ap/export semantics treat ids as expense line ids.
            $opts['line_ids'] = $ids;
        } else {
            $opts['ids'] = $ids;
        }
    }
    return $opts;
};

if (isset($governedExports[$type])) {
    $cfg = $governedExports[$type];
    $dataset = (string) $cfg['dataset'];
    $options = $datasetOptionsForType($type);
    if ($tplId > 0) {
        try {
            exportTemplateStreamDatasetCsv(
                $tid,
                $dataset,
                $tplId,
                $options,
                (string) $cfg['prefix'],
                $uid ?: null,
                null,
                [
                    'legacy_endpoint' => 'ap/export.php',
                    'type' => $type,
                    'filename_parts' => [date('Y-m-d')],
                ]
            );
            exit;
        } catch (ExportServiceException $e) {
            api_error($e->getMessage(), 422);
        }
    }

    try {
        $rows = exportDatasetFetchRows($tid, $dataset, $options);
    } catch (ExportServiceException $e) {
        api_error($e->getMessage(), 422);
    }
    $event = (string) ((exportDatasetGet($dataset)['audit_event'] ?? 'export.dataset.exported'));
    exportDatasetAudit($tid, $uid ?: null, $event, null, exportDatasetAuditMeta([
        'dataset' => $dataset,
        'format' => 'csv',
        'mode' => 'raw',
        'legacy_endpoint' => 'ap/export.php',
        'type' => $type,
        'rows' => count($rows),
    ], $options));
    apAudit('ap.export.csv', ['type' => $type, 'rows' => count($rows), 'tenant_id' => $tid, 'dataset' => $dataset]);
    (new CsvExportService($cfg['columns']))->stream($rows, (string) $cfg['filename']);
}

$db = getDB();

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
