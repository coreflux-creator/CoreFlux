<?php
/**
 * Bug-fix smoke — placements.remote_policy '' coercion.
 *
 * Repro: the frontend PlacementCreate form initialises remote_policy=''
 * (empty string) so the dropdown shows "—". Before the fix, submitting
 * the form yielded SQLSTATE[01000] 1265 "Data truncated for column
 * 'remote_policy'" because MySQL ENUM rejects ''. The fix normalises
 * '' (and any unknown value) to NULL via placementsNormalizeRemotePolicy().
 *
 * This smoke verifies:
 *   - Helper exists in lib/placements.php (so csv_import.php sees it without
 *     pulling the API file).
 *   - Helper coerces '' / null / unknown → null and accepts the three real
 *     enum values verbatim.
 *   - Both placements.php POST + PATCH paths call the helper.
 *   - csv_import.php call site uses the helper.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');

echo "Helper — modules/placements/lib/placements.php\n";
$libPath = "{$ROOT}/modules/placements/lib/placements.php";
$lib = (string) file_get_contents($libPath);
$assert('PLACEMENTS_ALLOWED_REMOTE constant exists',
    strpos($lib, "PLACEMENTS_ALLOWED_REMOTE = ['onsite','hybrid','remote']") !== false);
$assert('placementsNormalizeRemotePolicy() exists',
    strpos($lib, 'function placementsNormalizeRemotePolicy(') !== false);
$assert('helper has explanatory comment about SQLSTATE 1265',
    strpos($lib, 'SQLSTATE[01000] 1265') !== false);

echo "\nRuntime behaviour\n";
require_once $libPath;
$assert("''                  → null", placementsNormalizeRemotePolicy('')        === null);
$assert("'   '               → null", placementsNormalizeRemotePolicy('   ')     === null);
$assert("null                → null", placementsNormalizeRemotePolicy(null)      === null);
$assert("'onsite'            → 'onsite'",   placementsNormalizeRemotePolicy('onsite')   === 'onsite');
$assert("'hybrid'            → 'hybrid'",   placementsNormalizeRemotePolicy('hybrid')   === 'hybrid');
$assert("'remote'            → 'remote'",   placementsNormalizeRemotePolicy('remote')   === 'remote');
$assert("'  remote  '        → 'remote' (trimmed)",
    placementsNormalizeRemotePolicy('  remote  ') === 'remote');
$assert("'on-site' (unknown) → null",       placementsNormalizeRemotePolicy('on-site')  === null);
$assert("123 (unknown type)  → null",       placementsNormalizeRemotePolicy(123)        === null);

echo "\nWrite-path call sites\n";
$apiPath = "{$ROOT}/modules/placements/api/placements.php";
$api = (string) file_get_contents($apiPath);
$assert('POST insert array uses helper',
    strpos($api, "'remote_policy'    => placementsNormalizeRemotePolicy(\$body['remote_policy'] ?? null)") !== false);
$assert('PATCH path coerces remote_policy when present',
    strpos($api, "if (array_key_exists('remote_policy', \$body))") !== false
    && strpos($api, '$body[\'remote_policy\'] = placementsNormalizeRemotePolicy($body[\'remote_policy\'])') !== false);
$assert('legacy bare ?? null on remote_policy is gone (POST path)',
    strpos($api, "'remote_policy'    => \$body['remote_policy']    ?? null") === false);

$csvPath = "{$ROOT}/modules/placements/api/csv_import.php";
$csv = (string) file_get_contents($csvPath);
$assert('CSV import uses helper',
    strpos($csv, "'remote_policy'    => placementsNormalizeRemotePolicy(\$row['remote_policy'] ?? null)") !== false);
$assert('legacy bare ?? null on remote_policy is gone (csv_import)',
    strpos($csv, "'remote_policy'    => \$row['remote_policy']   ?? null") === false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
