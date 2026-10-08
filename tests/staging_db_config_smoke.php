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

[$exit, $output] = runConfigCheck("require $config;", ['COREFLUX_ENV' => 'coreaccounting']);
if ($exit === 0 || !str_contains($output, 'CoreAccounting database configuration is incomplete.')) {
    throw new RuntimeException('Standalone mode accepted implicit database settings.');
}

$standalone = ['COREFLUX_ENV' => 'coreaccounting', 'COREFLUX_STANDALONE_DATABASE' => null,
    'COREFLUX_PUBLIC_ORIGIN' => null];
[$exit, $output] = runConfigCheck($definitions . " require $config;", $standalone);
if ($exit === 0 || !str_contains($output, 'CoreAccounting database identity is missing or does not match.')) {
    throw new RuntimeException('Standalone mode accepted a database without an expected identity.');
}

[$exit, $output] = runConfigCheck($definitions . " require $config;",
    array_replace($standalone, ['COREFLUX_STANDALONE_DATABASE' => 'another_database']));
if ($exit === 0 || !str_contains($output, 'CoreAccounting database identity is missing or does not match.')) {
    throw new RuntimeException('Standalone mode accepted the wrong database.');
}

$standalone['COREFLUX_STANDALONE_DATABASE'] = 'stage_test';
[$exit, $output] = runConfigCheck($definitions . " require $config;", $standalone);
if ($exit === 0 || !str_contains($output, 'CoreAccounting requires an explicit HTTPS public origin.')) {
    throw new RuntimeException('Standalone mode accepted an implicit public origin.');
}

$standalone['COREFLUX_PUBLIC_ORIGIN'] = 'http://accounting.example.test';
[$exit, $output] = runConfigCheck($definitions . " require $config;", $standalone);
if ($exit === 0 || !str_contains($output, 'CoreAccounting requires an explicit HTTPS public origin.')) {
    throw new RuntimeException('Standalone mode accepted a non-HTTPS public origin.');
}

$standalone['COREFLUX_PUBLIC_ORIGIN'] = 'https://accounting.example.test';
$standalone['SMTP_USER'] = null;
$standalone['SMTP_PASS'] = null;
$standalone['SMTP_FROM_EMAIL'] = null;
[$exit, $output] = runConfigCheck($definitions . " \$_SERVER['HTTP_HOST'] = 'phpstack-123.cloudwaysapps.com'; require $config;
    echo json_encode([DB_NAME, APP_URL, COREFLUX_STAGING, SMTP_USER, SMTP_PASS, SMTP_FROM_EMAIL]);",
    $standalone);
if ($exit !== 0 || json_decode(trim($output), true) !==
    ['stage_test', 'https://accounting.example.test', false, '', '', '']) {
    throw new RuntimeException('Standalone mode did not isolate database, public origin and mail defaults.');
}

$mailProbe = $definitions . ' require ' . $mailBootstrap
    . '; $result = cf_mail_bootstrap()->driver("resend")->send(["to" => []]);'
    . ' echo $result["error"] ?? "";';
$mailEnv = array_replace($standalone, [
    'COREFLUX_DISABLE_DATABASE' => '1',
    'RESEND_API_KEY' => 're_legacy_test',
    'RESEND_FROM_EMAIL' => 'legacy@example.test',
    'COREFLUX_ACCOUNTING_RESEND_API_KEY' => null,
    'COREFLUX_ACCOUNTING_FROM_EMAIL' => null,
]);
[$exit, $output] = runConfigCheck($mailProbe, $mailEnv);
if ($exit !== 0 || trim($output) !== 'RESEND_API_KEY not configured') {
    throw new RuntimeException('Standalone mail inherited the ERP provider key.');
}

[$exit, $output] = runConfigCheck($mailProbe, array_replace($mailEnv, ['COREFLUX_ENV' => null]));
if ($exit !== 0 || trim($output) !== 'No recipients') {
    throw new RuntimeException('The existing ERP mail provider key stopped working.');
}

$mailEnv['COREFLUX_ACCOUNTING_RESEND_API_KEY'] = 're_accounting_test';
$mailWithRecipient = $definitions . ' require ' . $mailBootstrap
    . '; $result = cf_mail_bootstrap()->driver("resend")->send(["to" => ["test@example.test"]]);'
    . ' echo $result["error"] ?? "";';
[$exit, $output] = runConfigCheck($mailWithRecipient, $mailEnv);
if ($exit !== 0 || trim($output) !== 'From address not configured (set RESEND_FROM_EMAIL)') {
    throw new RuntimeException('Standalone mail inherited the ERP sender.');
}

echo "Staging database configuration checks passed.\n";
