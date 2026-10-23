<?php
/** Private S3 selection must work with putenv disabled and reject invalid config. */
declare(strict_types=1);

if (($argv[1] ?? '') === '--child') {
    if (function_exists('putenv')) throw new RuntimeException('Host restriction was not reproduced.');
    // Constructor capture only: no real credential or network request.
    eval('namespace Aws\\S3; class S3Client { public static array $args=[]; public function __construct(array $args){self::$args=$args;} }');
    $settings = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    define('COREFLUX_STORAGE_CONFIG', $settings);
    require __DIR__ . '/../core/StorageService.php';
    try {
        $storage = new \Core\StorageService();
        $args = \Aws\S3\S3Client::$args;
        if ($storage->driver_name() !== 's3' || ($args['region'] ?? null) !== 'us-east-1'
            || ($args['credentials'] ?? null) !== ['key' => 'synthetic-key', 'secret' => 'synthetic-secret']) {
            throw new RuntimeException('Private driver settings were not applied.');
        }
        echo 'configured';
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'Invalid private storage configuration.') throw $e;
        echo 'rejected';
    }
    exit;
}

$valid = ['driver' => 's3', 's3' => ['bucket' => 'synthetic-private-bucket', 'region' => 'us-east-1',
    'kms_key_id' => 'synthetic-key-arn', 'access_key_id' => 'synthetic-key', 'secret_access_key' => 'synthetic-secret']];
$cases = [[$valid, 'configured'], ['invalid', 'rejected'], [null, 'rejected'], [[], 'rejected'],
    [['driver' => 's3', 's3' => []], 'rejected'],
    [['driver' => 'local', 's3' => []], 'rejected'], [['driver' => 's3', 's3' => 'invalid'], 'rejected'],
    [$valid + ['unexpected' => true], 'rejected']];
foreach ($cases as [$settings, $expected]) {
    $p = proc_open([PHP_BINARY, '-d', 'disable_functions=putenv', __FILE__, '--child'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('Cannot run host-restriction check.');
    fwrite($pipes[0], json_encode($settings)); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($p) !== 0 || $output !== $expected || $error !== '') {
        throw new RuntimeException('Private storage configuration check failed.');
    }
}
echo "PASS private S3 configuration with putenv disabled; invalid configurations fail closed (8 cases).\n";
