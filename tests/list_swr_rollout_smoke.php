<?php
/**
 * Smoke — useApiCached rollout to high-traffic list pages.
 *
 * Locks that Placements / AP Bills / Billing Invoices list pages have
 * been migrated from `useApi` to `useApiCached` with a scoped cache key
 * keyed off the full filter-encoded path. Each page must still wire
 * { data, loading, error, reload } so optimistic mutations and refresh
 * buttons keep working.
 *
 * Run: php -d zend.assertions=1 /app/tests/list_swr_rollout_smoke.php
 */
declare(strict_types=1);

$passes  = 0;
$failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nuseApiCached rollout smoke (Placements / AP Bills / Billing Invoices)\n";
echo "=====================================================================\n\n";

$pages = [
    'placements' => [
        'path'   => __DIR__ . '/../modules/placements/ui/List.jsx',
        'prefix' => 'placements-list:',
    ],
    'ap_bills' => [
        'path'   => __DIR__ . '/../modules/ap/ui/BillsList.jsx',
        'prefix' => 'ap-bills-list:',
    ],
    'billing_invoices' => [
        'path'   => __DIR__ . '/../modules/billing/ui/InvoicesList.jsx',
        'prefix' => 'billing-invoices-list:',
    ],
];

foreach ($pages as $name => $cfg) {
    echo "── {$name} ──\n";
    check("{$name} file exists", is_file($cfg['path']));
    $src = is_file($cfg['path']) ? file_get_contents($cfg['path']) : '';

    check("{$name} imports useApiCached",         str_contains($src, 'useApiCached'));
    check("{$name} no longer imports useApi",
        // Must not have a bare `useApi` import token (without 'Cached' suffix).
        preg_match('/import\s*\{[^}]*\buseApi\b(?!Cached)[^}]*\}\s*from/', $src) !== 1);
    check("{$name} calls useApiCached(path, …)",
        preg_match('/useApiCached\(\s*path\s*,\s*\{/', $src) === 1);
    check("{$name} uses scoped cacheKey prefix '" . $cfg['prefix'] . "'",
        str_contains($src, "cacheKey: `" . $cfg['prefix']));
    check("{$name} still surfaces { data, loading, error, reload }",
        preg_match('/\{\s*data\s*,\s*loading\s*,\s*error\s*(?:,\s*elapsedMs\s*)?,\s*reload\s*\}\s*=\s*useApiCached/', $src) === 1);
    echo "\n";
}

echo "── api.js still exports both hooks ──\n";
$apiSrc = file_get_contents(__DIR__ . '/../dashboard/src/lib/api.js');
check('useApi (legacy) still exported',  str_contains($apiSrc, 'export function useApi('));
check('useApiCached still exported',     str_contains($apiSrc, 'export function useApiCached('));

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "list_swr_rollout smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
if ($failures) {
    foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
    exit(1);
}
exit(0);
