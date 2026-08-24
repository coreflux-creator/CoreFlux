<?php
/**
 * Universal list tools — static integration smoke.
 *
 * The interactive behavior is covered by the browser workflow used during
 * development; this test locks the shared mount, safety boundaries, controls,
 * and production-bundle wiring into CI.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { echo "  ✓ {$name}\n"; $pass++; }
    else     { echo "  ✗ {$name}\n"; $fail++; }
};
$root = realpath(__DIR__ . '/..');

$componentPath = "{$root}/dashboard/src/components/UniversalListTools.jsx";
$utilsPath     = "{$root}/dashboard/src/lib/listTableUtils.js";
$appPath       = "{$root}/dashboard/src/App.jsx";
$cssPath       = "{$root}/dashboard/src/styles.css";

echo "Universal list workspace files\n";
foreach ([$componentPath, $utilsPath, $appPath, $cssPath] as $path) {
    $assert(str_replace($root . DIRECTORY_SEPARATOR, '', $path), is_file($path));
}

$component = (string) file_get_contents($componentPath);
$utils     = (string) file_get_contents($utilsPath);
$app       = (string) file_get_contents($appPath);
$css       = (string) file_get_contents($cssPath);

echo "\nApp-wide mount\n";
$assert('App imports UniversalListTools',
    str_contains($app, "import UniversalListTools from './components/UniversalListTools';"));
$assert('App mounts tools with the current route key',
    str_contains($app, '<UniversalListTools routeKey={location.pathname} />'));
$assert('discovers standard data tables under main',
    str_contains($component, "document.querySelectorAll('main table.data-table')"));
$assert('reacts to asynchronously rendered tables',
    str_contains($component, 'new MutationObserver(scheduleScan)'));

echo "\nUser-facing controls\n";
foreach ([
    'list-tools-search',
    'list-tools-filter-button',
    'list-tools-columns-button',
    'list-tools-export',
] as $testId) {
    $assert("testid: {$testId}", str_contains($component, "data-testid=\"{$testId}\""));
}
$assert('supports select-all and selected row exports',
    str_contains($component, 'toggleAllVisible')
    && str_contains($component, "selectedRows.length > 0 ? selectedRows"));
$assert('persists per-list column preferences',
    str_contains($component, "cf:list-columns:")
    && str_contains($component, 'window.localStorage.setItem'));
$assert('reset restores the source row order',
    str_contains($component, 'ownsDomSortRef.current')
    && str_contains($component, 'originalOrderRef.current.get'));

echo "\nCompatibility boundaries\n";
$assert('editable grids are excluded',
    str_contains($component, 'input:not([type="checkbox"]):not([type="hidden"])')
    && str_contains($component, '[contenteditable="true"]'));
$assert('dialogs, drawers, details, and forms are excluded by default',
    str_contains($component, 'dialog, [role="dialog"], .modal, .drawer, details, form'));
$assert('tables can explicitly opt in or out',
    str_contains($component, "table.dataset.listTools === 'off'")
    && str_contains($component, "table.dataset.listTools !== 'on'"));
$assert('native first-column selection controls are respected',
    str_contains($component, 'tbody td:first-child input[type="checkbox"]'));
$assert('existing sortable React headers are respected',
    str_contains($component, 'useTableList keep their server/client sorting'));

echo "\nSort and CSV utilities\n";
$assert('natural numeric/date sort helper exists',
    str_contains($utils, 'export function sortableCellValue')
    && str_contains($utils, 'export function compareCellText'));
$assert('empty values remain last in both directions',
    str_contains($utils, "if (a.type === 'empty') return 1;"));
$assert('CSV values are quoted and double-quotes escaped',
    str_contains($utils, 'export function escapeCsvCell')
    && str_contains($utils, "text.replace(/\"/g, '\"\"')"));
$assert('CSV export includes UTF-8 BOM for Excel compatibility',
    str_contains($utils, 'return `\\uFEFF${lines.join'));

echo "\nResponsive styling and production bundle\n";
$assert('toolbar and selectable-row styles exist',
    str_contains($css, '.cf-list-tools {')
    && str_contains($css, '.cf-list-table--selectable'));
$assert('mobile toolbar layout exists',
    str_contains($css, '@media (max-width: 640px)'));

$dist = (string) file_get_contents("{$root}/dashboard/dist/index.html");
preg_match('/spa-assets\/(index-[A-Za-z0-9_-]+\.js)/', $dist, $bundleMatch);
$bundle = $bundleMatch[1] ?? '';
$assert('built dashboard references a JS bundle', $bundle !== '');
$assert('synced production bundle exists', $bundle !== '' && is_file("{$root}/spa-assets/{$bundle}"));
if ($bundle !== '' && is_file("{$root}/spa-assets/{$bundle}")) {
    $built = (string) file_get_contents("{$root}/spa-assets/{$bundle}");
    $assert('production bundle contains the list search UI', str_contains($built, 'Search this list'));
} else {
    $assert('production bundle contains the list search UI', false);
}

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
