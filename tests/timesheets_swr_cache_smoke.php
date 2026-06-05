<?php
/**
 * Smoke — LP-001 SWR cache for Timesheets list.
 *
 * Locks the contract added by P3-B:
 *   1. /app/dashboard/src/lib/api.js exports:
 *        - useApiCached(path, options)
 *        - bustApiCache(key)
 *        - peekApiCache(key)
 *      and still exports the original useApi (no regression).
 *   2. The cache implementation has the three SWR primitives we promised:
 *        - in-memory module-scoped Map
 *        - in-flight Promise dedup
 *        - TTL + stale-while-revalidate semantics
 *   3. /app/modules/staffing/ui/TimesheetsList.jsx wires useApiCached
 *      with a stable cacheKey keyed by query string.
 *
 * Run: php -d zend.assertions=1 /app/tests/timesheets_swr_cache_smoke.php
 */
declare(strict_types=1);

$passes  = 0;
$failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nLP-001 — Timesheets SWR cache smoke\n";
echo "===================================\n\n";

$apiPath  = __DIR__ . '/../dashboard/src/lib/api.js';
$listPath = __DIR__ . '/../modules/staffing/ui/TimesheetsList.jsx';

echo "── api.js exports ──\n";
check('api.js exists', is_file($apiPath));
$src = is_file($apiPath) ? file_get_contents($apiPath) : '';
check('still exports useApi (no regression)',    str_contains($src, 'export function useApi('));
check('exports useApiCached',                    str_contains($src, 'export function useApiCached('));
check('exports bustApiCache',                    str_contains($src, 'export function bustApiCache('));
check('exports peekApiCache',                    str_contains($src, 'export function peekApiCache('));

echo "\n── SWR primitives present ──\n";
check('module-scoped cache Map declared',        str_contains($src, '__apiCache    = new Map()')
                                              || str_contains($src, '__apiCache = new Map()'));
check('in-flight dedup Map declared',            str_contains($src, '__apiInflight = new Map()'));
check('in-flight dedup helper _fetchDeduped',    str_contains($src, 'function _fetchDeduped('));
check('cache writes carry a timestamp',          preg_match('/__apiCache\.set\([^,]+,\s*\{\s*data[^}]*,\s*ts:\s*Date\.now\(\)/', $src) === 1);
check('default TTL is 30000 ms',                 str_contains($src, 'ttlMs = 30000'));
check('default revalidateOnMount = true',        str_contains($src, 'revalidateOnMount = true'));

echo "\n── useApiCached behavior ──\n";
check('cache hit primes initial data state',
    preg_match('/useState\(\s*cached\s*\?\s*cached\.data\s*:\s*null\s*\)/', $src) === 1);
check('loading=false when cache hit on mount',
    preg_match('/useState\(\s*Boolean\(enabled\)\s*&&\s*!cached\s*\)/', $src) === 1);
check('mutate writes through to the cache',
    preg_match('/__apiCache\.set\(\s*key\s*,\s*\{\s*data:\s*next\s*,\s*ts:\s*Date\.now\(\)/', $src) === 1);
check('reload busts the entry before reloading',
    preg_match('/__apiCache\.delete\(\s*key\s*\)/', $src) === 1);
check('bustApiCache(undefined) clears whole cache',
    preg_match('/__apiCache\.clear\(\)/', $src) === 1);

echo "\n── TimesheetsList wiring ──\n";
check('TimesheetsList.jsx exists', is_file($listPath));
$listSrc = is_file($listPath) ? file_get_contents($listPath) : '';
check('imports useApiCached',
    str_contains($listSrc, "import { useApiCached }") || preg_match('/import\s*\{[^}]*useApiCached[^}]*\}/', $listSrc) === 1);
check('no longer imports plain useApi',
    !preg_match('/import\s*\{\s*useApi\s*\}/', $listSrc));
check('calls useApiCached with timesheets path',
    preg_match('/useApiCached\(\s*[^,]*timesheets\.php[^,]*,\s*\{/', $listSrc) === 1
    || preg_match('/useApiCached\(\s*timesheetsPath\s*,\s*\{/', $listSrc) === 1);
check('passes a cacheKey scoped to the query',
    preg_match('/cacheKey:\s*[`\'"]timesheets-list:/', $listSrc) === 1);
check('still surfaces loading + error + reload',
    preg_match('/\{\s*data\s*,\s*loading\s*,\s*error\s*,\s*reload\s*\}\s*=\s*useApiCached/', $listSrc) === 1);

echo "\n── Vite bundle synced ──\n";
$versionPath = __DIR__ . '/../.deploy-version';
if (is_file($versionPath)) {
    $ver = trim(file_get_contents($versionPath));
    check('.deploy-version is non-empty', $ver !== '');
} else {
    check('.deploy-version present (skipped — not used in this env)', true);
}

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "timesheets_swr_cache smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
if ($failures) {
    foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
    exit(1);
}
exit(0);
