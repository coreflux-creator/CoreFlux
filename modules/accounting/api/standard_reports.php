<?php
/**
 * Accounting API — Standard (operational) reports.
 *
 *   GET /api/accounting/standard_reports?type=gl_detail&from=&to=[&account_code=]
 *   GET /api/accounting/standard_reports?type=unposted_jes
 *   GET /api/accounting/standard_reports?type=approval_queue
 *   GET /api/accounting/standard_reports?type=audit_log&from=&to=[&event_like=]
 *   GET /api/accounting/standard_reports?type=account_activity&code=&from=&to=
 *
 * These are operational / audit reports — live tables that a controller
 * needs while closing a period. Financial statements (IS/BS/CF) live in
 * reports.php. CSV exports for the same reports are in export.php.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/accounting.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();

if ($method !== 'GET') api_error('Method not allowed', 405);
RBAC::requirePermission($user, 'accounting.reports.view');

$type = (string) ($_GET['type'] ?? '');
$from = $_GET['from']   ?? null;
$to   = $_GET['to']     ?? null;
$code = $_GET['account_code'] ?? $_GET['code'] ?? null;
$db   = getDB();

if ($type === 'gl_detail') {
    $where  = ['je.tenant_id = :t', "je.status = 'posted'"];
    $params = ['t' => $tid];
    if ($from) { $where[] = 'je.posting_date >= :f';   $params['f']   = $from; }
    if ($to)   { $where[] = 'je.posting_date <= :to2'; $params['to2'] = $to;   }
    if ($code) { $where[] = 'a.code = :ac';            $params['ac']  = $code; }
    $stmt = $db->prepare(
        'SELECT je.id AS je_id, je.je_number, je.posting_date, a.code AS account_code,
                a.name AS account_name, l.debit, l.credit, l.memo AS line_memo,
                je.memo AS je_memo, je.source_module, je.source_ref_type, je.source_ref_id
         FROM accounting_journal_entry_lines l
         JOIN accounting_journal_entries je ON je.id = l.je_id
         JOIN accounting_accounts a ON a.id = l.account_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY je.posting_date, je.id, l.line_no
         LIMIT 2000'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $td = 0.0; $tc = 0.0;
    foreach ($rows as $r) { $td += (float) $r['debit']; $tc += (float) $r['credit']; }
    api_ok(['rows' => $rows, 'total_debit' => round($td, 2), 'total_credit' => round($tc, 2), 'count' => count($rows)]);
}

if ($type === 'unposted_jes' || $type === 'unposted') {
    $stmt = $db->prepare(
        "SELECT id, je_number, posting_date, entity_id, period_id, source_module,
                status, total_debit, total_credit, memo, created_by_user_id, created_at
         FROM accounting_journal_entries
         WHERE tenant_id = :t AND status != 'posted'
         ORDER BY posting_date DESC, id DESC
         LIMIT 500"
    );
    $stmt->execute(['t' => $tid]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $byStatus = [];
    foreach ($rows as $r) $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
    api_ok(['rows' => $rows, 'count' => count($rows), 'by_status' => $byStatus]);
}

if ($type === 'approval_queue') {
    $stmt = $db->prepare(
        "SELECT id, je_number, posting_date, entity_id, source_module, source_ref_type, source_ref_id,
                total_debit, total_credit, memo, created_by_user_id, created_at
         FROM accounting_journal_entries
         WHERE tenant_id = :t AND status = 'draft'
         ORDER BY created_at ASC
         LIMIT 500"
    );
    $stmt->execute(['t' => $tid]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $bySrc = [];
    foreach ($rows as $r) $bySrc[$r['source_module']] = ($bySrc[$r['source_module']] ?? 0) + 1;
    api_ok(['rows' => $rows, 'count' => count($rows), 'by_source' => $bySrc]);
}

if ($type === 'audit_log') {
    RBAC::requirePermission($user, 'accounting.audit.view');
    $where  = ['tenant_id = :t', "event LIKE 'accounting.%'"];
    $params = ['t' => $tid];
    if ($from) { $where[] = 'created_at >= :f';   $params['f']   = $from . ' 00:00:00'; }
    if ($to)   { $where[] = 'created_at <= :to2'; $params['to2'] = $to   . ' 23:59:59'; }
    if (!empty($_GET['event_like'])) {
        $where[] = 'event LIKE :el';
        $params['el'] = 'accounting.' . rtrim((string) $_GET['event_like'], '%') . '%';
    }
    $stmt = $db->prepare(
        'SELECT id, event, actor_user_id, target_id, meta_json, ip_address, created_at
         FROM audit_log WHERE ' . implode(' AND ', $where) . '
         ORDER BY created_at DESC LIMIT 500'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    api_ok(['rows' => $rows, 'count' => count($rows)]);
}

if ($type === 'account_activity') {
    if (!$code) api_error('code (account_code) required', 422);
    $where  = ['je.tenant_id = :t', "je.status = 'posted'", 'a.code = :ac'];
    $params = ['t' => $tid, 'ac' => $code];
    if ($from) { $where[] = 'je.posting_date >= :f';   $params['f']   = $from; }
    if ($to)   { $where[] = 'je.posting_date <= :to2'; $params['to2'] = $to;   }
    $stmt = $db->prepare(
        'SELECT je.id AS je_id, je.je_number, je.posting_date,
                a.code AS account_code, a.name AS account_name, a.normal_side,
                l.debit, l.credit, l.memo, je.source_module, je.source_ref_type, je.source_ref_id
         FROM accounting_journal_entry_lines l
         JOIN accounting_journal_entries je ON je.id = l.je_id
         JOIN accounting_accounts a ON a.id = l.account_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY je.posting_date, je.id, l.line_no
         LIMIT 2000'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $run = 0.0; $td = 0.0; $tc = 0.0;
    foreach ($rows as &$r) {
        $delta = ($r['normal_side'] === 'debit')
            ? ((float) $r['debit'] - (float) $r['credit'])
            : ((float) $r['credit'] - (float) $r['debit']);
        $run += $delta;
        $r['running_balance'] = round($run, 2);
        $td += (float) $r['debit']; $tc += (float) $r['credit'];
    }
    unset($r);
    api_ok([
        'account_code' => $code,
        'rows'         => $rows,
        'total_debit'  => round($td, 2),
        'total_credit' => round($tc, 2),
        'ending_balance' => round($run, 2),
        'count'        => count($rows),
    ]);
}

api_error('Unknown report type. Use gl_detail|unposted_jes|approval_queue|audit_log|account_activity.', 422);
