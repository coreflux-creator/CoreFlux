<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/installer_helpers.php';

$failed = 0;
$check = static function (string $name, bool $ok) use (&$failed): void {
    echo ($ok ? 'OK ' : 'FAIL ') . $name . "\n";
    if (!$ok) $failed++;
};

$required = installerRequiredBaseTables();
$check('base schema includes platform identity and staffing records',
    in_array('tenants', $required, true) && in_array('users', $required, true)
    && in_array('placements', $required, true) && in_array('staffing_timesheets', $required, true));
$check('complete base schema passes', installerMissingBaseTables($required) === []);
$check('missing base tables are reported in dependency order',
    installerMissingBaseTables(['tenants', 'users']) === array_slice($required, 2));

$helper = file_get_contents(__DIR__ . '/../core/installer_helpers.php');
$installer = file_get_contents(__DIR__ . '/../install.php');
$check('preflight precedes the installer migration ledger',
    strpos($helper, 'installerCheckBaseSchema($pdo);')
        < strpos($helper, 'CREATE TABLE IF NOT EXISTS coreflux_migrations'));
$check('web installer rejects failed migration results',
    str_contains($installer, "['failed', 'unreadable']")
    && strpos($installer, "['failed', 'unreadable']") < strpos($installer, 'runSmokeInProcess($localCfg)'));

echo $failed === 0 ? "Passed: 5; Failed: 0\n" : "Failed: {$failed}\n";
exit($failed ? 1 : 0);
