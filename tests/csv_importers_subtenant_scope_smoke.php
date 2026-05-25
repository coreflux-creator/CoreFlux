<?php
/**
 * Smoke — Apply the same tenant-scope fix from placements/api/csv_import.php
 * to the People + Time CSV importers.
 *
 * Real-world risk this guards against:
 *   People dedupe lookup ran on raw `currentTenantId()` so a sub-tenant
 *   (people module = 'shared' by default → reads parent rows) would
 *   re-import a person that already exists under the master tenant.
 *
 *   Time importer's placement_external_id lookup had the same shape:
 *   binding raw `currentTenantId()` would silently miss every row for
 *   a sub-tenant where `placements` is configured 'shared'.
 *
 * Fixes locked in:
 *   - both csv_import.php files require_once core/sub_tenants.php
 *   - people email-collision SELECT binds effectiveTenantIdForModule('people')
 *   - time placement-lookup SELECT binds effectiveTenantIdForModule('placements')
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$people = (string) file_get_contents('/app/modules/people/api/csv_import.php');
$time   = (string) file_get_contents('/app/modules/time/api/csv_import.php');

echo "\n1. People CSV importer\n";
$a('require_once core/sub_tenants.php',
   str_contains($people, "require_once __DIR__ . '/../../../core/sub_tenants.php';"));
$a('email-collision query binds effectiveTenantIdForModule(\'people\')',
   str_contains($people, "\$peopleTid = effectiveTenantIdForModule('people') ?? currentTenantId();")
   && str_contains($people, '$stmt->execute(array_merge([$peopleTid], array_map(\'strtolower\', $emails)));'));
$a('no longer uses raw currentTenantId() for the dedupe lookup',
   !preg_match('/\\\$stmt->execute\(array_merge\(\[currentTenantId\(\)\], array_map\(\'strtolower\'/', $people));
$a('rationale comment present',
   str_contains($people, 'silently re-import a person'));

echo "\n2. Time CSV importer\n";
$a('require_once core/sub_tenants.php',
   str_contains($time, "require_once __DIR__ . '/../../../core/sub_tenants.php';"));
$a('placement-lookup query binds effectiveTenantIdForModule(\'placements\')',
   str_contains($time, "\$placementsTid = effectiveTenantIdForModule('placements') ?? currentTenantId();")
   && str_contains($time, '$stmt->execute(array_merge([$placementsTid], $exts));'));
$a('no longer uses raw currentTenantId() for placement lookup',
   !preg_match('/\\\$stmt->execute\(array_merge\(\[currentTenantId\(\)\], \\\$exts\)\)/', $time));
$a('rationale comment present',
   str_contains($time, "sub-tenant under shared placement scope"));

echo "\n3. PHP syntax\n";
foreach ([
    '/app/modules/people/api/csv_import.php',
    '/app/modules/time/api/csv_import.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Cross-importer sub-tenant scope smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
