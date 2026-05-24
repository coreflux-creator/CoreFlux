<?php
/**
 * POST /api/admin/router_deploy.php
 *
 * Dashboard-callable trigger for the GraphQL deploy pipeline. Same
 * pattern as /api/admin/migrate.php and /api/admin/opcache_flush.php —
 * an operator clicks a button in the dashboard, this endpoint runs
 * the existing /app/graphql/deploy/scripts/release.sh as a subprocess
 * and returns its full output.
 *
 * Why a PHP endpoint instead of "SSH and bash release.sh"?
 *   Because that's the established CoreFlux pattern: migrations, OPcache
 *   flushes, and now router deploys are all one-button operations from
 *   the dashboard. The operator never needs SSH for releases — only
 *   for the one-time bootstrap.sh (which sets up systemd + nginx).
 *
 * RBAC: integrations.field_map.manage (same as migrate.php — admin-only).
 *
 * Pre-conditions checked:
 *   - bootstrap stamp exists at /opt/coreflux/.bootstrap-complete
 *   - release.sh on disk
 *   - PHP-FPM user can execute it (release.sh internally requires root —
 *     in production this endpoint is configured via sudoers to allow
 *     `coreflux ALL=(root) NOPASSWD: /opt/coreflux/graphql/deploy/scripts/release.sh`
 *     and we shell out via `sudo`).
 *
 * Response:
 *   { ok: true,  duration_ms: 12345, output: "<full release.sh stdout/stderr>" }
 *   { ok: false, duration_ms: 0,      error:  "<reason>", output: "..." }
 *
 * The dashboard streams `output` into a <pre> block so the operator
 * sees pre-flight → build → compose → restart → smoke results.
 */
declare(strict_types=1);

// Inline fatal-error trap — same pattern as opcache_flush.php.
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) return;
    $fatalMask = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
    if (($err['type'] & $fatalMask) === 0) return;
    if (headers_sent()) return;
    @http_response_code(500);
    @header('Content-Type: application/json; charset=utf-8');
    while (ob_get_level() > 0) { @ob_end_clean(); }
    echo json_encode([
        'ok'    => false,
        'error' => 'Fatal: ' . ($err['message'] ?? 'unknown'),
        'file'  => $err['file'] ?? '?',
        'line'  => $err['line'] ?? 0,
    ]);
});

require_once __DIR__ . '/../../core/api_bootstrap.php';

// Auth + method guard (same as migrate.php).
rbac_legacy_require($user, 'integrations.field_map.manage');
if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body   = api_json_body();
$dryRun = !empty($body['dry_run']);

// ---------------------------------------------------------------------
// Pre-conditions
// ---------------------------------------------------------------------
$bootstrapStamp = '/opt/coreflux/.bootstrap-complete';
if (!is_file($bootstrapStamp)) {
    api_error(
        'Bootstrap not run yet. SSH into the box and run `sudo bash /opt/coreflux/graphql/deploy/scripts/bootstrap.sh` once before using this endpoint.',
        412
    );
}
$releaseScript = '/opt/coreflux/graphql/deploy/scripts/release.sh';
// During first-run before the script has been rsync'd to /opt, allow a
// direct in-repo path.
if (!is_executable($releaseScript)) {
    $releaseScript = '/app/graphql/deploy/scripts/release.sh';
    if (!is_executable($releaseScript)) {
        api_error('release.sh not found on disk', 500);
    }
}

// ---------------------------------------------------------------------
// Build the command. We use sudo to elevate to root (the PHP-FPM user
// can't bounce systemd units directly). The sudoers entry is documented
// in DEPLOYMENT.md. In dry-run mode we just print what the release
// would do — useful for the dashboard's "preview" affordance.
// ---------------------------------------------------------------------
$cmd  = 'sudo -n ' . escapeshellarg($releaseScript);
if ($dryRun) {
    // release.sh doesn't have a --dry-run flag; instead we invoke
    // deploy.sh directly with --dry-run, which is a strict subset of
    // what release does (no systemd, no smoke). This still proves the
    // pre-flight + build plan is intact.
    $deployScript = preg_replace('#/release\.sh$#', '/deploy.sh', $releaseScript);
    $cmd = escapeshellarg((string) $deployScript) . ' --dry-run';
}

$started = microtime(true);

// ---------------------------------------------------------------------
// Run + capture output.
// ---------------------------------------------------------------------
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$proc = proc_open($cmd . ' 2>&1', $descriptors, $pipes, '/');
if (!is_resource($proc)) {
    api_error('Failed to spawn deploy subprocess', 500);
}
fclose($pipes[0]);

// Stream stdout into memory (release.sh runs ~30-90s; we cap at 2 MB
// to keep the API response sane).
$output  = '';
$maxBytes = 2 * 1024 * 1024;
while (!feof($pipes[1])) {
    $chunk = fread($pipes[1], 8192);
    if ($chunk === false) break;
    $output .= $chunk;
    if (strlen($output) >= $maxBytes) {
        $output .= "\n... (truncated at 2 MB)\n";
        break;
    }
}
fclose($pipes[1]);
@fclose($pipes[2]);

$exitCode = proc_close($proc);
$durMs    = (int) round((microtime(true) - $started) * 1000);

// ---------------------------------------------------------------------
// Map the exit code into a structured response.
// ---------------------------------------------------------------------
if ($exitCode === 0) {
    api_ok([
        'duration_ms'  => $durMs,
        'dry_run'      => $dryRun,
        'exit_code'    => 0,
        'output'       => $output,
        'summary'      => $dryRun
            ? 'Dry-run plan generated successfully.'
            : 'Release completed: services healthy + smoke tests green.',
    ]);
}

// Try to extract a useful summary line for the dashboard toast even when
// the underlying script failed mid-pipeline (release.sh `set -e`s on
// first error, so the last non-blank line is usually the reason).
$summary = 'Release failed';
foreach (array_reverse(array_filter(array_map('rtrim', explode("\n", $output)))) as $line) {
    if (stripos($line, 'ERROR') !== false || stripos($line, 'die') !== false) {
        $summary = $line;
        break;
    }
}

http_response_code(500);
echo json_encode([
    'ok'           => false,
    'error'        => $summary,
    'duration_ms'  => $durMs,
    'dry_run'      => $dryRun,
    'exit_code'    => $exitCode,
    'output'       => $output,
]);
