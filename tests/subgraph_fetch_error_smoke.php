<?php
/**
 * Smoke for the coreflux subgraph's PHP-API fetch wrapper.
 *
 * Validates that fetch() failures from the droplet → Cloudways PHP API
 * surface the underlying cause (ENOTFOUND / ECONNREFUSED / TLS / etc.)
 * instead of swallowing them as a generic "fetch failed".
 *
 * Pure source-grep test — no Node runtime needed in the smoke env.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};

$src = (string) file_get_contents('/app/graphql/subgraph-coreflux/src/index.ts');

echo "\nSubgraph PHP-fetch error reporter\n";
$a('try/catch around fetch()',
    (bool) preg_match('/try\s*\{\s*r\s*=\s*await\s+fetch\(/s', $src));
$a('extracts e.cause from undici',     str_contains($src, 'e?.cause'));
$a('detects DNS failure (ENOTFOUND)',  str_contains($src, 'ENOTFOUND') && str_contains($src, 'DNS lookup failed'));
$a('detects connection refused',       str_contains($src, 'ECONNREFUSED') && str_contains($src, 'WAF blocking droplet'));
$a('detects connection timeout',       str_contains($src, 'ETIMEDOUT'));
$a('detects TLS cert failure',         str_contains($src, 'CERT_HAS_EXPIRED') || str_contains($src, "CERT"));
$a('reports host + path in error',     (bool) preg_match('/\$\{host\}\$\{path\}/', $src));
$a('preserves cause code in error',    str_contains($src, '(cause=${code})'));
$a('still rethrows on HTTP non-2xx',
    (bool) preg_match('/throw new Error\(`CoreFlux API \$\{r\.status\}/s', $src));

echo "\n=========================================\n";
echo "Subgraph fetch error reporter smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
