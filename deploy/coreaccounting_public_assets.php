<?php
/** Identify the static files intentionally served by a CoreAccounting release. */
declare(strict_types=1);

function coreAccountingExpectedPublicFiles(string $root): array
{
    $root = realpath($root);
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException('CoreAccounting release root is unavailable.');
    }

    $fixed = [
        '404.html',
        'assets/brand/coreflux-logo.png',
        'assets/brand/coreflux-mark.png',
        'assets/css/legal.css',
        'assets/css/styles.css',
        'dashboard/dist/index.html',
        'login.html',
        'privacy.html',
        'quickbooks-connect.html',
        'quickbooks-disconnect.html',
        'spa-assets/sw.js',
        'terms.html',
    ];
    if (is_file($root . '/_deploy_ok.txt')) {
        $fixed[] = '_deploy_ok.txt';
    }
    foreach ($fixed as $relative) {
        if (!is_file($root . '/' . $relative) || is_link($root . '/' . $relative)) {
            throw new RuntimeException("Required public file is missing or linked: $relative");
        }
    }

    $html = file_get_contents($root . '/dashboard/dist/index.html');
    if ($html === false) throw new RuntimeException('Could not read the installed app entry.');
    preg_match_all('~/(?:spa-assets|assets)/(index-[A-Za-z0-9_-]+\.(?:js|css))(?=["\'])~',
        $html, $matches);
    $entries = array_values(array_unique($matches[1] ?? []));
    foreach (['js', 'css'] as $extension) {
        $ofType = array_filter($entries,
            static fn(string $name): bool => str_ends_with($name, '.' . $extension));
        if (count($ofType) !== 1) {
            throw new RuntimeException("Installed app must identify one $extension entry asset.");
        }
    }

    $queue = $entries;
    $assets = [];
    while ($queue) {
        $name = array_shift($queue);
        if (isset($assets[$name])) continue;
        $path = $root . '/spa-assets/' . $name;
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException("Installed app asset is missing or linked: $name");
        }
        $assets[$name] = true;
        $source = file_get_contents($path);
        if ($source === false) throw new RuntimeException("Could not read installed asset: $name");
        preg_match_all('/index-[A-Za-z0-9_-]+\.(?:js|css)/', $source, $references);
        foreach (array_unique($references[0] ?? []) as $reference) {
            if (!isset($assets[$reference])) $queue[] = $reference;
        }
    }

    $expected = array_merge($fixed, array_map(
        static fn(string $name): string => 'spa-assets/' . $name,
        array_keys($assets)
    ));
    sort($expected, SORT_STRING);
    return $expected;
}
