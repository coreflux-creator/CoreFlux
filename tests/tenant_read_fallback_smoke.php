<?php
/**
 * Tenant read-fallback smoke test.
 *
 * After the RBAC Phase B5 read-swap, every tenant/membership read goes
 * through `membershipReadSourceSql()` so the production UI keeps working
 * while `tenant_memberships` is still being backfilled from `user_tenants`.
 *
 * This smoke verifies:
 *   1. `core/memberships.php` exposes the fallback helpers.
 *   2. The SQL fragment de-dupes (tenant_memberships wins on conflict).
 *   3. Legacy-only rows still surface via the UNION.
 *   4. Inactive/revoked rows are excluded on both sides.
 *   5. `core/data.php` and `api/users.php` no longer hard-code
 *      `FROM tenant_memberships` for membership lookups — they call the
 *      shim, so removing the legacy table later is a one-file change.
 *
 *   php -d zend.assertions=1 /app/tests/tenant_read_fallback_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/core/memberships.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// 1. Helper presence ---------------------------------------------------------
$a('membershipReadSourceSql() exists',  function_exists('membershipReadSourceSql'));
$a('membershipTenantCountForUser() exists', function_exists('membershipTenantCountForUser'));
$a('healMembershipsForUser() exists', function_exists('healMembershipsForUser'));
$a('login.php invokes healMembershipsForUser()',
    str_contains((string) file_get_contents($ROOT . '/login.php'), 'healMembershipsForUser('));

$sql = membershipReadSourceSql();
$a('returns a parenthesised fragment',         str_starts_with(trim($sql), '(') && str_ends_with(trim($sql), ')'));
$a('selects from tenant_memberships',          (bool) preg_match('/FROM\s+tenant_memberships/i', $sql));
$a('selects from user_tenants (fallback)',     (bool) preg_match('/FROM\s+user_tenants/i', $sql));
$a('exposes user_id column',                   (bool) preg_match('/\buser_id\b/', $sql));
$a('exposes tenant_id column',                 (bool) preg_match('/\btenant_id\b/', $sql));
$a('exposes persona_type column',              (bool) preg_match('/\bpersona_type\b/', $sql));
$a('exposes is_primary column',                (bool) preg_match('/\bis_primary\b/', $sql));

// 2. De-dup safety: legacy rows excluded when memberships row exists ----------
$a('NOT EXISTS clause de-dupes overlap',
    (bool) preg_match('/NOT\s+EXISTS\s*\(.*tenant_memberships.*\)/is', $sql));

// 3. Status filtering --------------------------------------------------------
$a('tenant_memberships side filtered by status=active',
    (bool) preg_match("/tenant_memberships\\s+WHERE\\s+status\\s*=\\s*'active'/is", $sql));
$a("user_tenants side excludes inactive/revoked rows",
    (bool) preg_match("/user_tenants\\s+ut\\s+WHERE\\s+COALESCE\\(ut\\.status[^)]+\\)\\s*=\\s*'active'/is", $sql));

// 4. Map legacy 'inactive' → new 'suspended'
$a("legacy 'inactive' status mapped to 'suspended'",
    (bool) preg_match("/CASE\\s+WHEN\\s+ut\\.status\\s*=\\s*'inactive'\\s+THEN\\s+'suspended'/is", $sql));

// 5. Call-site adoption ------------------------------------------------------
$dataPhp  = (string) file_get_contents($ROOT . '/core/data.php');
$usersPhp = (string) file_get_contents($ROOT . '/api/users.php');

// Legacy hard-coded `FROM tenant_memberships` reads in these files were a
// fragility — every UI/auth-path call site now routes through the shim.
// (The bootstrap helper in api/users.php still reads back its own freshly-
// inserted membership row by id; that's not a UI lookup so it's allowed.)
$a('core/data.php uses membershipReadSourceSql()',
    str_contains($dataPhp, 'membershipReadSourceSql()'));
$a('core/data.php has no remaining hard-coded FROM tenant_memberships',
    preg_match_all('/FROM\s+tenant_memberships\b/i', $dataPhp) === 0);
$a('api/users.php uses membershipReadSourceSql()',
    str_contains($usersPhp, 'membershipReadSourceSql()'));
$a('api/users.php only reads tenant_memberships from the bootstrap helper',
    preg_match_all('/FROM\s+tenant_memberships\b/i', $usersPhp) <= 1);

// 6. Live DB exec (best-effort — only when DB is available) -------------------
require_once $ROOT . '/core/db.php';
$pdo = function_exists('getDB') ? getDB() : null;
if ($pdo) {
    try {
        // The shim must be a valid subquery in any context.
        $row = $pdo->query('SELECT COUNT(*) FROM ' . membershipReadSourceSql() . ' src')->fetchColumn();
        $a('shim executes against live DB', is_numeric($row));
    } catch (\Throwable $e) {
        $a('shim executes against live DB (' . $e->getMessage() . ')', false);
    }
} else {
    echo "  · (skipping live DB exec — no DB connection in this sandbox)\n";
}

echo "\n=========================================\n";
echo "Tenant read-fallback smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
