<?php
/**
 * Module routing smoke — defends against the "this module is being developed"
 * regression where:
 *   1. session.php derived the slug from `name` instead of `id` ("Accounts
 *      Payable" → `accounts_payable` → fell through to GenericModule).
 *   2. App.jsx had `/modules/payroll/*` commented out.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

echo "session.php — slug derivation\n";
$session = (string) file_get_contents(__DIR__ . '/../session.php');
$a('prefers explicit $mod[\'id\'] over name-derived slug',
    strpos($session, "\$id = \$mod['id'] ?? strtolower") !== false);
$a('does NOT compute slug from name first (would break AP)',
    strpos($session, "strtolower(str_replace(' ', '_', \$mod['name'] ?? \$mod['id'] ?? 'module'))") === false);
$a('active_module also prefers id',
    strpos($session, "\$id = \$activeModule['id'] ?? strtolower") !== false);

echo "\ncore/modules.php — catalog has both ap + payroll with id keys\n";
require_once __DIR__ . '/../core/modules.php';
$cat = getModuleDefinitions();
$a('AP module defined with id=ap',                  isset($cat['ap']) && ($cat['ap']['id'] ?? null) === 'ap');
$a('AP name = "Accounts Payable" (would slug to accounts_payable if regressed)',
    ($cat['ap']['name'] ?? '') === 'Accounts Payable');
$a('Payroll module defined with id=payroll',        isset($cat['payroll']) && ($cat['payroll']['id'] ?? null) === 'payroll');

// Simulate the session.php transform on the catalog and assert the slug.
$transform = function (array $mod): string {
    return $mod['id'] ?? strtolower(str_replace(' ', '_', $mod['name'] ?? 'module'));
};
$a('AP catalog → slug "ap"',          $transform($cat['ap'])      === 'ap');
$a('Payroll catalog → slug "payroll"', $transform($cat['payroll']) === 'payroll');
$a('Accounting catalog → slug "accounting"', $transform($cat['accounting']) === 'accounting');

echo "\nApp.jsx + built bundle — every module has a Route\n";
$app = (string) file_get_contents(__DIR__ . '/../dashboard/src/App.jsx');
foreach (['people','placements','time','billing','ap','accounting','payroll'] as $m) {
    $a("App.jsx wires /modules/$m/*",
        preg_match('#<Route\s+path="/modules/' . preg_quote($m, '#') . '/\*"#', $app) === 1);
}
// And no commented-out payroll route lying around.
$a('No commented-out payroll route in App.jsx',
    !preg_match('#/\*\s*<Route\s+path="/modules/payroll/\*"#', $app));

// DEMO_SESSION fallback (used when /session.php is unreachable) must list
// every catalog module so admin previews show the same nav as production.
foreach (['people','placements','time','billing','ap','accounting','payroll'] as $m) {
    $a("DEMO_SESSION includes id: '$m'",
        preg_match("#id:\\s*'$m'#", $app) === 1);
}
$a('No "Payroll module ... omitted" stale comment',
    !preg_match('#Payroll module is approved.*omitted#s', $app));

// Built bundle carries every route.
$bundles = glob(__DIR__ . '/../spa-assets/index-*.js');
$bundle  = $bundles ? (string) file_get_contents($bundles[0]) : '';
foreach (['people','placements','time','billing','ap','accounting','payroll'] as $m) {
    $a("spa-assets bundle has /modules/$m/*",  strpos($bundle, "/modules/$m/*") !== false);
}

echo "\nGenericModule fallback message location is unchanged (regression marker)\n";
$gen = (string) file_get_contents(__DIR__ . '/../dashboard/src/modules/GenericModule.jsx');
$a('GenericModule still has the fallback string — only the catch-all should hit it',
    strpos($gen, 'This module is being developed') !== false);

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
