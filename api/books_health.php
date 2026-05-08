<?php
/**
 * Books Health endpoint (Sprint 7e.1, Layer-parity).
 *
 *   GET /api/books_health.php?entity_id=N
 *
 * Returns the full "BookkeepingOverview" payload in one call so the
 * dashboard can render without a chatty waterfall:
 *
 *   {
 *     entity_id, as_of, fiscal_period, period_status,
 *     bank_connections: { total, active, last_sync_at },
 *     reconciliation:   { last_reconciled_date, days_since, behind_30d, behind_60d },
 *     uncategorized:    { bank_tx_count, oldest_days, ai_assist_available },
 *     tasks:            { transactions_to_review, bills_pending, payments_pending,
 *                         transfers_pending, period_ready_to_close },
 *     pl_monthly:       [ {month, revenue, expense, net}, ...6 ],
 *     recent_events:    [ {event_type, je_id, posted_at, source_record_id}, ...10 ],
 *     health_score:     0..100,
 *     health_label:     'excellent'|'good'|'fair'|'needs_attention',
 *   }
 *
 * Health score formula (transparent):
 *   100 base
 *   −20 if no active bank connections
 *   −15 if last reconciliation > 60 days
 *   − 8 if last reconciliation > 30 days
 *   −10 if uncategorized bank txs > 50
 *   − 5 if uncategorized bank txs > 10
 *   −10 if current period not yet soft-closed and the previous month is
 *       fully past
 *   − 5 if any task count > 0 (mild penalty so 100 is hard to game)
 *   floor at 0
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tid = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);
RBAC::requirePermission($user, 'accounting.coa.view');

$entityId = api_query('entity_id') ? (int) api_query('entity_id') : null;
$pdo = getDB();
$asOf = date('Y-m-d');

// ──────────────────────────────────────────────────────────────────
// Bank connections
// ──────────────────────────────────────────────────────────────────
$baWhere = ['ba.tenant_id = :t']; $baP = ['t' => $tid];
if ($entityId) { $baWhere[] = 'ba.entity_id = :e'; $baP['e'] = $entityId; }
$baStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
            MAX(updated_at) AS last_sync_at
       FROM accounting_bank_accounts ba
      WHERE " . implode(' AND ', $baWhere)
);
$baStmt->execute($baP);
$ba = $baStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
$bankConn = [
    'total'        => (int) ($ba['total'] ?? 0),
    'active'       => (int) ($ba['active'] ?? 0),
    'last_sync_at' => $ba['last_sync_at'] ?: null,
];

// ──────────────────────────────────────────────────────────────────
// Last reconciliation
// ──────────────────────────────────────────────────────────────────
$lastRecon = null; $daysSince = null;
if ($pdo->query("SHOW TABLES LIKE 'accounting_reconciliations'")->fetchColumn()) {
    $rStmt = $pdo->prepare(
        "SELECT MAX(statement_end_date) AS d
           FROM accounting_reconciliations
          WHERE tenant_id = :t AND status = 'closed'"
    );
    $rStmt->execute(['t' => $tid]);
    $lastRecon = $rStmt->fetchColumn() ?: null;
    if ($lastRecon) {
        $daysSince = (int) ((strtotime($asOf) - strtotime((string) $lastRecon)) / 86400);
    }
}
$recon = [
    'last_reconciled_date' => $lastRecon,
    'days_since'           => $daysSince,
    'behind_30d'           => $daysSince !== null && $daysSince > 30,
    'behind_60d'           => $daysSince !== null && $daysSince > 60,
];

// ──────────────────────────────────────────────────────────────────
// Uncategorized bank txs
// ──────────────────────────────────────────────────────────────────
$uncatStmt = $pdo->prepare(
    "SELECT COUNT(*) AS cnt,
            MIN(posted_date) AS oldest
       FROM accounting_bank_statement_lines
      WHERE tenant_id = :t
        AND (match_status IS NULL OR match_status = 'pending')"
);
$uncatStmt->execute(['t' => $tid]);
$un = $uncatStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
$oldestDays = (!empty($un['oldest']))
    ? (int) ((strtotime($asOf) - strtotime((string) $un['oldest'])) / 86400)
    : null;
$uncat = [
    'bank_tx_count'       => (int) ($un['cnt'] ?? 0),
    'oldest_days'         => $oldestDays,
    'ai_assist_available' => true, // bank_ai.php is always present
];

// ──────────────────────────────────────────────────────────────────
// Tasks (counts only; UI links to the right page)
// ──────────────────────────────────────────────────────────────────
$tasks = [
    'transactions_to_review' => $uncat['bank_tx_count'],
    'bills_pending'          => 0,
    'payments_pending'       => 0,
    'transfers_pending'      => 0,
    'period_ready_to_close'  => 0,
];
$bills = $pdo->query("SHOW TABLES LIKE 'ap_bills'")->fetchColumn();
if ($bills) {
    $bStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM ap_bills
          WHERE tenant_id = :t AND status IN ('open','pending_approval','approved','partial')"
    );
    $bStmt->execute(['t' => $tid]);
    $tasks['bills_pending'] = (int) $bStmt->fetchColumn();
}
$tp = $pdo->query("SHOW TABLES LIKE 'treasury_payments'")->fetchColumn();
if ($tp) {
    $tStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM treasury_payments
          WHERE tenant_id = :t AND status IN ('draft','pending_approval','approved','scheduled')"
    );
    $tStmt->execute(['t' => $tid]);
    $tasks['payments_pending'] = (int) $tStmt->fetchColumn();
}
$tt = $pdo->query("SHOW TABLES LIKE 'treasury_transfers'")->fetchColumn();
if ($tt) {
    $tStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM treasury_transfers
          WHERE tenant_id = :t AND status IN ('draft','pending_approval','approved','scheduled')"
    );
    $tStmt->execute(['t' => $tid]);
    $tasks['transfers_pending'] = (int) $tStmt->fetchColumn();
}

// Period readiness — open period whose end_date is past
$periodStmt = $pdo->prepare(
    "SELECT id, period_number, fiscal_year, start_date, end_date, status
       FROM accounting_periods
      WHERE tenant_id = :t" . ($entityId ? ' AND entity_id = :e' : '') . "
        AND start_date <= :d
        AND end_date   >= :d
      ORDER BY start_date DESC LIMIT 1"
);
$pp = ['t' => $tid, 'd' => $asOf]; if ($entityId) $pp['e'] = $entityId;
$periodStmt->execute($pp);
$currentPeriod = $periodStmt->fetch(\PDO::FETCH_ASSOC) ?: null;

$readyStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM accounting_periods
      WHERE tenant_id = :t" . ($entityId ? ' AND entity_id = :e' : '') . "
        AND status = 'open' AND end_date < :d"
);
$readyStmt->execute($pp);
$tasks['period_ready_to_close'] = (int) $readyStmt->fetchColumn();

// ──────────────────────────────────────────────────────────────────
// 6-month P&L (revenue / expense / net per month)
// ──────────────────────────────────────────────────────────────────
$plStart = date('Y-m-01', strtotime('-5 months'));
$plStmt = $pdo->prepare(
    "SELECT DATE_FORMAT(je.posting_date, '%Y-%m') AS month,
            a.account_type,
            SUM(jl.credit - jl.debit) AS net
       FROM accounting_journal_lines jl
       JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id
       JOIN accounting_accounts a ON a.id = jl.account_id
      WHERE je.tenant_id = :t
        AND je.status = 'posted'
        AND je.posting_date >= :s
        AND a.account_type IN ('revenue','expense','contra_revenue','cost_of_goods_sold','other_income','other_expense')
      GROUP BY month, a.account_type
      ORDER BY month ASC"
);
$plStmt->execute(['t' => $tid, 's' => $plStart]);
$plRows = $plStmt->fetchAll(\PDO::FETCH_ASSOC);
$plByMonth = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-{$i} months"));
    $plByMonth[$m] = ['month' => $m, 'revenue' => 0.0, 'expense' => 0.0, 'net' => 0.0];
}
foreach ($plRows as $r) {
    $m = $r['month'];
    if (!isset($plByMonth[$m])) continue;
    $net = (float) $r['net'];
    if (in_array($r['account_type'], ['revenue', 'other_income'], true)) {
        $plByMonth[$m]['revenue'] += $net;
    } else {
        // expense / cogs / contra_revenue / other_expense
        $plByMonth[$m]['expense'] += -$net;  // expense is debit-natural
    }
}
foreach ($plByMonth as &$row) {
    $row['revenue'] = round($row['revenue'], 2);
    $row['expense'] = round($row['expense'], 2);
    $row['net']     = round($row['revenue'] - $row['expense'], 2);
}
unset($row);

// ──────────────────────────────────────────────────────────────────
// Recent engine activity (10 most recent posted events) — only if 7b table exists
// ──────────────────────────────────────────────────────────────────
$recent = [];
if ($pdo->query("SHOW TABLES LIKE 'accounting_events'")->fetchColumn()) {
    $rStmt = $pdo->prepare(
        "SELECT id, event_type, journal_entry_id, source_module, source_record_id, posted_at
           FROM accounting_events
          WHERE tenant_id = :t AND status = 'posted'
          ORDER BY posted_at DESC, id DESC
          LIMIT 10"
    );
    $rStmt->execute(['t' => $tid]);
    $recent = $rStmt->fetchAll(\PDO::FETCH_ASSOC);
}

// ──────────────────────────────────────────────────────────────────
// Health score
// ──────────────────────────────────────────────────────────────────
$score = 100;
$reasons = [];
if ($bankConn['active'] === 0)             { $score -= 20; $reasons[] = 'no_active_bank'; }
if ($recon['behind_60d'])                  { $score -= 15; $reasons[] = 'recon_behind_60d'; }
elseif ($recon['behind_30d'])              { $score -= 8;  $reasons[] = 'recon_behind_30d'; }
if ($uncat['bank_tx_count'] > 50)          { $score -= 10; $reasons[] = 'many_uncategorized'; }
elseif ($uncat['bank_tx_count'] > 10)      { $score -= 5;  $reasons[] = 'some_uncategorized'; }
if ($tasks['period_ready_to_close'] > 0)   { $score -= 10; $reasons[] = 'period_overdue_close'; }
$totalTasks = array_sum($tasks);
if ($totalTasks > 0)                       { $score -= 5;  $reasons[] = 'has_open_tasks'; }
$score = max(0, $score);
$label = $score >= 90 ? 'excellent' : ($score >= 75 ? 'good' : ($score >= 50 ? 'fair' : 'needs_attention'));

api_ok([
    'entity_id'        => $entityId,
    'as_of'            => $asOf,
    'fiscal_period'    => $currentPeriod ? [
        'id'            => (int) $currentPeriod['id'],
        'period_number' => (int) $currentPeriod['period_number'],
        'fiscal_year'   => (int) $currentPeriod['fiscal_year'],
        'start_date'    => $currentPeriod['start_date'],
        'end_date'      => $currentPeriod['end_date'],
    ] : null,
    'period_status'    => $currentPeriod['status'] ?? null,
    'bank_connections' => $bankConn,
    'reconciliation'   => $recon,
    'uncategorized'    => $uncat,
    'tasks'            => $tasks,
    'pl_monthly'       => array_values($plByMonth),
    'recent_events'    => $recent,
    'health_score'     => $score,
    'health_label'     => $label,
    'health_reasons'   => $reasons,
]);
