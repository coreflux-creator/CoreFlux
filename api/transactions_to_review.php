<?php
/**
 * Transactions to Review queue (Sprint 7e.2, Layer-parity).
 *
 * Unified bookkeeping inbox of every uncategorized bank-statement line
 * across every active bank account in the tenant. Closes the most common
 * bookkeeping workflow loop:
 *
 *     BookkeepingOverview · "5 to review"
 *         → click → land on this page
 *         → first row pre-loaded with AI suggestion
 *         → accept → next row
 *
 *   GET /api/transactions_to_review.php
 *        ?bank_account_id=N      (optional filter)
 *        &order=oldest_first|newest_first|amount_desc   default oldest_first
 *        &limit=50                                       default 50, max 200
 *        &offset=0                                       default 0
 *
 * Response envelope:
 *   {
 *     order, limit, offset, total,
 *     bank_accounts: [ { id, name, code } ],   // for the filter dropdown
 *     rows: [
 *       { id, posted_date, description, amount, bank_reference, fitid,
 *         match_status, ai_suggested_account_code, ai_suggested_confidence,
 *         applied_rule_id, bank_account_id, bank_account_name, bank_gl_code,
 *         age_days }
 *     ]
 *   }
 *
 * Per-row actions reuse existing endpoints:
 *   POST /api/accounting/bank_ai?action=suggest_categorize&line_id=N
 *   POST /api/accounting/bank_statements?action=accept_ai_categorize&line_id=N
 *   POST /api/accounting/bank_statements?action=ignore&line_id=N
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);
RBAC::requirePermission($user, 'accounting.coa.view');

$bid    = (int) (api_query('bank_account_id') ?? 0);
$order  = (string) (api_query('order') ?? 'oldest_first');
$limit  = max(1, min(200, (int) (api_query('limit')  ?? 50)));
$offset = max(0,            (int) (api_query('offset') ?? 0));

$orderBy = match ($order) {
    'newest_first' => 'bsl.posted_date DESC, bsl.id DESC',
    'amount_desc'  => 'ABS(bsl.amount) DESC, bsl.posted_date ASC',
    default        => 'bsl.posted_date ASC, bsl.id ASC', // oldest_first
};
if (!in_array($order, ['oldest_first', 'newest_first', 'amount_desc'], true)) {
    $order = 'oldest_first';
}

$pdo = getDB();

// ──────────────────────────────────────────────────────────────────
// Available bank accounts (for filter dropdown)
// ──────────────────────────────────────────────────────────────────
$baStmt = $pdo->prepare(
    "SELECT id, name, gl_account_code AS code
       FROM accounting_bank_accounts
      WHERE tenant_id = :t AND status = 'active'
      ORDER BY name ASC"
);
$baStmt->execute(['t' => $tid]);
$bankAccounts = $baStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

// ──────────────────────────────────────────────────────────────────
// Queue rows
// ──────────────────────────────────────────────────────────────────
$where  = ['bsl.tenant_id = :t', '(bsl.match_status IS NULL OR bsl.match_status = \'pending\')'];
$params = ['t' => $tid];
if ($bid > 0) { $where[] = 'bsl.bank_account_id = :b'; $params['b'] = $bid; }
$whereSql = implode(' AND ', $where);

$totalStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM accounting_bank_statement_lines bsl WHERE {$whereSql}"
);
$totalStmt->execute($params);
$total = (int) $totalStmt->fetchColumn();

$listStmt = $pdo->prepare(
    "SELECT bsl.id, bsl.posted_date, bsl.description, bsl.amount,
            bsl.bank_reference, bsl.fitid, bsl.match_status,
            bsl.ai_suggested_account_code, bsl.ai_suggested_confidence,
            bsl.ai_suggested_je_id, bsl.ai_suggested_rule_id, bsl.applied_rule_id,
            bsl.bank_account_id, ba.name AS bank_account_name, ba.gl_account_code AS bank_gl_code,
            DATEDIFF(CURRENT_DATE, bsl.posted_date) AS age_days
       FROM accounting_bank_statement_lines bsl
       JOIN accounting_bank_accounts ba ON ba.id = bsl.bank_account_id AND ba.tenant_id = bsl.tenant_id
      WHERE {$whereSql}
      ORDER BY {$orderBy}
      LIMIT {$limit} OFFSET {$offset}"
);
$listStmt->execute($params);
$rows = $listStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

// Coerce numerics so the UI can do clean arithmetic.
foreach ($rows as &$r) {
    $r['id']                       = (int) $r['id'];
    $r['amount']                   = (float) $r['amount'];
    $r['bank_account_id']          = (int) $r['bank_account_id'];
    $r['age_days']                 = $r['age_days'] !== null ? (int) $r['age_days'] : null;
    $r['ai_suggested_confidence']  = $r['ai_suggested_confidence'] !== null
        ? (float) $r['ai_suggested_confidence'] : null;
    $r['ai_suggested_je_id']       = $r['ai_suggested_je_id']    !== null ? (int) $r['ai_suggested_je_id']    : null;
    $r['ai_suggested_rule_id']     = $r['ai_suggested_rule_id']  !== null ? (int) $r['ai_suggested_rule_id']  : null;
    $r['applied_rule_id']          = $r['applied_rule_id']       !== null ? (int) $r['applied_rule_id']       : null;
}
unset($r);

api_ok([
    'order'         => $order,
    'limit'         => $limit,
    'offset'        => $offset,
    'total'         => $total,
    'bank_account_id' => $bid > 0 ? $bid : null,
    'bank_accounts' => array_map(static fn($b) => [
        'id'   => (int) $b['id'],
        'name' => (string) $b['name'],
        'code' => (string) ($b['code'] ?? ''),
    ], $bankAccounts),
    'rows'          => $rows,
]);
