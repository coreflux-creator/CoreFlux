<?php
declare(strict_types=1);

/** Add standalone-only direct-module denials without changing the shared ERP rules. */
function coreAccountingStandaloneApacheConfig(string $config): string
{
    $oldApiRule = 'RedirectMatch 404 ^/modules/(?!accounting/|billing/|ap/|treasury/)[^/]+/api/.*\.php$';
    $rules = [
        'RedirectMatch 404 ^/modules/[^/]+/(?!api/).*\.php$',
        'RedirectMatch 404 ^/modules/(?!accounting/|billing/|ap/|treasury/|people/api/companies\.php$)[^/]+/api/.*\.php$',
    ];
    $counts = array_map(static fn(string $rule): int => substr_count($config, $rule), $rules);
    $oldCount = substr_count($config, $oldApiRule);
    if ($counts === [1, 1] && $oldCount === 0) return $config;
    if ($counts === [1, 0] && $oldCount === 1) {
        return str_replace($oldApiRule, $rules[1], $config);
    }
    if ($counts !== [0, 0] || $oldCount !== 0) {
        throw new RuntimeException('Standalone Apache module rules are incomplete or duplicated.');
    }

    $lineEnding = str_contains($config, "\r\n") ? "\r\n" : "\n";
    $anchor = '# Sensible defaults' . $lineEnding;
    if (substr_count($config, $anchor) !== 1) {
        throw new RuntimeException('Apache config is missing its expected insertion point.');
    }
    $block = '# Standalone accounting exposes finance module APIs, not legacy module pages.' . $lineEnding
        . implode($lineEnding, $rules) . $lineEnding . $lineEnding;
    return str_replace($anchor, $block . $anchor, $config);
}
