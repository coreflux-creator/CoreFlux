<?php
/**
 * Accounting API — CSV exports.
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
require_once __DIR__ . '/../lib/accounting.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$type   = (string) ($_GET['type'] ?? '');

if ($method !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'accounting.reports.export');

$from = $_GET['from']   ?? null;
$to   = $_GET['to']     ?? null;
$asOf = $_GET['as_of']  ?? null;
$eid  = !empty($_GET['entity_id']) ? (int) $_GET['entity_id'] : null;
$code = $_GET['account_code'] ?? $_GET['code'] ?? null;

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

// ── Chart of Accounts ─────────────────────────────────────────────────────
if ($type === 'coa') {
    $stmt = $db->prepare('SELECT code, name, account_type, normal_side, parent_account_id,
                                 is_postable, currency, cash_flow_tag, description, active
                          FROM accounting_accounts
                          WHERE tenant_id = :t
                          ORDER BY code');
    $stmt->execute(['t' => $tid]);
    $emit("accounting-coa-{$tid}-{$today}.csv",
        ['code','name','account_type','normal_side','parent_account_id','is_postable','currency','cash_flow_tag','description','active'],
        $stmt);
}

// ── Journal Entries (headers) ─────────────────────────────────────────────
if ($type === 'je') {
    $where  = ['je.tenant_id = :t'];
    $params = ['t' => $tid];
    if ($from)                 { $where[] = 'je.posting_date >= :f';     $params['f']   = $from; }
    if ($to)                   { $where[] = 'je.posting_date <= :to2';   $params['to2'] = $to;   }
    if (!empty($_GET['status'])) { $where[] = 'je.status = :s';          $params['s']   = $_GET['status']; }
    $joinLine = '';
    if ($code) {
        $joinLine = ' INNER JOIN accounting_journal_entry_lines l ON l.je_id = je.id
                      INNER JOIN accounting_accounts a ON a.id = l.account_id ';
        $where[] = 'a.code = :ac'; $params['ac'] = $code;
    }
    $sql = 'SELECT DISTINCT je.id, je.je_number, je.posting_date, je.entity_id, je.period_id,
                   je.source_module, je.source_ref_type, je.source_ref_id, je.status,
                   je.currency, je.total_debit, je.total_credit, je.memo, je.posted_at
            FROM accounting_journal_entries je ' . $joinLine .
          ' WHERE ' . implode(' AND ', $where) . ' ORDER BY je.posting_date, je.id';
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $emit("accounting-journal-entries-{$tid}-{$today}.csv",
        ['id','je_number','posting_date','entity_id','period_id','source_module','source_ref_type','source_ref_id',
         'status','currency','total_debit','total_credit','memo','posted_at'],
        $stmt);
}

// ── JE Lines (detail rows) ────────────────────────────────────────────────
if ($type === 'je_lines' || $type === 'gl_detail') {
    $where  = ['je.tenant_id = :t', "je.status = 'posted'"];
    $params = ['t' => $tid];
    if ($from) { $where[] = 'je.posting_date >= :f';   $params['f']   = $from; }
    if ($to)   { $where[] = 'je.posting_date <= :to2'; $params['to2'] = $to;   }
    if ($code) { $where[] = 'a.code = :ac';            $params['ac']  = $code; }
    $stmt = $db->prepare(
        'SELECT je.je_number, je.posting_date, a.code AS account_code, a.name AS account_name,
                l.debit, l.credit, l.memo, je.source_module, je.source_ref_type, je.source_ref_id
         FROM accounting_journal_entry_lines l
         JOIN accounting_journal_entries je ON je.id = l.je_id
         JOIN accounting_accounts a ON a.id = l.account_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY je.posting_date, je.id, l.line_no'
    );
    $stmt->execute($params);
    $emit("accounting-gl-detail-{$tid}-{$today}.csv",
        ['je_number','posting_date','account_code','account_name','debit','credit','memo',
         'source_module','source_ref_type','source_ref_id'],
        $stmt);
}

// ── Trial Balance ─────────────────────────────────────────────────────────
if ($type === 'tb') {
    $asOf = $asOf ?: date('Y-m-d');
    $rows = accountingTrialBalance($tid, $asOf, $eid);
    $emit("accounting-trial-balance-{$tid}-{$asOf}.csv",
        ['code','name','account_type','normal_side','debit','credit','balance_signed'],
        $rows);
}

// ── Periods ───────────────────────────────────────────────────────────────
if ($type === 'periods') {
    $where  = ['tenant_id = :t']; $params = ['t' => $tid];
    if ($eid) { $where[] = 'entity_id = :e'; $params['e'] = $eid; }
    $stmt = $db->prepare(
        'SELECT id, entity_id, period_number, start_date, end_date, status,
                closed_at, closed_by_user_id, reopened_at, reopened_by_user_id, reopen_reason
         FROM accounting_periods WHERE ' . implode(' AND ', $where) . '
         ORDER BY start_date, period_number'
    );
    $stmt->execute($params);
    $emit("accounting-periods-{$tid}-{$today}.csv",
        ['id','entity_id','period_number','start_date','end_date','status',
         'closed_at','closed_by_user_id','reopened_at','reopened_by_user_id','reopen_reason'],
        $stmt);
}

// ── Bank statement lines ──────────────────────────────────────────────────
if ($type === 'bank_statements') {
    $baId = (int) ($_GET['bank_account_id'] ?? 0);
    if ($baId <= 0) api_error('bank_account_id required', 422);
    $where  = ['tenant_id = :t', 'bank_account_id = :b'];
    $params = ['t' => $tid, 'b' => $baId];
    if ($from) { $where[] = 'posted_date >= :f';   $params['f']   = $from; }
    if ($to)   { $where[] = 'posted_date <= :to2'; $params['to2'] = $to;   }
    $stmt = $db->prepare(
        'SELECT id, posted_date, description, amount, bank_reference, fitid,
                match_status, matched_je_id, matched_at
         FROM accounting_bank_statement_lines WHERE ' . implode(' AND ', $where) . '
         ORDER BY posted_date, id'
    );
    $stmt->execute($params);
    $emit("accounting-bank-stmts-{$tid}-{$baId}-{$today}.csv",
        ['id','posted_date','description','amount','bank_reference','fitid',
         'match_status','matched_je_id','matched_at'],
        $stmt);
}

// ── Unposted JEs ──────────────────────────────────────────────────────────
if ($type === 'unposted_jes' || $type === 'unposted') {
    $stmt = $db->prepare(
        "SELECT id, je_number, posting_date, entity_id, period_id, source_module,
                status, total_debit, total_credit, memo, created_by_user_id, created_at
         FROM accounting_journal_entries
         WHERE tenant_id = :t AND status != 'posted'
         ORDER BY posting_date DESC, id DESC"
    );
    $stmt->execute(['t' => $tid]);
    $emit("accounting-unposted-jes-{$tid}-{$today}.csv",
        ['id','je_number','posting_date','entity_id','period_id','source_module','status',
         'total_debit','total_credit','memo','created_by_user_id','created_at'],
        $stmt);
}

// ── Approval Queue (draft JEs pending review) ─────────────────────────────
if ($type === 'approval_queue') {
    $stmt = $db->prepare(
        "SELECT id, je_number, posting_date, entity_id, source_module,
                total_debit, total_credit, memo, created_by_user_id, created_at
         FROM accounting_journal_entries
         WHERE tenant_id = :t AND status = 'draft'
         ORDER BY created_at ASC"
    );
    $stmt->execute(['t' => $tid]);
    $emit("accounting-approval-queue-{$tid}-{$today}.csv",
        ['id','je_number','posting_date','entity_id','source_module',
         'total_debit','total_credit','memo','created_by_user_id','created_at'],
        $stmt);
}

// ── Accounting audit log ──────────────────────────────────────────────────
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

// ── Account Activity (one account, with running balance) ──────────────────
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
