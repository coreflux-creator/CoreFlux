<?php
/**
 * Accounting API - CSV exports.
 *
 *   GET /api/accounting/export?type=coa
 *   GET /api/accounting/export?type=je&from=YYYY-MM-DD&to=YYYY-MM-DD[&status=posted&account_code=1010]
 *   GET /api/accounting/export?type=je_lines&from=YYYY-MM-DD&to=YYYY-MM-DD[&account_code=]
 *   GET /api/accounting/export?type=tb&as_of=YYYY-MM-DD[&entity_id=]
 *   GET /api/accounting/export?type=periods[&entity_id=]
 *   GET /api/accounting/export?type=bank_statements&bank_account_id=N[&from=&to=]
 *   GET /api/accounting/export?type=gl_detail&from=&to=[&account_code=]
 *   GET /api/accounting/export?type=unposted_jes
 *   GET /api/accounting/export?type=approval_queue
 *   GET /api/accounting/export?type=audit_log&from=YYYY-MM-DD&to=YYYY-MM-DD
 *   GET /api/accounting/export?type=account_activity&code=1010&from=&to=
 *
 * Streams text/csv with Content-Disposition: attachment.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvExportService.php';
require_once __DIR__ . '/../../../core/export_service.php';
require_once __DIR__ . '/../lib/accounting.php';

use Core\CsvExportService;

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$type   = (string) ($_GET['type'] ?? '');
$uid    = (int) ($user['id'] ?? 0);

if ($method !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'accounting.reports.export');

$from = $_GET['from']   ?? null;
$to   = $_GET['to']     ?? null;
$asOf = $_GET['as_of']  ?? null;
$eid  = !empty($_GET['entity_id']) ? (int) $_GET['entity_id'] : null;
$code = $_GET['account_code'] ?? $_GET['code'] ?? null;
$tplId = (int) ($_GET['template_id'] ?? 0);

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
    accountingAudit('accounting.ledger.exported', ['type' => $type, 'rows' => $count], null);
    exit;
};

$db = getDB();
$today = date('Ymd');

$governedExports = [
    'coa' => [
        'dataset' => 'accounting_chart_of_accounts',
        'prefix' => 'accounting-coa',
        'filename' => "accounting-coa-{$tid}-{$today}.csv",
        'columns' => [
            'code'              => 'code',
            'name'              => 'name',
            'account_type'      => 'account_type',
            'normal_side'       => 'normal_side',
            'parent_account_id' => 'parent_account_id',
            'is_postable'       => 'is_postable',
            'currency'          => 'currency',
            'cash_flow_tag'     => 'cash_flow_tag',
            'description'       => 'description',
            'active'            => 'active',
        ],
    ],
    'je' => [
        'dataset' => 'accounting_journal_entries',
        'prefix' => 'accounting-journal-entries',
        'filename' => "accounting-journal-entries-{$tid}-{$today}.csv",
        'columns' => [
            'journal_entry_id' => 'id',
            'je_number'        => 'je_number',
            'posting_date'     => 'posting_date',
            'entity_id'        => 'entity_id',
            'period_id'        => 'period_id',
            'source_module'    => 'source_module',
            'source_ref_type'  => 'source_ref_type',
            'source_ref_id'    => 'source_ref_id',
            'status'           => 'status',
            'currency'         => 'currency',
            'total_debit'      => 'total_debit',
            'total_credit'     => 'total_credit',
            'memo'             => 'memo',
            'posted_at'        => 'posted_at',
        ],
    ],
    'je_lines' => [
        'dataset' => 'accounting_gl_detail',
        'prefix' => 'accounting-gl-detail',
        'filename' => "accounting-gl-detail-{$tid}-{$today}.csv",
        'columns' => [
            'je_number'       => 'je_number',
            'posting_date'    => 'posting_date',
            'account_code'    => 'account_code',
            'account_name'    => 'account_name',
            'debit'           => 'debit',
            'credit'          => 'credit',
            'memo'            => 'memo',
            'source_module'   => 'source_module',
            'source_ref_type' => 'source_ref_type',
            'source_ref_id'   => 'source_ref_id',
        ],
    ],
    'gl_detail' => [
        'dataset' => 'accounting_gl_detail',
        'prefix' => 'accounting-gl-detail',
        'filename' => "accounting-gl-detail-{$tid}-{$today}.csv",
        'columns' => [
            'je_number'       => 'je_number',
            'posting_date'    => 'posting_date',
            'account_code'    => 'account_code',
            'account_name'    => 'account_name',
            'debit'           => 'debit',
            'credit'          => 'credit',
            'memo'            => 'memo',
            'source_module'   => 'source_module',
            'source_ref_type' => 'source_ref_type',
            'source_ref_id'   => 'source_ref_id',
        ],
    ],
    'periods' => [
        'dataset' => 'accounting_periods',
        'prefix' => 'accounting-periods',
        'filename' => "accounting-periods-{$tid}-{$today}.csv",
        'columns' => [
            'period_id'           => 'id',
            'entity_id'           => 'entity_id',
            'period_number'       => 'period_number',
            'start_date'          => 'start_date',
            'end_date'            => 'end_date',
            'status'              => 'status',
            'closed_at'           => 'closed_at',
            'closed_by_user_id'   => 'closed_by_user_id',
            'reopened_at'         => 'reopened_at',
            'reopened_by_user_id' => 'reopened_by_user_id',
            'reopen_reason'       => 'reopen_reason',
        ],
    ],
    'bank_statements' => [
        'dataset' => 'accounting_bank_statement_lines',
        'prefix' => 'accounting-bank-statements',
        'filename' => "accounting-bank-stmts-{$tid}-" . (int) ($_GET['bank_account_id'] ?? 0) . "-{$today}.csv",
        'columns' => [
            'bank_statement_line_id' => 'id',
            'posted_date'            => 'posted_date',
            'description'            => 'description',
            'amount'                 => 'amount',
            'bank_reference'         => 'bank_reference',
            'fitid'                  => 'fitid',
            'match_status'           => 'match_status',
            'matched_je_id'          => 'matched_je_id',
            'matched_at'             => 'matched_at',
        ],
    ],
    'unposted_jes' => [
        'dataset' => 'accounting_journal_entries',
        'prefix' => 'accounting-unposted-jes',
        'filename' => "accounting-unposted-jes-{$tid}-{$today}.csv",
        'columns' => [
            'journal_entry_id'   => 'id',
            'je_number'          => 'je_number',
            'posting_date'       => 'posting_date',
            'entity_id'          => 'entity_id',
            'period_id'          => 'period_id',
            'source_module'      => 'source_module',
            'status'             => 'status',
            'total_debit'        => 'total_debit',
            'total_credit'       => 'total_credit',
            'memo'               => 'memo',
            'created_by_user_id' => 'created_by_user_id',
            'created_at'         => 'created_at',
        ],
        'forced_options' => ['exclude_status' => 'posted'],
    ],
    'unposted' => [
        'dataset' => 'accounting_journal_entries',
        'prefix' => 'accounting-unposted-jes',
        'filename' => "accounting-unposted-jes-{$tid}-{$today}.csv",
        'columns' => [
            'journal_entry_id'   => 'id',
            'je_number'          => 'je_number',
            'posting_date'       => 'posting_date',
            'entity_id'          => 'entity_id',
            'period_id'          => 'period_id',
            'source_module'      => 'source_module',
            'status'             => 'status',
            'total_debit'        => 'total_debit',
            'total_credit'       => 'total_credit',
            'memo'               => 'memo',
            'created_by_user_id' => 'created_by_user_id',
            'created_at'         => 'created_at',
        ],
        'forced_options' => ['exclude_status' => 'posted'],
    ],
    'approval_queue' => [
        'dataset' => 'accounting_journal_entries',
        'prefix' => 'accounting-approval-queue',
        'filename' => "accounting-approval-queue-{$tid}-{$today}.csv",
        'columns' => [
            'journal_entry_id'   => 'id',
            'je_number'          => 'je_number',
            'posting_date'       => 'posting_date',
            'entity_id'          => 'entity_id',
            'source_module'      => 'source_module',
            'total_debit'        => 'total_debit',
            'total_credit'       => 'total_credit',
            'memo'               => 'memo',
            'created_by_user_id' => 'created_by_user_id',
            'created_at'         => 'created_at',
        ],
        'forced_options' => ['status' => 'draft'],
    ],
];

$datasetOptionsForType = function (string $exportType, array $cfg) use ($from, $to, $eid, $code): array {
    $opts = ['limit' => 10000];
    foreach (($cfg['forced_options'] ?? []) as $key => $value) {
        $opts[$key] = $value;
    }
    if ($from) $opts['from'] = (string) $from;
    if ($to) $opts['to'] = (string) $to;
    if ($eid) $opts['entity_id'] = $eid;
    if (!empty($_GET['status']) && empty($cfg['forced_options']['status'])) {
        $opts['status'] = (string) $_GET['status'];
    }
    if (!empty($_GET['approval_state'])) $opts['approval_state'] = (string) $_GET['approval_state'];
    if (!empty($_GET['source_module'])) $opts['source_module'] = (string) $_GET['source_module'];
    if (!empty($_GET['period_id'])) $opts['period_id'] = (int) $_GET['period_id'];
    if ($code && in_array($exportType, ['coa', 'je', 'je_lines', 'gl_detail'], true)) {
        $opts[$exportType === 'coa' ? 'code' : 'account_code'] = (string) $code;
    }
    if (!empty($_GET['account_type']) && $exportType === 'coa') {
        $opts['account_type'] = (string) $_GET['account_type'];
    }
    if (array_key_exists('active', $_GET) && $exportType === 'coa') {
        $opts['active'] = (int) $_GET['active'];
    }
    if ($exportType === 'bank_statements') {
        $bankAccountId = (int) ($_GET['bank_account_id'] ?? 0);
        if ($bankAccountId <= 0) api_error('bank_account_id required', 422);
        $opts['bank_account_id'] = $bankAccountId;
        if (!empty($_GET['match_status'])) $opts['match_status'] = (string) $_GET['match_status'];
    }
    return $opts;
};

$optionKeys = function (array $opts): array {
    $keys = [];
    foreach ($opts as $key => $value) {
        if (is_array($value)) {
            if ($value) $keys[] = $key;
        } elseif ($value !== null && $value !== '') {
            $keys[] = $key;
        }
    }
    return $keys;
};

if (isset($governedExports[$type])) {
    $cfg = $governedExports[$type];
    $dataset = (string) $cfg['dataset'];
    $options = $datasetOptionsForType($type, $cfg);
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
    exportDatasetAudit($tid, $uid ?: null, 'accounting.ledger.exported', null, [
        'dataset' => $dataset,
        'format' => 'csv',
        'mode' => 'raw',
        'type' => $type,
        'rows' => count($rows),
        'option_keys' => $optionKeys($options),
    ]);
    (new CsvExportService($cfg['columns']))->stream($rows, (string) $cfg['filename']);
}

// Trial balance is computed, so it remains outside the tabular dataset dispatcher.
if ($type === 'tb') {
    $asOf = $asOf ?: date('Y-m-d');
    $rows = accountingTrialBalance($tid, $asOf, $eid);
    $emit("accounting-trial-balance-{$tid}-{$asOf}.csv",
        ['code','name','account_type','normal_side','debit','credit','balance_signed'],
        $rows);
}

// The audit log is specialized tenant/security evidence, not a report-builder dataset.
if ($type === 'audit_log') {
    $where  = ['tenant_id = :t', "event LIKE 'accounting.%'"];
    $params = ['t' => $tid];
    if ($from) { $where[] = 'created_at >= :f';         $params['f']   = $from . ' 00:00:00'; }
    if ($to)   { $where[] = 'created_at <= :to2';       $params['to2'] = $to   . ' 23:59:59'; }
    $stmt = $db->prepare(
        'SELECT id, event, actor_user_id, target_id, meta_json, ip_address, created_at
         FROM audit_log WHERE ' . implode(' AND ', $where) . '
         ORDER BY created_at DESC LIMIT 5000'
    );
    $stmt->execute($params);
    $emit("accounting-audit-log-{$tid}-{$today}.csv",
        ['id','event','actor_user_id','target_id','meta_json','ip_address','created_at'],
        $stmt);
}

// Account activity adds a computed running balance.
if ($type === 'account_activity') {
    if (!$code) api_error('code (account_code) required', 422);
    $where  = ['je.tenant_id = :t', "je.status = 'posted'", 'a.code = :ac'];
    $params = ['t' => $tid, 'ac' => $code];
    if ($from) { $where[] = 'je.posting_date >= :f';   $params['f']   = $from; }
    if ($to)   { $where[] = 'je.posting_date <= :to2'; $params['to2'] = $to;   }
    $stmt = $db->prepare(
        'SELECT je.je_number, je.posting_date, a.code AS account_code, a.name AS account_name,
                a.normal_side, l.debit, l.credit, l.memo, je.source_module
         FROM accounting_journal_entry_lines l
         JOIN accounting_journal_entries je ON je.id = l.je_id
         JOIN accounting_accounts a ON a.id = l.account_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY je.posting_date, je.id, l.line_no'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    // Running balance (signed by normal_side).
    $run = 0.0;
    foreach ($rows as &$r) {
        $delta = ($r['normal_side'] === 'debit')
            ? ((float) $r['debit'] - (float) $r['credit'])
            : ((float) $r['credit'] - (float) $r['debit']);
        $run += $delta;
        $r['running_balance'] = number_format($run, 2, '.', '');
    }
    unset($r);
    $emit("accounting-account-activity-{$tid}-{$code}-{$today}.csv",
        ['je_number','posting_date','account_code','account_name','debit','credit','memo','source_module','running_balance'],
        $rows);
}

api_error('Unknown export type: ' . $type, 422);
