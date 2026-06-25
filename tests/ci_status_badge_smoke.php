<?php
/**
 * tests/ci_status_badge_smoke.php
 *
 * Verifies the CI status badge feature shipped this release:
 *   - /api/ci_status.php endpoint shape (file + parse + key contracts)
 *   - Frontend CIStatusBadge component wiring (testids, state branches)
 *   - CFODashboard mounts the badge in its header
 *   - scripts/sync_bundle.sh is executable and contains the documented
 *     three-step contract (discover hashes / mirror assets / patch .deploy-version)
 *   - dashboard/package.json wires sync_bundle.sh as the postbuild hook
 *
 * Lane: ui (per scripts/ci_lane_classifier.sh)
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "  ok    {$name}\n"; }
    else     { $fail++; echo "  FAIL  {$name}\n"; }
};

echo "api/ci_status.php\n";
$apiPath = __DIR__ . '/../api/ci_status.php';
$apiLintOut = [];
$apiLintRc = 0;
exec('php -l ' . escapeshellarg($apiPath) . ' 2>&1', $apiLintOut, $apiLintRc);
$a('api/ci_status.php exists',                   is_file($apiPath));
$a('api/ci_status.php parses',
   $apiLintRc === 0);

$src = (string) file_get_contents($apiPath);
$a('requires api_bootstrap',                     str_contains($src, "require_once __DIR__ . '/../core/api_bootstrap.php'"));
$a('calls api_require_auth',                     str_contains($src, 'api_require_auth()'));
$a('GET-only (rejects non-GET)',                 str_contains($src, "api_method() !== 'GET'"));
$a('reads GITHUB_REPO env',                      str_contains($src, "getenv('GITHUB_REPO')"));
$a('reads GITHUB_TOKEN env',                     str_contains($src, "getenv('GITHUB_TOKEN')"));
$a('falls back to config constants',             str_contains($src, "defined('GITHUB_REPO')") && str_contains($src, "defined('GITHUB_TOKEN')"));
$a('returns configured=false when env missing',  str_contains($src, "'configured' => false"));
$a('5-minute server cache',                      str_contains($src, '$ttl       = 300'));
$a('cache file under sys_get_temp_dir',          str_contains($src, "sys_get_temp_dir() . '/coreflux_ci_status'"));
$a('cache key hashed from repo',                 str_contains($src, "hash('sha256', \$repo)"));
$a('fetches /actions/runs?per_page=1',           str_contains($src, '/actions/runs?per_page=1'));
$a('sends User-Agent header',                    str_contains($src, "User-Agent: CoreFlux-CFO-Dashboard"));
$a('passes Bearer token when set',               str_contains($src, "'Authorization: Bearer ' . \$token"));
$a('5s upstream timeout',                        str_contains($src, 'CURLOPT_TIMEOUT        => 5'));
$a('handles HTTP >= 400 gracefully',             str_contains($src, '$code >= 400'));
$a('handles 404 with hint',                      str_contains($src, "code === 404") || str_contains($src, '$code === 404'));
$a('handles 403 rate-limit hint',                str_contains($src, "code === 403") || str_contains($src, '$code === 403'));
$a('response includes conclusion field',         str_contains($src, "'conclusion'"));
$a('response includes status field',             str_contains($src, "'status'"));
$a('response includes html_url field',           str_contains($src, "'html_url'"));
$a('response includes branch field',             str_contains($src, "'branch'"));
$a('response includes workflow_name field',      str_contains($src, "'workflow_name'"));
$a('response includes commit_sha (short)',       str_contains($src, "substr((string) (\$run['head_sha']"));
$a('response includes ttl_seconds',              str_contains($src, "'ttl_seconds'"));
$a('writes cache best-effort (@)',               str_contains($src, '@file_put_contents($cacheFile'));

echo "\ncomponents/CIStatusBadge.jsx\n";
$jsxPath = __DIR__ . '/../dashboard/src/components/CIStatusBadge.jsx';
$jsx = (string) file_get_contents($jsxPath);
$a('CIStatusBadge.jsx exists',                   is_file($jsxPath));
$a('default exports CIStatusBadge',              str_contains($jsx, 'export default function CIStatusBadge'));
$a('fetches /api/ci_status.php',                 str_contains($jsx, "api.get('/api/ci_status.php')"));
$a('re-polls every 5 minutes',                   str_contains($jsx, '5 * 60 * 1000'));
$a('cleans up interval on unmount',              str_contains($jsx, 'clearInterval(t)'));
$a('renders data-testid=ci-status-badge',        str_contains($jsx, 'data-testid="ci-status-badge"'));
$a('loading state',                              str_contains($jsx, 'data-state="loading"'));
$a('unconfigured state',                         str_contains($jsx, 'data-state="unconfigured"'));
$a('error state',                                str_contains($jsx, 'data-state="error"'));
$a('green branch on success',                    str_contains($jsx, "conclusion === 'success'") && str_contains($jsx, "'CI green'"));
$a('red branch on failure/cancelled',            str_contains($jsx, "conclusion === 'failure'") && str_contains($jsx, "'CI failing'"));
$a('running branch on in_progress',              str_contains($jsx, "status === 'in_progress'") && str_contains($jsx, "'CI running'"));
$a('opens run in new tab on click',              str_contains($jsx, 'target="_blank"') && str_contains($jsx, 'rel="noopener noreferrer"'));
$a('silent fail on error (returns null)',        str_contains($jsx, 'if (err) return null'));
$a('renders branch chip with GitBranch icon',    str_contains($jsx, 'GitBranch'));
$a('renders Loader2 with cf-spin class',         str_contains($jsx, "className=\"cf-spin\"") || str_contains($jsx, "className={running ? 'cf-spin' : ''}"));

echo "\nCFODashboard.jsx wiring\n";
$cfo = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/CFODashboard.jsx');
$a('imports CIStatusBadge component',            str_contains($cfo, "import CIStatusBadge from '../components/CIStatusBadge'"));
$a('mounts <CIStatusBadge /> in header',         str_contains($cfo, '<CIStatusBadge />'));

echo "\nstyles.css cf-spin utility\n";
$css = (string) file_get_contents(__DIR__ . '/../dashboard/src/styles.css');
$a('cf-spin class defined',                      str_contains($css, '.cf-spin') && str_contains($css, 'animation: spin'));

echo "\nscripts/sync_bundle.sh contract\n";
$shPath = __DIR__ . '/../scripts/sync_bundle.sh';
$sh     = (string) file_get_contents($shPath);
$wf     = (string) @file_get_contents(__DIR__ . '/../.github/workflows/ci.yml');
$pkgForSync = (string) @file_get_contents(__DIR__ . '/../dashboard/package.json');
$a('sync_bundle.sh exists',                      is_file($shPath));
$a('sync_bundle.sh executable in CI or on local FS',
    is_executable($shPath) || str_contains($wf, 'chmod +x scripts/sync_bundle.sh') || str_contains($pkgForSync, 'sync_bundle.mjs'));
$a('uses set -euo pipefail (strict mode)',       str_contains($sh, 'set -euo pipefail'));
$a('discovers JS hash from dist/index.html',     str_contains($sh, "grep -oE 'index-[A-Za-z0-9_-]+\\.js'"));
$a('discovers CSS hash from dist/index.html',    str_contains($sh, "grep -oE 'index-[A-Za-z0-9_-]+\\.css'"));
$a('mirrors dist/spa-assets → top spa-assets',   str_contains($sh, 'DIST_ASSETS="dashboard/dist/spa-assets"') && str_contains($sh, 'TOP_ASSETS="spa-assets"'));
$a('patches .deploy-version expected_bundle:',   str_contains($sh, 'expected_bundle:') && str_contains($sh, 'awk'));
$a('uses awk (no PHP dependency)',               str_contains($sh, 'awk -v js=') && !str_contains($sh, 'php -r'));
$a('fails fast on missing dist/index.html',      str_contains($sh, '$DIST_INDEX not found'));
$a('fails fast on missing expected_bundle:',     str_contains($sh, 'expected_bundle: block not found or malformed'));

echo "\ndashboard/package.json postbuild hook\n";
$pkg = (string) file_get_contents(__DIR__ . '/../dashboard/package.json');
$a('postbuild script wired',                     str_contains($pkg, '"postbuild"'));
$a('postbuild calls bundle sync script',         str_contains($pkg, 'sync_bundle.sh') || str_contains($pkg, 'sync_bundle.mjs'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
