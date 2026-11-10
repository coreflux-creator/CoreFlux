<?php
/**
 * GL Detail report (Sprint 7f.1, Layer-parity).
 *
 *   GET /api/gl_detail.php
 *        ?account_id=N           OR   ?account_code=5100
 *        &start=YYYY-MM-DD              default = first day of current month
 *        &end=YYYY-MM-DD                default = today
 *        &entity_id=N                   optional
 *        &include_unposted=1            include drafts (reversed entries are always ledger history)
 *        &page=N&per_page=25|50|100|200
 *
 * Returns:
 *   {
 *     account: { id, code, name, account_type, normal_side },
 *     start, end, entity_id,
 *     opening_balance,
 *     lines: [
 *       { je_id, je_number, posting_date, memo, debit, credit, running, source_module, source_ref_type, source_ref_id, counterparty_company_id, dimensions },
 *     ],
 *     totals: { debit, credit, net, ending_balance },
 *     count
 *   }
 *
 * Used by the new /modules/accounting/gl-detail page. Drill-down click
 * on any row navigates to the JE detail page via the je_id.
 *
 * RBAC: `accounting.coa.view` (read-only).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'accounting.coa.view');

$accountId   = (int) (api_query('account_id') ?? 0);
$accountCode = trim((string) (api_query('account_code') ?? ''));
$start       = (string) (api_query('start') ?? date('Y-m-01'));
$end         = (string) (api_query('end')   ?? date('Y-m-d'));
$entityId    = (int) (api_query('entity_id') ?? 0);
$includeUnposted = !empty(api_query('include_unposted'));
$pageRaw = (string) (api_query('page') ?? '1');
$perPageRaw = (string) (api_query('per_page') ?? '50');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) api_error('start must be YYYY-MM-DD', 400);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   api_error('end must be YYYY-MM-DD', 400);
if ($start > $end) api_error('start must be <= end', 400);
if (!$accountId && $accountCode === '') api_error('account_id or account_code required', 400);
if (!preg_match('/^[1-9]\d*$/', $pageRaw) || strlen($pageRaw) > 7) api_error('page must be a positive integer', 400);
if (!in_array($perPageRaw, ['25', '50', '100', '200'], true)) api_error('per_page must be 25, 50, 100 or 200', 400);
$page = (int) $pageRaw;
$perPage = (int) $perPageRaw;

$pdo = getDB();

// Resolve account.
if ($accountId) {
    $aStmt = $pdo->prepare(
        'SELECT id, code, name, account_type, normal_side
           FROM accounting_accounts
          WHERE tenant_id = :t AND id = :id LIMIT 1'
    );
    $aStmt->execute(['t' => $tid, 'id' => $accountId]);
} else {
    $aStmt = $pdo->prepare(
        'SELECT id, code, name, account_type, normal_side
           FROM accounting_accounts
          WHERE tenant_id = :t AND code = :c LIMIT 1'
    );
    $aStmt->execute(['t' => $tid, 'c' => $accountCode]);
}
$account = $aStmt->fetch(\PDO::FETCH_ASSOC);
if (!$account) api_error('Account not found', 404);

$account['id'] = (int) $account['id'];
$accountId = (int) $account['id'];

// Opening, full-period totals and the selected page must observe the same
// ledger state if another request posts while this report is loading.
$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$pdo->beginTransaction();

// Reversed entries were posted and remain part of ledger history. Their
// separately posted reversal supplies the offset. Excluding the original
// would count only the offset and invert the books.
$statusSql = $includeUnposted
    ? "je.status IN ('posted','draft','reversed')"
    : "je.status IN ('posted','reversed')";

$entityWhere = $entityId ? 'AND je.entity_id = :eid' : '';

// Opening balance — sum of (debit - credit) for the normal-debit side
// (or credit - debit for normal-credit), pre-`start`.
$openSql = "SELECT
            COALESCE(SUM(jl.debit), 0)  AS d,
            COALESCE(SUM(jl.credit), 0) AS c
       FROM accounting_journal_entry_lines jl
       JOIN accounting_journal_entries je ON je.id = jl.je_id
      WHERE je.tenant_id = :t
        AND jl.account_id = :aid
        AND je.posting_date < :start
        AND {$statusSql}
        {$entityWhere}";
$openStmt = $pdo->prepare($openSql);
$openParams = ['t' => $tid, 'aid' => $accountId, 'start' => $start];
if ($entityId) $openParams['eid'] = $entityId;
$openStmt->execute($openParams);
$openRow = $openStmt->fetch(\PDO::FETCH_ASSOC) ?: ['d' => 0, 'c' => 0];

$normalSide = strtolower((string) $account['normal_side']);
$opening = $normalSide === 'credit'
    ? round((float) $openRow['c'] - (float) $openRow['d'], 2)
    : round((float) $openRow['d'] - (float) $openRow['c'], 2);

// Keep full-window totals independent of the requested detail page.
$rangeWhere = "je.tenant_id = :t AND jl.account_id = :aid
               AND je.posting_date BETWEEN :start AND :end
               AND {$statusSql} {$entityWhere}";
$rangeParams = ['t' => $tid, 'aid' => $accountId, 'start' => $start, 'end' => $end];
if ($entityId) $rangeParams['eid'] = $entityId;
$totalStmt = $pdo->prepare(
    "SELECT COUNT(*) AS line_count, COALESCE(SUM(jl.debit), 0) AS d,
            COALESCE(SUM(jl.credit), 0) AS c
       FROM accounting_journal_entry_lines jl
       JOIN accounting_journal_entries je ON je.id = jl.je_id
      WHERE {$rangeWhere}"
);
$totalStmt->execute($rangeParams);
$period = $totalStmt->fetch(\PDO::FETCH_ASSOC) ?: ['line_count' => 0, 'd' => 0, 'c' => 0];
$totalRows = (int) $period['line_count'];
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$linesSql = "SELECT jl.id AS line_id, jl.line_no, je.id AS je_id, je.je_number,
                    je.posting_date, je.memo, je.status,
                    je.source_module, je.source_ref_type, je.source_ref_id,
                    jl.debit, jl.credit, jl.description, jl.counterparty_company_id,
                    jl.dim_json AS dimension_values
               FROM accounting_journal_entry_lines jl
               JOIN accounting_journal_entries je ON je.id = jl.je_id
              WHERE {$rangeWhere}
              ORDER BY je.posting_date ASC, je.id ASC, jl.line_no ASC, jl.id ASC
              LIMIT :per_page OFFSET :offset";
$linesStmt = $pdo->prepare($linesSql);
foreach ($rangeParams as $key => $value) $linesStmt->bindValue(':' . $key, $value);
$linesStmt->bindValue(':per_page', $perPage, \PDO::PARAM_INT);
$linesStmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
$linesStmt->execute();
$rows = $linesStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

$pageOpening = $opening;
if ($offset > 0 && $rows) {
    $first = $rows[0];
    $beforeStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(jl.debit), 0) AS d, COALESCE(SUM(jl.credit), 0) AS c
           FROM accounting_journal_entry_lines jl
           JOIN accounting_journal_entries je ON je.id = jl.je_id
          WHERE {$rangeWhere}
            AND (je.posting_date < :first_date_before
              OR (je.posting_date = :first_date_equal AND
                  (je.id < :first_je_before
                   OR (je.id = :first_je_equal AND
                       (jl.line_no < :first_line_before
                        OR (jl.line_no = :first_line_equal AND jl.id < :first_line_id))))))"
    );
    $beforeStmt->execute($rangeParams + [
        'first_date_before' => $first['posting_date'], 'first_date_equal' => $first['posting_date'],
        'first_je_before' => $first['je_id'], 'first_je_equal' => $first['je_id'],
        'first_line_before' => $first['line_no'], 'first_line_equal' => $first['line_no'],
        'first_line_id' => $first['line_id'],
    ]);
    $before = $beforeStmt->fetch(\PDO::FETCH_ASSOC) ?: ['d' => 0, 'c' => 0];
    $pageOpening += $normalSide === 'credit'
        ? (float) $before['c'] - (float) $before['d']
        : (float) $before['d'] - (float) $before['c'];
    $pageOpening = round($pageOpening, 2);
}

$running = $pageOpening;
$out     = [];
foreach ($rows as $r) {
    $d = round((float) $r['debit'],  2);
    $c = round((float) $r['credit'], 2);
    $running = $normalSide === 'credit'
        ? round($running + $c - $d, 2)
        : round($running + $d - $c, 2);
    $dims = null;
    if (!empty($r['dimension_values'])) {
        $j = json_decode((string) $r['dimension_values'], true);
        if (is_array($j)) $dims = $j;
    }

    $out[] = [
        'line_id'       => (int) $r['line_id'],
        'je_id'         => (int) $r['je_id'],
        'je_number'     => (string) $r['je_number'],
        'posting_date'  => (string) $r['posting_date'],
        'status'        => (string) $r['status'],
        'memo'          => $r['memo'] !== null ? (string) $r['memo'] : null,
        'description'   => $r['description'] !== null ? (string) $r['description'] : null,
        'debit'         => $d,
        'credit'        => $c,
        'running'       => $running,
        'source_module' => $r['source_module'] !== null ? (string) $r['source_module'] : null,
        'source_ref_type' => $r['source_ref_type'] !== null ? (string) $r['source_ref_type'] : null,
        'source_ref_id' => $r['source_ref_id'] !== null ? (string) $r['source_ref_id'] : null,
        'counterparty_company_id' => $r['counterparty_company_id'] !== null ? (int) $r['counterparty_company_id'] : null,
        'dimensions'    => $dims,
    ];
}

$totalD = round((float) $period['d'], 2);
$totalC = round((float) $period['c'], 2);
$net = $normalSide === 'credit' ? $totalC - $totalD : $totalD - $totalC;
$pdo->commit();

api_ok([
    'account'         => [
        'id'           => (int) $account['id'],
        'code'         => (string) $account['code'],
        'name'         => (string) $account['name'],
        'account_type' => (string) $account['account_type'],
        'normal_side'  => $normalSide,
    ],
    'start'           => $start,
    'end'             => $end,
    'entity_id'       => $entityId ?: null,
    'include_unposted'=> $includeUnposted,
    'opening_balance' => $opening,
    'page_opening_balance' => $pageOpening,
    'lines'           => $out,
    'totals'          => [
        'debit'           => $totalD,
        'credit'          => $totalC,
        'net'             => round($net, 2),
        'ending_balance'  => round($opening + $net, 2),
    ],
    'pagination'     => [
        'page' => $page, 'per_page' => $perPage,
        'total_rows' => $totalRows, 'total_pages' => $totalPages,
    ],
    'count'           => count($out),
]);
