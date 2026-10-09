<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$privateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coreaccounting-mail-' . bin2hex(random_bytes(6));
if (!mkdir($privateDir, 0700)) throw new RuntimeException('Could not create private test directory');
$dbConfig = $privateDir . DIRECTORY_SEPARATOR . 'db.php';
$mailConfig = $privateDir . DIRECTORY_SEPARATOR . 'mail.php';
file_put_contents($dbConfig, "<?php\ndefine('DB_HOST', 'localhost');\ndefine('DB_NAME', 'qa_mail_config_test');\ndefine('DB_USER', 'synthetic');\ndefine('DB_PASS', 'synthetic');\n");
file_put_contents($mailConfig, "<?php\nputenv('COREFLUX_ACCOUNTING_MAIL_CONFIG_LOADED=yes');\n");
$probe = 'require ' . var_export($root . '/core/config.php', true)
    . '; echo (string) getenv("COREFLUX_ACCOUNTING_MAIL_CONFIG_LOADED");';
$baseEnv = array_merge(getenv(), [
    'COREFLUX_ENV' => 'coreaccounting',
    'COREFLUX_ACCOUNTING_DB_CONFIG_PATH' => $dbConfig,
    'COREFLUX_STANDALONE_DATABASE' => 'qa_mail_config_test',
    'COREFLUX_PUBLIC_ORIGIN' => 'https://stage.corefluxapp.com',
]);
$run = static function (string $path) use ($probe, $baseEnv): array {
    $env = array_merge($baseEnv, ['COREFLUX_ACCOUNTING_MAIL_CONFIG_PATH' => $path]);
    $process = proc_open([PHP_BINARY, '-r', $probe],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($process)) throw new RuntimeException('Could not start config probe');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string) $stdout, (string) $stderr];
};

try {
    [$status, $stdout] = $run($mailConfig);
    if ($status !== 0 || $stdout !== 'yes') throw new RuntimeException('Private mail config did not load');

    [$status, $stdout, $stderr] = $run('mail.php');
    if ($status === 0 || !str_contains($stdout . $stderr, 'private mail configuration is unavailable')) {
        throw new RuntimeException('Relative mail config was accepted');
    }

    [$status, $stdout, $stderr] = $run($root . '/core/config.local.example.php');
    if ($status === 0 || !str_contains($stdout . $stderr, 'private mail configuration is unavailable')) {
        throw new RuntimeException('In-webroot mail config was accepted');
    }

    [$status, $stdout, $stderr] = $run($privateDir . '/missing.php');
    if ($status === 0 || !str_contains($stdout . $stderr, 'private mail configuration is unavailable')) {
        throw new RuntimeException('Missing mail config was accepted');
    }
} finally {
    @unlink($dbConfig);
    @unlink($mailConfig);
    @rmdir($privateDir);
}

echo "CoreAccounting private mail config: passed\n";
