<?php
/**
 * /api/cfo_formulas — user-defined "KPI = A op B" widgets.
 *
 *   GET    /api/cfo_formulas.php                → list (own + tenant shared)
 *   POST   /api/cfo_formulas.php  { name, operand_a, operator, operand_b, format, is_shared? }
 *   DELETE /api/cfo_formulas.php?id=N
 *   POST   /api/cfo_formulas.php?action=evaluate { operand_a, operator, operand_b, snapshot }
 *           → resolves the two operands against the supplied dashboard snapshot
 *             (the same payload returned by /api/exec_dashboard.php) and
 *             returns the numeric result. NO use of PHP's eval — purely metric lookup.
 *
 * SECURITY: operand keys are validated against a whitelist of dotted paths
 * into the dashboard snapshot. We never accept arbitrary PHP expressions.
 * No use of PHP's runtime evaluator anywhere in this file.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';

$ctx      = api_require_cfo();
$user     = $ctx['user'];
$userId   = (int) ($user['id'] ?? 0);
$tenantId = (int) (currentTenantId() ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

$pdo    = getDB();
$method = api_method();
$action = (string) api_query('action', '');
$id     = (int) api_query('id', 0);

// Whitelist of paths a formula may reference inside the dashboard snapshot.
const CFO_FORMULA_KEYS = [
    'finance.revenue.mtd',           'finance.revenue.qtd',     'finance.revenue.ytd', 'finance.revenue.run_rate_90d',
    'finance.margin.mtd',            'finance.margin.qtd',      'finance.margin.ytd',  'finance.margin.gross_pct',
    'finance.ar_aging.total',        'finance.ar_aging.current','finance.ar_aging.d30','finance.ar_aging.d60','finance.ar_aging.d90','finance.ar_aging.d90_plus',
    'finance.ap_aging.total',        'finance.ap_aging.current','finance.ap_aging.d30','finance.ap_aging.d60','finance.ap_aging.d90','finance.ap_aging.d90_plus',
    'finance.payroll.mtd',           'finance.payroll.qtd',     'finance.payroll.ytd', 'finance.payroll.last_run_total',
    'finance.dso',                   'finance.dpo',             'finance.unapplied_cash',
    'staffing.headcount.active',     'staffing.headcount.contractors_w2', 'staffing.headcount.contractors_c2c', 'staffing.headcount.contractors_1099', 'staffing.headcount.perm',
    'staffing.new_starts.period',    'staffing.terminations.period', 'staffing.net_change.period',
    'staffing.upcoming_starts',      'staffing.upcoming_terminations',
    'staffing.active_placements',    'staffing.new_placements.period', 'staffing.ending_soon',
    'staffing.billable_hours.period',
];
const CFO_FORMULA_OPS = ['+','-','*','/','pct_of'];

function _cfoFormulaResolve(array $snapshot, string $key): ?float {
    if (!in_array($key, CFO_FORMULA_KEYS, true)) return null;
    $cursor = $snapshot;
    foreach (explode('.', $key) as $seg) {
        if (!is_array($cursor) || !array_key_exists($seg, $cursor)) return null;
        $cursor = $cursor[$seg];
    }
    return is_numeric($cursor) ? (float) $cursor : null;
}

function _cfoFormulaApply(?float $a, ?float $b, string $op): ?float {
    if ($a === null || $b === null) return null;
    return match ($op) {
        '+'      => $a + $b,
        '-'      => $a - $b,
        '*'      => $a * $b,
        '/'      => $b == 0.0 ? null : $a / $b,
        'pct_of' => $b == 0.0 ? null : ($a / $b) * 100,
        default  => null,
    };
}

if ($method === 'POST' && $action === 'evaluate') {
    $body  = api_json_body();
    $opA   = (string) ($body['operand_a'] ?? '');
    $op    = (string) ($body['operator']  ?? '');
    $opB   = (string) ($body['operand_b'] ?? '');
    $snap  = $body['snapshot'] ?? null;
    if (!in_array($op, CFO_FORMULA_OPS, true)) api_error('Invalid operator', 422);
    if (!is_array($snap))                       api_error('snapshot required', 422);
    $a = _cfoFormulaResolve($snap, $opA);
    $b = _cfoFormulaResolve($snap, $opB);
    $v = _cfoFormulaApply($a, $b, $op);
    api_ok([
        'operand_a' => $opA, 'operand_b' => $opB, 'operator' => $op,
        'a_value'   => $a,   'b_value'   => $b,   'result'   => $v,
    ]);
}

if ($method === 'GET') {
    $stmt = $pdo->prepare(
        "SELECT * FROM cfo_custom_formulas
          WHERE tenant_id = :t AND (user_id = :u OR is_shared = 1)
       ORDER BY name ASC"
    );
    $stmt->execute(['t' => $tenantId, 'u' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out  = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'        => (int) $r['id'],
            'name'      => $r['name'],
            'operand_a' => $r['operand_a'],
            'operator'  => $r['operator'],
            'operand_b' => $r['operand_b'],
            'format'    => $r['format'],
            'is_shared' => (int) $r['is_shared'] === 1,
            'is_owner'  => (int) $r['user_id'] === $userId,
        ];
    }
    api_ok([
        'formulas'      => $out,
        'allowed_keys'  => CFO_FORMULA_KEYS,
        'allowed_ops'   => CFO_FORMULA_OPS,
        'allowed_formats' => ['money','number','percent','ratio'],
    ]);
}

if ($method === 'POST') {
    $body = api_json_body();
    $name = trim((string) ($body['name'] ?? ''));
    $opA  = (string) ($body['operand_a'] ?? '');
    $op   = (string) ($body['operator']  ?? '');
    $opB  = (string) ($body['operand_b'] ?? '');
    $fmt  = (string) ($body['format']    ?? 'number');
    $shared = (int) (bool) ($body['is_shared'] ?? 0);

    if ($name === '') api_error('name required', 422);
    if (mb_strlen($name) > 120) api_error('name too long (max 120)', 422);
    if (!in_array($opA, CFO_FORMULA_KEYS, true)) api_error('Invalid operand_a', 422);
    if (!in_array($opB, CFO_FORMULA_KEYS, true)) api_error('Invalid operand_b', 422);
    if (!in_array($op,  CFO_FORMULA_OPS,  true)) api_error('Invalid operator',  422);
    if (!in_array($fmt, ['money','number','percent','ratio'], true)) api_error('Invalid format', 422);

    $pdo->prepare(
        "INSERT INTO cfo_custom_formulas
            (tenant_id, user_id, name, operand_a, operator, operand_b, format, is_shared)
         VALUES (:t, :u, :n, :a, :op, :b, :f, :sh)
         ON DUPLICATE KEY UPDATE
            operand_a = VALUES(operand_a), operator = VALUES(operator),
            operand_b = VALUES(operand_b), format    = VALUES(format),
            is_shared = VALUES(is_shared), updated_at = NOW()"
    )->execute([
        't' => $tenantId, 'u' => $userId, 'n' => $name,
        'a' => $opA, 'op' => $op, 'b' => $opB, 'f' => $fmt, 'sh' => $shared,
    ]);
    api_ok(['id' => (int) $pdo->lastInsertId()], 201);
}

if ($method === 'DELETE' && $id) {
    $pdo->prepare("DELETE FROM cfo_custom_formulas
                    WHERE id = :id AND tenant_id = :t AND user_id = :u")
        ->execute(['id' => $id, 't' => $tenantId, 'u' => $userId]);
    api_ok(['id' => $id, 'deleted' => true]);
}

api_error('Method not allowed', 405);
