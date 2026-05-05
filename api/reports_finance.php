<?php
/**
 * /api/reports_finance.php — finance drill page payload.
 *
 * Returns:
 *   1. pnl              — P&L summary (Revenue, Direct Cost, Gross Margin,
 *                         Indirect Costs, Net Income) with optional prior-period.
 *   2. cash_flow        — Beginning cash → Receipts → Operating → Payroll →
 *                         Ending cash, with weekly trendline.
 *   3. ar_detail        — Outstanding invoices (one row per invoice).
 *   4. ap_detail        — Outstanding bills (one row per bill).
 *
 *   GET /api/reports_finance.php?from=...&to=...&client_id=...&compare=prior_year
 *
 * Reuses the same date-range / filter contract as /api/exec_dashboard.php
 * so the React side can pass the same query string.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';

$ctx       = api_require_auth();
$user      = $ctx['user'];
$role      = $ctx['role'] ?? 'employee';
$tenantId  = (int) (currentTenantId() ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

if (!in_array($role, ['master_admin', 'tenant_admin', 'admin', 'manager'], true)) {
    api_error('Forbidden — reports require manager+', 403);
}

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

/* ---------- date range ---------- */
$today = new DateTimeImmutable('today');
$rawFrom = (string) api_query('from', '');
$rawTo   = (string) api_query('to',   '');
$weeks   = max(1, min(208, (int) api_query('weeks', 12)));

if ($rawFrom !== '' && $rawTo !== '') {
    try {
        $from = (new DateTimeImmutable($rawFrom));
        $to   = (new DateTimeImmutable($rawTo));
        if ($from > $to) [$from, $to] = [$to, $from];
    } catch (Throwable $_) {
        $from = $today->modify("-{$weeks} weeks");
        $to   = $today;
    }
} else {
    $from = $today->modify("-{$weeks} weeks");
    $to   = $today;
}

$compareEnabled = ((string) api_query('compare', '')) === 'prior_year';
$prevFrom = $from->modify('-1 year');
$prevTo   = $to->modify('-1 year');

/* ---------- helpers ---------- */
function _rfFetch(PDO $pdo, string $sql, array $p): array {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($p); return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { error_log('reports_finance: ' . $e->getMessage()); return []; }
}
function _rfSum(array $rows, string $key): float {
    $t = 0.0; foreach ($rows as $r) $t += (float) ($r[$key] ?? 0); return round($t, 2);
}

/* =========================================================================
 *  1. P&L Summary
 * ========================================================================= */
function _rfPnlForRange(PDO $pdo, int $tenantId, DateTimeImmutable $from, DateTimeImmutable $to): array {
    $params = ['t' => $tenantId, 'a' => $from->format('Y-m-d'), 'b' => $to->format('Y-m-d')];

    // Revenue: invoices in the period (any status counted as recognised sales).
    $rev = _rfFetch($pdo,
        "SELECT COALESCE(SUM(total),0) AS v FROM billing_invoices
          WHERE tenant_id = :t AND status IN ('sent','partially_paid','paid')
            AND issue_date BETWEEN :a AND :b",
        $params);
    $revenue = (float) ($rev[0]['v'] ?? 0);

    // Direct cost: pay_rate * billable hours via placement_rates.
    $dc = _rfFetch($pdo,
        "SELECT COALESCE(SUM(te.hours *
            (SELECT pr.pay_rate FROM placement_rates pr
              WHERE pr.placement_id = te.placement_id
                AND pr.approved_at IS NOT NULL
                AND pr.effective_from <= te.work_date
                AND (pr.effective_to IS NULL OR pr.effective_to >= te.work_date)
              ORDER BY pr.effective_from DESC LIMIT 1)),0) AS v
           FROM time_entries te
          WHERE te.tenant_id = :t AND te.status = 'approved'
            AND te.category IN ('regular_billable','OT_billable')
            AND te.work_date BETWEEN :a AND :b",
        $params);
    $directCost = (float) ($dc[0]['v'] ?? 0);

    // Indirect cost approximation: AP bills (non-payroll) + admin/back-office payroll.
    $ind = _rfFetch($pdo,
        "SELECT COALESCE(SUM(total),0) AS v FROM ap_bills
          WHERE tenant_id = :t AND status IN ('approved','partially_paid','paid')
            AND issue_date BETWEEN :a AND :b",
        $params);
    $indirect = (float) ($ind[0]['v'] ?? 0);

    $grossMargin = $revenue - $directCost;
    $netIncome   = $grossMargin - $indirect;

    return [
        'revenue'      => round($revenue,     2),
        'direct_cost'  => round($directCost,  2),
        'gross_margin' => round($grossMargin, 2),
        'gross_pct'    => $revenue > 0 ? round($grossMargin / $revenue * 100, 1) : 0,
        'indirect'     => round($indirect,    2),
        'net_income'   => round($netIncome,   2),
        'net_pct'      => $revenue > 0 ? round($netIncome / $revenue * 100, 1) : 0,
    ];
}
$pnl = _rfPnlForRange($pdo, $tenantId, $from, $to);
if ($compareEnabled) {
    $pnl['prev_period'] = _rfPnlForRange($pdo, $tenantId, $prevFrom, $prevTo);
}

/* =========================================================================
 *  2. Cash Flow waterfall + weekly trend
 * ========================================================================= */
$cashFlow = ['beginning' => 0.0, 'receipts' => 0.0, 'operating' => 0.0, 'payroll' => 0.0, 'ending' => 0.0, 'trend' => []];

$beginRow = _rfFetch($pdo,
    "SELECT COALESCE(SUM(current_balance_cents),0) AS v FROM plaid_accounts
      WHERE tenant_id = :t AND deleted_at IS NULL",
    ['t' => $tenantId]);
$cashFlow['beginning'] = round(((float) ($beginRow[0]['v'] ?? 0)) / 100, 2);

// Receipts (billing_payments in period)
$rec = _rfFetch($pdo,
    "SELECT COALESCE(SUM(amount),0) AS v FROM billing_payments
      WHERE tenant_id = :t AND received_date BETWEEN :a AND :b",
    ['t' => $tenantId, 'a' => $from->format('Y-m-d'), 'b' => $to->format('Y-m-d')]);
$cashFlow['receipts'] = round((float) ($rec[0]['v'] ?? 0), 2);

// Operating outflows (AP payments in period)
$opRows = _rfFetch($pdo,
    "SELECT COALESCE(SUM(amount),0) AS v FROM ap_payments
      WHERE tenant_id = :t AND payment_date BETWEEN :a AND :b",
    ['t' => $tenantId, 'a' => $from->format('Y-m-d'), 'b' => $to->format('Y-m-d')]);
$cashFlow['operating'] = round((float) ($opRows[0]['v'] ?? 0), 2);

// Payroll outflows
$pyRows = _rfFetch($pdo,
    "SELECT COALESCE(SUM(total_gross_cents),0) AS v FROM payroll_runs
      WHERE tenant_id = :t AND status IN ('approved','paid')
        AND created_at BETWEEN :a AND :b",
    ['t' => $tenantId, 'a' => $from->format('Y-m-d') . ' 00:00:00', 'b' => $to->format('Y-m-d') . ' 23:59:59']);
$cashFlow['payroll'] = round(((float) ($pyRows[0]['v'] ?? 0)) / 100, 2);

$cashFlow['ending'] = round(
    $cashFlow['beginning'] + $cashFlow['receipts'] - $cashFlow['operating'] - $cashFlow['payroll'], 2
);

// Weekly net cash flow trend
$weekly = _rfFetch($pdo,
    "SELECT DATE_FORMAT(received_date, '%Y-%u') AS wk,
            DATE(DATE_SUB(received_date, INTERVAL WEEKDAY(received_date) DAY)) AS week_start,
            SUM(amount) AS net
       FROM billing_payments
      WHERE tenant_id = :t AND received_date BETWEEN :a AND :b
   GROUP BY wk, week_start
   ORDER BY week_start",
    ['t' => $tenantId, 'a' => $from->format('Y-m-d'), 'b' => $to->format('Y-m-d')]);
$cashFlow['trend'] = array_map(
    fn($r) => ['week' => $r['week_start'], 'amount' => (float) $r['net']],
    $weekly
);

/* =========================================================================
 *  3. AR Detail (one row per outstanding invoice)
 * ========================================================================= */
$arRows = _rfFetch($pdo,
    "SELECT i.id, i.invoice_number, i.client_name, i.issue_date, i.due_date,
            i.total, i.amount_paid, i.status,
            DATEDIFF(:today, i.due_date) AS days_overdue
       FROM billing_invoices i
      WHERE i.tenant_id = :t AND i.status IN ('sent','partially_paid')
   ORDER BY DATEDIFF(:today, i.due_date) DESC, i.total DESC
      LIMIT 500",
    ['t' => $tenantId, 'today' => $today->format('Y-m-d')]);
foreach ($arRows as &$r) {
    $r['outstanding'] = round((float) $r['total'] - (float) $r['amount_paid'], 2);
    $r['total']       = (float) $r['total'];
    $r['amount_paid'] = (float) $r['amount_paid'];
    $r['days_overdue']= (int)   $r['days_overdue'];
}
unset($r);

/* =========================================================================
 *  4. AP Detail (one row per outstanding bill)
 * ========================================================================= */
$apRows = _rfFetch($pdo,
    "SELECT b.id, b.bill_number, b.vendor_name, b.issue_date, b.due_date,
            b.total, b.amount_paid, b.status,
            DATEDIFF(:today, b.due_date) AS days_overdue
       FROM ap_bills b
      WHERE b.tenant_id = :t AND b.status IN ('approved','partially_paid')
   ORDER BY DATEDIFF(:today, b.due_date) DESC, b.total DESC
      LIMIT 500",
    ['t' => $tenantId, 'today' => $today->format('Y-m-d')]);
foreach ($apRows as &$r) {
    $r['outstanding'] = round((float) $r['total'] - (float) $r['amount_paid'], 2);
    $r['total']       = (float) $r['total'];
    $r['amount_paid'] = (float) $r['amount_paid'];
    $r['days_overdue']= (int)   $r['days_overdue'];
}
unset($r);

api_ok([
    'range'     => [
        'from'  => $from->format('Y-m-d'),
        'to'    => $to->format('Y-m-d'),
    ],
    'compare'   => $compareEnabled ? [
        'mode'      => 'prior_year',
        'prev_from' => $prevFrom->format('Y-m-d'),
        'prev_to'   => $prevTo->format('Y-m-d'),
    ] : null,
    'pnl'       => $pnl,
    'cash_flow' => $cashFlow,
    'ar_detail' => $arRows,
    'ap_detail' => $apRows,
]);
