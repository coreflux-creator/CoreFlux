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
require_once __DIR__ . '/../lib/consolidation.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
RBAC::requirePermission($user, 'accounting.coa.view');

if ($method !== 'GET') api_error('Method not allowed', 405);
$type = (string) ($_GET['type'] ?? '');
$eid  = !empty($_GET['entity_id']) ? (int) $_GET['entity_id'] : null;

// Consolidation-mode input: ?consolidate=1&entity_ids=1,2,3
// Or derive from an ownership root: ?consolidate=1&root_entity_id=1
$consolidate = !empty($_GET['consolidate']);
$entityIds   = [];
if ($consolidate) {
    if (!empty($_GET['entity_ids'])) {
        $entityIds = array_values(array_filter(array_map('intval', explode(',', (string) $_GET['entity_ids']))));
    } elseif (!empty($_GET['root_entity_id'])) {
        $asOfForTree = $_GET['as_of'] ?? $_GET['to'] ?? date('Y-m-d');
        $tree = entityRelationshipResolveDescendants($tid, (int) $_GET['root_entity_id'], $asOfForTree);
        $entityIds = array_map('intval', array_keys($tree));
    }
    if (!$entityIds) api_error('consolidate=1 requires entity_ids=... or root_entity_id=...', 422);
}

/**
 * Wrap a report builder so any SQL / library error becomes a 200 with a
 * `data_warning` string instead of a raw 500. The front-end shows an
 * amber "data not ready yet" banner per Sprint 6f UX-cleanup pattern.
 */
function _safeReport(callable $fn): array {
    try {
        $out = $fn();
        return is_array($out) ? $out : ['rows' => $out];
    } catch (\Throwable $e) {
        error_log('accounting/reports failed: ' . $e->getMessage());
        return [
            'data_warning' => 'Report data not ready yet — ' . $e->getMessage(),
            'rows'         => [],
            'lines'        => [],
            'sections'     => [],
            'totals'       => [],
        ];
    }
}

if ($type === 'income_statement') {
    $from = (string) ($_GET['from'] ?? date('Y-01-01'));
    $to   = (string) ($_GET['to']   ?? date('Y-m-d'));
    api_ok(_safeReport(fn() => $consolidate
        ? consolidateIncomeStatement($tid, $entityIds, $from, $to)
        : reportIncomeStatement($tid, $from, $to, $eid)));
}

if ($type === 'balance_sheet') {
    $asOf = (string) ($_GET['as_of'] ?? date('Y-m-d'));
    api_ok(_safeReport(fn() => $consolidate
        ? consolidateBalanceSheet($tid, $entityIds, $asOf)
        : reportBalanceSheet($tid, $asOf, $eid)));
}

if ($type === 'trial_balance') {
    $asOf = (string) ($_GET['as_of'] ?? date('Y-m-d'));
    api_ok(_safeReport(function () use ($consolidate, $tid, $entityIds, $asOf, $eid) {
        if ($consolidate) return consolidateTrialBalance($tid, $entityIds, $asOf);
        return ['rows' => accountingTrialBalance($tid, $asOf, $eid), 'as_of' => $asOf, 'entity_id' => $eid];
    }));
}

if ($type === 'cash_flow_indirect' || $type === 'cash_flow') {
    $from = (string) ($_GET['from'] ?? date('Y-01-01'));
    $to   = (string) ($_GET['to']   ?? date('Y-m-d'));
    api_ok(_safeReport(fn() => reportCashFlowIndirect($tid, $from, $to, $eid)));
}

api_error('Unknown report type. Use income_statement, balance_sheet, trial_balance, or cash_flow_indirect.', 422);

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


/**
 * Cash Flow Statement (indirect method).
 *
 * Per SPEC §12 (Phase A v1.0): "Cash flow statement = indirect method only.
 * cash_flow_tag on COA already supports indirect."
 *
 * Method:
 *   1. Net income from the period (reportIncomeStatement).
 *   2. Walk every non-cash balance-sheet account, compute the period-change
 *      and bucket it by `cash_flow_tag`:
 *        - operating_addback_*    → add to net income (e.g. depreciation expense)
 *        - operating_wc_ar / wc_ap / wc_inventory / wc_other → working capital
 *        - investing_*            → investing activities
 *        - financing_*            → financing activities
 *        - cash_and_equivalents   → SKIP (these ARE the cash we're explaining)
 *        - NULL / unset           → tagged 'untagged' (admin must classify in COA)
 *   3. Beginning cash + total change = ending cash. We tie out ending cash
 *      against the actual GL ending balance of cash accounts and report the
 *      difference (should be 0).
 *
 * Sign convention (asset accounts are debit-normal, liability/equity are credit-normal):
 *   - Increase in non-cash asset      → use of cash (subtract)
 *   - Decrease in non-cash asset      → source of cash (add)
 *   - Increase in liability or equity → source of cash (add)
 *   - Decrease in liability or equity → use of cash (subtract)
 */
function reportCashFlowIndirect(int $tenantId, string $from, string $to, ?int $entityId): array
{
    $pdo = getDB();

    // 1. Net income for the period
    $is        = reportIncomeStatement($tenantId, $from, $to, $entityId);
    $netIncome = (float) $is['net_income'];

    // 2. Pull balance-sheet account balances at the start (day before $from)
    //    and end ($to) so we can compute the period change.
    $startDate = date('Y-m-d', strtotime($from . ' -1 day'));
    $startBs   = reportBalanceSheet($tenantId, $startDate, $entityId);
    $endBs     = reportBalanceSheet($tenantId, $to,        $entityId);

    // Index account balances by code for fast lookup
    $startByCode = []; $endByCode = []; $tagByCode = []; $typeByCode = []; $nameByCode = [];
    foreach ([['rows' => array_merge($startBs['assets'], $startBs['liabilities'], $startBs['equity']), 'tgt' => &$startByCode],
             ['rows' => array_merge($endBs['assets'],   $endBs['liabilities'],   $endBs['equity']),   'tgt' => &$endByCode]] as $bag) {
        foreach ($bag['rows'] as $r) {
            if (!empty($r['synthetic'])) continue;
            $bag['tgt'][(string) $r['code']] = (float) $r['amount'];
        }
    }
    // cash_flow_tag lookup direct from accounting_accounts
    $tagStmt = $pdo->prepare(
        'SELECT code, name, account_type, COALESCE(cash_flow_tag, "untagged") AS cash_flow_tag
         FROM accounting_accounts WHERE tenant_id = :t'
    );
    $tagStmt->execute(['t' => $tenantId]);
    foreach ($tagStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $tagByCode[(string) $r['code']]  = (string) $r['cash_flow_tag'];
        $typeByCode[(string) $r['code']] = (string) $r['account_type'];
        $nameByCode[(string) $r['code']] = (string) $r['name'];
    }

    $sections = [
        'operating'            => ['lines' => [], 'subtotal' => 0.0],
        'investing'            => ['lines' => [], 'subtotal' => 0.0],
        'financing'            => ['lines' => [], 'subtotal' => 0.0],
        'untagged'             => ['lines' => [], 'subtotal' => 0.0],
    ];

    // 3. Walk every account that appeared in either balance sheet
    $allCodes = array_unique(array_merge(array_keys($startByCode), array_keys($endByCode)));
    $cashEndingFromGl = 0.0;
    $cashStartingFromGl = 0.0;
    foreach ($allCodes as $code) {
        $tag    = $tagByCode[$code] ?? 'untagged';
        $type   = $typeByCode[$code] ?? '';
        $name   = $nameByCode[$code] ?? $code;
        $start  = $startByCode[$code] ?? 0.0;
        $end    = $endByCode[$code]   ?? 0.0;
        $delta  = round($end - $start, 2);

        if ($tag === 'cash_and_equivalents') {
            $cashStartingFromGl += $start;
            $cashEndingFromGl   += $end;
            continue;
        }

        // Convert balance-change into cash-impact:
        //   asset increase      → use of cash → flip sign
        //   liability/equity ↑  → source of cash → keep sign
        $cashImpact = ($type === 'asset') ? -$delta : $delta;

        $bucket = 'untagged';
        if (str_starts_with($tag, 'operating')) $bucket = 'operating';
        elseif (str_starts_with($tag, 'investing')) $bucket = 'investing';
        elseif (str_starts_with($tag, 'financing')) $bucket = 'financing';

        if (abs($cashImpact) < 0.005) continue;
        $sections[$bucket]['lines'][] = [
            'code'        => $code,
            'name'        => $name,
            'cash_flow_tag' => $tag,
            'amount'      => round($cashImpact, 2),
        ];
        $sections[$bucket]['subtotal'] = round($sections[$bucket]['subtotal'] + $cashImpact, 2);
    }

    // Operating starts with net income
    array_unshift($sections['operating']['lines'], [
        'code' => null, 'name' => 'Net income', 'cash_flow_tag' => 'net_income',
        'amount' => round($netIncome, 2),
    ]);
    $sections['operating']['subtotal'] = round($sections['operating']['subtotal'] + $netIncome, 2);

    $netChange       = round($sections['operating']['subtotal'] + $sections['investing']['subtotal'] + $sections['financing']['subtotal'], 2);
    $cashGlChange    = round($cashEndingFromGl - $cashStartingFromGl, 2);
    $reconciliation  = round($cashGlChange - $netChange, 2);

    return [
        'period'               => ['from' => $from, 'to' => $to, 'entity_id' => $entityId],
        'net_income'           => round($netIncome, 2),
        'sections'             => $sections,
        'net_change_in_cash'   => $netChange,
        'cash_beginning'       => round($cashStartingFromGl, 2),
        'cash_ending'          => round($cashEndingFromGl, 2),
        'cash_change_from_gl'  => $cashGlChange,
        'reconciliation_diff'  => $reconciliation,    // should be 0.00
        'balanced'             => abs($reconciliation) < 0.01,
        'untagged_warning'     => count($sections['untagged']['lines']) > 0,
    ];
}
