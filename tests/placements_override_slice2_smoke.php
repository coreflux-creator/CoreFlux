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
 * Native edits, approval-contact pairing, explicit handback and actual source
 * refresh run against MariaDB in `placement_native_edit_db_check.php`.
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
    preg_match(
        '/SELECT\s+[^;]*coreflux_overridden_fields[^;]*FROM\s+placements/is',
        $syncSrc
    ) === 1);
$assert('sync distinguishes JSON ownership lists from objects',
    str_contains($syncSrc, 'json_decode($rawOverride)') &&
    str_contains($syncSrc, '!is_array($overrides) || !array_is_list($overrides)'));
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
$editSrc = (string) file_get_contents('/app/modules/placements/lib/edit.php');
$assert('PATCH uses the same atomic edit as bulk operations',
    str_contains($apiSrc, "placementApplyNativeEdit(\$id, \$body, \$user, 'patch_status')"));
$assert('native edit checks JobDiva origin and validates stored ownership',
    str_contains($editSrc, "str_starts_with((string) (\$existing['external_id'] ?? ''), 'jd:')")
    && str_contains($editSrc, "placementOwnershipFields(\$existing['coreflux_overridden_fields'] ?? null)"));
$assert('native values and ownership share one locked update',
    str_contains($editSrc, 'placementWithLockedRecord(')
    && str_contains($editSrc, "scopedUpdate('placements', \$id, \$patch)"));
$assert('native-only placements retain their existing ownership',
    str_contains($editSrc, 'if ($isJobDivaSourced)'));
$assert('PATCH blocks client-supplied coreflux_overridden_fields',
    str_contains($apiSrc, "'coreflux_overridden_fields'"));

echo "\nAPI — POST ?action=clear_override\n";
$assert('clear_override action handler exists',
    str_contains($apiSrc, "action === 'clear_override'"));
$assert('clear_override requires placements.manage RBAC',
    preg_match("/clear_override.*rbac_legacy_require\(\\\$user,\s*'placements\.manage'\)/s", $apiSrc) === 1);
$assert('clear_override requires fields[] in body',
    preg_match("/clear_override.*fields\\[\\] required/s", $apiSrc) === 1);
$ownershipSrc = (string) file_get_contents('/app/modules/placements/lib/ownership.php');
$assert('clear_override uses the locked ownership release',
    str_contains($apiSrc, 'placementClearOverrides($id, $fields, $body[\'review\'])') && str_contains($ownershipSrc, 'FOR UPDATE'));
$assert('clear_override retains its audit operation',
    str_contains($ownershipSrc, "placement.override_cleared"));

echo "\n=== Summary ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
