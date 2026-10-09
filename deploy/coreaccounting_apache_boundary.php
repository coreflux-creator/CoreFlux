<?php
declare(strict_types=1);

/** Add standalone-only direct-module denials without changing the shared ERP rules. */
function coreAccountingStandaloneApacheConfig(string $config): string
{
    $oldApiRule = 'RedirectMatch 404 ^/modules/(?!accounting/|billing/|ap/|treasury/)[^/]+/api/.*\.php$';
    $rules = [
        'RedirectMatch 404 ^/modules/[^/]+/(?!api/).*\.php$',
        'RedirectMatch 404 ^/modules/(?!accounting/|billing/|ap/|treasury/|people/api/companies\.php$)[^/]+/api/.*\.php$',
        'RedirectMatch 404 ^/data(?:/|$)',
        'RedirectMatch 404 ^/modules/(?!accounting(?:/|$)|billing(?:/|$)|ap(?:/|$)|treasury(?:/|$)|people/api/companies\.php$)[^/]+(?:/|$)',
        'RedirectMatch 404 ^/modules/[^/]+/assets(?:/|$)',
    ];
    $counts = array_map(static fn(string $rule): int => substr_count($config, $rule), $rules);
    $oldCount = substr_count($config, $oldApiRule);
    if ($oldCount === 1 && $counts[0] === 1 && $counts[1] === 0) {
        $config = str_replace($oldApiRule, $rules[1], $config);
        $counts[1] = 1;
        $oldCount = 0;
    }
    $lineEnding = str_contains($config, "\r\n") ? "\r\n" : "\n";
    if ($counts[0] === 1 && $counts[1] === 1 && $oldCount === 0
        && in_array($counts[2], [0, 1], true) && in_array($counts[3], [0, 1], true)
        && in_array($counts[4], [0, 1], true)) {
        foreach ([2, 3, 4] as $index) {
            if ($counts[$index] === 1) continue;
            $anchor = $rules[$index - 1] . $lineEnding;
            if (substr_count($config, $anchor) !== 1) {
                throw new RuntimeException('Standalone Apache module rules have an unexpected layout.');
            }
            $config = str_replace($anchor, $anchor . $rules[$index] . $lineEnding, $config);
            $counts[$index] = 1;
        }
        return $config;
    }
    if ($counts !== [0, 0, 0, 0, 0] || $oldCount !== 0) {
        throw new RuntimeException('Standalone Apache module rules are incomplete or duplicated.');
    }

    $anchor = '# Sensible defaults' . $lineEnding;
    if (substr_count($config, $anchor) !== 1) {
        throw new RuntimeException('Apache config is missing its expected insertion point.');
    }
    $block = '# Standalone accounting exposes finance module APIs, not legacy module pages.' . $lineEnding
        . implode($lineEnding, $rules) . $lineEnding . $lineEnding;
    return str_replace($anchor, $block . $anchor, $config);
}
