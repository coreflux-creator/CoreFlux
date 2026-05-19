<?php
/**
 * Missing-dimension detector (Sprint 7f.4).
 *
 * Scans posted JE lines whose account has at least one `required` dimension
 * rule but where the line's `dimension_values` JSON omits or empties that key.
 * Powers the yellow "Missing dimension" CTA on the Bookkeeping Overview.
 *
 *   GET /api/missing_dimensions.php
 *     ?days=90              (1..1825, default 90)
 *     &entity_id=N          (optional)
 *     &limit=100            (1..500, default 100)
 *
 *   Response: {
 *     window_days, count, by_account: [{account_id, account_code, account_name,
 *       missing_dim_keys: [..], missing_count}],
 *     rows: [{je_id, je_number, posting_date, line_id, account_code,
 *             account_name, missing_dim_keys: [..], description, debit, credit}]
 *   }
 *
 * Tenant-scoped. RBAC: `accounting.je.view`.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../modules/accounting/lib/dimensions.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'accounting.je.view');

$days     = max(1, min(1825, (int) (api_query('days') ?? 90)));
$entityId = (int) (api_query('entity_id') ?? 0);
$limit    = max(1, min(500, (int) (api_query('limit') ?? 100)));
$since    = date('Y-m-d', strtotime("-{$days} days"));

$pdo = getDB();

// 1. Tenant dim registry — empty? nothing to detect.
$dimStmt = $pdo->prepare(
    'SELECT id, dim_key, label, required_default
       FROM accounting_dimensions
      WHERE tenant_id = :t AND active = 1'
);
$dimStmt->execute(['t' => $tid]);
$dims = $dimStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
if (!$dims) {
    api_ok([
        'window_days' => $days, 'count' => 0,
        'by_account'  => [], 'rows' => [],
        'note'        => 'No active dimensions defined for this tenant.',
    ]);
}

// 2. Fetch posted lines in window with their account + dim_values JSON.
$sql = "SELECT jl.id AS line_id, jl.account_id, jl.dimension_values,
               jl.description, jl.debit, jl.credit,
               je.id AS je_id, je.je_number, je.posting_date,
               a.account_code, a.account_name
          FROM accounting_journal_lines jl
          JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id
          JOIN accounting_accounts a         ON a.id  = jl.account_id
         WHERE jl.tenant_id = :t
           AND je.status    = 'posted'
           AND je.posting_date >= :since"
       . ($entityId ? ' AND je.entity_id = :e' : '');
$bind = ['t' => $tid, 'since' => $since];
if ($entityId) $bind['e'] = $entityId;
$stmt = $pdo->prepare($sql);
$stmt->execute($bind);

$rows       = [];
$byAccount  = [];
$count      = 0;

while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
    $accId  = (int) $r['account_id'];
    $rules  = accountingAccountDimRules($tid, $accId);
    $missing = [];
    $values = [];
    if (!empty($r['dimension_values'])) {
        $decoded = json_decode((string) $r['dimension_values'], true);
        if (is_array($decoded)) $values = $decoded;
    }
    foreach ($dims as $d) {
        $key = (string) $d['dim_key'];
        $req = $rules[$key] ?? ((int) $d['required_default'] === 1 ? 'required' : 'optional');
        if ($req !== 'required') continue;
        $v = $values[$key] ?? null;
        if ($v === null || $v === '') $missing[] = $key;
    }
    if (!$missing) continue;
    $count++;

    if (!isset($byAccount[$accId])) {
        $byAccount[$accId] = [
            'account_id'        => $accId,
            'account_code'      => $r['account_code'],
            'account_name'      => $r['account_name'],
            'missing_dim_keys'  => [],
            'missing_count'     => 0,
        ];
    }
    $byAccount[$accId]['missing_count']++;
    $byAccount[$accId]['missing_dim_keys'] = array_values(array_unique(array_merge(
        $byAccount[$accId]['missing_dim_keys'], $missing
    )));

    if (count($rows) < $limit) {
        $rows[] = [
            'je_id'            => (int) $r['je_id'],
            'je_number'        => $r['je_number'],
            'posting_date'     => $r['posting_date'],
            'line_id'          => (int) $r['line_id'],
            'account_id'       => $accId,
            'account_code'     => $r['account_code'],
            'account_name'     => $r['account_name'],
            'missing_dim_keys' => $missing,
            'description'      => $r['description'],
            'debit'            => (float) $r['debit'],
            'credit'           => (float) $r['credit'],
        ];
    }
}

// Sort by_account by missing_count desc.
usort($byAccount, static fn($a, $b) => $b['missing_count'] <=> $a['missing_count']);

api_ok([
    'window_days' => $days,
    'count'       => $count,
    'by_account'  => array_values($byAccount),
    'rows'        => $rows,
    'limit'       => $limit,
]);
