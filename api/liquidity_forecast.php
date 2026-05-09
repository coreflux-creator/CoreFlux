<?php
/**
 * Liquidity Forecast (Sprint 7g+ / P2).
 *
 *   GET /api/liquidity_forecast.php
 *     ?days=90              (1..365, default 90)
 *     &entity_id=N          (optional)
 *
 *   Response: {
 *     window_days,
 *     starting_cash,
 *     daily: [{ date, opening, inflows, outflows, closing }],
 *     totals: { total_inflows, total_outflows, ending_cash, lowest_balance,
 *               lowest_balance_date, runway_days_to_zero | null },
 *     sources: {
 *       inflows: [{ source: 'invoice', count, amount }, ...],
 *       outflows: [{ source: 'treasury_payment'|'ap_bill', count, amount }, ...]
 *     },
 *     guards: { has_bank_accounts, has_open_ar, has_open_ap, has_scheduled_payments }
 *   }
 *
 * Tenant-scoped. RBAC: `treasury.payment.view` (matches the rest of treasury).
 *
 * Inflows model: open billing_invoices (status NOT IN paid/void/draft) due
 * within window — uses `amount_due` (= total - amount_paid).
 *
 * Outflows model: open ap_bills (status NOT IN paid/void) due within window
 * UNION treasury_payments scheduled in window with status in
 * (approved, scheduled, pending_approval, draft) — DEDUPED so the same
 * obligation isn't counted twice when an AP bill has been wrapped in a
 * treasury payment (we trust treasury_payments and exclude any AP bills
 * referenced by an active payment via accounting_event_id heuristic — best
 * effort; spec doesn't promise foreign keys).
 *
 * Starting cash: sum of latest balance across `accounting_bank_accounts`
 * with status='active'. Falls back to GL balance of the linked
 * gl_account_code when reconciliation balance isn't present.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);
RBAC::requirePermission($user, 'treasury.payment.view');

$days     = max(1, min(365, (int) (api_query('days') ?? 90)));
$entityId = (int) (api_query('entity_id') ?? 0);
$today    = date('Y-m-d');
$endDate  = date('Y-m-d', strtotime("+{$days} days"));

$pdo = getDB();

// ──────────────────────────────────────────────────────────────────
// Starting cash — sum GL balance of cash accounts (the bank-mapped
// asset accounts). Cleaner than chasing reconciliation snapshots.
// ──────────────────────────────────────────────────────────────────
$cashStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
       FROM accounting_bank_accounts ba
       JOIN accounting_accounts a ON a.tenant_id = ba.tenant_id AND a.account_code = ba.gl_account_code
       JOIN accounting_journal_lines jl ON jl.account_id = a.id AND jl.tenant_id = a.tenant_id
       JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
      WHERE ba.tenant_id = :t
        AND ba.status    = 'active'
        AND je.posting_date <= :d"
   . ($entityId ? ' AND ba.entity_id = :e' : '')
);
$bind = ['t' => $tid, 'd' => $today];
if ($entityId) $bind['e'] = $entityId;
$cashStmt->execute($bind);
$startingCash = (float) $cashStmt->fetchColumn();

$bankCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM accounting_bank_accounts
      WHERE tenant_id = {$tid} AND status = 'active'"
)->fetchColumn();

// ──────────────────────────────────────────────────────────────────
// Open AR (inflows) — billing_invoices.amount_due > 0, status open, due
// within window.
// ──────────────────────────────────────────────────────────────────
$arStmt = $pdo->prepare(
    "SELECT due_date, COALESCE(amount_due, total - amount_paid) AS due
       FROM billing_invoices
      WHERE tenant_id = :t
        AND status IN ('approved','sent','partially_paid')
        AND due_date BETWEEN :s AND :e
        AND COALESCE(amount_due, total - amount_paid) > 0"
);
$arStmt->execute(['t' => $tid, 's' => $today, 'e' => $endDate]);
$arRows = $arStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

// ──────────────────────────────────────────────────────────────────
// Outflows — treasury_payments first (preferred, more accurate),
// then AP bills due in window NOT already covered by a scheduled
// treasury payment for the same vendor + amount.
// ──────────────────────────────────────────────────────────────────
$tpStmt = $pdo->prepare(
    "SELECT payment_date, amount, payee_name, payee_id, payee_type, status
       FROM treasury_payments
      WHERE tenant_id = :t
        AND status IN ('draft','pending_approval','approved','scheduled')
        AND payment_date BETWEEN :s AND :e"
   . ($entityId ? ' AND entity_id = :ent' : '')
);
$bind = ['t' => $tid, 's' => $today, 'e' => $endDate];
if ($entityId) $bind['ent'] = $entityId;
$tpStmt->execute($bind);
$tpRows = $tpStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

// AP bills NOT already scheduled in treasury (heuristic dedup by vendor + amount).
$tpKeys = [];
foreach ($tpRows as $r) {
    $tpKeys[strtolower((string) $r['payee_name']) . '|' . number_format((float) $r['amount'], 2, '.', '')] = true;
}

$apStmt = $pdo->prepare(
    "SELECT due_date, amount_due, vendor_name
       FROM ap_bills
      WHERE tenant_id = :t
        AND status IN ('approved','partially_paid','pending_approval')
        AND due_date BETWEEN :s AND :e
        AND amount_due > 0"
);
$apStmt->execute(['t' => $tid, 's' => $today, 'e' => $endDate]);
$apRows = [];
while ($r = $apStmt->fetch(\PDO::FETCH_ASSOC)) {
    $key = strtolower((string) $r['vendor_name']) . '|' . number_format((float) $r['amount_due'], 2, '.', '');
    if (isset($tpKeys[$key])) continue; // already counted via treasury_payment
    $apRows[] = $r;
}

// ──────────────────────────────────────────────────────────────────
// Build day-by-day projection.
// ──────────────────────────────────────────────────────────────────
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
    $d = (string) $r['due_date'];
    $outflowsByDate[$d] = ($outflowsByDate[$d] ?? 0.0) + (float) $r['amount_due'];
}

$daily = [];
$running = $startingCash;
$lowest  = $startingCash;
$lowestDate = $today;
$runwayDay  = null;
for ($i = 0; $i <= $days; $i++) {
    $d = date('Y-m-d', strtotime("+{$i} days"));
    $opening   = $running;
    $inflows   = round($inflowsByDate[$d] ?? 0.0, 2);
    $outflows  = round($outflowsByDate[$d] ?? 0.0, 2);
    $closing   = round($opening + $inflows - $outflows, 2);
    $running   = $closing;
    if ($closing < $lowest) {
        $lowest      = $closing;
        $lowestDate  = $d;
    }
    if ($runwayDay === null && $closing < 0) {
        $runwayDay = $i;
    }
    $daily[] = [
        'date'     => $d,
        'opening'  => round($opening, 2),
        'inflows'  => $inflows,
        'outflows' => $outflows,
        'closing'  => $closing,
    ];
}

$totalInflows  = array_sum(array_column($daily, 'inflows'));
$totalOutflows = array_sum(array_column($daily, 'outflows'));

api_ok([
    'window_days'    => $days,
    'starting_cash'  => round($startingCash, 2),
    'daily'          => $daily,
    'totals'         => [
        'total_inflows'        => round($totalInflows, 2),
        'total_outflows'       => round($totalOutflows, 2),
        'ending_cash'          => $daily ? end($daily)['closing'] : round($startingCash, 2),
        'lowest_balance'       => round($lowest, 2),
        'lowest_balance_date'  => $lowestDate,
        'runway_days_to_zero'  => $runwayDay,
    ],
    'sources'        => [
        'inflows'  => [['source' => 'invoice',          'count' => count($arRows), 'amount' => round($totalInflows, 2)]],
        'outflows' => [
            ['source' => 'treasury_payment', 'count' => count($tpRows), 'amount' => round(array_sum(array_column($tpRows, 'amount')), 2)],
            ['source' => 'ap_bill',          'count' => count($apRows), 'amount' => round(array_sum(array_column($apRows, 'amount_due')), 2)],
        ],
    ],
    'guards'         => [
        'has_bank_accounts'        => $bankCount > 0,
        'has_open_ar'              => count($arRows) > 0,
        'has_open_ap'              => count($apRows) > 0,
        'has_scheduled_payments'   => count($tpRows) > 0,
    ],
]);
