<?php
/**
 * Smoke for the Placements list REST→GraphQL pilot migration.
 *
 * Validates four moving parts:
 *
 *   1. /api/auth/issue_dashboard_jwt.php — passes the auth-gate sentry,
 *      uses jwtSign(), returns the right shape.
 *   2. /app/dashboard/src/lib/graphqlClient.js — caches token, exposes
 *      gql() + useGql(), points at graphql.corefluxapp.com (or env override).
 *   3. /app/modules/placements/ui/ListGraphql.jsx — uses useGql, queries
 *      placements, has parity testids with List.jsx where applicable.
 *   4. PlacementsModule.jsx — registers list-graphql route, imports the
 *      new component.
 *
 * And makes sure the existing List.jsx still passes the same data-testid
 * checks (we only added a new button, didn't break old ones).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$jwt   = '/app/api/auth/issue_dashboard_jwt.php';
$cli   = '/app/dashboard/src/lib/graphqlClient.js';
$page  = '/app/modules/placements/ui/ListGraphql.jsx';
$mod   = '/app/modules/placements/ui/PlacementsModule.jsx';
$rest  = '/app/modules/placements/ui/List.jsx';

echo "\n1. JWT-mint endpoint\n";
$a('issue_dashboard_jwt.php exists', is_file($jwt));
$jwtSrc = (string) file_get_contents($jwt);
$a('requires api_require_auth()', str_contains($jwtSrc, 'api_require_auth()'));
$a('requires core/jwt.php',       str_contains($jwtSrc, "require_once __DIR__ . '/../../core/jwt.php'"));
$a('calls jwtSign with TTL',
    str_contains($jwtSrc, 'jwtSign(') && str_contains($jwtSrc, '$accessTtl'));
$a('includes tenant_id claim',    (bool) preg_match("/'tenant_id'\s*=>/", $jwtSrc));
$a('includes user_id claim',      (bool) preg_match("/'user_id'\s*=>/", $jwtSrc));
$a('returns jwt + expires_in',
    str_contains($jwtSrc, "'jwt'") && str_contains($jwtSrc, "'expires_in'"));

echo "\n2. graphqlClient.js\n";
$a('graphqlClient.js exists', is_file($cli));
$cliSrc = (string) file_get_contents($cli);
$a('exports gql()',           str_contains($cliSrc, 'export async function gql'));
$a('exports useGql()',        str_contains($cliSrc, 'export function useGql'));
$a('exports getToken()',      str_contains($cliSrc, 'export async function getToken'));
$a('exports clearToken()',    str_contains($cliSrc, 'export function clearToken'));
$a('exports runDiagnostics()',str_contains($cliSrc, 'export async function runDiagnostics'));
$a('points at graphql.corefluxapp.com by default',
    str_contains($cliSrc, "'https://graphql.corefluxapp.com/'"));
$a('honors VITE_GRAPHQL_URL override',
    str_contains($cliSrc, 'VITE_GRAPHQL_URL'));
$a('fetches JWT from issue_dashboard_jwt.php',
    str_contains($cliSrc, '/api/auth/issue_dashboard_jwt.php'));
$a('attaches Authorization: Bearer',
    str_contains($cliSrc, 'Authorization: `Bearer ${token}`'));
$a('uses credentials: include for JWT mint',
    str_contains($cliSrc, "credentials: 'include'"));
$a('caches token in-memory + refresh window',
    str_contains($cliSrc, 'expiresAt') && str_contains($cliSrc, 'Date.now()'));
$a('coalesces concurrent token fetches (inflight)', str_contains($cliSrc, 'inflight'));
$a('clears token on 401',     str_contains($cliSrc, "r.status === 401"));

echo "\n2b. Diagnostics + perf\n";
$a('classifies JWT-mint network failure',  str_contains($cliSrc, 'AUTH_MINT_NETWORK'));
$a('classifies JWT-mint HTTP failure',     str_contains($cliSrc, 'AUTH_MINT_HTTP'));
$a('classifies GraphQL network failure',   str_contains($cliSrc, 'GQL_NETWORK'));
$a('measures fetch elapsed ms',            str_contains($cliSrc, 'elapsedMs'));
$a('uses performance.now when available',  str_contains($cliSrc, 'performance.now'));
$a('useGql exposes elapsedMs',             (bool) preg_match('/return\s*\{[^}]*elapsedMs[^}]*\}/s', $cliSrc));
$a('runDiagnostics probes both endpoints', str_contains($cliSrc, 'jwtMint') && str_contains($cliSrc, 'graphql'));
$a('special 404 hint for missing JWT endpoint',
    str_contains($cliSrc, 'issue_dashboard_jwt.php not deployed yet'));
$a('hints DevTools Network panel on net failure',
    str_contains($cliSrc, 'DevTools'));

echo "\n3. ListGraphql.jsx pilot\n";
$a('ListGraphql.jsx exists', is_file($page));
$pSrc = (string) file_get_contents($page);
$a('imports useGql from graphqlClient',
    str_contains($pSrc, 'graphqlClient') && str_contains($pSrc, 'useGql'));
$a('queries placements with status + limit',
    str_contains($pSrc, 'placements(status: $status, limit: $limit)'));
$a('asks for person.firstName/lastName',
    str_contains($pSrc, 'person { id firstName lastName }'));
$a('shows GraphQL badge',     str_contains($pSrc, 'placements-gql-badge'));
$a('has client-side search',  str_contains($pSrc, 'placements-gql-search'));
$a('has status filter',       str_contains($pSrc, 'placements-gql-status-filter'));
$a('has pager next/prev',
    str_contains($pSrc, 'placements-gql-next') && str_contains($pSrc, 'placements-gql-prev'));
$a('surfaces graphql errors', str_contains($pSrc, 'placements-gql-error'));
$a('has switch-to-REST link', str_contains($pSrc, 'placements-gql-switch-rest'));

echo "\n3b. Perf analytic + diagnostic panel\n";
$a('renders perf badge',          str_contains($pSrc, 'placements-gql-perf'));
$a('uses elapsedMs from useGql',  str_contains($pSrc, 'elapsedMs'));
$a('renders ms suffix',           str_contains($pSrc, 'ms via graphql.corefluxapp.com'));
$a('has "Run diagnostics" button',str_contains($pSrc, 'placements-gql-diag-run'));
$a('renders diag-jwt-row',        str_contains($pSrc, 'diag-jwt-row'));
$a('renders diag-graphql-row',    str_contains($pSrc, 'diag-graphql-row'));
$a('imports runDiagnostics',      str_contains($pSrc, 'runDiagnostics'));
$a('imports CheckCircle2 + XCircle for diag', str_contains($pSrc, 'CheckCircle2') && str_contains($pSrc, 'XCircle'));
$a('shows error.code badge',      str_contains($pSrc, 'error.code'));

echo "\n4. PlacementsModule wiring\n";
$mSrc = (string) file_get_contents($mod);
$a('imports ListGraphql',     str_contains($mSrc, "import ListGraphql from './ListGraphql'"));
$a('route list-graphql registered',
    (bool) preg_match('#<Route\s+path="list-graphql"\s+element=\{<ListGraphql#', $mSrc));
$a('REST route still present',
    (bool) preg_match('#<Route\s+path="list"\s+element=\{<List\s+session=#', $mSrc));

echo "\n5. List.jsx (REST) — Try-GraphQL CTA, no regressions\n";
$rSrc = (string) file_get_contents($rest);
$a('Try-GraphQL CTA present',
    str_contains($rSrc, 'placements-try-graphql-btn') &&
    str_contains($rSrc, '../list-graphql'));
// Existing testids must remain.
foreach (['placements-list', 'placements-count', 'placements-csv-btn', 'placements-new-btn', 'placements-search', 'placements-status-filter'] as $tid) {
    $a("REST list still has data-testid={$tid}", str_contains($rSrc, "data-testid=\"{$tid}\""));
}

echo "\n5b. REST perf badge parity\n";
$a('REST renders perf badge',           str_contains($rSrc, 'placements-rest-perf'));
$a('REST uses elapsedMs from useApi',   (bool) preg_match('/useApi(Cached)?\([^)]*\).*elapsedMs/s', $rSrc));
$a('REST badge labels as REST',         str_contains($rSrc, 'ms via /api (REST)'));
// useApi must export elapsedMs.
$apiSrc = (string) file_get_contents('/app/dashboard/src/lib/api.js');
$a('useApi tracks elapsedMs',           str_contains($apiSrc, 'setElapsedMs'));
$a('useApi returns elapsedMs',          (bool) preg_match('/return\s*\{[^}]*elapsedMs/', $apiSrc));
$a('useApi uses performance.now',       str_contains($apiSrc, 'performance.now'));

echo "\n6. Sentry cross-checks\n";
exec('php -d zend.assertions=1 /app/tests/auth_gate_static_analyzer_smoke.php 2>&1', $ag, $agRc);
$a('auth-gate sentry still green', $agRc === 0,
    'last lines: ' . implode(' | ', array_slice($ag, -5)));

echo "\n=========================================\n";
echo "Placements GraphQL pilot smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
