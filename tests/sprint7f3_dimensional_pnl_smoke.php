<?php
/**
 * Sprint 7f.3 smoke — Dimensional P&L.
 *
 * Asserts:
 *   - api/dimensional_pnl.php (RBAC, GET-only, dim_key required,
 *     date validation, dimension scoped to tenant + active, posted-only
 *     filter, normal_side-aware sign, '(unset)' bucket, family
 *     subtotals, net-income calculation, rectangular per_value rows).
 *   - DimensionalPnL.jsx renders the matrix with full testid coverage.
 *   - AccountingV1Module + sidebar wiring.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Backend — api/dimensional_pnl.php\n";
$ep = (string) file_get_contents("{$ROOT}/api/dimensional_pnl.php");
$assert('endpoint exists',                       strlen($ep) > 0);
$assert('parses',                                $lint("{$ROOT}/api/dimensional_pnl.php"));
$assert('GET-only',                              strpos($ep, "if (api_method() !== 'GET')") !== false);
$assert('RBAC accounting.coa.view',              strpos($ep, "rbac_legacy_require(\$user, 'accounting.coa.view')") !== false);
$assert('dim_key required',                      strpos($ep, "dim_key required") !== false);
$assert('YYYY-MM-DD start/end',                  strpos($ep, "preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', \$start)") !== false);
$assert('rejects start > end',                   strpos($ep, '$start > $end') !== false);
$assert('dimension lookup tenant-scoped + active',
    strpos($ep, "FROM accounting_dimensions") !== false
    && strpos($ep, "AND active = 1") !== false);
$assert('returns 404 if dimension not found',    strpos($ep, "Dimension '{\$dimKey}' not found or inactive") !== false);
$assert('joins JE lines + entries + accounts',
    strpos($ep, 'FROM accounting_journal_lines jl') !== false
    && strpos($ep, 'JOIN accounting_journal_entries je') !== false
    && strpos($ep, 'JOIN accounting_accounts a') !== false);
$assert('posted-only filter',                    strpos($ep, "je.status = 'posted'") !== false);
$assert('account_type whitelist (rev + expense families)',
    strpos($ep, "('revenue','cost_of_goods_sold','expense','other_income','other_expense','contra_revenue')") !== false);
$assert('entity_id filter applied',              strpos($ep, 'je.entity_id = :eid') !== false);
$assert('parses dimension_values JSON',          strpos($ep, 'json_decode((string) $r[\'dimension_values\']') !== false);
$assert('"(unset)" fallback bucket',             strpos($ep, "\$bucket = '(unset)';") !== false);
$assert('normal_side sign convention',
    strpos($ep, "\$byAccount[\$aid]['normal_side'] === 'credit' ? (\$c - \$d) : (\$d - \$c)") !== false);
$assert('"(unset)" sorted last',                 strpos($ep, "if (\$a === '(unset)') return 1") !== false);
$assert('rectangular row backfill',
    strpos($ep, "\$a['per_value'][\$v] = round(\$a['per_value'][\$v] ?? 0.0, 2)") !== false);
$assert('family subtotals per dim value',
    strpos($ep, '$familySubtotals[$a[\'account_type\']][\'per_value\'][$v]') !== false);
$assert('net income = (rev + other_income - contra) - expense families',
    strpos($ep, "['revenue']['per_value'][\$v]") !== false
    && strpos($ep, "['other_income']['per_value'][\$v]") !== false
    && strpos($ep, "['contra_revenue']['per_value'][\$v]") !== false
    && strpos($ep, "['cost_of_goods_sold']['per_value'][\$v]") !== false
    && strpos($ep, "['expense']['per_value'][\$v]") !== false
    && strpos($ep, "['other_expense']['per_value'][\$v]") !== false);
$assert('returns dim_values + accounts + subtotals envelope',
    strpos($ep, "'dim_values' => \$dimValues") !== false
    && strpos($ep, "'accounts'   => \$accounts") !== false
    && strpos($ep, "'subtotals'  => [") !== false
    && strpos($ep, "'net_income'          => \$netIncome") !== false);

echo "\nModule alias\n";
$alias = "{$ROOT}/modules/accounting/api/dimensional_pnl.php";
$assert('alias exists', is_file($alias));
$assert('alias parses', $lint($alias));
$assert('alias delegates',
    strpos((string) file_get_contents($alias), "require __DIR__ . '/../../../api/dimensional_pnl.php'") !== false);

echo "\nFrontend — DimensionalPnL.jsx\n";
$jsx = (string) file_get_contents("{$ROOT}/modules/accounting/ui/DimensionalPnL.jsx");
$assert('reads dimensions list through v1 API',
    strpos($jsx, "/api/v1/accounting/dimensions") !== false
    && strpos($jsx, "/modules/accounting/api/dimensions.php") === false);
$assert('hits dimensional_pnl through v1 API',
    strpos($jsx, '/api/v1/accounting/dimensional-pnl') !== false
    && strpos($jsx, '/api/dimensional_pnl.php?dim_key=') === false);
$assert('groups accounts by family',
    strpos($jsx, "['revenue', 'other_income', 'contra_revenue', 'cost_of_goods_sold', 'expense', 'other_expense']") !== false);
foreach ([
    'page','refresh','dim','start','end','empty-state','loading','error',
    'summary','table','net-income-row',
] as $id) {
    $assert("testid: accounting-dim-pnl-{$id}",
        strpos($jsx, "data-testid=\"accounting-dim-pnl-{$id}\"") !== false);
}
foreach (['bucket-count','account-count','net-income-total'] as $id) {
    $assert("summary stat testId: accounting-dim-pnl-{$id}",
        strpos($jsx, "testId=\"accounting-dim-pnl-{$id}\"") !== false);
}
$assert('column header testid template',         strpos($jsx, 'data-testid={`accounting-dim-pnl-col-${slug(v)}`}') !== false);
$assert('account row testid template',           strpos($jsx, 'data-testid={`accounting-dim-pnl-row-${a.code}`}') !== false);
$assert('subtotal row testid template',          strpos($jsx, 'data-testid={`accounting-dim-pnl-subtotal-${g.family}`}') !== false);

echo "\nWiring — AccountingV1Module + App sidebar\n";
$mod = (string) file_get_contents("{$ROOT}/modules/accounting/ui/AccountingModule.jsx");
$assert('imports DimensionalPnL',                strpos($mod, "import DimensionalPnL from './DimensionalPnL'") !== false);
$assert('mounts /dim-pnl route',                 strpos($mod, 'path="dim-pnl" element={<DimensionalPnL />}') !== false);
$assert('sub-nav Dimensional P&L tab',           strpos($mod, '<Tab to="dim-pnl"   label="Dimensional P&L" />') !== false);

$app = (string) file_get_contents("{$ROOT}/dashboard/src/App.jsx");
$assert('sidebar Dimensional P&L action',
    strpos($app, "name: 'Dimensional P&L'") !== false
    && strpos($app, "route: 'dim-pnl'") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
