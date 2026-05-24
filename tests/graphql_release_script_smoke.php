<?php
/**
 * Smoke for /app/graphql/deploy/scripts/release.sh
 *
 * The release wrapper can only be exercised end-to-end on a real
 * Cloudways host (it bounces systemd units and reads /etc/coreflux/
 * graphql.env). What we CAN verify locally:
 *   - The script parses as bash with no syntax errors.
 *   - The pre-flight guards reject obvious misconfigurations:
 *     missing ENV_FILE, placeholder secrets, missing tools.
 *   - The script delegates the rsync+build+compose to deploy.sh.
 *   - The post-deploy smoke-test invocation list is correct.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$script = '/app/graphql/deploy/scripts/release.sh';
$assert('release.sh exists',        is_file($script));
$assert('release.sh is executable', is_executable($script));

echo "\nBash syntax\n";
exec("bash -n " . escapeshellarg($script) . " 2>&1", $out, $rc);
$assert('bash -n returns 0', $rc === 0, implode("\n", $out));

$src = (string) file_get_contents($script);

echo "\nPre-flight guards\n";
$assert('aborts when not run as root',             str_contains($src, '"$EUID" -eq 0'));
$assert('requires ENV_FILE present',               str_contains($src, '[ -f "$ENV_FILE" ]'));
$assert('rejects placeholder secrets',             str_contains($src, "REPLACE_WITH_OPENSSL"));
$assert('checks for node/yarn/router/php',
    str_contains($src, 'command -v node')   &&
    str_contains($src, 'command -v yarn')   &&
    str_contains($src, 'command -v router') &&
    str_contains($src, 'command -v php'));

echo "\nPipeline\n";
$assert('snapshots supergraph.graphql.prev for rollback',
    str_contains($src, 'supergraph.graphql.prev'));
$assert('delegates rsync+build+compose to deploy.sh',
    str_contains($src, 'deploy/scripts/deploy.sh'));
$assert('selectively restarts only changed subgraphs',
    str_contains($src, 'restart_if_changed coreflux-subgraph-coreflux') &&
    str_contains($src, 'restart_if_changed coreflux-subgraph-jobdiva') &&
    str_contains($src, 'restart_if_changed coreflux-mcp'));
$assert('does NOT auto-restart the router (it hot-reloads)',
    !preg_match('/restart_if_changed coreflux-router/', $src));
$assert('captures pre- and post-deploy health',
    str_contains($src, '$PRE_HEALTH=') === false &&
    str_contains($src, 'PRE_HEALTH=') &&
    str_contains($src, 'POST_HEALTH='));
$assert('runs three GraphQL smoke tests',
    str_contains($src, 'graphql_router_prod_config_smoke.php') &&
    str_contains($src, 'graphql_federation_smoke.php') &&
    str_contains($src, 'internal_hmac_bridge_smoke.php'));
$assert('prints rollback hint at the end',
    str_contains($src, 'Rollback (schema only):'));

echo "\nFunctional pre-flight (rejects bad env)\n";
// We're already root in this sandbox; the EUID guard won't trip. Test
// the next guard instead: ENV_FILE missing → should error immediately.
$out = (string) shell_exec("ENV_FILE=/nonexistent-env-file {$script} 2>&1 || true");
$assert('invalid ENV_FILE blocks the run before doing any work',
    str_contains($out, 'ERROR') && (
        str_contains($out, 'missing') ||
        str_contains($out, 'must run as root')
    ));

echo "\n=== Summary ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
