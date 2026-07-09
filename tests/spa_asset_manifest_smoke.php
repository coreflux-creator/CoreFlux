<?php
/**
 * SPA asset manifest smoke.
 *
 * Production must serve the exact Vite bundle referenced by
 * dashboard/dist/index.html / .deploy-version. If spa.php guesses by mtime
 * first, a Git deploy can mix old JS with new CSS and make pushed changes
 * appear invisible.
 */
$root = dirname(__DIR__);
$spa = (string) file_get_contents($root . '/spa.php');
$dist = (string) file_get_contents($root . '/dashboard/dist/index.html');
$stamp = (string) file_get_contents($root . '/.deploy-version');

$ok = 0;
$fail = 0;
$a = function (string $label, bool $cond) use (&$ok, &$fail): void {
    echo ($cond ? "OK   " : "FAIL ") . $label . "\n";
    $cond ? $ok++ : $fail++;
};

$a('spa.php has dist manifest picker', str_contains($spa, 'corefluxSpaPickAssetFromDist'));
$a('spa.php has deploy stamp fallback', str_contains($spa, 'corefluxSpaPickAssetFromStamp'));
$a('dist picker runs before mtime fallback',
    strpos($spa, 'corefluxSpaPickAssetFromDist') !== false
    && strpos($spa, 'Last-resort fallback') !== false
    && strpos($spa, 'corefluxSpaPickAssetFromDist') < strpos($spa, 'Last-resort fallback'));

preg_match('#/spa-assets/(index-[A-Za-z0-9_-]+\.js)#', $dist, $distJs);
preg_match('#/spa-assets/(index-[A-Za-z0-9_-]+\.css)#', $dist, $distCss);
$js = $distJs[1] ?? '';
$css = $distCss[1] ?? '';

$a('dashboard/dist/index.html declares JS bundle', $js !== '');
$a('dashboard/dist/index.html declares CSS bundle', $css !== '');
$a('spa-assets contains dist JS bundle', $js !== '' && is_file($root . '/spa-assets/' . $js));
$a('spa-assets contains dist CSS bundle', $css !== '' && is_file($root . '/spa-assets/' . $css));
$a('.deploy-version points at dist JS bundle', $js !== '' && str_contains($stamp, 'spa-assets/' . $js));
$a('.deploy-version points at dist CSS bundle', $css !== '' && str_contains($stamp, 'spa-assets/' . $css));

echo "spa_asset_manifest_smoke: {$ok} ok / {$fail} fail\n";
exit($fail ? 1 : 0);
