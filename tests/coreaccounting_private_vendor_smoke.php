<?php
declare(strict_types=1);
require_once __DIR__ . '/../deploy/coreaccounting_vendor_layout.php';

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

$run = static function (array $args, array $environment = ['COREFLUX_ENV' => 'staging']): array {
    $command = array_merge([PHP_BINARY, __DIR__ . '/../deploy/privatize_coreaccounting_vendor.php'], $args);
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        null, $environment);
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
$standaloneEnvironment = [
    'COREFLUX_ENV' => 'coreaccounting',
    'COREFLUX_STANDALONE_WEBROOT' => $webroot,
    'COREFLUX_PUBLIC_ORIGIN' => 'https://accounting.example.test',
    'COREFLUX_STANDALONE_DATABASE' => 'fixture_accounting',
];
$standaloneArgs = ['--confirm-standalone-webroot', '--webroot=' . $webroot,
    '--expected-webroot=' . $webroot, '--private=' . $private,
    '--origin=https://accounting.example.test', '--database=fixture_accounting'];

try {
    [$code] = $run(['--confirm-disposable-staging', '--webroot=' . $webroot,
        '--expected-webroot=' . $privateParent, '--private=' . $private]);
    if ($code === 0 || !is_file($vendor . '/autoload.php') || is_dir($private)) {
        throw new RuntimeException('Unsafe webroot was accepted or mutated');
    }

    foreach ([
        '--origin=https://wrong.example.test',
        '--database=wrong_database',
    ] as $wrong) {
        $changed = array_map(static fn(string $arg): string =>
            str_starts_with($arg, explode('=', $wrong, 2)[0] . '=') ? $wrong : $arg,
            $standaloneArgs);
        [$code, $out, $err] = $run($changed, $standaloneEnvironment);
        if ($code === 0 || !str_contains($err . $out, 'identity must match host settings')
            || !is_file($vendor . '/autoload.php') || is_dir($private)) {
            throw new RuntimeException('Mismatched standalone identity was accepted or mutated: '
                . $code . ' ' . $err . ' ' . $out);
        }
    }

    [$code, $out, $err] = $run($standaloneArgs, $standaloneEnvironment);
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
    [$code, $out, $err] = $run($standaloneArgs, $standaloneEnvironment);
    if ($code !== 0 || $err !== '' || (json_decode($out, true)['already_private'] ?? null) !== true) {
        throw new RuntimeException('Standalone Composer rerun was not idempotent');
    }
    [$code] = $run(['--confirm-disposable-staging', '--webroot=' . $webroot,
        '--expected-webroot=' . $webroot, '--private=' . $privateParent . '/other']);
    if ($code === 0 || !is_file($private . '/vendor/autoload.php')) {
        throw new RuntimeException('A second private Composer destination was accepted');
    }

    $packageRoot = $base . '/package/public_html';
    $packageVendor = $packageRoot . '/vendor';
    mkdir($packageVendor . '/dompdf/dompdf/src', 0700, true);
    file_put_contents($packageVendor . '/autoload.php', "<?php\nclass CoreAccountingPortableVendorFixture {}\n");
    file_put_contents($packageVendor . '/dompdf/dompdf/src/Dompdf.php', "<?php\n");
    $packagePrivate = $base . '/package/private_runtime';
    $packageEnvironment = $standaloneEnvironment;
    $packageEnvironment['COREFLUX_STANDALONE_WEBROOT'] = $packageRoot;
    $packageArgs = ['--confirm-standalone-webroot', '--package-layout',
        '--webroot=' . $packageRoot, '--expected-webroot=' . $packageRoot,
        '--private=' . $packagePrivate,
        '--origin=https://accounting.example.test', '--database=fixture_accounting'];
    $wrongPackageArgs = array_map(static fn(string $arg): string =>
        str_starts_with($arg, '--private=') ? '--private=' . $base . '/package/not_private_runtime' : $arg,
        $packageArgs);
    [$code] = $run($wrongPackageArgs, $packageEnvironment);
    if ($code === 0 || !is_file($packageVendor . '/autoload.php') || is_dir($packagePrivate)) {
        throw new RuntimeException('Portable package accepted a wrong private sibling');
    }
    [$code, $out, $err] = $run($packageArgs, $packageEnvironment);
    if ($code !== 0 || $err !== '' || (json_decode($out, true)['already_private'] ?? null) !== false
        || file_get_contents($packageVendor . '/autoload.php') !== coreAccountingPortableVendorShim()
        || !is_file($packagePrivate . '/vendor/autoload.php')) {
        throw new RuntimeException('Portable Composer package failed: ' . $err . ' ' . $out);
    }
    require $packageVendor . '/autoload.php';
    if (!class_exists('CoreAccountingPortableVendorFixture')) {
        throw new RuntimeException('Portable autoload shim does not load private dependencies');
    }
    [$code, $out, $err] = $run($packageArgs, $packageEnvironment);
    if ($code !== 0 || $err !== '' || (json_decode($out, true)['already_private'] ?? null) !== true) {
        throw new RuntimeException('Portable Composer rerun was not idempotent');
    }
    $hostAutoload = $base . '/host-private/vendor/autoload.php';
    mkdir(dirname($hostAutoload), 0700, true);
    file_put_contents($hostAutoload, "<?php\nclass CoreAccountingHostVendorFixture {}\n");
    $checkHostPath = static function (string $autoloadPath, string $expectedClass)
        use ($packageVendor): int {
        $process = proc_open([PHP_BINARY, '-r',
            'require $argv[1]; if (!class_exists($argv[2])) exit(3);',
            $packageVendor . '/autoload.php', $expectedClass],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            ['COREFLUX_ACCOUNTING_PRIVATE_VENDOR_AUTOLOAD' => $autoloadPath]);
        if (!is_resource($process)) throw new RuntimeException('Could not check host Composer path');
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process);
    };
    if ($checkHostPath($hostAutoload, 'CoreAccountingHostVendorFixture') !== 0
        || $checkHostPath($packageVendor . '/dompdf/dompdf/src/Dompdf.php',
            'CoreAccountingHostVendorFixture') === 0
        || $checkHostPath($base . '/missing-autoload.php', 'CoreAccountingHostVendorFixture') === 0) {
        throw new RuntimeException('Host Composer override did not enforce a private existing path');
    }
    echo "Passed: private Composer runtime, portable shim, rerun, and unsafe-target refusals\n";
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
