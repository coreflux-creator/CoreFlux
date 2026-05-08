<?php
/**
 * GL Detail report (Sprint 7f.1, Layer-parity).
 *
 *   GET /api/gl_detail.php
 *        ?account_id=N           OR   ?account_code=5100
 *        &start=YYYY-MM-DD              default = first day of current month
 *        &end=YYYY-MM-DD                default = today
 *        &entity_id=N                   optional
 *        &include_unposted=1            include draft / reversed
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
RBAC::requirePermission($user, 'accounting.coa.view');

$accountId   = (int) (api_query('account_id') ?? 0);
$accountCode = trim((string) (api_query('account_code') ?? ''));
$start       = (string) (api_query('start') ?? date('Y-m-01'));
$end         = (string) (api_query('end')   ?? date('Y-m-d'));
$entityId    = (int) (api_query('entity_id') ?? 0);
$includeUnposted = !empty(api_query('include_unposted'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) api_error('start must be YYYY-MM-DD', 400);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   api_error('end must be YYYY-MM-DD', 400);
if ($start > $end) api_error('start must be <= end', 400);
if (!$accountId && $accountCode === '') api_error('account_id or account_code required', 400);

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

// Status filter.
$statusSql = $includeUnposted
    ? "je.status IN ('posted','draft','reversed')"
    : "je.status = 'posted'";

$entityWhere = $entityId ? 'AND je.entity_id = :eid' : '';

// Opening balance — sum of (debit - credit) for the normal-debit side
// (or credit - debit for normal-credit), pre-`start`.
$openSql = "SELECT
            COALESCE(SUM(jl.debit), 0)  AS d,
            COALESCE(SUM(jl.credit), 0) AS c
       FROM accounting_journal_lines jl
       JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id
      WHERE jl.tenant_id = :t
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

// Detail rows.
$linesSql = "SELECT je.id AS je_id, je.je_number, je.posting_date, je.memo,
                    je.source_module, je.source_ref_type, je.source_ref_id,
                    jl.debit, jl.credit, jl.description, jl.counterparty_company_id,
                    jl.dimension_values
               FROM accounting_journal_lines jl
               JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id
              WHERE jl.tenant_id = :t
                AND jl.account_id = :aid
                AND je.posting_date BETWEEN :start AND :end
                AND {$statusSql}
                {$entityWhere}
              ORDER BY je.posting_date ASC, je.id ASC, jl.line_no ASC";
$linesStmt = $pdo->prepare($linesSql);
$linesParams = ['t' => $tid, 'aid' => $accountId, 'start' => $start, 'end' => $end];
if ($entityId) $linesParams['eid'] = $entityId;
$linesStmt->execute($linesParams);
$rows = $linesStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

$running = $opening;
$totalD  = 0.0;
$totalC  = 0.0;
$out     = [];
foreach ($rows as $r) {
    $d = round((float) $r['debit'],  2);
    $c = round((float) $r['credit'], 2);
    $running = $normalSide === 'credit'
        ? round($running + $c - $d, 2)
        : round($running + $d - $c, 2);
    $totalD += $d; $totalC += $c;

    $dims = null;
    if (!empty($r['dimension_values'])) {
        $j = json_decode((string) $r['dimension_values'], true);
        if (is_array($j)) $dims = $j;
    }

    $out[] = [
        'je_id'         => (int) $r['je_id'],
        'je_number'     => (string) $r['je_number'],
        'posting_date'  => (string) $r['posting_date'],
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
    'lines'           => $out,
    'totals'          => [
        'debit'           => round($totalD, 2),
        'credit'          => round($totalC, 2),
        'net'             => $normalSide === 'credit'
            ? round($totalC - $totalD, 2)
            : round($totalD - $totalC, 2),
        'ending_balance'  => $running,
    ],
    'count'           => count($out),
]);
