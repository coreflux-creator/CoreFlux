<?php
/**
 * Shared liquidity projection engine.
 *
 * Single source of truth for the day-by-day cash projection used by:
 *   - api/liquidity_forecast.php           (the main treasury forecast)
 *   - api/ap_bill_liquidity_impact.php     (the per-bill what-if overlay)
 *   - api/treasury_scenario.php            (the multi-event scenario builder)
 *
 * The engine is intentionally tiny and pure-PHP — it pulls baseline
 * datasets from MongoDB-equivalent SQL tables (cash GL + AR + open AP +
 * scheduled treasury payments), then walks a per-day projection. Every
 * caller can layer additional hypothetical events on top via
 * `extraInflowsByDate` and `extraOutflowsByDate` without rewriting the
 * SQL.
 *
 * Returns:
 *   {
 *     starting_cash,
 *     daily:  [{date, opening, inflows, outflows, closing}],
 *     totals: {total_inflows, total_outflows, ending_cash,
 *              lowest_balance, lowest_balance_date,
 *              runway_days_to_zero},
 *     sources: {inflows: [...], outflows: [...]},
 *     guards:  {has_bank_accounts, has_open_ar,
 *               has_open_ap, has_scheduled_payments}
 *   }
 *
 * NOTE: pure read-only helpers; never write to the DB.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

const LIQUIDITY_PROJECTION_RULE_VERSION = 'treasury.liquidity.daily.v2026-06-16.1';

/**
 * Pull the four baseline datasets in a single shape so the projection
 * walker doesn't have to know about SQL.
 *
 * @return array{starting_cash:float, ar:array, tp:array, ap:array,
 *               bank_count:int, ar_dedup_keys:array}
 */
function liquidityBaselineDatasets(int $tenantId, string $today, string $endDate, ?int $entityId = null, ?int $excludeBillId = null): array
{
    $pdo = getDB();

    $cashStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
           FROM accounting_bank_accounts ba
           JOIN accounting_accounts a ON a.tenant_id = ba.tenant_id AND a.account_code = ba.gl_account_code
           JOIN accounting_journal_lines jl ON jl.account_id = a.id AND jl.tenant_id = a.tenant_id
           JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
          WHERE ba.tenant_id = :t AND ba.status = 'active' AND je.posting_date <= :d"
       . ($entityId ? ' AND ba.entity_id = :e' : '')
    );
    $bind = ['t' => $tenantId, 'd' => $today];
    if ($entityId) $bind['e'] = $entityId;
    $cashStmt->execute($bind);
    $startingCash = (float) $cashStmt->fetchColumn();

    $bankCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM accounting_bank_accounts
          WHERE tenant_id = " . (int) $tenantId . " AND status = 'active'"
    )->fetchColumn();

    $arStmt = $pdo->prepare(
        "SELECT due_date, COALESCE(amount_due, total - amount_paid) AS due
           FROM billing_invoices
          WHERE tenant_id = :t AND status IN ('approved','sent','partially_paid')
            AND due_date BETWEEN :s AND :e
            AND COALESCE(amount_due, total - amount_paid) > 0"
    );
    $arStmt->execute(['t' => $tenantId, 's' => $today, 'e' => $endDate]);
    $arRows = $arStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $tpStmt = $pdo->prepare(
        "SELECT payment_date, amount, payee_name
           FROM treasury_payments
          WHERE tenant_id = :t
            AND status IN ('draft','pending_approval','approved','scheduled')
            AND payment_date BETWEEN :s AND :e"
       . ($entityId ? ' AND entity_id = :ent' : '')
    );
    $bind = ['t' => $tenantId, 's' => $today, 'e' => $endDate];
    if ($entityId) $bind['ent'] = $entityId;
    $tpStmt->execute($bind);
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
    $apStmt->execute(['t' => $tenantId, 's' => $today, 'e' => $endDate]);
    $apRows = [];
    while ($r = $apStmt->fetch(\PDO::FETCH_ASSOC)) {
        if ($excludeBillId !== null && (int) $r['id'] === $excludeBillId) continue;
        $key = strtolower((string) $r['vendor_name']) . '|' . number_format((float) $r['amount_due'], 2, '.', '');
        if (isset($tpKeys[$key])) continue;
        $apRows[] = $r;
    }

    return [
        'starting_cash' => $startingCash,
        'ar'            => $arRows,
        'tp'            => $tpRows,
        'ap'            => $apRows,
        'bank_count'    => $bankCount,
    ];
}

/**
 * Walk the day-by-day projection. Returns the lowest balance, the date
 * that occurred on, and the runway-to-zero days (or null).
 *
 * @param array<string,float> $extraInflowsByDate
 * @param array<string,float> $extraOutflowsByDate
 * @return array{lowest_balance:float, lowest_balance_date:string, runway_days_to_zero:?int, daily:array}
 */
function liquidityWalkProjection(
    float $startingCash,
    int $days,
    string $today,
    array $inflowsByDate,
    array $outflowsByDate,
    array $extraInflowsByDate = [],
    array $extraOutflowsByDate = []
): array {
    $running    = $startingCash;
    $lowest     = $startingCash;
    $lowestDate = $today;
    $runwayDay  = null;
    $daily      = [];
    for ($i = 0; $i <= $days; $i++) {
        $d = date('Y-m-d', strtotime("+{$i} days"));
        $opening   = $running;
        $inflows   = round(($inflowsByDate[$d]  ?? 0.0) + ($extraInflowsByDate[$d]  ?? 0.0), 2);
        $outflows  = round(($outflowsByDate[$d] ?? 0.0) + ($extraOutflowsByDate[$d] ?? 0.0), 2);
        $closing   = round($opening + $inflows - $outflows, 2);
        $running   = $closing;
        if ($closing < $lowest) {
            $lowest     = $closing;
            $lowestDate = $d;
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
    return [
        'lowest_balance'      => round($lowest, 2),
        'lowest_balance_date' => $lowestDate,
        'runway_days_to_zero' => $runwayDay,
        'daily'               => $daily,
    ];
}

/**
 * Standard projection evidence envelope for replay/version governance.
 *
 * The projection math is deterministic; this captures which rule version,
 * source population, grain, and overlay shape produced a response.
 */
function liquidityProjectionEvidence(
    int $tenantId,
    string $today,
    string $endDate,
    int $days,
    array $datasets,
    array $overlays = []
): array {
    $sourcePopulation = [
        'bank_accounts' => (int) ($datasets['bank_count'] ?? 0),
        'open_ar' => count($datasets['ar'] ?? []),
        'scheduled_treasury_payments' => count($datasets['tp'] ?? []),
        'open_ap' => count($datasets['ap'] ?? []),
        'overlay_inflow_dates' => count($overlays['extra_inflows_by_date'] ?? []),
        'overlay_outflow_dates' => count($overlays['extra_outflows_by_date'] ?? []),
    ];
    $basis = [
        'tenant_id' => $tenantId,
        'rule_version' => LIQUIDITY_PROJECTION_RULE_VERSION,
        'grain' => 'daily',
        'as_of_date' => $today,
        'window_end_date' => $endDate,
        'window_days' => $days,
        'source_population' => $sourcePopulation,
        'overlays' => [
            'extra_inflows_by_date' => $overlays['extra_inflows_by_date'] ?? [],
            'extra_outflows_by_date' => $overlays['extra_outflows_by_date'] ?? [],
        ],
    ];

    return [
        'projection_engine' => 'treasury.liquidity',
        'rule_version' => LIQUIDITY_PROJECTION_RULE_VERSION,
        'grain' => 'daily',
        'replay_basis' => 'graph_state_plus_business_events',
        'as_of_date' => $today,
        'window_end_date' => $endDate,
        'window_days' => $days,
        'source_population' => $sourcePopulation,
        'replay_key' => hash('sha256', json_encode($basis, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: ''),
    ];
}

/**
 * Convert AR/TP/AP datasets into per-day inflow + outflow maps.
 *
 * @return array{inflows_by_date:array<string,float>, outflows_by_date:array<string,float>}
 */
function liquidityBucketDatasets(array $datasets): array
{
    $inflowsByDate  = [];
    $outflowsByDate = [];
    foreach ($datasets['ar'] as $r) {
        $d = (string) $r['due_date'];
        $inflowsByDate[$d] = ($inflowsByDate[$d] ?? 0.0) + (float) $r['due'];
    }
    foreach ($datasets['tp'] as $r) {
        $d = (string) $r['payment_date'];
        $outflowsByDate[$d] = ($outflowsByDate[$d] ?? 0.0) + (float) $r['amount'];
    }
    foreach ($datasets['ap'] as $r) {
        $d = (string) $r['due_date'];
        $outflowsByDate[$d] = ($outflowsByDate[$d] ?? 0.0) + (float) $r['amount_due'];
    }
    return [
        'inflows_by_date'  => $inflowsByDate,
        'outflows_by_date' => $outflowsByDate,
    ];
}
