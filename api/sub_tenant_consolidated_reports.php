<?php
/**
 * /api/sub_tenant_consolidated_reports.php
 *
 * Roll-up financial reports across every active sub-tenant of the current
 * master tenant. Reuses the per-tenant report builders in
 * `modules/accounting/api/reports.php`, then sums them by row line so the
 * master gets a single P&L / Balance Sheet / Cash Flow view.
 *
 *   GET ?type=income_statement&from=YYYY-MM-DD&to=YYYY-MM-DD[&include_master=1]
 *   GET ?type=balance_sheet&as_of=YYYY-MM-DD[&include_master=1]
 *   GET ?type=cash_flow_indirect&from=YYYY-MM-DD&to=YYYY-MM-DD[&include_master=1]
 *
 * Permissions: master_admin OR tenant_admin of the master tenant.
 * Sub-tenant admins cannot view siblings via this endpoint.
 *
 * Sign convention matches the per-tenant reports — no further normalisation
 * needed because consolidated lines are a straight sum.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/sub_tenants.php';
require_once __DIR__ . '/../modules/accounting/lib/standard_reports.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$role     = $ctx['role'] ?? 'employee';
$tenantId = (int) ($ctx['tenant_id'] ?? 0);

if (api_method() !== 'GET') api_error('Method not allowed', 405);

// Resolve the master parent.
$me = subTenantLookup($tenantId);
if (!$me) api_error('Tenant not found', 404);
$parentId = $me['tenant_type'] === 'master'
    ? (int) $me['id']
    : (int) ($me['parent_id'] ?? 0);
if (!$parentId) api_error('Active tenant has no master parent', 400);

// Permission gate — master_admin OR tenant_admin on the master tenant.
if ($role !== 'master_admin') {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT persona_type AS role FROM " . membershipReadSourceSql() . " src
          WHERE src.user_id = :u AND src.tenant_id = :t LIMIT 1"
    );
    $stmt->execute(['u' => (int)($user['id'] ?? 0), 't' => $parentId]);
    $r = $stmt->fetch();
    if (!$r || !in_array($r['role'], ['tenant_admin','master_admin'], true)) {
        api_error('Forbidden — only master_admin or master tenant_admin', 403);
    }
}

$type = (string) ($_GET['type'] ?? 'income_statement');
$includeMaster = !empty($_GET['include_master']);

// Pull sub-tenants to consolidate.
$pdo = getDB();
$stmt = $pdo->prepare(
    "SELECT id, name FROM tenants
      WHERE parent_id = :p AND tenant_type = 'sub' AND is_active = 1
   ORDER BY name ASC"
);
$stmt->execute(['p' => $parentId]);
$tenants = $stmt->fetchAll();

if ($includeMaster) {
    array_unshift($tenants, ['id' => $parentId, 'name' => $me['tenant_type'] === 'master' ? $me['name'] : null]);
}

if (!$tenants) {
    api_ok([
        'type'             => $type,
        'parent_tenant_id' => $parentId,
        'tenants'          => [],
        'consolidated'     => [],
        'as_of'            => date('c'),
    ]);
}

$tenantIds = array_map(fn($t) => (int) $t['id'], $tenants);

// Per-type collation.
$payload = [
    'type'             => $type,
    'parent_tenant_id' => $parentId,
    'tenants'          => $tenants,
    'as_of'            => date('c'),
];

if ($type === 'income_statement') {
    $from = (string) ($_GET['from'] ?? date('Y-01-01'));
    $to   = (string) ($_GET['to']   ?? date('Y-m-d'));
    $payload['period'] = ['from' => $from, 'to' => $to];

    $byTenant = [];
    $revenueByCode = []; $expenseByCode = [];
    $totalRev = 0.0; $totalExp = 0.0;
    foreach ($tenantIds as $tid) {
        try { $r = reportIncomeStatement($tid, $from, $to, null); }
        catch (\Throwable $e) {
            $byTenant[$tid] = ['error' => $e->getMessage()];
            continue;
        }
        $byTenant[$tid] = [
            'total_revenue' => $r['total_revenue'] ?? 0,
            'total_expense' => $r['total_expense'] ?? 0,
            'net_income'    => $r['net_income']    ?? 0,
        ];
        $totalRev += (float) ($r['total_revenue'] ?? 0);
        $totalExp += (float) ($r['total_expense'] ?? 0);
        foreach (($r['revenue'] ?? []) as $row) _sumByCode($revenueByCode, $row);
        foreach (($r['expense'] ?? []) as $row) _sumByCode($expenseByCode, $row);
    }
    $payload['by_tenant'] = $byTenant;
    $payload['consolidated'] = [
        'revenue'       => array_values($revenueByCode),
        'expense'       => array_values($expenseByCode),
        'total_revenue' => round($totalRev, 2),
        'total_expense' => round($totalExp, 2),
        'net_income'    => round($totalRev - $totalExp, 2),
    ];
    api_ok($payload);
}

if ($type === 'balance_sheet') {
    $asOf = (string) ($_GET['as_of'] ?? date('Y-m-d'));
    $payload['as_of_date'] = $asOf;

    $byTenant = [];
    $assetsByCode = []; $liabilitiesByCode = []; $equityByCode = [];
    $aTot = 0.0; $lTot = 0.0; $eTot = 0.0;
    foreach ($tenantIds as $tid) {
        try { $r = reportBalanceSheet($tid, $asOf, null); }
        catch (\Throwable $e) {
            $byTenant[$tid] = ['error' => $e->getMessage()];
            continue;
        }
        $byTenant[$tid] = [
            'total_assets'      => $r['total_assets']      ?? 0,
            'total_liabilities' => $r['total_liabilities'] ?? 0,
            'total_equity'      => $r['total_equity']      ?? 0,
            'balanced'          => $r['balanced']          ?? false,
        ];
        $aTot += (float) ($r['total_assets']      ?? 0);
        $lTot += (float) ($r['total_liabilities'] ?? 0);
        $eTot += (float) ($r['total_equity']      ?? 0);
        foreach (($r['assets']      ?? []) as $row) _sumByCode($assetsByCode, $row);
        foreach (($r['liabilities'] ?? []) as $row) _sumByCode($liabilitiesByCode, $row);
        foreach (($r['equity']      ?? []) as $row) _sumByCode($equityByCode, $row);
    }
    $payload['by_tenant'] = $byTenant;
    $payload['consolidated'] = [
        'assets'                  => array_values($assetsByCode),
        'liabilities'             => array_values($liabilitiesByCode),
        'equity'                  => array_values($equityByCode),
        'total_assets'            => round($aTot, 2),
        'total_liabilities'       => round($lTot, 2),
        'total_equity'            => round($eTot, 2),
        'liabilities_plus_equity' => round($lTot + $eTot, 2),
        'balanced'                => abs($aTot - ($lTot + $eTot)) < 0.01,
    ];
    api_ok($payload);
}

if ($type === 'cash_flow_indirect' || $type === 'cash_flow') {
    $from = (string) ($_GET['from'] ?? date('Y-01-01'));
    $to   = (string) ($_GET['to']   ?? date('Y-m-d'));
    $payload['period'] = ['from' => $from, 'to' => $to];

    $byTenant = [];
    $netIncomeTotal = 0.0; $opTotal = 0.0; $invTotal = 0.0; $finTotal = 0.0;
    $cashBegin = 0.0; $cashEnd = 0.0;
    foreach ($tenantIds as $tid) {
        try { $r = reportCashFlowIndirect($tid, $from, $to, null); }
        catch (\Throwable $e) {
            $byTenant[$tid] = ['error' => $e->getMessage()];
            continue;
        }
        $op  = (float) ($r['sections']['operating']['subtotal'] ?? 0);
        $inv = (float) ($r['sections']['investing']['subtotal'] ?? 0);
        $fin = (float) ($r['sections']['financing']['subtotal'] ?? 0);
        $byTenant[$tid] = [
            'net_income'         => $r['net_income']         ?? 0,
            'operating'          => $op,
            'investing'          => $inv,
            'financing'          => $fin,
            'net_change_in_cash' => $r['net_change_in_cash'] ?? 0,
            'cash_beginning'     => $r['cash_beginning']     ?? 0,
            'cash_ending'        => $r['cash_ending']        ?? 0,
        ];
        $netIncomeTotal += (float) ($r['net_income'] ?? 0);
        $opTotal  += $op;
        $invTotal += $inv;
        $finTotal += $fin;
        $cashBegin += (float) ($r['cash_beginning'] ?? 0);
        $cashEnd   += (float) ($r['cash_ending']    ?? 0);
    }
    $payload['by_tenant'] = $byTenant;
    $payload['consolidated'] = [
        'net_income'         => round($netIncomeTotal, 2),
        'operating'          => round($opTotal, 2),
        'investing'          => round($invTotal, 2),
        'financing'          => round($finTotal, 2),
        'net_change_in_cash' => round($opTotal + $invTotal + $finTotal, 2),
        'cash_beginning'     => round($cashBegin, 2),
        'cash_ending'        => round($cashEnd, 2),
    ];
    api_ok($payload);
}

api_error('Unknown report type. Use income_statement, balance_sheet, or cash_flow_indirect.', 422);

/**
 * Aggregate one report row keyed by COA code so the same account from
 * multiple sub-tenants stacks into one consolidated line.
 */
function _sumByCode(array &$bucket, array $row): void {
    $code = (string) ($row['code'] ?? '');
    if (!$code) return;
    if (!isset($bucket[$code])) {
        $bucket[$code] = [
            'code'         => $code,
            'name'         => (string) ($row['name'] ?? $code),
            'account_type' => (string) ($row['account_type'] ?? ''),
            'normal_side'  => (string) ($row['normal_side']  ?? ''),
            'amount'       => 0.0,
            'tenant_count' => 0,
        ];
    }
    $bucket[$code]['amount']       = round($bucket[$code]['amount'] + (float) ($row['amount'] ?? 0), 2);
    $bucket[$code]['tenant_count'] = (int) $bucket[$code]['tenant_count'] + 1;
}
