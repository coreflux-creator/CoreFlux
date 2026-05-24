<?php
/**
 * Smoke for require_once path correctness across every PHP endpoint.
 *
 * Why this exists: on 2026-02-XX the /api/admin/integrations/field_map.php
 * endpoint shipped to prod with `require_once __DIR__ . '/../../core/…'`
 * which silently resolves to /api/core/ (nonexistent) instead of /core/.
 * Because the file lives at /api/admin/integrations/ it needs THREE
 * levels of `..`, not two. The bug was latent in dev (where include_path
 * masked it) and surfaced as a fatal "Failed opening required" in prod.
 *
 * This test walks every .php under /api/ and asserts that every
 * __DIR__ relative require/require_once/include/include_once string
 * actually resolves to an existing file when expanded against the
 * file's real location. Catches typos before they hit prod.
 */
declare(strict_types=1);

$pass = 0; $fail = 0; $skipped = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};

$apiRoot = realpath(__DIR__ . '/../api');
if ($apiRoot === false) {
    echo "  ✗ /api directory not found\n"; exit(1);
}

$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($apiRoot, RecursiveDirectoryIterator::SKIP_DOTS));
$badFiles = [];
$checked = 0;

foreach ($iter as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $checked++;
    $src = (string) file_get_contents($f->getPathname());
    // Match: require/require_once/include/include_once  __DIR__ . '/<rel>'
    //   OR:  require/require_once/include/include_once  __DIR__ . "/<rel>"
    if (!preg_match_all(
        '/(?:require|require_once|include|include_once)\s*(?:\(\s*)?__DIR__\s*\.\s*[\'"]([^\'"\n]+)[\'"]/',
        $src, $matches)
    ) continue;

    $dir = dirname($f->getPathname());
    foreach ($matches[1] as $rel) {
        // Skip dynamic paths that interpolate variables.
        if (str_contains($rel, '$') || str_contains($rel, '{')) { $skipped++; continue; }
        $resolved = realpath($dir . $rel);
        if ($resolved === false || !is_file($resolved)) {
            $badFiles[] = sprintf('  %s → "%s" (resolves to %s)',
                str_replace($apiRoot, '', $f->getPathname()),
                $rel,
                $dir . $rel
            );
        }
    }
}

echo "require_once path check — scanned {$checked} .php files under /api/, skipped {$skipped} dynamic paths\n";
if (count($badFiles) === 0) {
    echo "  ✓ every static __DIR__-relative require/include resolves to an existing file\n";
    $pass++;
} else {
    echo "  ✗ " . count($badFiles) . " broken require path(s):\n";
    foreach ($badFiles as $b) echo $b . "\n";
    $fail++;
}

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
