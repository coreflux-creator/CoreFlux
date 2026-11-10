<?php
/** Installed privately on the isolated Cloudways pilot; contains no credentials. */
declare(strict_types=1);

(static function (): void {
    $directory = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/coreflux-storage-pilot';
    if (__DIR__ !== $directory || realpath($directory) !== $directory || is_link($directory)
        || !defined('DB_NAME') || DB_NAME !== 'muzqvdvqbx') {
        throw new RuntimeException('Private pilot storage scope mismatch.');
    }
    $path = $directory . '/settings.json';
    if (is_link($path) || realpath($path) !== $path || !is_readable($path)
        || (fileperms($path) & 0007) !== 0) {
        throw new RuntimeException('Private pilot storage settings are unavailable.');
    }
    $settings = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    $names = ['STORAGE_S3_BUCKET', 'STORAGE_S3_REGION', 'STORAGE_S3_KMS_KEY_ID',
        'STORAGE_SIGNED_URL_DEFAULT_TTL', 'STORAGE_S3_ACCESS_KEY_ID', 'STORAGE_S3_SECRET_ACCESS_KEY'];
    if (!is_array($settings) || array_diff(array_keys($settings), $names)
        || array_diff($names, array_keys($settings))
        || $settings['STORAGE_S3_BUCKET'] !== 'coreflux-documents-pilot-documentsbucket-58usklwrp9sz'
        || $settings['STORAGE_S3_REGION'] !== 'us-east-1'
        || $settings['STORAGE_S3_KMS_KEY_ID'] !== 'arn:aws:kms:us-east-1:041304781980:key/da6364d0-e0d5-44bb-8b06-856d66da36bb'
        || $settings['STORAGE_SIGNED_URL_DEFAULT_TTL'] !== '300'
        || !preg_match('/^AKIA[A-Z0-9]{16}$/D', $settings['STORAGE_S3_ACCESS_KEY_ID'])
        || !preg_match('~^[A-Za-z0-9/+=]{40}$~D', $settings['STORAGE_S3_SECRET_ACCESS_KEY'])) {
        throw new RuntimeException('Private pilot storage settings are invalid.');
    }
    foreach ($settings as $name => $value) {
        if (!is_string($value) || str_contains($value, "\0")
            || (getenv($name) !== false && getenv($name) !== '' && getenv($name) !== $value)) {
            throw new RuntimeException('Conflicting pilot storage setting.');
        }
    }
    // Keep the host's disabled-function policy intact; no putenv() is required.
    if (defined('COREFLUX_STORAGE_CONFIG') || getenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENTS') === '1') {
        throw new RuntimeException('Pilot storage or document intake is already configured.');
    }
    define('COREFLUX_STORAGE_CONFIG', ['driver' => 's3', 's3' => [
        'bucket' => $settings['STORAGE_S3_BUCKET'], 'region' => $settings['STORAGE_S3_REGION'],
        'kms_key_id' => $settings['STORAGE_S3_KMS_KEY_ID'],
        'default_ttl' => (int) $settings['STORAGE_SIGNED_URL_DEFAULT_TTL'],
        'access_key_id' => $settings['STORAGE_S3_ACCESS_KEY_ID'],
        'secret_access_key' => $settings['STORAGE_S3_SECRET_ACCESS_KEY'],
    ]]);
})();
