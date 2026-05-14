<?php
/**
 * /api/ci_status.php — CI status badge data for the CFO Dashboard header.
 *
 * Fetches the latest GitHub Actions workflow run for the configured repo
 * and returns its conclusion (success | failure | in_progress | ...) plus
 * a human-readable timestamp. Cached server-side for 5 minutes so a
 * dashboard full of operators doesn't hammer the GitHub API.
 *
 * Config (any one of these works; all are read from environment for the
 * deployed server, falling back to constants in core/config.local.php):
 *
 *   GITHUB_REPO   — "owner/repo" string. Required.
 *   GITHUB_TOKEN  — Optional. Personal access token. Only needed for
 *                   PRIVATE repos. Public repos work unauthenticated
 *                   (GitHub allows ~60 req/hour anonymous; with our
 *                   5-min cache that is plenty).
 *
 *   GET /api/ci_status.php
 *     → 200 { configured: true, conclusion, status, html_url, started_at,
 *             updated_at, workflow_name, branch, cached_at, ttl_seconds }
 *     → 200 { configured: false, reason }     // env not set
 *     → 200 { configured: true, error }       // upstream / parse error
 *
 * Auth: standard session/JWT. No RBAC tier — the data is non-sensitive
 * (public repo metadata + a status enum).
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/api_bootstrap.php';

api_require_auth();

if (api_method() !== 'GET') {
    api_error('Method not allowed', 405);
}

// ── Config -------------------------------------------------------------
$repo  = getenv('GITHUB_REPO') ?: (defined('GITHUB_REPO') ? GITHUB_REPO : '');
$token = getenv('GITHUB_TOKEN') ?: (defined('GITHUB_TOKEN') ? GITHUB_TOKEN : '');

if ($repo === '' || strpos($repo, '/') === false) {
    api_ok([
        'configured' => false,
        'reason'     => 'GITHUB_REPO env var not set. Add GITHUB_REPO=owner/repo to enable the CI badge.',
    ]);
}

// ── Cache (5 minutes) --------------------------------------------------
$cacheDir = sys_get_temp_dir() . '/coreflux_ci_status';
@mkdir($cacheDir, 0700, true);
$cacheKey  = 'ci_' . substr(hash('sha256', $repo), 0, 16) . '.json';
$cacheFile = $cacheDir . '/' . $cacheKey;
$ttl       = 300; // 5 min

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    $cached = json_decode((string) file_get_contents($cacheFile), true);
    if (is_array($cached)) {
        $cached['cached_at']   = date('c', (int) filemtime($cacheFile));
        $cached['ttl_seconds'] = $ttl - (time() - filemtime($cacheFile));
        api_ok($cached);
    }
}

// ── Fetch from GitHub -------------------------------------------------
$url = "https://api.github.com/repos/{$repo}/actions/runs?per_page=1";
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_HTTPHEADER     => array_filter([
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: CoreFlux-CFO-Dashboard',
        $token ? ('Authorization: Bearer ' . $token) : null,
    ]),
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($body === false || $code >= 400) {
    api_ok([
        'configured' => true,
        'error'      => "GitHub API returned HTTP {$code}" . ($err ? " ({$err})" : ''),
        'hint'       => $code === 404 ? 'Repo not found or private without GITHUB_TOKEN.' :
                        ($code === 403 ? 'Rate-limited or unauthorized. Set GITHUB_TOKEN.' : null),
    ]);
}

$parsed = json_decode((string) $body, true);
if (!is_array($parsed) || empty($parsed['workflow_runs'][0])) {
    api_ok([
        'configured' => true,
        'error'      => 'No workflow runs found for repo ' . $repo,
    ]);
}

$run = $parsed['workflow_runs'][0];

$result = [
    'configured'    => true,
    'conclusion'    => $run['conclusion'] ?? null,     // success | failure | cancelled | null (when in progress)
    'status'        => $run['status']     ?? null,     // queued | in_progress | completed
    'html_url'      => $run['html_url']   ?? null,
    'started_at'    => $run['run_started_at'] ?? null,
    'updated_at'    => $run['updated_at']     ?? null,
    'workflow_name' => $run['name']           ?? null,
    'branch'        => $run['head_branch']    ?? null,
    'commit_sha'    => substr((string) ($run['head_sha'] ?? ''), 0, 7),
    'commit_msg'    => (string) ($run['display_title'] ?? $run['head_commit']['message'] ?? ''),
    'cached_at'     => date('c'),
    'ttl_seconds'   => $ttl,
];

// Persist cache (best-effort)
@file_put_contents($cacheFile, json_encode($result));

api_ok($result);
