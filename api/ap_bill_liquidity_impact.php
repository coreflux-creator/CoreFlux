<?php
/**
 * AP Bill Liquidity Impact (P0).
 *
 * Inline read for the AP BillDetail UI: "If you pay this bill on date X,
 * here's how the projected lowest balance and runway shift compared to
 * the baseline forecast."
 *
 *   GET /api/ap_bill_liquidity_impact.php?bill_id=N&pay_date=YYYY-MM-DD
 *
 * Response envelope:
 *   {
 *     bill_id, bill_amount, pay_date, days_horizon,
 *     baseline:  { lowest_balance, lowest_balance_date, runway_days_to_zero },
 *     simulated: { lowest_balance, lowest_balance_date, runway_days_to_zero },
 *     delta:     { lowest_balance_shift, lowest_date_shift_days, runway_days_lost,
 *                  crosses_zero }
 *   }
 *
 * Tenant-scoped. RBAC: `treasury.payment.view` (matches the underlying
 * forecast endpoint). pay_date defaults to today and is clamped to the
 * 90-day forecast window so we always return a meaningful comparison.
 *
 * Note: this endpoint does NOT post anything; it's purely a read-only
 * projection overlay. The actual payment is recorded through the existing
 * AP payments flow.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);
RBAC::requirePermission($user, 'treasury.payment.view');

$billId   = (int) (api_query('bill_id') ?? 0);
if ($billId <= 0) api_error('bill_id required', 422);

$days     = max(1, min(365, (int) (api_query('days') ?? 90)));
$today    = date('Y-m-d');
$endDate  = date('Y-m-d', strtotime("+{$days} days"));

$pdo = getDB();

// Bill — must belong to the tenant; we read amount_due so the simulated
// outflow always reflects what the operator would actually pay today.
$billStmt = $pdo->prepare(
    "SELECT id, amount_due, status, vendor_name
       FROM ap_bills
      WHERE tenant_id = :t AND id = :b LIMIT 1"
);
$billStmt->execute(['t' => $tid, 'b' => $billId]);
$bill = $billStmt->fetch(\PDO::FETCH_ASSOC);
if (!$bill) api_error('Bill not found', 404);

$billAmount = round((float) $bill['amount_due'], 2);
if ($billAmount <= 0) {
    // Nothing to project — bill already paid or zero balance.
    api_ok([
        'bill_id'      => $billId,
        'bill_amount'  => 0.0,
        'pay_date'     => $today,
        'days_horizon' => $days,
        'note'         => 'Bill has zero balance due — no liquidity impact.',
        'baseline'     => null,
        'simulated'    => null,
        'delta'        => null,
    ]);
}

// Pay date — defaults to today, clamped inside the forecast window so the
// projection is always comparable.
$payDate = (string) (api_query('pay_date') ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) $payDate = $today;
if ($payDate < $today)   $payDate = $today;
if ($payDate > $endDate) $payDate = $endDate;

// ──────────────────────────────────────────────────────────────────
// Pull the same datasets the baseline forecast uses, then run the
// projection twice: baseline (no extra outflow) and simulated
// (extra outflow on $payDate).
// ──────────────────────────────────────────────────────────────────
$cashStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
       FROM accounting_bank_accounts ba
       JOIN accounting_accounts a ON a.tenant_id = ba.tenant_id AND a.account_code = ba.gl_account_code
       JOIN accounting_journal_lines jl ON jl.account_id = a.id AND jl.tenant_id = a.tenant_id
       JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
      WHERE ba.tenant_id = :t AND ba.status = 'active' AND je.posting_date <= :d"
);
$cashStmt->execute(['t' => $tid, 'd' => $today]);
$startingCash = (float) $cashStmt->fetchColumn();

$arStmt = $pdo->prepare(
    "SELECT due_date, COALESCE(amount_due, total - amount_paid) AS due
       FROM billing_invoices
      WHERE tenant_id = :t AND status IN ('approved','sent','partially_paid')
        AND due_date BETWEEN :s AND :e
        AND COALESCE(amount_due, total - amount_paid) > 0"
);
$arStmt->execute(['t' => $tid, 's' => $today, 'e' => $endDate]);
$arRows = $arStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

$tpStmt = $pdo->prepare(
    "SELECT payment_date, amount, payee_name
       FROM treasury_payments
      WHERE tenant_id = :t
        AND status IN ('draft','pending_approval','approved','scheduled')
        AND payment_date BETWEEN :s AND :e"
);
$tpStmt->execute(['t' => $tid, 's' => $today, 'e' => $endDate]);
$tpRows = $tpStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

$tpKeys = [];
foreach ($tpRows as $r) {
    $tpKeys[strtolower((string) $r['payee_name']) . '|' . number_format((float) $r['amount'], 2, '.', '')] = true;
}

$apStmt = $pdo->prepare(
    "SELECT id, due_date, amount_due, vendor_name
       FROM ap_bills
      WHERE tenant_id = :t AND status IN ('approved','partially_paid','pending_approval')
        AND due_date BETWEEN :s AND :e AND amount_due > 0"
);
$apStmt->execute(['t' => $tid, 's' => $today, 'e' => $endDate]);
$apRows = [];
while ($r = $apStmt->fetch(\PDO::FETCH_ASSOC)) {
    $key = strtolower((string) $r['vendor_name']) . '|' . number_format((float) $r['amount_due'], 2, '.', '');
    if (isset($tpKeys[$key])) continue;
    $apRows[] = $r;
}

// Build the day-keyed in/out maps shared by both runs.
$inflowsByDate  = [];
$outflowsByDate = [];
foreach ($arRows as $r) {
    $d = (string) $r['due_date'];
    $inflowsByDate[$d] = ($inflowsByDate[$d] ?? 0.0) + (float) $r['due'];
}
foreach ($tpRows as $r) {
    $d = (string) $r['payment_date'];
    $outflowsByDate[$d] = ($outflowsByDate[$d] ?? 0.0) + (float) $r['amount'];
}
foreach ($apRows as $r) {
    // Skip the bill we're simulating — it's already in the baseline as a
    // future outflow on its own due_date. The simulation re-anchors it
    // on $payDate (which may or may not equal due_date).
    if ((int) $r['id'] === $billId) continue;
    $d = (string) $r['due_date'];
    $outflowsByDate[$d] = ($outflowsByDate[$d] ?? 0.0) + (float) $r['amount_due'];
}

/**
 * Walk the projection day-by-day starting from $startingCash. Returns
 * the lowest balance, the date it occurs on, and the days-to-zero crossing
 * (or null if balance never goes negative).
 *
 * @param array<string,float> $extraOutflowsByDate  optional sim overlay
 */
$project = function (array $extraOutflowsByDate = []) use ($startingCash, $days, $today, $inflowsByDate, $outflowsByDate) {
    $running    = $startingCash;
    $lowest     = $startingCash;
    $lowestDate = $today;
    $runwayDay  = null;
    for ($i = 0; $i <= $days; $i++) {
        $d = date('Y-m-d', strtotime("+{$i} days"));
        $inflows  = $inflowsByDate[$d]  ?? 0.0;
        $outflows = ($outflowsByDate[$d] ?? 0.0) + ($extraOutflowsByDate[$d] ?? 0.0);
        $running  = round($running + $inflows - $outflows, 2);
        if ($running < $lowest) {
            $lowest     = $running;
            $lowestDate = $d;
        }
        if ($runwayDay === null && $running < 0) {
            $runwayDay = $i;
        }
    }
    return [
        'lowest_balance'      => round($lowest, 2),
        'lowest_balance_date' => $lowestDate,
        'runway_days_to_zero' => $runwayDay,
    ];
};

$baseline  = $project();
$simulated = $project([$payDate => $billAmount]);

$lowestShift     = round($simulated['lowest_balance'] - $baseline['lowest_balance'], 2);
$baselineRunway  = $baseline['runway_days_to_zero'];
$simulatedRunway = $simulated['runway_days_to_zero'];

if ($baselineRunway === null && $simulatedRunway !== null) {
    $runwayLost   = $days - $simulatedRunway; // we GAINED a runway gap; expressed as "lost days"
    $crossesZero  = true;
} elseif ($baselineRunway !== null && $simulatedRunway !== null) {
    $runwayLost   = max(0, $baselineRunway - $simulatedRunway);
    $crossesZero  = true;
} elseif ($baselineRunway !== null && $simulatedRunway === null) {
    // Math sanity — adding outflow can never push runway later.
    $runwayLost   = 0;
    $crossesZero  = true;
} else {
    $runwayLost   = 0;
    $crossesZero  = false;
}

$lowestDateShiftDays = (int) round((strtotime($simulated['lowest_balance_date']) - strtotime($baseline['lowest_balance_date'])) / 86400);

api_ok([
    'bill_id'       => $billId,
    'bill_amount'   => $billAmount,
    'pay_date'      => $payDate,
    'days_horizon'  => $days,
    'baseline'      => $baseline,
    'simulated'     => $simulated,
    'delta'         => [
        'lowest_balance_shift'    => $lowestShift,
        'lowest_date_shift_days'  => $lowestDateShiftDays,
        'runway_days_lost'        => $runwayLost,
        'crosses_zero'            => $crossesZero,
    ],
]);
