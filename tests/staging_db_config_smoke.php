<?php
declare(strict_types=1);

$config = var_export(dirname(__DIR__) . '/core/config.php', true);

function runConfigCheck(string $code, array $env = []): array
{
    $env += ['COREFLUX_ACCOUNTING_DB_CONFIG_PATH' => null,
        'COREFLUX_DB_CONFIG_PATH' => null];
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

$configSource = file_get_contents(dirname(__DIR__) . '/core/config.php');
if ($configSource === false
    || preg_match("/define\\('DB_PASS',\\s*'[^']+'\\)/", $configSource)
    || preg_match("/define\\('DB_PASS',[^\\n]*:\\s*'[^']+'\\)/", $configSource)
    || preg_match("/define\\('SMTP_PASS',\\s*'[^']+'\\)/", $configSource)) {
    throw new RuntimeException('Shared configuration contains a source-embedded credential fallback.');
}

[$exit, $output] = runConfigCheck("require $config; echo json_encode([DB_HOST, DB_NAME, DB_USER, DB_PASS, SMTP_HOST, SMTP_USER, SMTP_PASS]);", [
    'COREFLUX_ENV' => null,
    'DB_HOST' => 'db.example.test', 'DB_NAME' => 'legacy_fixture',
    'DB_USER' => 'fixture_user', 'DB_PASS' => 'synthetic-db-only',
    'SMTP_HOST' => 'mail.example.test', 'SMTP_USER' => 'fixture_mail',
    'SMTP_PASS' => 'synthetic-mail-only',
]);
if ($exit !== 0 || json_decode(trim($output), true) !== [
    'db.example.test', 'legacy_fixture', 'fixture_user', 'synthetic-db-only',
    'mail.example.test', 'fixture_mail', 'synthetic-mail-only',
]) {
    throw new RuntimeException('Legacy ERP did not honor explicit database and mail settings.');
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
$privateFixture = tempnam(sys_get_temp_dir(), 'coreacc-db-');
if ($privateFixture === false || !rename($privateFixture, $privateFixture . '.php')) {
    throw new RuntimeException('Could not create a private database configuration fixture.');
}
$privateFixture .= '.php';
register_shutdown_function(static fn() => @unlink($privateFixture));
if (file_put_contents($privateFixture, '<?php if (!defined("DB_NAME")) { ' . $definitions . ' }') === false) {
    throw new RuntimeException('Could not write the private database configuration fixture.');
}

[$exit, $output] = runConfigCheck("require $config; echo DB_NAME;", [
    'COREFLUX_ENV' => null, 'COREFLUX_DB_CONFIG_PATH' => $privateFixture,
]);
if ($exit !== 0 || trim($output) !== 'stage_test') {
    throw new RuntimeException('ERP did not load its private database configuration.');
}
[$exit, $output] = runConfigCheck("require $config;", [
    'COREFLUX_ENV' => null,
    'COREFLUX_DB_CONFIG_PATH' => dirname(__DIR__) . '/core/db.local.example.php',
]);
if ($exit === 0 || !str_contains($output, 'Private ERP database configuration is unavailable.')) {
    throw new RuntimeException('ERP accepted a private database file inside the public checkout.');
}

[$exit, $output] = runConfigCheck("require $config; echo DB_NAME;", [
    'COREFLUX_ENV' => 'staging', 'COREFLUX_ACCOUNTING_DB_CONFIG_PATH' => $privateFixture,
]);
if ($exit !== 0 || trim($output) !== 'stage_test') {
    throw new RuntimeException('Staging CLI did not load the private database configuration.');
}

[$exit, $output] = runConfigCheck($definitions . " require $config; echo DB_NAME . ':' . (COREFLUX_STAGING ? 'staging' : 'prod');", ['COREFLUX_ENV' => 'staging']);
if ($exit !== 0 || trim($output) !== 'stage_test:staging') {
    throw new RuntimeException('Explicit staging database settings were not honored.');
}

$mailBootstrap = var_export(dirname(__DIR__) . '/core/mail_bootstrap.php', true);
[$exit, $output] = runConfigCheck($definitions . " require $mailBootstrap; echo cf_mail_bootstrap()->default_driver_name() . ':' . (cf_mail_bootstrap()->driver('resend') ? 'resend' : 'none');", ['COREFLUX_ENV' => 'staging']);
if ($exit !== 0 || !str_contains($output, 'log:none')) {
    throw new RuntimeException('Staging mail bootstrap did not enforce log-only delivery.');
}

[$exit, $output] = runConfigCheck($definitions . " require $config;", [
    'COREFLUX_ENV' => 'coreaccounting', 'COREFLUX_ACCOUNTING_DB_CONFIG_PATH' => null,
]);
if ($exit === 0 || !str_contains($output, 'CoreAccounting private database configuration is unavailable.')) {
    throw new RuntimeException('Standalone mode accepted an in-webroot or implicit database configuration.');
}

[$exit, $output] = runConfigCheck($definitions . " require $config;", [
    'COREFLUX_ENV' => 'coreaccounting',
    'COREFLUX_ACCOUNTING_DB_CONFIG_PATH' => dirname(__DIR__) . '/core/db.local.example.php',
]);
if ($exit === 0 || !str_contains($output, 'CoreAccounting private database configuration is unavailable.')) {
    throw new RuntimeException('Standalone mode accepted a database configuration inside the public webroot.');
}

[$exit, $output] = runConfigCheck($definitions . " require $config;", [
    'COREFLUX_ENV' => 'coreaccounting',
    'COREFLUX_ACCOUNTING_DB_CONFIG_PATH' => $privateFixture . '.missing',
]);
if ($exit === 0 || !str_contains($output, 'CoreAccounting private database configuration is unavailable.')) {
    throw new RuntimeException('Standalone mode accepted a missing private database configuration.');
}

[$exit, $output] = runConfigCheck($definitions . " require $config;", [
    'COREFLUX_ENV' => 'coreaccounting',
    'COREFLUX_ACCOUNTING_DB_CONFIG_PATH' => 'core/db.local.example.php',
]);
if ($exit === 0 || !str_contains($output, 'CoreAccounting private database configuration is unavailable.')) {
    throw new RuntimeException('Standalone mode accepted a relative database configuration path.');
}

$standalone = ['COREFLUX_ENV' => 'coreaccounting', 'COREFLUX_STANDALONE_DATABASE' => null,
    'COREFLUX_PUBLIC_ORIGIN' => null, 'COREFLUX_ACCOUNTING_DB_CONFIG_PATH' => $privateFixture];
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
$standalone['COREFLUX_ACCOUNTING_SMTP_HOST'] = null;
$standalone['COREFLUX_ACCOUNTING_SMTP_USER'] = null;
$standalone['COREFLUX_ACCOUNTING_SMTP_PASS'] = null;
$standalone['COREFLUX_ACCOUNTING_FROM_EMAIL'] = null;
$standalone['SIM_MOCK_EMAIL'] = null;
$standalone['SIM_MOCK_RESEND'] = null;
$standalone['SMTP_HOST'] = 'erp-smtp.example.test';
$standalone['SMTP_USER'] = 'erp-user';
$standalone['SMTP_PASS'] = 'erp-password';
$standalone['SMTP_FROM_EMAIL'] = 'erp-sender@example.test';
[$exit, $output] = runConfigCheck($definitions . " \$_SERVER['HTTP_HOST'] = 'phpstack-123.cloudwaysapps.com'; require $config;
    echo json_encode([DB_NAME, APP_URL, COREFLUX_STAGING, SMTP_HOST, SMTP_USER, SMTP_PASS, SMTP_FROM_EMAIL]);",
    $standalone);
if ($exit !== 0 || json_decode(trim($output), true) !==
    ['stage_test', 'https://accounting.example.test', false, '', '', '', '']) {
    throw new RuntimeException('Standalone mode did not isolate database, public origin and mail defaults.');
}

[$exit, $output] = runConfigCheck($definitions . " define('SMTP_USER', 'copied-erp-user'); require $config;", $standalone);
if ($exit === 0 || !str_contains($output, 'CoreAccounting SMTP settings must use dedicated environment variables.')) {
    throw new RuntimeException('Standalone mode accepted a copied ERP SMTP constant.');
}

$mailer = var_export(dirname(__DIR__) . '/core/mailer.php', true);
$smtpProbe = $definitions . " require $mailer;"
    . ' try { sendEmail(["to" => "test@example.test", "subject" => "Test", "body_text" => "Test"]); }'
    . ' catch (RuntimeException $e) { echo $e->getMessage(); }';
[$exit, $output] = runConfigCheck($smtpProbe, array_replace($standalone, [
    'SIM_MODE' => null, 'COREFLUX_DISABLE_DATABASE' => '1',
]));
if ($exit !== 0 || trim($output) !== 'CoreAccounting SMTP delivery is not configured.') {
    throw new RuntimeException('Standalone SMTP fallback did not fail closed.');
}

$ownSmtp = array_replace($standalone, [
    'COREFLUX_DISABLE_DATABASE' => '1',
    'COREFLUX_ACCOUNTING_SMTP_HOST' => 'accounting-smtp.example.test',
    'COREFLUX_ACCOUNTING_SMTP_USER' => 'accounting-user',
    'COREFLUX_ACCOUNTING_SMTP_PASS' => 'synthetic-only',
    'COREFLUX_ACCOUNTING_FROM_EMAIL' => 'accounting@example.test',
]);
[$exit, $output] = runConfigCheck($definitions . " require $config; echo json_encode([SMTP_HOST, SMTP_USER, SMTP_PASS, SMTP_FROM_EMAIL]);", $ownSmtp);
if ($exit !== 0 || json_decode(trim($output), true) !==
    ['accounting-smtp.example.test', 'accounting-user', 'synthetic-only', 'accounting@example.test']) {
    throw new RuntimeException('Standalone SMTP ignored its dedicated settings.');
}
$smtpOverrideProbe = $definitions . " require $mailer;"
    . ' try { sendEmail(["to" => "test@example.test", "subject" => "Test", "body_text" => "Test",'
    . ' "from_email" => "legacy@example.test"]); }'
    . ' catch (RuntimeException $e) { echo $e->getMessage(); }';
[$exit, $output] = runConfigCheck($smtpOverrideProbe, $ownSmtp);
if ($exit !== 0 || trim($output) !== 'From address does not match the configured CoreAccounting sender.') {
    throw new RuntimeException('Standalone SMTP accepted an ERP sender override.');
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

$tenantMail = var_export(dirname(__DIR__) . '/core/tenant_mail.php', true);
$senderProbe = $definitions . " require $config; require $tenantMail;"
    . ' $sender = cf_tenant_mail_sender(0); echo json_encode([$sender["from"], $sender["from_name"]]);';
[$exit, $output] = runConfigCheck($senderProbe, $mailEnv);
if ($exit !== 0 || json_decode(trim($output), true) !== [null, null]) {
    throw new RuntimeException('Standalone tenant mail inherited the ERP sender.');
}
[$exit, $output] = runConfigCheck($senderProbe, array_replace($mailEnv, [
    'COREFLUX_ACCOUNTING_FROM_EMAIL' => 'accounting@example.test',
    'COREFLUX_ACCOUNTING_FROM_NAME' => 'Accounting',
]));
if ($exit !== 0 || json_decode(trim($output), true) !== ['accounting@example.test', 'Accounting']) {
    throw new RuntimeException('Standalone tenant mail ignored its dedicated sender.');
}
[$exit, $output] = runConfigCheck($senderProbe, array_replace($mailEnv, ['COREFLUX_ENV' => null]));
if ($exit !== 0 || json_decode(trim($output), true)[0] !== 'legacy@example.test') {
    throw new RuntimeException('The existing ERP tenant mail sender changed.');
}

require_once dirname(__DIR__) . '/core/mail/ResendDriver.php';
$driver = new Core\Mail\ResendDriver('re_test', 'accounting@example.test', 'Accounting',
    static fn(array $request): array => ['ok' => true, 'id' => 'synthetic', 'http' => 200], true);
$rejected = $driver->send(['to' => ['test@example.test'], 'from' => 'legacy@example.test']);
if (($rejected['error'] ?? '') !== 'From address does not match the configured CoreAccounting sender') {
    throw new RuntimeException('Standalone provider accepted an ERP sender override.');
}
$accepted = $driver->send(['to' => ['test@example.test'], 'from' => 'accounting@example.test']);
if (($accepted['status'] ?? '') !== 'sent') {
    throw new RuntimeException('Standalone provider rejected its dedicated sender.');
}

echo "Staging database configuration checks passed.\n";
