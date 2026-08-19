<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $condition) use (&$pass, &$fail): void {
    if ($condition) {
        echo "  ok - {$label}\n";
        $pass++;
    } else {
        echo "  FAIL - {$label}\n";
        $fail++;
    }
};

require_once $root . '/core/installer_helpers.php';
$installer = (string) file_get_contents($root . '/core/installer_helpers.php');
$encryption = (string) file_get_contents($root . '/core/encryption.php');
$update = (string) file_get_contents($root . '/update.php');

echo "Updater data-key resilience smoke\n";
echo "=================================\n";

$testKey = base64_encode(str_repeat('k', 32));
putenv('COREFLUX_TEST_CONFIG_VALUE=' . $testKey);
$assert('managed-host config helper reads process environment',
    installerConfigValue('COREFLUX_TEST_CONFIG_VALUE') === $testKey);
putenv('COREFLUX_TEST_CONFIG_VALUE');
$_SERVER['COREFLUX_TEST_SERVER_VALUE'] = $testKey;
$assert('managed-host config helper reads PHP-FPM server variables',
    installerConfigValue('COREFLUX_TEST_SERVER_VALUE') === $testKey);
unset($_SERVER['COREFLUX_TEST_SERVER_VALUE']);

$assert('installer checks environment-backed COREFLUX_DATA_KEY',
    str_contains($installer, "installerConfigValue('COREFLUX_DATA_KEY')"));
$assert('installer does not attempt encryption without a valid key',
    str_contains($installer, 'if ($keyOk)')
        && str_contains($installer, 'not attempted until the original COREFLUX_DATA_KEY is restored'));
$assert('missing-key guidance prevents accidental key rotation',
    str_contains($installer, 'Do not generate a replacement when encrypted data already exists.'));
$assert('encryption supports PHP-FPM server and environment variables',
    str_contains($encryption, "\$_SERVER['COREFLUX_DATA_KEY']")
        && str_contains($encryption, "\$_ENV['COREFLUX_DATA_KEY']"));
$assert('updater catches smoke failures without discarding prior results',
    str_contains($update, 'health check failed without interrupting the update')
        && str_contains($update, '$smokeChecks = runSmokeInProcess($localCfg)'));
$assert('top-level smoke status reflects individual checks',
    str_contains($update, '$smokeOk = !array_filter($smokeChecks'));

foreach (['core/installer_helpers.php', 'core/encryption.php', 'update.php'] as $file) {
    $output = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $output, $rc);
    $assert('php -l ' . $file, $rc === 0);
}

echo "\n{$pass} passed / {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
