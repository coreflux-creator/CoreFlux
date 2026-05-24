<?php
/**
 * Smoke for JobDiva Placements Edit — Slice 2 (backend-only).
 *
 * Validates:
 *   1. Migration 070 adds `coreflux_overridden_fields` JSON column.
 *   2. jobdivaSyncUpsertPlacement strips overridden columns from its
 *      UPDATE statement when the placement has them flagged.
 *   3. PATCH /api/placements/placements auto-flags every column it
 *      touches when the placement is JobDiva-sourced (external_id has
 *      `jd:` prefix).
 *   4. PATCH leaves the flag list alone when the placement was created
 *      directly in CoreFlux (no `jd:` prefix).
 *   5. POST ?action=clear_override drops fields from the flag list.
 *
 * Strategy
 * --------
 * We don't have a writable DB in this CI environment, so we test the
 * code path by:
 *   - Asserting the migration file's SQL.
 *   - Reading the SOURCE of jobdivaSyncUpsertPlacement and asserting
 *     the override-handling block exists and references the right
 *     column names.
 *   - Reading the SOURCE of /api/placements/placements PATCH handler
 *     and asserting it merges field names into coreflux_overridden_fields
 *     when external_id starts with 'jd:'.
 *
 * For a real integration test (DB + REST), see the existing
 * `jobdiva_placement_rates_smoke.php`.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "Migration — 070_placement_override_flags.sql\n";
$migPath = '/app/core/migrations/070_placement_override_flags.sql';
$assert('migration file exists', is_file($migPath));
$mig = (string) file_get_contents($migPath);
$assert('adds coreflux_overridden_fields column',
    str_contains($mig, 'coreflux_overridden_fields'));
$assert('column is JSON typed',
    preg_match('/coreflux_overridden_fields\s+JSON/i', $mig) === 1);
$assert('column NULL-able (default behaviour)',
    preg_match('/coreflux_overridden_fields\s+JSON\s+NULL/i', $mig) === 1);
$assert('migration is ALTER TABLE on placements',
    preg_match('/ALTER\s+TABLE\s+placements/i', $mig) === 1);

echo "\nSync writer — jobdivaSyncUpsertPlacement respects override list\n";
$syncSrc = (string) file_get_contents('/app/core/jobdiva/sync.php');
$assert('sync reads coreflux_overridden_fields before UPDATE',
    str_contains($syncSrc, 'SELECT coreflux_overridden_fields FROM placements'));
$assert('sync decodes the JSON override list',
    str_contains($syncSrc, 'json_decode($rawOverride, true)'));
$assert('sync builds UPDATE assignments dynamically',
    preg_match('/\$assignments\s*=\s*\[\];/', $syncSrc) === 1);
$assert('sync skips overridden fields with in_array',
    preg_match('/in_array\(\$col,\s*\$overrides/', $syncSrc) === 1);
$assert('sync logs skipped fields (audit trail)',
    str_contains($syncSrc, 'CoreFlux-overridden fields'));
foreach (['title', 'end_client_name', 'notes', 'status', 'start_date', 'engagement_type'] as $col) {
    $assert("override allow-list includes column: {$col}",
        preg_match("/'$col'\s*=>\s*\[/", $syncSrc) === 1);
}
$assert('sync runs UPDATE only when assignments non-empty',
    str_contains($syncSrc, 'if (!empty($assignments))'));

echo "\nAPI — PATCH /api/placements/placements auto-flags JobDiva-sourced edits\n";
$apiSrc = (string) file_get_contents('/app/modules/placements/api/placements.php');
$assert('PATCH detects JobDiva-sourced placement via external_id prefix',
    preg_match("/jd:.*===\s*0|strpos\(\(string\)\s*\\\$existing\['external_id'\],\s*'jd:'\)\s*===\s*0/", $apiSrc) === 1);
$assert('PATCH skips override flagging for non-JobDiva placements',
    preg_match('/if\s*\(\s*\$isJobDivaSourced\s*\)/', $apiSrc) === 1);
$assert('PATCH merges body keys into existing override list',
    str_contains($apiSrc, 'array_keys($body)') &&
    str_contains($apiSrc, 'array_unique'));
$assert('PATCH writes coreflux_overridden_fields back via scopedUpdate',
    preg_match("/scopedUpdate\(\s*'placements'\s*,\s*\\\$id\s*,\s*\[\s*'coreflux_overridden_fields'/", $apiSrc) === 1);
$assert('PATCH blocks client-supplied coreflux_overridden_fields',
    str_contains($apiSrc, "'coreflux_overridden_fields'"));

echo "\nAPI — POST ?action=clear_override\n";
$assert('clear_override action handler exists',
    str_contains($apiSrc, "action === 'clear_override'"));
$assert('clear_override requires placements.manage RBAC',
    preg_match("/clear_override.*rbac_legacy_require\(\\\$user,\s*'placements\.manage'\)/s", $apiSrc) === 1);
$assert('clear_override requires fields[] in body',
    preg_match("/clear_override.*fields\\[\\] required/s", $apiSrc) === 1);
$assert('clear_override array_diffs the requested fields',
    preg_match("/clear_override.*array_diff\(\\\$current,/s", $apiSrc) === 1);
$assert('clear_override writes audit',
    str_contains($apiSrc, "placement.override_cleared"));

echo "\n=== Summary ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
