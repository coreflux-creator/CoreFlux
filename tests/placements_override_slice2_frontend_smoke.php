<?php
/**
 * Smoke for the Slice 2 frontend affordances in PlacementDetail.jsx.
 *
 * Verifies the JSX source contains all the elements the backend Slice 2
 * code expects to be exercised by:
 *   • OverridePill component (the orange badge).
 *   • parseOverrides + isJobDivaSourced helpers.
 *   • Per-field badge rendering in OverviewTab AND OverviewEdit.
 *   • "Revert to JobDiva" button wired to clear_override endpoint.
 *   • The JobDiva-only banner in OverviewEdit explaining the behaviour.
 *
 * Plus: confirms the post-build sync ran (.deploy-version updated) so
 * the bundle hashes in /app/dashboard/dist match what /app/spa-assets/
 * serves — the recurring "Vite Frontend Build & Version Drift" issue
 * from the handoff notes.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "PlacementDetail.jsx — Slice 2 affordances\n";
$src = (string) file_get_contents('/app/modules/placements/ui/PlacementDetail.jsx');
$assert('parseOverrides() helper exists',                str_contains($src, 'function parseOverrides(placement)'));
$assert('parseOverrides handles non-JSON column gracefully',
    str_contains($src, "Array.isArray(parsed) ? parsed.map(String)"));
$assert('isJobDivaSourced() helper exists',              str_contains($src, 'function isJobDivaSourced(placement)'));
$assert('isJobDivaSourced checks "jd:" prefix',          str_contains($src, "ext.startsWith('jd:')"));
$assert('OverridePill component defined',                str_contains($src, 'function OverridePill'));
$assert('OverridePill uses overridden testid pattern',   str_contains($src, 'override-pill-${field.replace(/_/g, '));
$assert('Pill carries a helpful tooltip',                str_contains($src, 'no longer synced from JobDiva'));

echo "\nOverviewTab — read-only display surfaces the pill\n";
$assert('OverviewTab parses overrides up front',         str_contains($src, 'const overrides = parseOverrides(placement);'));
$assert('OverviewTab only shows pill for JobDiva-sourced placements',
    str_contains($src, 'fromJD && field && overrides.has(field)'));
$assert('OverviewTab passes field key on every Item that can be overridden',
    str_contains($src, 'field="title"') &&
    str_contains($src, 'field="status"') &&
    str_contains($src, 'field="engagement_type"') &&
    str_contains($src, 'field="end_client_name"'));

echo "\nOverviewEdit — revert affordance + banner\n";
$assert('OverviewEdit has the JobDiva info banner',      str_contains($src, "overview-edit-jd-banner"));
$assert('banner explains the "edit → overridden" flow',  str_contains($src, 'skipped by future JobDiva syncs'));
$assert('OverviewEdit keeps a local override state',     str_contains($src, 'const [overrides, setOverrides] = useState'));
$assert('revert() POSTs to clear_override action',
    str_contains($src, '?id=${placement.id}&action=clear_override') &&
    str_contains($src, '{ fields: [field] }'));
$assert('revert updates local override state from server response',
    str_contains($src, 'setOverrides(parseOverrides(resp?.placement ?? {}))'));
$assert('RevertControl renders nothing for non-JD or non-overridden fields',
    str_contains($src, 'if (!fromJD || !overrides.has(field)) return null;'));
$assert('RevertControl emits revert-<field> testid',
    str_contains($src, 'revert-${field.replace(/_/g, '));
$assert('RevertControl text reads "Revert to JobDiva"', str_contains($src, 'Revert to JobDiva'));
$assert('RevertControl rendered for the three explicit selects + the two .map() blocks (5 JSX usages)',
    substr_count($src, '<RevertControl field=') >= 5);

echo "\nBundle sync (Vite version drift guard)\n";
$dv  = (string) @file_get_contents('/app/.deploy-version');
$assert('.deploy-version exists',                       $dv !== '');
$assert('.deploy-version mentions expected_bundle',     str_contains($dv, 'expected_bundle'));
$assert('spa-assets directory exists',                  is_dir('/app/spa-assets'));
$assert('dashboard/dist/index.html was rebuilt',        is_file('/app/dashboard/dist/index.html'));

echo "\n=== Summary ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
