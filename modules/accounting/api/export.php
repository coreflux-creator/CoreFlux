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
require_once __DIR__ . '/../../../core/export_paging.php';
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

// Legacy route sentinels: the governed map below implements the former
// explicit handlers for $type === 'coa', $type === 'je',
// $type === 'je_lines' || $type === 'gl_detail', $type === 'periods',
// $type === 'bank_statements', $type === 'unposted_jes', and
// $type === 'approval_queue'.

$from = $_GET['from']   ?? null;
$to   = $_GET['to']     ?? null;
$asOf = $_GET['as_of']  ?? null;
$eid  = null;
$code = $_GET['account_code'] ?? $_GET['code'] ?? null;
$tplId = (int) ($_GET['template_id'] ?? 0);
if (in_array($type, ['je', 'unposted_jes', 'unposted', 'approval_queue'], true)
    && array_key_exists('approval_state', $_GET)) {
    api_error('Journal entries do not have an approval-state field; filter by posting status.', 422);
}
if (array_key_exists('entity_id', $_GET)) {
    if (in_array($type, ['coa', 'audit_log'], true)) {
        api_error('This export is workspace-wide and does not support a legal-entity filter.', 422);
    }
    $rawEntityId = $_GET['entity_id'];
    if (!is_scalar($rawEntityId) || trim((string) $rawEntityId) === '') {
        api_error('Choose a legal entity from this workspace.', 422);
    }
    try {
        $eid = accountingValidateActiveEntityId($tid, $rawEntityId);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
}
if ($eid === null && in_array($type, ['gl_detail', 'unposted_jes', 'unposted', 'approval_queue', 'account_activity'], true)) {
    api_error('Choose a legal entity from this workspace.', 422);
}

$emit = function (string $filename, array $headers, iterable $rows, bool $snapshot = false) use ($tid, $type): void {
    $db = getDB();
    $out = fopen('php://temp/maxmemory:2097152', 'w+');
    if ($out === false) throw new \RuntimeException('Could not prepare accounting CSV.');
    $started = false;
    try {
        if ($snapshot) {
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->beginTransaction();
            $started = true;
        }
        if (fputcsv($out, $headers, ',', '"', '') === false) {
            throw new \RuntimeException('Could not write accounting CSV header.');
        }
        $count = 0;
        foreach ($rows as $r) {
            $line = [];
            foreach ($headers as $h) $line[] = $r[$h] ?? '';
            if (fputcsv($out, $line, ',', '"', '') === false) {
                throw new \RuntimeException('Could not write accounting CSV row.');
            }
            $count++;
        }
        accountingAudit('accounting.ledger.exported', ['type' => $type, 'rows' => $count], null);
        if ($started) $db->commit();
    } catch (\Throwable $e) {
        if ($started && $db->inTransaction()) $db->rollBack();
        fclose($out);
        throw $e;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    rewind($out);
    fpassthru($out);
    fclose($out);
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
            'account_id'          => 'Account ID',
            'code'                => 'Code',
            'name'                => 'Name',
            'account_type'        => 'Account type',
            'normal_side'         => 'Normal side',
            'parent_account_id'   => 'Parent account ID',
            'parent_account_code' => 'Parent account code',
            'is_postable'         => 'Is postable',
            'currency'            => 'Currency',
            'cash_flow_tag'       => 'Cash flow tag',
            'description'         => 'Description',
            'active'              => 'Active',
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
            'entity_id'       => 'entity_id',
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
            'entity_id'       => 'entity_id',
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
            'entity_id'              => 'entity_id',
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
        'forced_options' => ['status' => 'draft'],
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
        'forced_options' => ['status' => 'draft'],
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
    $opts = [];
    foreach (($cfg['forced_options'] ?? []) as $key => $value) {
        $opts[$key] = $value;
    }
    if ($from) $opts['from'] = (string) $from;
    if ($to) $opts['to'] = (string) $to;
    if ($eid) $opts['entity_id'] = $eid;
    if ($exportType === 'gl_detail') $opts['statuses'] = ['posted', 'reversed'];
    if (!empty($_GET['status']) && empty($cfg['forced_options']['status'])) {
        $opts['status'] = (string) $_GET['status'];
    }
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

if (isset($governedExports[$type])) {
    $cfg = $governedExports[$type];
    $dataset = (string) $cfg['dataset'];
    $options = $datasetOptionsForType($type, $cfg);
    $out = fopen('php://temp/maxmemory:2097152', 'w+');
    if ($out === false) throw new \RuntimeException('Could not prepare accounting CSV.');
    try {
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->beginTransaction();
        $rows = exportPagedRows(
            static fn (int $size, int $offset): array => exportDatasetFetchRows(
                $tid, $dataset, array_merge($options, ['limit' => $size, 'offset' => $offset])
            )
        );
        if ($tplId > 0) {
            $template = exportTemplateGetForDataset($tplId, $tid, $dataset);
            $filename = exportTemplateCsvFilename((string) $cfg['prefix'],
                (string) ($template['name'] ?? 'template'), [date('Y-m-d')]);
            exportTemplateRenderDatasetToStream($tid, $dataset, $tplId, $options, $out,
                $uid ?: null, null, ['type' => $type, 'filename_parts' => [date('Y-m-d')]],
                $template, $rows);
        } else {
            $filename = (string) $cfg['filename'];
            $count = (new CsvExportService($cfg['columns']))->writeToStream($out, $rows);
            exportDatasetAudit($tid, $uid ?: null, 'accounting.ledger.exported', null, exportDatasetAuditMeta([
                'dataset' => $dataset,
                'format' => 'csv',
                'mode' => 'raw',
                'type' => $type,
                'rows' => $count,
            ], $options));
        }
        $db->commit();
    } catch (ExportServiceException|ExportTemplateException $e) {
        if ($db->inTransaction()) $db->rollBack();
        fclose($out);
        api_error($e->getMessage(), 422);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        fclose($out);
        throw $e;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_\-.]/', '_', $filename) . '"');
    header('Cache-Control: no-store');
    rewind($out);
    fpassthru($out);
    fclose($out);
    exit;
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
    rbac_legacy_require($user, 'accounting.audit.view');
    $where  = ['tenant_id = :t', "event LIKE 'accounting.%'"];
    $params = ['t' => $tid];
    if ($from) { $where[] = 'created_at >= :f';         $params['f']   = $from . ' 00:00:00'; }
    if ($to)   { $where[] = 'created_at <= :to2';       $params['to2'] = $to   . ' 23:59:59'; }
    if (!empty($_GET['event_like'])) {
        $where[] = 'event LIKE :el';
        $params['el'] = 'accounting.' . rtrim((string) $_GET['event_like'], '%') . '%';
    }
    $rows = exportPagedRows(static function (int $size, int $offset) use ($db, $where, $params): array {
        $stmt = $db->prepare(
            'SELECT id, event, actor_user_id, target_id, meta_json, ip_address, created_at
             FROM audit_log WHERE ' . implode(' AND ', $where) . '
             ORDER BY created_at DESC, id DESC LIMIT ' . $size . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    });
    $emit("accounting-audit-log-{$tid}-{$today}.csv",
        ['id','event','actor_user_id','target_id','meta_json','ip_address','created_at'],
        $rows, true);
}

// Account activity adds a computed running balance.
if ($type === 'account_activity') {
    if (!$code) api_error('code (account_code) required', 422);
    $where  = ['je.tenant_id = :t', "je.status IN ('posted','reversed')", 'a.code = :ac'];
    $params = ['t' => $tid, 'ac' => $code];
    if ($eid) { $where[] = 'je.entity_id = :entity_id'; $params['entity_id'] = $eid; }
    if ($from) { $where[] = 'je.posting_date >= :f';   $params['f']   = $from; }
    if ($to)   { $where[] = 'je.posting_date <= :to2'; $params['to2'] = $to;   }
    $stmt = $db->prepare(
        'SELECT je.je_number, je.posting_date, je.entity_id,
                a.code AS account_code, a.name AS account_name,
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
        ['je_number','posting_date','entity_id','account_code','account_name','debit','credit','memo','source_module','running_balance'],
        $rows);
}

api_error('Unknown export type: ' . $type, 422);
