<?php
/** Pinned wrapper for the disposable QA app's public-file inventory. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !in_array('--confirm-disposable-qa', $argv, true)) {
    fwrite(STDERR, "Disposable QA CLI only.\n");
    exit(2);
}

$option = static function (string $name) use ($argv): string {
    $prefix = "--$name=";
    $values = array_values(array_filter($argv,
        static fn(string $arg): bool => str_starts_with($arg, $prefix)));
    return count($values) === 1 ? substr($values[0], strlen($prefix)) : '';
};
$root = realpath($option('root'));
$origin = $option('origin');
if ($root !== '/home/1516771.cloudwaysapps.com/aqdcpvafpj/public_html'
    || $origin !== 'https://phpstack-1516771-6717961.cloudwaysapps.com') {
    fwrite(STDERR, "This inventory is pinned to the disposable QA app.\n");
    exit(2);
}

$library = is_file(__DIR__ . '/coreaccounting_public_inventory.php')
    ? __DIR__ . '/coreaccounting_public_inventory.php'
    : __DIR__ . '/../deploy/coreaccounting_public_inventory.php';
require_once $library;
try {
    $result = coreAccountingAuditPublicFiles($root, $origin, [
        '/home/master/.coreaccounting-cleanqa/static-prune-20261009',
        '/home/master/.coreaccounting-cleanqa/vendor-private-20261009',
    ]);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(coreAccountingPublicInventoryPassed($result) ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
