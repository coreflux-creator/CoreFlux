<?php
/**
 * Dimensional P&L (Sprint 7f.3).
 *
 *   GET /api/dimensional_pnl.php
 *        ?dim_key=department          required (must exist in accounting_dimensions)
 *        &start=YYYY-MM-DD             default = first day of current year
 *        &end=YYYY-MM-DD               default = today
 *        &entity_id=N                  optional
 *
 * Pivots posted JE lines along the given dimension key. Returns a
 * matrix:
 *
 *   {
 *     dim_key, dim_label, start, end, entity_id,
 *     dim_values: ['(unset)','Sales','Engineering','G&A'],
 *     accounts: [
 *       { code, name, account_type, normal_side,
 *         per_value: { '(unset)':123.45, Sales:9870.00, ... },
 *         total: 12345.67 }
 *     ],
 *     subtotals: {
 *       revenue:        { per_value:{...}, total },
 *       cost_of_goods_sold: {...},
 *       expense:        {...},
 *       net_income:     { per_value:{...}, total }
 *     }
 *   }
 *
 * Sign convention: revenue/credit-normal accounts emit positive on
 * credits; expense/debit-normal positive on debits.
 *
 * RBAC: `accounting.coa.view`.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);
RBAC::requirePermission($user, 'accounting.coa.view');

$dimKey   = trim((string) (api_query('dim_key') ?? ''));
$start    = (string) (api_query('start') ?? date('Y-01-01'));
$end      = (string) (api_query('end')   ?? date('Y-m-d'));
$entityId = (int) (api_query('entity_id') ?? 0);

if ($dimKey === '') api_error('dim_key required', 400);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) api_error('start must be YYYY-MM-DD', 400);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   api_error('end must be YYYY-MM-DD', 400);
if ($start > $end) api_error('start must be <= end', 400);

$pdo = getDB();

// Resolve dimension.
$dimStmt = $pdo->prepare(
    'SELECT id, dim_key, label, data_type
       FROM accounting_dimensions
      WHERE tenant_id = :t AND dim_key = :k AND active = 1 LIMIT 1'
);
$dimStmt->execute(['t' => $tid, 'k' => $dimKey]);
$dim = $dimStmt->fetch(\PDO::FETCH_ASSOC);
if (!$dim) api_error("Dimension '{$dimKey}' not found or inactive", 404);

$entityWhere = $entityId ? 'AND je.entity_id = :eid' : '';

// Pull every JE line in the window for revenue / expense families.
$sql = "SELECT a.id   AS account_id,
               a.code AS code,
               a.name AS name,
               a.account_type,
               a.normal_side,
               jl.dimension_values,
               jl.debit,
               jl.credit
          FROM accounting_journal_lines jl
          JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id
          JOIN accounting_accounts a        ON a.id = jl.account_id AND a.tenant_id = jl.tenant_id
         WHERE jl.tenant_id = :t
           AND je.posting_date BETWEEN :start AND :end
           AND je.status = 'posted'
           AND a.account_type IN ('revenue','cost_of_goods_sold','expense','other_income','other_expense','contra_revenue')
           {$entityWhere}";
$stmt = $pdo->prepare($sql);
$params = ['t' => $tid, 'start' => $start, 'end' => $end];
if ($entityId) $params['eid'] = $entityId;
$stmt->execute($params);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

$valuesSet  = [];   // dim_value → 1
$byAccount  = [];   // account_id → ['code','name','type','normal_side','per_value'=>[]]

foreach ($rows as $r) {
    $aid = (int) $r['account_id'];
    if (!isset($byAccount[$aid])) {
        $byAccount[$aid] = [
            'account_id'   => $aid,
            'code'         => (string) $r['code'],
            'name'         => (string) $r['name'],
            'account_type' => (string) $r['account_type'],
            'normal_side'  => strtolower((string) $r['normal_side']),
            'per_value'    => [],
        ];
    }
    $bucket = '(unset)';
    if (!empty($r['dimension_values'])) {
        $j = json_decode((string) $r['dimension_values'], true);
        if (is_array($j) && isset($j[$dimKey]) && trim((string) $j[$dimKey]) !== '') {
            $bucket = (string) $j[$dimKey];
        }
    }
    $valuesSet[$bucket] = true;

    $d = (float) $r['debit'];
    $c = (float) $r['credit'];
    $delta = $byAccount[$aid]['normal_side'] === 'credit' ? ($c - $d) : ($d - $c);

    $byAccount[$aid]['per_value'][$bucket] = round(
        ($byAccount[$aid]['per_value'][$bucket] ?? 0.0) + $delta, 2
    );
}

// Sort dim values: '(unset)' last, otherwise alphabetical.
$dimValues = array_keys($valuesSet);
usort($dimValues, static function ($a, $b) {
    if ($a === '(unset)') return 1;
    if ($b === '(unset)') return -1;
    return strcasecmp($a, $b);
});

// Compute account totals + family subtotals.
$accounts = [];
$familySubtotals = [
    'revenue'             => ['per_value' => [], 'total' => 0.0],
    'cost_of_goods_sold'  => ['per_value' => [], 'total' => 0.0],
    'expense'             => ['per_value' => [], 'total' => 0.0],
    'other_income'        => ['per_value' => [], 'total' => 0.0],
    'other_expense'       => ['per_value' => [], 'total' => 0.0],
    'contra_revenue'      => ['per_value' => [], 'total' => 0.0],
];
foreach ($byAccount as $a) {
    $total = 0.0;
    // Backfill missing buckets with 0 so the row is rectangular.
    foreach ($dimValues as $v) {
        $a['per_value'][$v] = round($a['per_value'][$v] ?? 0.0, 2);
        $total += $a['per_value'][$v];
    }
    $a['total'] = round($total, 2);
    $accounts[] = $a;

    if (isset($familySubtotals[$a['account_type']])) {
        foreach ($dimValues as $v) {
            $familySubtotals[$a['account_type']]['per_value'][$v] =
                round(($familySubtotals[$a['account_type']]['per_value'][$v] ?? 0.0) + $a['per_value'][$v], 2);
        }
        $familySubtotals[$a['account_type']]['total'] =
            round($familySubtotals[$a['account_type']]['total'] + $a['total'], 2);
    }
}
// Sort accounts by code.
usort($accounts, static fn($x, $y) => strcmp($x['code'], $y['code']));

// Net income per dimension value.
$netIncome = ['per_value' => [], 'total' => 0.0];
foreach ($dimValues as $v) {
    $rev = ($familySubtotals['revenue']['per_value'][$v] ?? 0)
         + ($familySubtotals['other_income']['per_value'][$v] ?? 0)
         - ($familySubtotals['contra_revenue']['per_value'][$v] ?? 0);
    $exp = ($familySubtotals['cost_of_goods_sold']['per_value'][$v] ?? 0)
         + ($familySubtotals['expense']['per_value'][$v] ?? 0)
         + ($familySubtotals['other_expense']['per_value'][$v] ?? 0);
    $netIncome['per_value'][$v] = round($rev - $exp, 2);
    $netIncome['total'] += $netIncome['per_value'][$v];
}
$netIncome['total'] = round($netIncome['total'], 2);

api_ok([
    'dim_key'    => $dimKey,
    'dim_label'  => (string) $dim['label'],
    'start'      => $start,
    'end'        => $end,
    'entity_id'  => $entityId ?: null,
    'dim_values' => $dimValues,
    'accounts'   => $accounts,
    'subtotals'  => [
        'revenue'             => $familySubtotals['revenue'],
        'cost_of_goods_sold'  => $familySubtotals['cost_of_goods_sold'],
        'expense'             => $familySubtotals['expense'],
        'other_income'        => $familySubtotals['other_income'],
        'other_expense'       => $familySubtotals['other_expense'],
        'contra_revenue'      => $familySubtotals['contra_revenue'],
        'net_income'          => $netIncome,
    ],
    'count'      => count($accounts),
]);
