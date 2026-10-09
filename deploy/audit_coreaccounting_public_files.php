<?php
/** Read-only outside-in static-file gate for a standalone CoreAccounting host. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || !in_array('--confirm-read-only-standalone', $argv, true)) {
    fwrite(STDERR, "Standalone CoreAccounting read-only CLI only.\n");
    exit(2);
}

$option = static function (string $name) use ($argv): string {
    $prefix = "--$name=";
    $values = array_values(array_filter($argv,
        static fn(string $arg): bool => str_starts_with($arg, $prefix)));
    return count($values) === 1 ? substr($values[0], strlen($prefix)) : '';
};
$root = realpath($option('root'));
$configuredRoot = realpath((string) getenv('COREFLUX_STANDALONE_WEBROOT'));
$origin = $option('origin');
$database = $option('database');
if ($root === false || $configuredRoot === false || $root !== $configuredRoot
    || $origin === '' || $origin !== getenv('COREFLUX_PUBLIC_ORIGIN')
    || $database === '' || $database !== getenv('COREFLUX_STANDALONE_DATABASE')) {
    fwrite(STDERR, "Release root, HTTPS origin and database identity must match host settings.\n");
    exit(2);
}

$retiredRoots = [];
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--retired-root=')) {
        $retiredRoots[] = substr($argument, strlen('--retired-root='));
    }
}

require_once __DIR__ . '/coreaccounting_public_inventory.php';
try {
    $result = coreAccountingAuditPublicFiles($root, $origin, $retiredRoots);
    echo json_encode(['database_identity' => $database, 'origin' => $origin] + $result,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(coreAccountingPublicInventoryPassed($result) ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
