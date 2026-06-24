<?php
/**
 * Smoke — surface heal-blocking errors to the operator (2026-02).
 *
 * The previous heal flow swallowed every per-user error silently and
 * returned only an integer count. The user reported "no matter how many
 * times I heal, these remain" — meaning errors were happening but the UI
 * had no way to see them. This regression smoke locks in:
 *
 *   • healMembershipsForUser returns { healed: int, errors: string[] }
 *   • POST /api/admin/membership_drift.php?action=heal echoes errors[]
 *   • POST /api/admin/membership_drift.php?action=heal_all aggregates errors[]
 *   • MembershipDriftBanner.jsx renders a red error block when set
 *   • login.php call-site (returns-discarded) still works with new signature
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. healMembershipsForUser returns array shape\n";
$lib = (string) file_get_contents('/app/core/memberships.php');
$a('signature returns array',
    str_contains($lib, 'function healMembershipsForUser(int $userId): array'));
$a('early returns ship array shape',
    str_contains($lib, "return ['healed' => 0, 'errors' => []];")
    && str_contains($lib, "return ['healed' => 0, 'errors' => ['no DB connection']];"));
$a('user_tenants read failure populates errors[]',
    str_contains($lib, "'user_tenants read failed: ' . \$e->getMessage()"));
$a('per-row failure captures tenant + role + err',
    str_contains($lib, "tenant=' . (int) \$r['tenant_id'] . ' role=' . (\$r['role'] ?? '?') . ' err=' . \$e->getMessage()"));
$a('final return ships healed + errors',
    str_contains($lib, "return ['healed' => \$healed, 'errors' => \$errors];"));

echo "\n2. /api/admin/membership_drift.php exposes errors\n";
$api = (string) file_get_contents('/app/api/admin/membership_drift.php');
$a('heal action returns rows_healed + errors',
    str_contains($api, "'rows_healed' => \$result['healed']")
    && str_contains($api, "'errors'      => \$result['errors']"));
$a('heal_all action aggregates errors per uid',
    str_contains($api, "foreach (\$r['errors'] as \$e) \$allErrors[] = \"uid={\$id} {\$e}\";"));
$a('heal_all response includes top-level errors array',
    str_contains($api, "'errors'          => \$allErrors"));

echo "\n3. login.php still works (discarded return)\n";
$login = (string) file_get_contents('/app/login.php');
$a('login.php discards return — array OK',
    str_contains($login, 'try { healMembershipsForUser((int) $dbUser[\'id\']); }'));

echo "\n4. MembershipDriftBanner surfaces heal errors\n";
$banner = (string) file_get_contents('/app/dashboard/src/pages/MembershipDriftBanner.jsx');
$a('declares healErrors state',                       str_contains($banner, 'const [healErrors, setHealErrors] = useState([])'));
$a('healOne captures res.errors',                     str_contains($banner, 'if (res?.errors?.length) setHealErrors(res.errors);'));
$a('healAll aggregates errors across batches',        str_contains($banner, 'allErrors.push(...res.errors)'));
$a('renders red error block conditionally',           str_contains($banner, 'data-testid="drift-heal-errors"'));
$a('each error gets its own data-testid',             str_contains($banner, 'data-testid={`drift-heal-error-${i}`}'));

echo "\n5. Bundle integration\n";
$dv = trim((string) @file_get_contents('/app/.deploy-version'));
$bundleHashJs = '';
if (preg_match('/index-([a-zA-Z0-9_-]+)\.js/', $dv, $m)) $bundleHashJs = $m[0];
$jsBundle = '';
foreach (['/app/spa-assets/', '/app/dashboard/dist/spa-assets/'] as $dir) {
    if ($bundleHashJs && is_file($dir . $bundleHashJs)) {
        $jsBundle = (string) @file_get_contents($dir . $bundleHashJs);
        break;
    }
}
$a('bundle contains drift-heal-errors testid', $jsBundle !== '' && str_contains($jsBundle, 'drift-heal-errors'),
   $bundleHashJs ? "bundle={$bundleHashJs}" : 'no bundle hash resolved');

echo "\n6. PHP syntax\n";
$a('php -l /app/core/memberships.php',
    str_contains((string) shell_exec('php -l /app/core/memberships.php 2>&1'), 'No syntax errors'));
$a('php -l /app/api/admin/membership_drift.php',
    str_contains((string) shell_exec('php -l /app/api/admin/membership_drift.php 2>&1'), 'No syntax errors'));
$a('php -l /app/api/users.php',
    str_contains((string) shell_exec('php -l /app/api/users.php 2>&1'), 'No syntax errors'));

echo "\n— pass={$pass}  fail={$fail}\n";
exit($fail === 0 ? 0 : 1);
