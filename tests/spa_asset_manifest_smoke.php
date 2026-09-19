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
$updater = (string) file_get_contents($root . '/update.php');
$lightDeploy = (string) file_get_contents($root . '/.github/workflows/deploy-light-workspace.yml');
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

$assetQueue = array_values(array_filter([$js, $css]));
$seenAssets = [];
$missingAssets = [];
$unstampedAssets = [];
for ($i = 0; $i < count($assetQueue); $i++) {
    $asset = $assetQueue[$i];
    if (isset($seenAssets[$asset])) continue;
    $seenAssets[$asset] = true;

    $path = $root . '/spa-assets/' . $asset;
    if (!is_file($path)) {
        $missingAssets[] = $asset;
        continue;
    }
    if (!str_contains($stamp, 'spa-assets/' . $asset)) {
        $unstampedAssets[] = $asset;
    }
    if (!str_ends_with($asset, '.js')) continue;

    preg_match_all('/index-[A-Za-z0-9_-]+\.(?:js|css)/', (string) file_get_contents($path), $references);
    foreach (array_unique($references[0] ?? []) as $reference) {
        if (!isset($seenAssets[$reference])) $assetQueue[] = $reference;
    }
}
$a('spa-assets contains every recursively referenced bundle asset', $missingAssets === []);
if ($missingAssets !== []) echo 'Missing: ' . implode(', ', $missingAssets) . "\n";
$a('.deploy-version stamps every recursively referenced bundle asset', $unstampedAssets === []);
if ($unstampedAssets !== []) echo 'Unstamped: ' . implode(', ', $unstampedAssets) . "\n";

$manifestGuard = strpos($updater, 'if (!$expectedBundles)');
$newestJs = strpos($updater, '$keepNewest($jsList);');
$newestCss = strpos($updater, '$keepNewest($cssList);');
$a('updater only collapses bundle siblings when no manifest exists',
    $manifestGuard !== false
    && $newestJs !== false
    && $newestCss !== false
    && $manifestGuard < $newestJs
    && $newestJs < $newestCss);
$a('light workspace release packages the updater',
    str_contains($lightDeploy, "            update.php \\\n"));
$a('light workspace release lints the updater on production',
    str_contains($lightDeploy, "            for PHP_FILE in \\\n              update.php \\\n"));
$a('light workspace release runs the SPA asset regression on production',
    str_contains($lightDeploy, 'php tests/spa_asset_manifest_smoke.php'));

echo "spa_asset_manifest_smoke: {$ok} ok / {$fail} fail\n";
exit($fail ? 1 : 0);
