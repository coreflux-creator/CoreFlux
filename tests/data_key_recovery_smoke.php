<?php
declare(strict_types=1);

$ok = 0;
$bad = 0;
$assert = static function (string $label, bool $condition, string $detail = '') use (&$ok, &$bad): void {
    echo ($condition ? "  ✓ " : "  ✗ ") . $label . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
    $condition ? $ok++ : $bad++;
};

$root = dirname(__DIR__);
$script = $root . '/scripts/recover_data_key.php';
$tmp = sys_get_temp_dir() . '/coreflux-key-recovery-' . bin2hex(random_bytes(6));
$core = $tmp . '/public_html/core';
$private = $tmp . '/private_html';
$recovery = $tmp . '/recovery';
mkdir($core, 0770, true);
mkdir($private, 0770, true);
mkdir($recovery, 0700, true);

$key = base64_encode(str_repeat("K", 32));
$config = $core . '/config.local.php';
file_put_contents($config, "<?php\ndefine('COREFLUX_DATA_KEY', " . var_export($key, true) . ");\n");

$run = static function (string $mode) use ($script, $tmp, $recovery): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' '
        . escapeshellarg($mode) . ' ' . escapeshellarg($tmp) . ' '
        . escapeshellarg($recovery . '/.coreflux-data-key-recovery') . ' 2>&1';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    return [$code, implode("\n", $output)];
};

[$captureCode, $captureOutput] = $run('capture');
$assert('capture succeeds with a valid original key', $captureCode === 0, $captureOutput);
$assert('capture output withholds the key', !str_contains($captureOutput, $key));
$assert('escrow is created outside restored web folders', is_file($recovery . '/.coreflux-data-key-recovery'));

file_put_contents($config, "<?php\ndefine('QBO_ENV', 'sandbox');\n");
[$restoreCode, $restoreOutput] = $run('restore');
$restoredConfig = (string) file_get_contents($config);
$privateConfig = (string) file_get_contents($private . '/coreflux.secrets.php');
$assert('restore succeeds from escrow', $restoreCode === 0, $restoreOutput);
$assert('restore output withholds the key', !str_contains($restoreOutput, $key));
$assert('restored config contains the original key', str_contains($restoredConfig, $key));
$assert('durable private backup contains the original key', str_contains($privateConfig, $key));
$assert('unrelated host config is preserved', str_contains($restoredConfig, "define('QBO_ENV', 'sandbox')"));

$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $remove($path . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($path);
    } elseif (is_file($path)) {
        unlink($path);
    }
};
$remove($tmp);

echo "Data-key recovery smoke: {$ok} ✓ / {$bad} ✗" . PHP_EOL;
exit($bad === 0 ? 0 : 1);
