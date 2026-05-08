<?php
/**
 * Sprint 7e.2 smoke — Transactions to Review queue + AI polish + deep-links.
 *
 * Asserts:
 *   - api/transactions_to_review.php (RBAC, GET-only, ordering, filter, paging)
 *   - module alias /api/accounting/transactions-to-review delegates correctly
 *   - TransactionsToReview.jsx renders and honours ?prefilter / ?autoload
 *   - BookkeepingOverview "Transactions to review" task row deep-links into
 *     the new queue with the exact spec query string
 *   - AccountingModule (live) + dashboard AccountingModule + App.jsx sidebar
 *     wiring all reach the new page
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

echo "Backend — api/transactions_to_review.php\n";
$api = (string) file_get_contents("{$ROOT}/api/transactions_to_review.php");
$assert('endpoint exists',                    strlen($api) > 0);
$assert('parses',                             $lint("{$ROOT}/api/transactions_to_review.php"));
$assert('GET-only',                           strpos($api, "if (api_method() !== 'GET')") !== false);
$assert('RBAC accounting.coa.view',
    strpos($api, "RBAC::requirePermission(\$user, 'accounting.coa.view')") !== false);
$assert('order param honoured',               strpos($api, "api_query('order')") !== false);
$assert('limit clamp 1..200',
    strpos($api, "max(1, min(200, (int) (api_query('limit')")  !== false);
$assert('offset >= 0',                        strpos($api, "max(0,") !== false);
$assert('order match: oldest_first',          strpos($api, 'oldest_first') !== false);
$assert('order match: newest_first',          strpos($api, 'newest_first') !== false);
$assert('order match: amount_desc',           strpos($api, 'amount_desc')  !== false);
$assert('queue filter: pending/null match_status',
    strpos($api, "(bsl.match_status IS NULL OR bsl.match_status = \\'pending\\')") !== false);
$assert('joins bank account for display',
    strpos($api, 'JOIN accounting_bank_accounts ba') !== false);
$assert('returns total count',                strpos($api, "'total'") !== false);
$assert('returns rows array',                 strpos($api, "'rows'") !== false);
$assert('returns bank_accounts list',         strpos($api, "'bank_accounts'") !== false);
$assert('row coerces id+amount to numeric',
    strpos($api, "\$r['id']                       = (int) \$r['id']") !== false
    && strpos($api, "\$r['amount']                   = (float) \$r['amount']") !== false);
$assert('age_days computed via DATEDIFF',
    strpos($api, 'DATEDIFF(CURRENT_DATE, bsl.posted_date) AS age_days') !== false);

echo "\nModule alias — /api/accounting/transactions-to-review\n";
$alias = "{$ROOT}/modules/accounting/api/transactions_to_review.php";
$assert('alias file exists',                  is_file($alias));
$assert('alias parses',                       $lint($alias));
$assert('alias delegates to root handler',
    strpos((string) file_get_contents($alias), "require __DIR__ . '/../../../api/transactions_to_review.php'") !== false);

echo "\nFrontend — TransactionsToReview.jsx\n";
$jsx = (string) file_get_contents("{$ROOT}/dashboard/src/pages/TransactionsToReview.jsx");
$assert('page file exists',                   strlen($jsx) > 0);
$assert('reads ?prefilter from URL',          strpos($jsx, "params.get('prefilter')") !== false);
$assert('reads ?autoload from URL',           strpos($jsx, "params.get('autoload') === '1'") !== false);
$assert('reads ?bank_account_id from URL',    strpos($jsx, "params.get('bank_account_id')") !== false);
$assert('hits queue endpoint',
    strpos($jsx, '/api/transactions_to_review.php?order=') !== false);
$assert('autoload triggers AI suggest on first row',
    strpos($jsx, 'autoloadFiredRef.current = true') !== false
    && strpos($jsx, 'fetchAiSuggestion(first.id)') !== false);
$assert('AI suggest endpoint',
    strpos($jsx, '/modules/accounting/api/bank_ai.php?action=suggest_categorize&line_id=') !== false);
$assert('Accept endpoint stamps history moat',
    strpos($jsx, '/modules/accounting/api/bank_statements.php?action=accept_ai_categorize&line_id=') !== false);
$assert('Skip endpoint flips match_status=ignored',
    strpos($jsx, '/modules/accounting/api/bank_statements.php?action=ignore&line_id=') !== false);
$assert('advance focus to next row after accept',
    strpos($jsx, 'const nextRow = visibleRows.find') !== false);

$ids = [
    'page', 'subtitle', 'order', 'bank-filter', 'refresh',
    'list', 'empty', 'progress', 'overview-link',
    'error', 'retry', 'action-error', 'back-overview',
];
foreach ($ids as $id) {
    $assert("testid: transactions-to-review-{$id}",
        strpos($jsx, "data-testid=\"transactions-to-review-{$id}\"") !== false);
}
$rowIds = [
    'transactions-to-review-row-${r.id}',
    'transactions-to-review-row-toggle-${r.id}',
    'transactions-to-review-row-detail-${r.id}',
    'transactions-to-review-suggest-${r.id}',
    'transactions-to-review-ai-loading-${r.id}',
    'transactions-to-review-ai-result-${r.id}',
    'transactions-to-review-ai-err-${r.id}',
    'transactions-to-review-coa-${r.id}',
    'transactions-to-review-accept-${r.id}',
    'transactions-to-review-skip-${r.id}',
    'transactions-to-review-open-bank-${r.id}',
];
foreach ($rowIds as $tpl) {
    $assert("row testid template: {$tpl}",
        strpos($jsx, "data-testid={`{$tpl}`}") !== false);
}

echo "\nDeep-link wiring — BookkeepingOverview.jsx\n";
$bk = (string) file_get_contents("{$ROOT}/dashboard/src/pages/BookkeepingOverview.jsx");
$assert('Tx-to-review row points at /modules/accounting/transactions-to-review',
    strpos($bk, '/modules/accounting/transactions-to-review') !== false);
$assert('deep-link carries prefilter=oldest_first',
    strpos($bk, 'prefilter=oldest_first') !== false);
$assert('deep-link carries autoload=1',
    strpos($bk, 'autoload=1') !== false);
$assert('deep-link still labelled "Transactions to review"',
    strpos($bk, 'label="Transactions to review"') !== false);

echo "\nWiring — Accounting modules + App sidebar\n";
$dashMod = (string) file_get_contents("{$ROOT}/dashboard/src/modules/AccountingModule.jsx");
$assert('dashboard AccountingModule imports TransactionsToReview',
    strpos($dashMod, "import TransactionsToReview from '../pages/TransactionsToReview'") !== false);
$assert('dashboard AccountingModule mounts /transactions-to-review',
    strpos($dashMod, 'path="transactions-to-review" element={<TransactionsToReview />}') !== false);
$assert('dashboard AccountingModule snake-case alias redirects',
    strpos($dashMod, 'path="transactions_to_review"') !== false
    && strpos($dashMod, 'Navigate to="../transactions-to-review"') !== false);

$liveMod = (string) file_get_contents("{$ROOT}/modules/accounting/ui/AccountingModule.jsx");
$assert('live AccountingModule (V1) imports TransactionsToReview',
    strpos($liveMod, "import TransactionsToReview from '../../../dashboard/src/pages/TransactionsToReview'") !== false);
$assert('live AccountingModule (V1) imports BookkeepingOverview',
    strpos($liveMod, "import BookkeepingOverview from '../../../dashboard/src/pages/BookkeepingOverview'") !== false);
$assert('live AccountingModule mounts /transactions-to-review route',
    strpos($liveMod, 'path="transactions-to-review" element={<TransactionsToReview />}') !== false);
$assert('live AccountingModule mounts /bookkeeping route',
    strpos($liveMod, 'path="bookkeeping" element={<BookkeepingOverview />}') !== false);
$assert('live AccountingModule sub-nav has Tx-to-Review tab',
    strpos($liveMod, '<Tab to="transactions-to-review" label="Tx to Review" />') !== false);
$assert('live AccountingModule sub-nav has Bookkeeping tab',
    strpos($liveMod, '<Tab to="bookkeeping" label="Bookkeeping" />') !== false);

$app = (string) file_get_contents("{$ROOT}/dashboard/src/App.jsx");
$assert("App sidebar carries 'Transactions to Review' nav action",
    strpos($app, "name: 'Transactions to Review'") !== false
    && strpos($app, "route: 'transactions-to-review'") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
