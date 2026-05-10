<?php
/**
 * Standard Reports library — Income Statement, Balance Sheet, Cash Flow.
 *
 * Extracted from `modules/accounting/api/reports.php` so both the per-tenant
 * endpoint AND the cross-tenant consolidated endpoint
 * (`api/sub_tenant_consolidated_reports.php`) can call the same builders
 * without duplicating the SQL or the sign-convention logic.
 *
 * No top-level side effects — pure function declarations.
 */
declare(strict_types=1);

require_once __DIR__ . '/accounting.php';
require_once __DIR__ . '/consolidation.php';

/**
 * Income Statement (P&L) — revenue and expenses for the period.
 * Reuses trial-balance arithmetic but filters to revenue/expense and
 * aggregates by account_type. Posted JEs only.
 */
function reportIncomeStatement(int $tenantId, string $from, string $to, ?int $entityId): array
{
    $pdo = getDB();
    $where  = ['je.tenant_id = :t_je', 'je.status = "posted"', 'je.posting_date >= :f', 'je.posting_date <= :tx'];
    $params = ['t_a' => $tenantId, 't_je' => $tenantId, 'f' => $from, 'tx' => $to];
    if ($entityId) { $where[] = 'je.entity_id = :e'; $params['e'] = $entityId; }

    $stmt = $pdo->prepare(
        'SELECT a.code, a.name, a.account_type, a.normal_side,
                COALESCE(SUM(l.debit),0)  AS debit,
                COALESCE(SUM(l.credit),0) AS credit
         FROM accounting_accounts a
         LEFT JOIN accounting_journal_entry_lines l ON l.account_id = a.id
         LEFT JOIN accounting_journal_entries je ON je.id = l.je_id
         WHERE a.tenant_id = :t_a AND a.account_type IN ("revenue","expense")
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
    $where  = ['je.tenant_id = :t_je', 'je.status = "posted"', 'je.posting_date <= :d'];
    $params = ['t_a' => $tenantId, 't_je' => $tenantId, 'd' => $asOf];
    if ($entityId) { $where[] = 'je.entity_id = :e'; $params['e'] = $entityId; }

    $stmt = $pdo->prepare(
        'SELECT a.code, a.name, a.account_type, a.normal_side,
                COALESCE(SUM(l.debit),0)  AS debit,
                COALESCE(SUM(l.credit),0) AS credit
         FROM accounting_accounts a
         LEFT JOIN accounting_journal_entry_lines l ON l.account_id = a.id
         LEFT JOIN accounting_journal_entries je ON je.id = l.je_id
         WHERE a.tenant_id = :t_a
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
