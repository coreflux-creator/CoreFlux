<?php
/**
 * Accounting API — Standard reports
 *
 *   GET /api/accounting/reports?type=income_statement&from=YYYY-MM-DD&to=YYYY-MM-DD[&entity_id=]
 *   GET /api/accounting/reports?type=balance_sheet&as_of=YYYY-MM-DD[&entity_id=]
 *
 * Both reports drive off accountingTrialBalance() for the underlying numbers
 * but reshape to the canonical financial-statement structure.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/accounting.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
RBAC::requirePermission($user, 'accounting.coa.view');

if ($method !== 'GET') api_error('Method not allowed', 405);
$type = (string) ($_GET['type'] ?? '');
$eid  = !empty($_GET['entity_id']) ? (int) $_GET['entity_id'] : null;

if ($type === 'income_statement') {
    $from = (string) ($_GET['from'] ?? date('Y-01-01'));
    $to   = (string) ($_GET['to']   ?? date('Y-m-d'));
    api_ok(reportIncomeStatement($tid, $from, $to, $eid));
}

if ($type === 'balance_sheet') {
    $asOf = (string) ($_GET['as_of'] ?? date('Y-m-d'));
    api_ok(reportBalanceSheet($tid, $asOf, $eid));
}

api_error('Unknown report type. Use income_statement or balance_sheet.', 422);

/**
 * Income Statement (P&L) — revenue and expenses for the period.
 * Reuses trial-balance arithmetic but filters to revenue/expense and
 * aggregates by account_type. Posted JEs only.
 */
function reportIncomeStatement(int $tenantId, string $from, string $to, ?int $entityId): array
{
    $pdo = getDB();
    $where  = ['je.tenant_id = :t', 'je.status = "posted"', 'je.posting_date >= :f', 'je.posting_date <= :tx'];
    $params = ['t' => $tenantId, 'f' => $from, 'tx' => $to];
    if ($entityId) { $where[] = 'je.entity_id = :e'; $params['e'] = $entityId; }

    $stmt = $pdo->prepare(
        'SELECT a.code, a.name, a.account_type, a.normal_side,
                COALESCE(SUM(l.debit),0)  AS debit,
                COALESCE(SUM(l.credit),0) AS credit
         FROM accounting_accounts a
         LEFT JOIN accounting_journal_entry_lines l ON l.account_id = a.id
         LEFT JOIN accounting_journal_entries je ON je.id = l.je_id
         WHERE a.tenant_id = :t AND a.account_type IN ("revenue","expense")
           AND (je.id IS NULL OR (' . implode(' AND ', $where) . '))
         GROUP BY a.id
         ORDER BY a.account_type DESC, a.code'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $revenue = []; $expense = [];
    $revTotal = 0.0; $expTotal = 0.0;
    foreach ($rows as $r) {
        $d = (float) $r['debit']; $c = (float) $r['credit'];
        // Period activity for the account (signed by normal side).
        $bal = $r['normal_side'] === 'debit' ? round($d - $c, 2) : round($c - $d, 2);
        $r['amount'] = $bal;
        if ($r['account_type'] === 'revenue') { $revenue[] = $r; $revTotal += $bal; }
        else                                  { $expense[] = $r; $expTotal += $bal; }
    }
    return [
        'period'        => ['from' => $from, 'to' => $to, 'entity_id' => $entityId],
        'revenue'       => $revenue,
        'expense'       => $expense,
        'total_revenue' => round($revTotal, 2),
        'total_expense' => round($expTotal, 2),
        'net_income'    => round($revTotal - $expTotal, 2),
    ];
}

/**
 * Balance Sheet — assets / liabilities / equity (+ implied YTD net income).
 * Same posted-JE-only rule. Equity bucket gets a synthetic "Current period
 * net income" line so the sheet balances when retained earnings haven't
 * been swept yet.
 */
function reportBalanceSheet(int $tenantId, string $asOf, ?int $entityId): array
{
    $pdo = getDB();
    $where  = ['je.tenant_id = :t', 'je.status = "posted"', 'je.posting_date <= :d'];
    $params = ['t' => $tenantId, 'd' => $asOf];
    if ($entityId) { $where[] = 'je.entity_id = :e'; $params['e'] = $entityId; }

    $stmt = $pdo->prepare(
        'SELECT a.code, a.name, a.account_type, a.normal_side,
                COALESCE(SUM(l.debit),0)  AS debit,
                COALESCE(SUM(l.credit),0) AS credit
         FROM accounting_accounts a
         LEFT JOIN accounting_journal_entry_lines l ON l.account_id = a.id
         LEFT JOIN accounting_journal_entries je ON je.id = l.je_id
         WHERE a.tenant_id = :t
           AND (je.id IS NULL OR (' . implode(' AND ', $where) . '))
         GROUP BY a.id
         ORDER BY a.account_type, a.code'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $assets = []; $liabilities = []; $equity = [];
    $aTot = 0.0; $lTot = 0.0; $eTot = 0.0;
    $revAcc = 0.0; $expAcc = 0.0;
    foreach ($rows as $r) {
        $d = (float) $r['debit']; $c = (float) $r['credit'];
        $bal = $r['normal_side'] === 'debit' ? round($d - $c, 2) : round($c - $d, 2);
        $r['amount'] = $bal;
        switch ($r['account_type']) {
            case 'asset':     $assets[]      = $r; $aTot += $bal; break;
            case 'liability': $liabilities[] = $r; $lTot += $bal; break;
            case 'equity':    $equity[]      = $r; $eTot += $bal; break;
            case 'revenue':   $revAcc += $bal;                    break;
            case 'expense':   $expAcc += $bal;                    break;
        }
    }
    $netIncome = round($revAcc - $expAcc, 2);
    if (abs($netIncome) > 0.005) {
        // Synthetic line so the sheet balances when retained earnings haven't
        // been closed yet. Front-end can call this out as "current period".
        $equity[] = [
            'code' => '3999', 'name' => 'Current period net income (to be closed)',
            'account_type' => 'equity', 'normal_side' => 'credit',
            'debit' => 0, 'credit' => $netIncome, 'amount' => $netIncome,
            'synthetic' => true,
        ];
        $eTot += $netIncome;
    }
    return [
        'as_of'              => $asOf,
        'entity_id'          => $entityId,
        'assets'             => $assets,
        'liabilities'        => $liabilities,
        'equity'             => $equity,
        'total_assets'       => round($aTot, 2),
        'total_liabilities'  => round($lTot, 2),
        'total_equity'       => round($eTot, 2),
        'liabilities_plus_equity' => round($lTot + $eTot, 2),
        'balanced'           => abs($aTot - ($lTot + $eTot)) < 0.005,
    ];
}
