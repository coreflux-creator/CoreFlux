<?php
declare(strict_types=1);

require_once __DIR__ . '/../deploy/coreaccounting_apache_boundary.php';
$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coreaccounting-package-' . bin2hex(random_bytes(6));
$root = $base . DIRECTORY_SEPARATOR . 'public_html';
if (!mkdir($root, 0700, true)) throw new RuntimeException('Could not create package fixture.');
$put = static function (string $relative, string $content = 'fixture') use ($root): void {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) {
        throw new RuntimeException("Could not create fixture parent: $relative");
    }
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException("Could not create fixture: $relative");
    }
};
foreach ([
    '404.html', 'assets/brand/coreflux-logo.png', 'assets/brand/coreflux-mark.png',
    'assets/css/legal.css', 'assets/css/styles.css', 'login.html', 'privacy.html',
    'quickbooks-connect.html', 'quickbooks-disconnect.html', 'spa-assets/sw.js',
    'terms.html', 'vendor/autoload.php',
] as $file) $put($file);
$put('dashboard/dist/index.html',
    '<script src="/spa-assets/index-current.js"></script><link href="/spa-assets/index-current.css" rel="stylesheet">');
$put('spa-assets/index-current.js');
$put('spa-assets/index-current.css');
$apache = file_get_contents(__DIR__ . '/../.htaccess');
if ($apache === false) throw new RuntimeException('Could not read Apache fixture.');
$put('.htaccess', coreAccountingStandaloneApacheConfig($apache));

$manifestScript = __DIR__ . '/../deploy/create_coreaccounting_release_manifest.php';
$commit = str_repeat('a', 40);
$run = static function (string $output) use ($manifestScript, $root, $commit): array {
    $original = getenv('COREFLUX_ENV');
    putenv('COREFLUX_ENV=coreaccounting');
    try {
        $process = proc_open([PHP_BINARY, $manifestScript, '--confirm-package-build',
            '--root=' . $root, '--output=' . $output, '--commit=' . $commit],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not run manifest fixture.');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), (string) $stdout . (string) $stderr];
    } finally {
        $original === false ? putenv('COREFLUX_ENV') : putenv('COREFLUX_ENV=' . $original);
    }
};
$verifyScript = __DIR__ . '/../deploy/verify_coreaccounting_release.php';
$verify = static function (string $output, string $trustedHash) use ($verifyScript, $root, $commit): array {
    $originalEnvironment = getenv('COREFLUX_ENV');
    $originalRoot = getenv('COREFLUX_STANDALONE_WEBROOT');
    putenv('COREFLUX_ENV=coreaccounting');
    putenv('COREFLUX_STANDALONE_WEBROOT=' . $root);
    try {
        $process = proc_open([PHP_BINARY, $verifyScript, '--confirm-read-only-package',
            '--root=' . $root, '--manifest=' . $output,
            '--commit=' . $commit, '--manifest-sha256=' . $trustedHash],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not run release verifier fixture.');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), (string) $stdout . (string) $stderr];
    } finally {
        $originalEnvironment === false ? putenv('COREFLUX_ENV') : putenv('COREFLUX_ENV=' . $originalEnvironment);
        $originalRoot === false ? putenv('COREFLUX_STANDALONE_WEBROOT')
            : putenv('COREFLUX_STANDALONE_WEBROOT=' . $originalRoot);
    }
};

try {
    $output = $base . DIRECTORY_SEPARATOR . 'manifest.json';
    [$status, $summaryJson] = $run($output);
    $summary = json_decode($summaryJson, true);
    $manifest = json_decode((string) file_get_contents($output), true);
    if ($status !== 0 || !is_array($summary) || !is_array($manifest)
        || ($summary['expected_public_files'] ?? 0) !== 14
        || ($manifest['source_commit'] ?? null) !== $commit
        || ($manifest['files']['login.html'] ?? null) !== hash_file('sha256', $root . '/login.html')
        || ($summary['manifest_sha256'] ?? null) !== hash_file('sha256', $output)) {
        throw new RuntimeException('Prepared release manifest did not match its fixture.');
    }
    $trustedHash = hash_file('sha256', $output);
    [$status, $verificationJson] = $verify($output, (string) $trustedHash);
    $verification = json_decode($verificationJson, true);
    if ($status !== 0 || !is_array($verification)
        || ($verification['file_count'] ?? null) !== $manifest['file_count']) {
        throw new RuntimeException('Exact extracted release failed verification.');
    }
    [$status] = $verify($output, str_repeat('b', 64));
    if ($status === 0) throw new RuntimeException('Verifier accepted the wrong trusted manifest hash.');

    $put('login.html', 'changed');
    [$status, $verificationJson] = $verify($output, (string) $trustedHash);
    $verification = json_decode($verificationJson, true);
    if ($status === 0 || !in_array('login.html', $verification['changed'] ?? [], true)) {
        throw new RuntimeException('Verifier accepted a changed public page.');
    }
    $put('login.html');
    $put('unexpected.txt');
    [$status, $verificationJson] = $verify($output, (string) $trustedHash);
    $verification = json_decode($verificationJson, true);
    if ($status === 0 || !in_array('unexpected.txt', $verification['extra'] ?? [], true)) {
        throw new RuntimeException('Verifier accepted an extra packaged file.');
    }
    unlink($root . '/unexpected.txt');

    [$status] = $run($root . DIRECTORY_SEPARATOR . 'inside.json');
    if ($status === 0 || file_exists($root . '/inside.json')) {
        throw new RuntimeException('Manifest writer accepted an in-webroot destination.');
    }
    $put('composer.json');
    [$status] = $run($base . DIRECTORY_SEPARATOR . 'second.json');
    if ($status === 0 || file_exists($base . '/second.json')) {
        throw new RuntimeException('Manifest writer accepted build-only files in webroot.');
    }
    echo "CoreAccounting release manifest: passed\n";
} finally {
    $resolved = realpath($base);
    $temp = realpath(sys_get_temp_dir());
    if ($resolved === false || $temp === false
        || !str_starts_with($resolved, $temp . DIRECTORY_SEPARATOR . 'coreaccounting-package-')) {
        throw new RuntimeException('Refusing fixture cleanup outside the test directory.');
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
