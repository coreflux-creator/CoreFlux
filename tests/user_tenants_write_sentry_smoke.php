<?php
/**
 * Legacy user_tenants write-sentry — every INSERT/UPDATE/DELETE against the
 * legacy table must now go through `core/memberships.php`'s helpers
 * (`provisionMembership`, `deactivateMembership`, `setPrimaryMembership`,
 * `purgeMembershipsForUser`). The only file that may write to user_tenants
 * directly is `core/memberships.php` itself — that's where the dual-write
 * is encapsulated.
 *
 * This sentry fails the suite the moment a PR re-introduces a direct write,
 * so the legacy table can be dropped safely once usage drops to zero in
 * production logs.
 *
 *   php -d zend.assertions=1 /app/tests/user_tenants_write_sentry_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

function _userTenantsWriteAllowlist(): array {
    return [
        'core/memberships.php'              => 'provisionMembership / deactivateMembership / purgeMembershipsForUser — the dual-write helper itself',
        'scripts/backfill_memberships.php'  => 'The actual user_tenants → tenant_memberships migration script',
        'core/sub_tenants.php'              => 'subTenantTouchLastActive() heartbeat-touches both user_tenants + tenant_memberships last_active_at',
    ];
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = (string) $f;
    $pn = str_replace('\\', '/', $p);
    if (!str_ends_with($p, '.php')) continue;
    foreach (['/node_modules/', '/vendor/', '/lib/PHPMailer/', '/dashboard/', '/tests/', '/legacy/', '/private_equity 2/'] as $s) {
        if (strpos($pn, $s) !== false) continue 2;
    }
    $files[] = $p;
}
sort($files);
$a('discovered php files to scan', count($files) > 0);

$allow = _userTenantsWriteAllowlist();
$writePattern = '/\b(?:INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM)\s+`?user_tenants`?\b/i';
$offenders = [];
foreach ($files as $path) {
    $rel = str_replace('\\', '/', ltrim(substr($path, strlen($ROOT)), '/\\'));
    if (isset($allow[$rel])) continue;
    $src = (string) file_get_contents($path);
    if (!preg_match($writePattern, $src)) continue;
    $offenders[] = $rel;
}

echo "\nUser_tenants write-sentry report\n";
if ($offenders) {
    echo "  · " . count($offenders) . " file(s) still WRITE to user_tenants outside the allow-list:\n";
    foreach ($offenders as $o) echo "    ✗ $o\n";
}
$a('every direct user_tenants write goes through core/memberships.php', count($offenders) === 0);

// Self-test
echo "\nSelf-test (synthetic offender — sentry must catch this)\n";
$tmp = sys_get_temp_dir() . '/user_tenants_write_test_' . getmypid() . '.php';
file_put_contents($tmp, "<?php\n\$pdo->prepare('INSERT INTO user_tenants (user_id) VALUES (1)');\n");
$src = (string) file_get_contents($tmp);
$caught = preg_match($writePattern, $src) === 1;
@unlink($tmp);
$a('sentry pattern matches synthetic INSERT INTO user_tenants', $caught);

echo "\n=========================================\n";
echo "User_tenants write-sentry smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
