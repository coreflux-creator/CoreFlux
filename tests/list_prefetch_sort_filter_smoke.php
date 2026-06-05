<?php
/**
 * Smoke — prefetch-on-hover + sort + filter + MM/DD/YYYY date format
 * across the four list pages (Timesheets, Placements, AP Bills,
 * Billing Invoices).
 *
 * Locks the contract added in this session:
 *   1. api.js exports prefetchApi(path, cacheKey) with cache-hit
 *      short-circuit and swallowed errors.
 *   2. dashboard/src/lib/formatDate.js exports fmtDate / fmtDateTime
 *      that always produce MM/DD/YYYY with no timezone math.
 *   3. dashboard/src/lib/useTableList.jsx exports useTableList +
 *      SortIndicator with the documented client-side filter+sort
 *      semantics (search filter, dateKeys, numericKeys).
 *   4. Each of the four list pages:
 *        - imports prefetchApi, useTableList, fmtDate
 *        - wires onMouseEnter prefetch on its row link
 *        - swaps rows.map → items.map (the sorted/filtered output)
 *        - renders dates through fmtDate
 *        - declares at least one sortable header via headerProps()
 *
 * Run: php -d zend.assertions=1 /app/tests/list_prefetch_sort_filter_smoke.php
 */
declare(strict_types=1);

$passes  = 0;
$failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nPrefetch + sort + filter + MM/DD/YYYY smoke\n";
echo "===========================================\n\n";

$apiSrc       = file_get_contents(__DIR__ . '/../dashboard/src/lib/api.js');
$fmtSrc       = file_get_contents(__DIR__ . '/../dashboard/src/lib/formatDate.js');
$tableListSrc = file_get_contents(__DIR__ . '/../dashboard/src/lib/useTableList.jsx');

echo "── api.js: prefetchApi ──\n";
check('exports prefetchApi',                str_contains($apiSrc, 'export function prefetchApi('));
check('prefetchApi swallows errors',        str_contains($apiSrc, '.catch(() => undefined)'));
check('prefetchApi short-circuits on hit',  preg_match('/const hit = __apiCache\.get\(key\);\s*if \(hit\) return Promise\.resolve/', $apiSrc) === 1);
check('prefetchApi delegates to _fetchDeduped', str_contains($apiSrc, '_fetchDeduped(path, key)'));

echo "\n── formatDate.js: MM/DD/YYYY contract ──\n";
check('exports fmtDate',                    str_contains($fmtSrc, 'export function fmtDate('));
check('exports fmtDateTime',                str_contains($fmtSrc, 'export function fmtDateTime('));
check('strict YYYY-MM-DD regex (no TZ math)', str_contains($fmtSrc, '/^(\d{4})-(\d{2})-(\d{2})/'));
check('zero-pads mm and dd to width 2',     preg_match("/String\(mm\)\.padStart\(2, '0'\)/", $fmtSrc) === 1);
check('returns em-dash fallback for empty', str_contains($fmtSrc, "EM_DASH = '—'"));
check('output order: mm/dd/yyyy',           str_contains($fmtSrc, '${String(mm).padStart(2, \'0\')}/${String(dd).padStart(2, \'0\')}/${yyyy}'));
check('handles Date instances',             str_contains($fmtSrc, 'input instanceof Date'));
check('fmtDateTime extracts HH:MM',         str_contains($fmtSrc, '${datePart} ${m[4]}:${m[5]}'));

echo "\n── useTableList.jsx: shape ──\n";
check('exports useTableList',               str_contains($tableListSrc, 'export function useTableList('));
check('exports SortIndicator',              str_contains($tableListSrc, 'export function SortIndicator('));
check('returns items + sort state + search', str_contains($tableListSrc, 'return { items, sortKey, sortDir, toggleSort, search, setSearch, headerProps'));
check('search applies OR across searchKeys', str_contains($tableListSrc, 'searchKeys.some'));
check('toggleSort flips direction on same column',
    preg_match('/setSortDir\(d => \(d === \'asc\' \? \'desc\' : \'asc\'\)\)/', $tableListSrc) === 1);
check('toggleSort resets to asc on new column', preg_match("/setSortDir\('asc'\)/", $tableListSrc) === 1);
check('null/undefined sort last',           preg_match('/if \(av == null && bv == null\) return 0/', $tableListSrc) === 1);
check('dateKeys path uses lexicographic slice(0,10)', str_contains($tableListSrc, '.slice(0, 10)'));
check('numericKeys path coerces with Number()', str_contains($tableListSrc, 'Number(av)'));
check('headerProps sets aria-sort',         str_contains($tableListSrc, "'aria-sort'"));
check('headerProps keyboard accessible',    str_contains($tableListSrc, "e.key === 'Enter'"));

echo "\n── per-list wiring ──\n";

$lists = [
    'timesheets' => [
        'path'       => __DIR__ . '/../modules/staffing/ui/TimesheetsList.jsx',
        'prefetch'   => 'timesheets-detail:',
        'sort_keys'  => ['period_start', 'total_hours', 'status'],
        'search'     => 'timesheets-list-search',
    ],
    'ap_bills' => [
        'path'       => __DIR__ . '/../modules/ap/ui/BillsList.jsx',
        'prefetch'   => 'ap-bill-detail:',
        'sort_keys'  => ['bill_date', 'due_date', 'total'],
        'search'     => 'ap-bills-search',
    ],
    'billing_invoices' => [
        'path'       => __DIR__ . '/../modules/billing/ui/InvoicesList.jsx',
        'prefetch'   => 'billing-invoice-detail:',
        'sort_keys'  => ['issue_date', 'due_date', 'total'],
        'search'     => 'billing-invoices-search',
    ],
    'placements' => [
        'path'       => __DIR__ . '/../modules/placements/ui/List.jsx',
        'prefetch'   => 'placement-detail:',
        'sort_keys'  => ['start_date', 'due_date', 'status'],
        'search'     => null, // server-side q= already handles search
    ],
];

foreach ($lists as $name => $cfg) {
    echo "── {$name} ──\n";
    $src = is_file($cfg['path']) ? file_get_contents($cfg['path']) : '';
    check("{$name}: imports prefetchApi",            str_contains($src, 'prefetchApi'));
    check("{$name}: imports useTableList",           str_contains($src, 'useTableList'));
    check("{$name}: imports SortIndicator",          str_contains($src, 'SortIndicator'));
    check("{$name}: imports fmtDate",                str_contains($src, 'fmtDate'));
    check("{$name}: calls useTableList(rows, …)",
        preg_match('/useTableList\(\s*rows\s*,\s*\{/', $src) === 1);
    check("{$name}: row Link uses onMouseEnter prefetch",
        preg_match('/onMouseEnter=\{\(\) => prefetchApi\(/', $src) === 1);
    check("{$name}: prefetch uses scoped cacheKey '" . $cfg['prefetch'] . "'",
        str_contains($src, '`' . $cfg['prefetch']));
    check("{$name}: renders dates through fmtDate(",  preg_match('/fmtDate\(\s*[a-zA-Z_.]+\.[a-zA-Z_]+/', $src) === 1);
    check("{$name}: maps the sorted items array",     str_contains($src, 'items.map'));
    foreach ($cfg['sort_keys'] as $key) {
        check("{$name}: header sortable by '{$key}'",
            str_contains($src, "headerProps('{$key}'"));
    }
    if ($cfg['search']) {
        check("{$name}: search input data-testid '{$cfg['search']}'",
            str_contains($src, "data-testid=\"{$cfg['search']}\""));
    }
    echo "\n";
}

$total = $passes + count($failures);
echo "=========================================\n";
echo "list_prefetch_sort_filter smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
if ($failures) {
    foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
    exit(1);
}
exit(0);
