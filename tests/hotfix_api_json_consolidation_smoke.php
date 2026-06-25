<?php
/**
 * Hotfix smoke (2026-02):
 *   1. api_json() → api_ok() rename: AI settings/usage endpoints don't crash.
 *   2. Consolidation.jsx no longer violates hooks rule (double-useApi on
 *      same expression) — only one useApi('/modules/accounting/api/entities.php')
 *      call per component, with safe fallback chain.
 *   3. Map over relationships filters Boolean so a null row can't crash render.
 *
 *   php -d zend.assertions=1 /app/tests/hotfix_api_json_consolidation_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
require_once $ROOT . '/core/api_bootstrap.php';

// 1. api_json() must not appear in any active PHP file — it's not defined.
$apiJsonCallers = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT));
foreach ($it as $f) {
    if ($f->isDir()) continue;
    $p = $f->getPathname();
    $norm = str_replace('\\', '/', $p);
    if (!preg_match('/\.php$/', $p)) continue;
    if (str_contains($norm, '/dashboard/node_modules/')) continue;
    if (str_contains($norm, '/node_modules/')) continue;
    if (str_contains($norm, '/vendor/')) continue;
    if (str_contains($norm, '/spa-assets/')) continue;
    if (str_contains($norm, '/tests/')) continue;
    if (str_contains($norm, '/.codex_product_plan_extract/')) continue;
    $src = (string) file_get_contents($p);
    if (preg_match('/\bapi_json\s*\(/', $src)) $apiJsonCallers[] = $p;
}
$a('no source file calls undefined api_json()',
    count($apiJsonCallers) === 0,
);
if ($apiJsonCallers) {
    foreach ($apiJsonCallers as $f) echo "      · $f\n";
}
$a('api_ok() is defined',                  function_exists('api_ok'));
$a('api_json_body() is defined',           function_exists('api_json_body'));

// 2. Consolidation.jsx hooks rule fix.
$ui = (string) file_get_contents($ROOT . '/modules/accounting/ui/Consolidation.jsx');
$entitiesCalls = substr_count($ui, 'useApi(`${ACCOUNTING_ENTITIES_API}?scope=hierarchy`)');
$a('Consolidation.jsx calls useApi(v1 entities) exactly twice (once per component)',
    $entitiesCalls === 2);
$a('Consolidation.jsx uses ?? fallback rather than ||',
    str_contains($ui, "entitiesApi.data?.rows ?? entitiesApi.data?.entities"));
$a('relationships table filters Boolean before map',
    str_contains($ui, '.filter(Boolean).map'));

// 3. PHP syntax sanity
foreach (['api/admin/ai_settings.php','api/admin/ai_usage.php'] as $rel) {
    $rc = 0; $out = [];
    exec('php -l ' . escapeshellarg($ROOT . '/' . $rel) . ' 2>&1', $out, $rc);
    $a("php -l $rel", $rc === 0);
}

echo "\n=========================================\n";
echo "Hotfix smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
