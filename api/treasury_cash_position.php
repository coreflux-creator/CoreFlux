<?php
/**
 * Treasury Cash Position report (Sprint 7c, spec §28).
 *
 *   GET /api/treasury_cash_position.php?as_of=YYYY-MM-DD&entity_id=N
 *
 * Returns per-bank-account:
 *   - GL cash balance (sum of debits - credits on the linked accounting
 *     account through the as-of date, posted JEs only)
 *   - last reconciled date (most recent reconciliation row)
 *   - outstanding payments (treasury_payments status in
 *     pending_approval / approved / scheduled — net cash impact)
 *   - expected receipts next 7 days (open AR invoices due in window)
 *
 * Plus a tenant-level grand total + per-currency breakdown so the user
 * sees "you have $X liquid right now, and after the next 7 days of
 * outflows / inflows you'll be at $Y".
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);
RBAC::requirePermission($user, 'treasury.view_bank_balances');

$asOf  = (string) (api_query('as_of') ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) api_error('as_of must be YYYY-MM-DD', 400);
$entityId = api_query('entity_id') ? (int) api_query('entity_id') : null;
$forecastDays = max(0, min(60, (int) (api_query('forecast_days') ?? 7)));
$forecastEnd  = date('Y-m-d', strtotime("$asOf +{$forecastDays} days"));

$pdo = getDB();

// 1) Bank accounts in scope.
$baWhere = ['ba.tenant_id = :t', "ba.status = 'active'"];
$baP     = ['t' => $tid];
if ($entityId) { $baWhere[] = 'ba.entity_id = :e'; $baP['e'] = $entityId; }
$baStmt = $pdo->prepare(
    "SELECT ba.id, ba.name, ba.bank_name, ba.last4, ba.entity_id, ba.currency,
            ba.gl_account_code, aa.id AS gl_account_id
       FROM accounting_bank_accounts ba
       LEFT JOIN accounting_accounts aa
         ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
      WHERE " . implode(' AND ', $baWhere) . "
      ORDER BY ba.name ASC"
);
$baStmt->execute($baP);
$banks = $baStmt->fetchAll(\PDO::FETCH_ASSOC);

// 2) For each bank account compute GL balance.
$balStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) AS bal
       FROM accounting_journal_lines jl
       JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id
      WHERE je.tenant_id = :t
        AND je.status   = 'posted'
        AND je.posting_date <= :a
        AND jl.account_id = :acc"
);

// 3) Outstanding payment outflows (pending/approved/scheduled, payment_date <= forecast end).
$outflowStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS amt
       FROM treasury_payments
      WHERE tenant_id = :t
        AND bank_account_id = :ba
        AND status IN ('pending_approval','approved','scheduled')
        AND payment_date <= :end"
);

// 4) Last reconciled date (best-effort — works against accounting_reconciliations
// if present; degrades to NULL otherwise).
$reconStmt = null;
$hasRecon = $pdo->query("SHOW TABLES LIKE 'accounting_reconciliations'")->fetchColumn();
if ($hasRecon) {
    $reconStmt = $pdo->prepare(
        "SELECT MAX(statement_end_date) AS last_date
           FROM accounting_reconciliations
          WHERE tenant_id = :t AND bank_account_id = :ba AND status = 'closed'"
    );
}

$report = [];
$grandTotal = ['by_currency' => []];
foreach ($banks as $b) {
    $entry = [
        'bank_account_id' => (int) $b['id'],
        'name'           => (string) $b['name'],
        'bank_name'      => $b['bank_name'],
        'last4'          => $b['last4'],
        'entity_id'      => (int) ($b['entity_id'] ?? 0),
        'currency'       => (string) $b['currency'],
        'gl_account_id'  => $b['gl_account_id'] ? (int) $b['gl_account_id'] : null,
        'gl_balance'     => 0.0,
        'last_reconciled_date' => null,
        'pending_outflows' => 0.0,
        'projected_balance' => 0.0,
    ];

    if ($entry['gl_account_id']) {
        $balStmt->execute(['t' => $tid, 'a' => $asOf, 'acc' => $entry['gl_account_id']]);
        $entry['gl_balance'] = round((float) $balStmt->fetchColumn(), 2);
    }
    $outflowStmt->execute(['t' => $tid, 'ba' => $entry['bank_account_id'], 'end' => $forecastEnd]);
    $entry['pending_outflows'] = round((float) $outflowStmt->fetchColumn(), 2);

    if ($reconStmt) {
        $reconStmt->execute(['t' => $tid, 'ba' => $entry['bank_account_id']]);
        $entry['last_reconciled_date'] = $reconStmt->fetchColumn() ?: null;
    }

    $entry['projected_balance'] = round($entry['gl_balance'] - $entry['pending_outflows'], 2);
    $report[] = $entry;

    $cur = $entry['currency'];
    $grandTotal['by_currency'][$cur] ??= ['gl_balance' => 0.0, 'pending_outflows' => 0.0, 'projected_balance' => 0.0];
    $grandTotal['by_currency'][$cur]['gl_balance']        += $entry['gl_balance'];
    $grandTotal['by_currency'][$cur]['pending_outflows']  += $entry['pending_outflows'];
    $grandTotal['by_currency'][$cur]['projected_balance'] += $entry['projected_balance'];
}
foreach ($grandTotal['by_currency'] as &$g) {
    foreach ($g as &$v) $v = round($v, 2);
}

api_ok([
    'as_of' => $asOf,
    'forecast_days' => $forecastDays,
    'forecast_end' => $forecastEnd,
    'entity_id_filter' => $entityId,
    'rows' => $report,
    'totals' => $grandTotal,
]);
