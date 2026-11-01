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
rbac_legacy_require($user, 'accounting.reports.view');

$type = (string) ($_GET['type'] ?? '');
$from = $_GET['from']   ?? null;
$to   = $_GET['to']     ?? null;
$code = $_GET['account_code'] ?? $_GET['code'] ?? null;
$db   = getDB();
$entityId = null;
$page = 1;
$pageSize = 50;
$offset = 0;
if (in_array($type, ['gl_detail', 'unposted_jes', 'unposted', 'approval_queue', 'account_activity'], true)) {
    try {
        $entityId = accountingValidateActiveEntityId($tid, $_GET['entity_id'] ?? null);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
}

if (in_array($type, ['gl_detail', 'unposted_jes', 'unposted', 'approval_queue', 'audit_log', 'account_activity'], true)) {
    $pageRaw = $_GET['page'] ?? '1';
    $pageSizeRaw = $_GET['page_size'] ?? '50';
    if (!is_scalar($pageRaw) || !preg_match('/^[1-9][0-9]{0,5}$/', (string) $pageRaw)
        || !is_scalar($pageSizeRaw) || !preg_match('/^[1-9][0-9]{0,2}$/', (string) $pageSizeRaw)
        || (int) $pageSizeRaw > 200) {
        api_error('Choose a valid report page and page size (1-200).', 422);
    }
    $page = (int) $pageRaw;
    $pageSize = (int) $pageSizeRaw;
    $offset = ($page - 1) * $pageSize;
}

$readPage = static function (string $summarySql, string $rowsSql, array $params,
    ?callable $extra = null) use ($db, $pageSize, $offset): array {
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->beginTransaction();
    try {
        $summaryStmt = $db->prepare($summarySql);
        $summaryStmt->execute($params);
        $summary = $summaryStmt->fetch(\PDO::FETCH_ASSOC);
        $additional = $extra ? $extra($db, $params) : null;
        $rowsStmt = $db->prepare($rowsSql . ' LIMIT ' . $pageSize . ' OFFSET ' . $offset);
        $rowsStmt->execute($params);
        $rows = $rowsStmt->fetchAll(\PDO::FETCH_ASSOC);
        $db->commit();
        return [$summary, $rows, $additional];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
};

if ($type === 'gl_detail') {
    $where  = ['je.tenant_id = :t', "je.status IN ('posted','reversed')"];
    $params = ['t' => $tid];
    if ($entityId) { $where[] = 'je.entity_id = :entity_id'; $params['entity_id'] = $entityId; }
    if ($from) { $where[] = 'je.posting_date >= :f';   $params['f']   = $from; }
    if ($to)   { $where[] = 'je.posting_date <= :to2'; $params['to2'] = $to;   }
    if ($code) { $where[] = 'a.code = :ac';            $params['ac']  = $code; }
    $source = ' FROM accounting_journal_entry_lines l
         JOIN accounting_journal_entries je ON je.id = l.je_id
         JOIN accounting_accounts a ON a.id = l.account_id
         WHERE ' . implode(' AND ', $where);
    [$summary, $rows] = $readPage(
        'SELECT COUNT(*) AS line_count, COALESCE(SUM(l.debit), 0) AS total_debit,
            COALESCE(SUM(l.credit), 0) AS total_credit' . $source,
        'SELECT je.id AS je_id, je.je_number, je.posting_date, je.entity_id,
                a.id AS account_id, a.code AS account_code,
                a.name AS account_name, l.debit, l.credit, l.memo AS line_memo,
                je.memo AS je_memo, je.source_module, je.source_ref_type, je.source_ref_id'
            . $source . ' ORDER BY je.posting_date DESC, je.id DESC, l.line_no, l.id',
        $params
    );
    $count = (int) $summary['line_count'];
    api_ok(['entity_id' => $entityId, 'rows' => $rows,
        'from' => (string) ($from ?? ''), 'to' => (string) ($to ?? ''),
        'account_code' => (string) ($code ?? ''),
        'total_debit' => round((float) $summary['total_debit'], 2),
        'total_credit' => round((float) $summary['total_credit'], 2),
        'count' => $count, 'page' => $page, 'page_size' => $pageSize,
        'has_more' => $offset + count($rows) < $count]);
}

if ($type === 'unposted_jes' || $type === 'unposted') {
    $where = "tenant_id = :t AND status = 'draft'";
    $params = ['t' => $tid];
    if ($entityId) { $where .= ' AND entity_id = :entity_id'; $params['entity_id'] = $entityId; }
    [$summary, $rows] = $readPage(
        "SELECT COUNT(*) AS row_count FROM accounting_journal_entries WHERE {$where}",
        "SELECT id, je_number, posting_date, entity_id, period_id, source_module,
                status, total_debit, total_credit, memo, created_by_user_id, created_at
         FROM accounting_journal_entries
         WHERE {$where}
         ORDER BY posting_date DESC, id DESC",
        $params
    );
    $count = (int) $summary['row_count'];
    api_ok(['entity_id' => $entityId, 'rows' => $rows,
        'count' => $count, 'by_status' => $count ? ['draft' => $count] : [],
        'page' => $page, 'page_size' => $pageSize,
        'has_more' => $offset + count($rows) < $count]);
}

if ($type === 'approval_queue') {
    $where = "tenant_id = :t AND status = 'draft'";
    $params = ['t' => $tid];
    if ($entityId) { $where .= ' AND entity_id = :entity_id'; $params['entity_id'] = $entityId; }
    [$summary, $rows, $bySrc] = $readPage(
        "SELECT COUNT(*) AS row_count FROM accounting_journal_entries WHERE {$where}",
        "SELECT id, je_number, posting_date, entity_id, source_module, source_ref_type, source_ref_id,
                total_debit, total_credit, memo, created_by_user_id, created_at
         FROM accounting_journal_entries
         WHERE {$where}
         ORDER BY created_at ASC, id ASC",
        $params,
        static function (\PDO $db, array $params) use ($where): array {
            $stmt = $db->prepare("SELECT source_module, COUNT(*) AS n
                FROM accounting_journal_entries WHERE {$where} GROUP BY source_module");
            $stmt->execute($params);
            $groups = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $groups[$row['source_module']] = (int) $row['n'];
            }
            return $groups;
        }
    );
    $count = (int) $summary['row_count'];
    api_ok(['entity_id' => $entityId, 'rows' => $rows,
        'count' => $count, 'by_source' => $bySrc,
        'page' => $page, 'page_size' => $pageSize,
        'has_more' => $offset + count($rows) < $count]);
}

if ($type === 'audit_log') {
    rbac_legacy_require($user, 'accounting.audit.view');
    $where  = ['tenant_id = :t', "event LIKE 'accounting.%'"];
    $params = ['t' => $tid];
    if ($from) { $where[] = 'created_at >= :f';   $params['f']   = $from . ' 00:00:00'; }
    if ($to)   { $where[] = 'created_at <= :to2'; $params['to2'] = $to   . ' 23:59:59'; }
    if (!empty($_GET['event_like'])) {
        $where[] = 'event LIKE :el';
        $params['el'] = 'accounting.' . rtrim((string) $_GET['event_like'], '%') . '%';
    }
    $source = ' FROM audit_log WHERE ' . implode(' AND ', $where);
    [$summary, $rows] = $readPage(
        'SELECT COUNT(*) AS row_count' . $source,
        'SELECT id, event, actor_user_id, target_id, meta_json, ip_address, created_at'
            . $source . ' ORDER BY created_at DESC, id DESC',
        $params
    );
    $count = (int) $summary['row_count'];
    api_ok(['rows' => $rows, 'count' => $count,
        'from' => (string) ($from ?? ''), 'to' => (string) ($to ?? ''),
        'event_like' => (string) ($_GET['event_like'] ?? ''),
        'page' => $page, 'page_size' => $pageSize,
        'has_more' => $offset + count($rows) < $count]);
}

if ($type === 'account_activity') {
    if (!$code) api_error('code (account_code) required', 422);
    $where  = ['je.tenant_id = :t', "je.status IN ('posted','reversed')", 'a.code = :ac'];
    $params = ['t' => $tid, 'ac' => $code];
    if ($entityId) { $where[] = 'je.entity_id = :entity_id'; $params['entity_id'] = $entityId; }
    if ($from) { $where[] = 'je.posting_date >= :f';   $params['f']   = $from; }
    if ($to)   { $where[] = 'je.posting_date <= :to2'; $params['to2'] = $to;   }
    $source = ' FROM accounting_journal_entry_lines l
        JOIN accounting_journal_entries je ON je.id = l.je_id
        JOIN accounting_accounts a ON a.id = l.account_id
        WHERE ' . implode(' AND ', $where);
    $signed = "CASE WHEN a.normal_side = 'debit' THEN l.debit - l.credit
        ELSE l.credit - l.debit END";
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->beginTransaction();
    try {
        $totalsStmt = $db->prepare('SELECT COUNT(*) AS line_count,
            COALESCE(SUM(l.debit), 0) AS total_debit,
            COALESCE(SUM(l.credit), 0) AS total_credit,
            COALESCE(SUM(' . $signed . '), 0) AS ending_balance' . $source);
        $totalsStmt->execute($params);
        $totals = $totalsStmt->fetch(\PDO::FETCH_ASSOC);
        $stmt = $db->prepare(
            'SELECT je.id AS je_id, je.je_number, je.posting_date, je.entity_id,
                    a.code AS account_code, a.name AS account_name, a.normal_side,
                    l.debit, l.credit, l.memo, je.source_module, je.source_ref_type, je.source_ref_id,
                    SUM(' . $signed . ') OVER (
                        ORDER BY je.posting_date, je.id, l.line_no, l.id
                        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                    ) AS running_balance'
            . $source . ' ORDER BY je.posting_date, je.id, l.line_no, l.id
                LIMIT ' . $pageSize . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    foreach ($rows as &$row) $row['running_balance'] = round((float) $row['running_balance'], 2);
    unset($row);
    $count = (int) $totals['line_count'];
    api_ok([
        'entity_id' => $entityId,
        'account_code' => $code,
        'from' => (string) ($from ?? ''),
        'to' => (string) ($to ?? ''),
        'page' => $page,
        'page_size' => $pageSize,
        'has_more' => $offset + count($rows) < $count,
        'rows'         => $rows,
        'total_debit'  => round((float) $totals['total_debit'], 2),
        'total_credit' => round((float) $totals['total_credit'], 2),
        'ending_balance' => round((float) $totals['ending_balance'], 2),
        'count'        => $count,
    ]);
}

api_error('Unknown report type. Use gl_detail|unposted_jes|approval_queue|audit_log|account_activity.', 422);
