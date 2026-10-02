<?php
declare(strict_types=1);

$config = var_export(dirname(__DIR__) . '/core/config.php', true);

function runConfigCheck(string $code, array $env = []): array
{
    $original = [];
    foreach ($env as $name => $value) {
        $original[$name] = getenv($name);
        if ($value === null) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }

    $process = proc_open([PHP_BINARY, '-r', $code], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not launch PHP configuration check.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    foreach ($original as $name => $value) {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
    return [$exit, $output];
}

[$exit, $output] = runConfigCheck("require $config;", ['COREFLUX_ENV' => 'staging']);
if ($exit === 0 || !str_contains($output, 'Staging database configuration is incomplete.')) {
    throw new RuntimeException('Staging without explicit database settings did not fail closed.');
}

[$exit, $output] = runConfigCheck("\$_SERVER['HTTP_HOST'] = 'phpstack-123.cloudwaysapps.com'; require $config;", ['COREFLUX_ENV' => null]);
if ($exit === 0 || !str_contains($output, 'Staging database configuration is incomplete.')) {
    throw new RuntimeException('Cloudways default hostname did not fail closed.');
}

[$exit, $output] = runConfigCheck("\$_SERVER['HTTP_HOST'] = 'staging.corefluxapp.com'; require $config;", ['COREFLUX_ENV' => null]);
if ($exit === 0 || !str_contains($output, 'Staging database configuration is incomplete.')) {
    throw new RuntimeException('Custom staging hostname did not fail closed.');
}

$definitions = "define('DB_HOST', '127.0.0.1'); define('DB_NAME', 'stage_test'); define('DB_USER', 'stage_user'); define('DB_PASS', 'stage_pass');";
[$exit, $output] = runConfigCheck($definitions . " require $config; echo DB_NAME . ':' . (COREFLUX_STAGING ? 'staging' : 'prod');", ['COREFLUX_ENV' => 'staging']);
if ($exit !== 0 || trim($output) !== 'stage_test:staging') {
    throw new RuntimeException('Explicit staging database settings were not honored.');
}

$mailBootstrap = var_export(dirname(__DIR__) . '/core/mail_bootstrap.php', true);
[$exit, $output] = runConfigCheck($definitions . " require $mailBootstrap; echo cf_mail_bootstrap()->default_driver_name() . ':' . (cf_mail_bootstrap()->driver('resend') ? 'resend' : 'none');", ['COREFLUX_ENV' => 'staging']);
if ($exit !== 0 || !str_contains($output, 'log:none')) {
    throw new RuntimeException('Staging mail bootstrap did not enforce log-only delivery.');
}

echo "Staging database configuration checks passed.\n";
