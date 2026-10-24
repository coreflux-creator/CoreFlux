<?php
declare(strict_types=1);

[$script, $mode, $host, $expected, $override] = array_pad($argv, 5, '');
putenv('COREFLUX_ENV=' . ($mode === 'production' ? '' : 'staging'));
putenv('COREFLUX_STAGING_PUBLIC_ORIGIN=' . $override);
$_SERVER['HTTP_HOST'] = $host;
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $name) define($name, 'smoke');
try {
    require __DIR__ . '/../core/config.php';
    $actual = APP_URL;
} catch (RuntimeException $e) {
    $actual = '<reject>';
}
$passed = $actual === $expected;
echo ($passed ? 'OK' : 'FAIL') . " public origin: $mode / $host" . PHP_EOL;
exit($passed ? 0 : 1);
