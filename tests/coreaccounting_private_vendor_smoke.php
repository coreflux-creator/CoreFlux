<?php
declare(strict_types=1);

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coreaccounting-vendor-' . bin2hex(random_bytes(6));
$webroot = $base . DIRECTORY_SEPARATOR . 'public_html';
$privateParent = $base . DIRECTORY_SEPARATOR . 'private';
$vendor = $webroot . DIRECTORY_SEPARATOR . 'vendor';
mkdir($vendor . '/dompdf/dompdf/src', 0700, true);
mkdir($vendor . '/dompdf/dompdf/lib/res', 0700, true);
mkdir($privateParent, 0700, true);
file_put_contents($vendor . '/autoload.php', "<?php\nclass CoreAccountingVendorFixture {}\n");
file_put_contents($vendor . '/dompdf/dompdf/src/Dompdf.php', "<?php\n");
file_put_contents($vendor . '/dompdf/dompdf/lib/res/broken_image.png', 'fixture');

$run = static function (array $args): array {
    $command = array_merge([PHP_BINARY, __DIR__ . '/../deploy/privatize_coreaccounting_vendor.php'], $args);
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        null, ['COREFLUX_ENV' => 'staging']);
    if (!is_resource($process)) throw new RuntimeException('Could not start vendor packaging fixture');
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $err];
};
$private = $privateParent . DIRECTORY_SEPARATOR . 'release';
$args = ['--confirm-disposable-staging', '--webroot=' . $webroot,
    '--expected-webroot=' . $webroot, '--private=' . $private];

try {
    [$code] = $run(['--confirm-disposable-staging', '--webroot=' . $webroot,
        '--expected-webroot=' . $privateParent, '--private=' . $private]);
    if ($code === 0 || !is_file($vendor . '/autoload.php') || is_dir($private)) {
        throw new RuntimeException('Unsafe webroot was accepted or mutated');
    }

    [$code, $out, $err] = $run($args);
    $result = json_decode($out, true);
    if ($code !== 0 || $err !== '' || ($result['already_private'] ?? null) !== false
        || !is_file($private . '/vendor/dompdf/dompdf/lib/res/broken_image.png')
        || !is_file($vendor . '/autoload.php')) {
        throw new RuntimeException('Private Composer move failed (' . $code . '): ' . $err . ' ' . $out);
    }
    require $vendor . '/autoload.php';
    if (!class_exists('CoreAccountingVendorFixture')) {
        throw new RuntimeException('Public autoload shim does not load private dependencies');
    }
    [$code, $out, $err] = $run($args);
    $result = json_decode($out, true);
    if ($code !== 0 || $err !== '' || ($result['already_private'] ?? null) !== true) {
        throw new RuntimeException('Private Composer rerun was not idempotent');
    }
    [$code] = $run(['--confirm-disposable-staging', '--webroot=' . $webroot,
        '--expected-webroot=' . $webroot, '--private=' . $privateParent . '/other']);
    if ($code === 0 || !is_file($private . '/vendor/autoload.php')) {
        throw new RuntimeException('A second private Composer destination was accepted');
    }
    echo "Passed: private Composer runtime, autoload shim, rerun, and unsafe-target refusals\n";
} finally {
    $resolved = realpath($base);
    $temp = realpath(sys_get_temp_dir());
    if ($resolved === false || $temp === false
        || !str_starts_with($resolved, $temp . DIRECTORY_SEPARATOR . 'coreaccounting-vendor-')) {
        throw new RuntimeException('Refusing cleanup outside the vendor fixture directory');
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        if ($file->isDir()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($resolved);
}
