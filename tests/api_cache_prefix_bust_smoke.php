<?php
/**
 * Smoke — useApiCached mutation-side prefix invalidation.
 *
 * Locks two things:
 *   1. api.js exposes a prefix-busting helper (bustApiCachePrefix) and
 *      bustApiCache accepts a predicate function for ad-hoc busts.
 *   2. The four list-page mutation flows call bustApiCachePrefix with
 *      their scoped prefix before reload():
 *        - placements (bulk status change)
 *        - ap bills   (new from time-bundle / time-entries / suggest run)
 *        - billing invoices (new from time-bundle / time-entries)
 *        - timesheets (submit from week, approve/reject/reopen from detail)
 *
 * Run: php -d zend.assertions=1 /app/tests/api_cache_prefix_bust_smoke.php
 */
declare(strict_types=1);

$passes  = 0;
$failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nuseApiCached prefix-bust smoke\n";
echo "==============================\n\n";

$apiSrc = file_get_contents(__DIR__ . '/../dashboard/src/lib/api.js');

echo "── api.js prefix-bust helpers ──\n";
check('exports bustApiCachePrefix',            str_contains($apiSrc, 'export function bustApiCachePrefix('));
check('bustApiCache accepts a predicate fn',   str_contains($apiSrc, "typeof keyOrPredicate === 'function'"));
check('predicate path iterates cache keys',    str_contains($apiSrc, 'Array.from(__apiCache.keys())'));
check('prefix helper short-circuits on empty', str_contains($apiSrc, "prefix === ''"));
check('prefix helper calls bustApiCache(fn)',  preg_match('/bustApiCache\(\s*\(k\)\s*=>/', $apiSrc) === 1);
check('prefix predicate startsWith check',     str_contains($apiSrc, 'k.startsWith(prefix)'));

echo "\n── mutation wiring ──\n";

$cases = [
    'placements bulk status'   => ['/app/modules/placements/ui/List.jsx',          'placements-list:'],
    'ap bills modals'          => ['/app/modules/ap/ui/BillsList.jsx',             'ap-bills-list:'],
    'billing invoices modals'  => ['/app/modules/billing/ui/InvoicesList.jsx',     'billing-invoices-list:'],
    'timesheet submit (week)'  => ['/app/modules/staffing/ui/TimesheetWeek.jsx',   'timesheets-list:'],
    'timesheet detail actions' => ['/app/modules/staffing/ui/TimesheetDetail.jsx', 'timesheets-list:'],
];

foreach ($cases as $label => [$path, $prefix]) {
    $src = is_file($path) ? file_get_contents($path) : '';
    check("{$label}: imports bustApiCachePrefix", str_contains($src, 'bustApiCachePrefix'));
    check("{$label}: calls bustApiCachePrefix('{$prefix}')",
        str_contains($src, "bustApiCachePrefix('" . $prefix . "')"));
}

echo "\n── timesheet detail covers all status-changing actions ──\n";
$detail = file_get_contents('/app/modules/staffing/ui/TimesheetDetail.jsx');
// The shared `act()` helper wraps submit/approve/reject/etc — one bust there
// covers all of them. reopenForEdit is the other independent mutation.
check("act() helper busts the prefix",
    preg_match('/const act = async.*?bustApiCachePrefix\(.*?timesheets-list:/s', $detail) === 1);
check("reopenForEdit busts the prefix",
    preg_match('/reopenForEdit\s*=\s*async.*?bustApiCachePrefix\(.*?timesheets-list:/s', $detail) === 1);

echo "\n── ap bills covers all three modals ──\n";
$ap = file_get_contents('/app/modules/ap/ui/BillsList.jsx');
check("BillFromTimeBundleModal onCreated busts",
    preg_match('/BillFromTimeBundleModal.*?onCreated.*?bustApiCachePrefix\(.*?ap-bills-list:/s', $ap) === 1);
check("BillFromTimeEntriesModal onCreated busts",
    preg_match('/BillFromTimeEntriesModal.*?onCreated.*?bustApiCachePrefix\(.*?ap-bills-list:/s', $ap) === 1);
check("SuggestPaymentRunModal onCreated busts",
    preg_match('/SuggestPaymentRunModal.*?onCreated.*?bustApiCachePrefix\(.*?ap-bills-list:/s', $ap) === 1);

echo "\n── billing invoices covers both modals ──\n";
$bi = file_get_contents('/app/modules/billing/ui/InvoicesList.jsx');
check("InvoiceFromTimeBundleModal onCreated busts",
    preg_match('/InvoiceFromTimeBundleModal.*?onCreated.*?bustApiCachePrefix\(.*?billing-invoices-list:/s', $bi) === 1);
check("InvoiceFromTimeEntriesModal onCreated busts",
    preg_match('/InvoiceFromTimeEntriesModal.*?onCreated.*?bustApiCachePrefix\(.*?billing-invoices-list:/s', $bi) === 1);

echo "\n── api.js: useApi + useApiCached + helpers all still exported ──\n";
check('useApi still exported',        str_contains($apiSrc, 'export function useApi('));
check('useApiCached still exported',  str_contains($apiSrc, 'export function useApiCached('));
check('bustApiCache still exported',  str_contains($apiSrc, 'export function bustApiCache('));
check('peekApiCache still exported',  str_contains($apiSrc, 'export function peekApiCache('));

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "api_cache_prefix_bust smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
if ($failures) {
    foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
    exit(1);
}
exit(0);
