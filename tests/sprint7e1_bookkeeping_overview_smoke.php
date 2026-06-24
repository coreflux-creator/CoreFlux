<?php
/**
 * Sprint 7e.1 smoke — Bookkeeping Overview (Layer-style books-health snapshot).
 *
 * Asserts:
 *   - api/books_health.php is RBAC-gated and returns the spec-shaped envelope
 *   - module alias mounted at /api/v1/accounting/books-health
 *   - BookkeepingOverview.jsx page renders + carries every test id
 *   - Wired into AccountingModule routes + sidebar
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

echo "Backend — api/books_health.php\n";
$api = (string) file_get_contents("{$ROOT}/api/books_health.php");
$assert('endpoint exists',                       strlen($api) > 0);
$assert('parses',                                $lint("{$ROOT}/api/books_health.php"));
$assert('GET-only',                              strpos($api, "if (api_method() !== 'GET')") !== false);
$assert('RBAC accounting.coa.view',              strpos($api, "rbac_legacy_require(\$user, 'accounting.coa.view')") !== false);
$assert('returns bank_connections envelope',     strpos($api, "'bank_connections'") !== false);
$assert('returns reconciliation envelope',       strpos($api, "'reconciliation'") !== false);
$assert('returns uncategorized envelope',        strpos($api, "'uncategorized'") !== false);
$assert('returns tasks envelope',                strpos($api, "'tasks'") !== false);
$assert('returns 6-month pl_monthly array',      strpos($api, "'pl_monthly'") !== false
                                               && strpos($api, "for (\$i = 5; \$i >= 0; \$i--)") !== false);
$assert('returns recent_events',                 strpos($api, "'recent_events'") !== false);
$assert('returns ai_assist envelope',            strpos($api, "'ai_assist'") !== false);
$assert('ai_assist counts last 7d outcomes accepted',
    strpos($api, "outcome IN ('accepted','auto_applied')") !== false
    && strpos($api, "INTERVAL 7 DAY") !== false);
$assert('ai_assist hours_saved math (n × 30s)',
    strpos($api, '$n7 * 0.5 / 60') !== false);
$assert('ai_assist graceful when ai_interactions absent',
    strpos($api, "/* table absent on pre-AI tenants — fine */") !== false);
$assert('graceful when accounting_events absent',
    strpos($api, "SHOW TABLES LIKE 'accounting_events'") !== false);
$assert('graceful when accounting_reconciliations absent',
    strpos($api, "SHOW TABLES LIKE 'accounting_reconciliations'") !== false);
$assert('graceful when ap_bills absent',         strpos($api, "SHOW TABLES LIKE 'ap_bills'") !== false);
$assert('graceful when treasury_payments absent',strpos($api, "SHOW TABLES LIKE 'treasury_payments'") !== false);
$assert('graceful when treasury_transfers absent',strpos($api, "SHOW TABLES LIKE 'treasury_transfers'") !== false);
$assert('health_score floored at 0',             strpos($api, '$score = max(0, $score)') !== false);
$assert('label thresholds 90/75/50',
    strpos($api, "\$score >= 90") !== false
    && strpos($api, "\$score >= 75") !== false
    && strpos($api, "\$score >= 50") !== false);
$assert('health_reasons enumerable',
    strpos($api, "'no_active_bank'") !== false
    && strpos($api, "'recon_behind_60d'") !== false
    && strpos($api, "'many_uncategorized'") !== false
    && strpos($api, "'period_overdue_close'") !== false);
$assert('PL pulls posted JEs only',              strpos($api, "je.status = 'posted'") !== false);
$assert('PL groups by month + account_type',     strpos($api, "GROUP BY month, a.account_type") !== false);
$assert('PL covers full account-type spread',
    strpos($api, "'revenue','expense','contra_revenue','cost_of_goods_sold','other_income','other_expense'") !== false);

echo "\nModule alias — /api/v1/accounting/books-health\n";
$alias = "{$ROOT}/modules/accounting/api/books_health.php";
$assert('alias file exists',                     is_file($alias));
$assert('alias parses',                          $lint($alias));
$assert('alias delegates to root handler',
    strpos((string) file_get_contents($alias), 'require __DIR__ . \'/../../../api/books_health.php\'') !== false);

echo "\nFrontend — BookkeepingOverview.jsx\n";
$jsx = (string) file_get_contents("{$ROOT}/dashboard/src/pages/BookkeepingOverview.jsx");
$assert('page file exists',                      strlen($jsx) > 0);
$assert('hits v1 books-health endpoint',
    strpos($jsx, "const BOOKS_HEALTH_API = '/api/v1/accounting/books-health'") !== false
    && strpos($jsx, 'useApi(BOOKS_HEALTH_API)') !== false);
$assert('top-level testid',                      strpos($jsx, 'data-testid="bookkeeping-overview-page"') !== false);
$assert('error testid',                          strpos($jsx, 'data-testid="bookkeeping-overview-error"') !== false);
$assert('loading testid',                        strpos($jsx, 'data-testid="bookkeeping-overview-loading"') !== false);
$assert('refresh button testid',                 strpos($jsx, 'data-testid="bookkeeping-overview-refresh"') !== false);

$ids = [
    'health-card', 'health-score', 'health-label', 'health-reasons',
    'pl-chart', 'recent-events', 'recent-empty',
    'tasks-card', 'banks-card', 'banks-active', 'last-reconciled',
    'period-card', 'period-status',
    'connect-bank', 'connect-bank-cta',
    'saved-hours-card', 'saved-hours-value', 'saved-hours-detail', 'saved-hours-cta',
];
foreach ($ids as $id) {
    $assert("testid: bookkeeping-overview-{$id}", strpos($jsx, "data-testid=\"bookkeeping-overview-{$id}\"") !== false);
}
foreach (['task-tx-review', 'task-bills', 'task-payments', 'task-transfers', 'task-period-close'] as $id) {
    $assert("task row testid: {$id}",            strpos($jsx, "testId=\"{$id}\"") !== false);
}
$assert('PL bar testid template',                strpos($jsx, 'data-testid={`bookkeeping-overview-pl-bar-${m.month}`}') !== false);

echo "\nWiring — Accounting module + App sidebar\n";
$mod = (string) file_get_contents("{$ROOT}/dashboard/src/modules/AccountingModule.jsx");
$assert('imports BookkeepingOverview',           strpos($mod, "import BookkeepingOverview from '../pages/BookkeepingOverview'") !== false);
$assert('Route mounted at /bookkeeping',         strpos($mod, 'path="bookkeeping" element={<BookkeepingOverview />}') !== false);
$assert('books-health alias route navigates to bookkeeping',
    strpos($mod, 'path="books-health"') !== false
    && strpos($mod, 'Navigate to="../bookkeeping"') !== false);
$assert('Quick-actions tile present',            strpos($mod, "title=\"Bookkeeping overview\"") !== false);

$app = (string) file_get_contents("{$ROOT}/dashboard/src/App.jsx");
$assert("'Bookkeeping Overview' nav action",     strpos($app, "name: 'Bookkeeping Overview'") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
