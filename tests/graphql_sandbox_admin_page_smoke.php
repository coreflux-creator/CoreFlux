<?php
/**
 * Smoke for the GraphqlSandbox admin page.
 *
 * Confirms:
 *   1. The page file exists with the right exports.
 *   2. It points at the production endpoint (graphql.corefluxapp.com).
 *   3. It performs an introspection check on mount.
 *   4. AdminModule wires the page: import, ActionCard, sidebar link, route.
 *   5. The required data-testids are in place for downstream tests.
 *   6. Cross-checks the existing sentries still pass.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$page  = '/app/dashboard/src/pages/GraphqlSandbox.jsx';
$admin = '/app/dashboard/src/pages/AdminModule.jsx';

echo "\nGraphqlSandbox page\n";
$a('GraphqlSandbox.jsx exists',  is_file($page));
$src = (string) file_get_contents($page);
$a('default export present',         (bool) preg_match('/export\s+default\s+GraphqlSandbox/', $src));
$a('points at production endpoint',  str_contains($src, 'https://graphql.corefluxapp.com/'));
$a('performs introspection on mount',
    str_contains($src, '__schema') &&
    str_contains($src, 'queryType') &&
    str_contains($src, 'fetch('));
$a('uses AbortController for cleanup', str_contains($src, 'AbortController'));
$a('CORS-safe — no credentials:include',
    !preg_match('/credentials:\s*[\'"]include[\'"]/', $src));
$a('has copy-curl button',           str_contains($src, 'clipboard') && str_contains($src, 'curl'));
$a('has open-sandbox link',          str_contains($src, 'target="_blank"') && str_contains($src, 'rel="noopener noreferrer"'));

echo "\nRequired data-testids\n";
foreach ([
    'gql-sandbox-page',
    'gql-sandbox-endpoint-url',
    'gql-sandbox-status-loading',
    'gql-sandbox-status-ok',
    'gql-sandbox-status-fail',
    'gql-sandbox-open-button',
    'gql-sandbox-copy-curl',
    'gql-sandbox-arch-diagram',
] as $tid) {
    $a("testid {$tid}", str_contains($src, "data-testid=\"{$tid}\""));
}

echo "\nAdminModule wiring\n";
$adminSrc = (string) file_get_contents($admin);
$a('imports GraphqlSandbox',           str_contains($adminSrc, "import GraphqlSandbox from './GraphqlSandbox'"));
$a('imports Zap icon',                 (bool) preg_match('/import\s*\{[^}]*\bZap\b[^}]*\}\s+from\s+[\'"]lucide-react[\'"]/s', $adminSrc));
$a('ActionCard in overview',           str_contains($adminSrc, 'title="GraphQL Sandbox"'));
$a('sidebar link present',             str_contains($adminSrc, "to: '/admin/graphql-sandbox'"));
$a('route registered',                 str_contains($adminSrc, '<Route path="/graphql-sandbox"'));
$a('route renders GraphqlSandbox',     (bool) preg_match('#path="/graphql-sandbox"\s+element=\{<GraphqlSandbox#', $adminSrc));

echo "\nSentry cross-checks\n";
// Make sure adding the page didn't break the auth-gate static analyzer.
// (GraphqlSandbox is purely a React page; no /api/*.php endpoints involved.)
exec('php -d zend.assertions=1 /app/tests/auth_gate_static_analyzer_smoke.php 2>&1', $ag, $agRc);
$a('auth-gate sentry still green',     $agRc === 0,
    'last 5 lines: ' . implode(' | ', array_slice($ag, -5)));

echo "\n=========================================\n";
echo "GraphQL Sandbox admin page smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
