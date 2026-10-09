<?php
/** Move Composer dependencies outside a disposable standalone webroot. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !in_array('--confirm-disposable-staging', $argv, true)) {
    fwrite(STDERR, "Disposable staging CLI only.\n");
    exit(2);
}

$option = static function (string $name) use ($argv): string {
    $prefix = "--$name=";
    $matches = array_values(array_filter($argv, static fn(string $arg): bool => str_starts_with($arg, $prefix)));
    return count($matches) === 1 ? substr($matches[0], strlen($prefix)) : '';
};
$webroot = realpath($option('webroot'));
$expected = realpath($option('expected-webroot'));
$privatePath = $option('private');
$parent = $privatePath !== '' ? realpath(dirname($privatePath)) : false;
$normalizedPrivatePath = str_replace('\\', '/', $privatePath);
if ($webroot === false || $expected !== $webroot || $parent === false
    || preg_match('~^(?:/|[A-Za-z]:/)~', $normalizedPrivatePath) !== 1) {
    throw new RuntimeException('Exact existing webroot and absolute private destination are required.');
}
$private = $parent . DIRECTORY_SEPARATOR . basename($privatePath);
$rootPath = str_replace('\\', '/', $webroot);
$parentPath = str_replace('\\', '/', $parent);
if ($parent === '/' || $parentPath === $rootPath
    || str_starts_with($parentPath . '/', $rootPath . '/')
    || is_link($private) || is_link($webroot . '/vendor')) {
    throw new RuntimeException('Private destination must be outside the webroot; symlinks are not allowed.');
}
$privateAutoload = $private . '/vendor/autoload.php';
$shim = "<?php\n/** Composer runtime lives outside the public document root. */\n"
    . 'return require ' . var_export($privateAutoload, true) . ";\n";
$publicAutoload = $webroot . '/vendor/autoload.php';
if (is_dir($private)) {
    if (!is_file($privateAutoload) || !is_file($publicAutoload)
        || file_get_contents($publicAutoload) !== $shim
        || count(glob($webroot . '/vendor/*') ?: []) !== 1) {
        throw new RuntimeException('Private Composer runtime is incomplete or conflicts with this release.');
    }
    echo json_encode(['private_vendor_dir' => $private . '/vendor', 'already_private' => true],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}
if (!is_dir($webroot . '/vendor') || !is_file($publicAutoload)
    || !is_file($webroot . '/vendor/dompdf/dompdf/src/Dompdf.php')) {
    throw new RuntimeException('Installed Composer runtime is incomplete.');
}
if (!mkdir($private, 0755)) throw new RuntimeException('Could not create private Composer parent.');
$moved = false;
try {
    if (!rename($webroot . '/vendor', $private . '/vendor')) {
        throw new RuntimeException('Could not move Composer runtime out of webroot.');
    }
    $moved = true;
    if (!mkdir($webroot . '/vendor', 0755)
        || file_put_contents($publicAutoload, $shim, LOCK_EX) !== strlen($shim)
        || !is_file($privateAutoload)) {
        throw new RuntimeException('Could not install private Composer autoload shim.');
    }
} catch (Throwable $error) {
    if ($moved) {
        if (is_file($publicAutoload)) unlink($publicAutoload);
        if (is_dir($webroot . '/vendor')) rmdir($webroot . '/vendor');
        if (!rename($private . '/vendor', $webroot . '/vendor')) {
            throw new RuntimeException('Composer move failed and automatic rollback also failed.', 0, $error);
        }
    }
    if (is_dir($private)) rmdir($private);
    throw $error;
}
echo json_encode(['private_vendor_dir' => $private . '/vendor', 'already_private' => false],
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
