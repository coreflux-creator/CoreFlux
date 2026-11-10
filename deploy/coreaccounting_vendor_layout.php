<?php
/** Shared package contract for Composer outside the standalone document root. */
declare(strict_types=1);

function coreAccountingPortableVendorShim(): string
{
    return <<<'PHP'
<?php
/** Composer runtime stays outside the public document root. */
$autoloadPath = getenv('COREFLUX_ACCOUNTING_PRIVATE_VENDOR_AUTOLOAD');
if ($autoloadPath === false || $autoloadPath === '') {
    $autoloadPath = dirname(__DIR__, 2) . '/private_runtime/vendor/autoload.php';
}
$resolved = realpath($autoloadPath);
$webroot = dirname(__DIR__);
if ($resolved === false || !is_file($resolved) || is_link($autoloadPath)
    || $resolved === $webroot || str_starts_with($resolved, $webroot . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Private Composer runtime is missing or inside the public webroot.');
}
return require $resolved;
PHP . "\n";
}
