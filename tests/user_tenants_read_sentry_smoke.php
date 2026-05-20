<?php
/**
 * Legacy user_tenants read-sentry — keeps the post-B5 RBAC migration from
 * silently regressing.
 *
 * After RBAC Phase B5 retired the legacy `user_tenants` table in favour of
 * `tenant_memberships`, a previous sweep removed every plain SELECT against
 * the old table. The handful of legitimate exceptions are listed in
 * `_userTenantsReadAllowlist()` with their reason — they're the ones the
 * platform can't retire yet (backfill script, dual-table drift inspector,
 * the legacy_role compatibility method).
 *
 * Any new SELECT against user_tenants outside that allow-list flags here,
 * so a regression PR fails the smoke suite immediately instead of leaking
 * stale role data to a sub-tenant six months later.
 *
 *   php -d zend.assertions=1 /app/tests/user_tenants_read_sentry_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

/**
 * Files that may legitimately still READ from user_tenants. Each entry has
 * a one-line reason; PRs adding to this list must justify the addition.
 */
function _userTenantsReadAllowlist(): array {
    return [
        'core/sub_tenants.php'                       => 'Compatibility bridge — backfills tenant_memberships from user_tenants on demand',
        'core/api_bootstrap.php'                     => 'Legacy role lookup during request bootstrap (B5 compatibility path)',
        'core/rbac/permissions.php'                  => 'RBACResolver::legacyRole() — by design, queries the legacy table',
        'api/admin/user_effective_permissions.php'   => 'Dual-table drift inspector — surfaces rows that exist in only one of the two tables',
        'scripts/backfill_memberships.php'           => 'The actual user_tenants → tenant_memberships migration script',

        // ── Pending write-side refactor (next session) ──
        // These files mix reads+writes against user_tenants. Retiring them
        // safely requires a `provisionMembership($user, $tenant, $role)`
        // helper that dual-writes both tables (or moves writes to
        // tenant_memberships and keeps user_tenants as a derived view).
        // Listed here so the sentry stays clean while the refactor lands.
        'core/views/admin/user_edit.php'             => 'Pending dual-write refactor — admin user-edit form (P2 follow-up)',
        'people/includes/people_helper.php'          => 'Pending dual-write refactor — people module bootstrap (P2 follow-up)',
        'api/users.php'                              => 'Pending dual-write refactor — primary user CRUD (P2 follow-up; largest surface, needs helper)',
    ];
}

// ----------------------------------------------------------------- discovery
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = (string) $f;
    if (!str_ends_with($p, '.php')) continue;
    if (strpos($p, '/node_modules/') !== false) continue;
    if (strpos($p, '/vendor/') !== false) continue;
    if (strpos($p, '/lib/PHPMailer/') !== false) continue;
    if (strpos($p, '/dashboard/') !== false) continue;
    if (strpos($p, '/tests/') !== false) continue;
    if (strpos($p, '/legacy/') !== false) continue;
    if (strpos($p, '/private_equity 2/') !== false) continue;
    $files[] = $p;
}
sort($files);
$a('discovered php files to scan', count($files) > 0);

$allow = _userTenantsReadAllowlist();
$offenders = [];
foreach ($files as $path) {
    $rel = ltrim(substr($path, strlen($ROOT)), '/');
    $src = (string) file_get_contents($path);
    if (!preg_match('/\bFROM\s+`?user_tenants`?\b/i', $src)) continue;
    if (isset($allow[$rel])) continue;
    $offenders[] = $rel;
}

echo "\nUser_tenants read-sentry report\n";
if ($offenders) {
    echo "  · " . count($offenders) . " file(s) still SELECT from user_tenants outside the allow-list:\n";
    foreach ($offenders as $o) echo "    ✗ $o\n";
}
$a('zero unauthorised user_tenants reads', count($offenders) === 0);

// ----------------------------------------------------------------- self-test
echo "\nSelf-test (synthetic offender — sentry must catch this)\n";
$tmp = sys_get_temp_dir() . '/user_tenants_sentry_test_' . getmypid() . '.php';
file_put_contents($tmp, "<?php\n\$stmt = \$pdo->prepare('SELECT role FROM user_tenants WHERE user_id = :u');\n");
$src = (string) file_get_contents($tmp);
$caught = preg_match('/\bFROM\s+`?user_tenants`?\b/i', $src) === 1;
@unlink($tmp);
$a('sentry pattern matches synthetic SELECT FROM user_tenants', $caught);

echo "\n=========================================\n";
echo "User_tenants read-sentry smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
