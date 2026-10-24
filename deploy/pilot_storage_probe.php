<?php
/** Synthetic S3 acceptance helper. Call only from a guarded isolated-pilot runner. */
declare(strict_types=1);

function corePilotStorageProbe(array $settings, string $root, bool $useActiveBackend = false): array
{
    if ($root !== '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html'
        || realpath($root) !== $root
        || ($settings['STORAGE_S3_BUCKET'] ?? '') !== 'coreflux-documents-pilot-documentsbucket-58usklwrp9sz'
        || ($settings['STORAGE_S3_KMS_KEY_ID'] ?? '') !== 'arn:aws:kms:us-east-1:041304781980:key/da6364d0-e0d5-44bb-8b06-856d66da36bb') {
        throw new RuntimeException('Exact isolated pilot required.');
    }
    require_once $root . '/core/StorageService.php';
    $driver = new \Core\Storage\S3Driver([
        'bucket' => $settings['STORAGE_S3_BUCKET'], 'region' => $settings['STORAGE_S3_REGION'],
        'kms_key_id' => $settings['STORAGE_S3_KMS_KEY_ID'],
        'access_key_id' => $settings['STORAGE_S3_ACCESS_KEY_ID'],
        'secret_access_key' => $settings['STORAGE_S3_SECRET_ACCESS_KEY'],
    ]);
    $storage = $useActiveBackend ? \Core\StorageService::getInstance() : new \Core\StorageService($driver);
    if ($useActiveBackend && ($storage->driver_name() !== 's3'
        || !hash_equals($driver->location_fingerprint(), $storage->location_fingerprint()))) {
        throw new RuntimeException('Active application storage does not match the pilot.');
    }
    $client = new \Aws\S3\S3Client(['version' => 'latest', 'region' => $settings['STORAGE_S3_REGION'],
        'credentials' => ['key' => $settings['STORAGE_S3_ACCESS_KEY_ID'], 'secret' => $settings['STORAGE_S3_SECRET_ACCESS_KEY']]]);
    $bucket = $settings['STORAGE_S3_BUCKET'];
    $bytes = "CoreFlux synthetic private storage acceptance\n" . bin2hex(random_bytes(16));
    $key = $storage->build_key('people', 999, 'document', 'storage-probe', 'synthetic.txt');
    $checks = [];
    $check = static function (string $name, bool $ok) use (&$checks): void {
        $checks[$name] = $ok;
        if (!$ok) throw new RuntimeException('Storage acceptance check failed: ' . $name);
    };
    $request = static function (string $url, ?array $fields = null): array {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        if ($fields !== null) curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields]);
        $body = curl_exec($c); $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
        return [$status, $body];
    };
    $postKey = null;
    try {
        $put = $storage->put_reserved('people', 999, 'document', 'storage-probe', $key, $bytes,
            ['mime' => 'text/plain', 'tags' => ['purpose' => 'synthetic-pilot-acceptance']]);
        $check('versioned_upload', is_string($put['version_id'] ?? null) && $put['version_id'] !== '');
        $head = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
        $check('exact_kms_encryption', $head->get('ServerSideEncryption') === 'aws:kms'
            && $head->get('SSEKMSKeyId') === $settings['STORAGE_S3_KMS_KEY_ID']);
        $check('bounded_read_matches', $storage->read($key, strlen($bytes)) === $bytes);
        $denied = false;
        try { $storage->read($key, strlen($bytes) - 1); } catch (RuntimeException $e) { $denied = true; }
        $check('oversized_read_rejected', $denied);
        $denied = false;
        try { $storage->put_reserved('people', 999, 'document', 'storage-probe', $key, 'replacement'); }
        catch (\Aws\S3\Exception\S3Exception $e) { $denied = $e->getStatusCode() === 412; }
        $check('reserved_overwrite_rejected', $denied && $storage->read($key, strlen($bytes)) === $bytes);
        [$status, $body] = $request($storage->get_signed_url($key, 60));
        $check('signed_download_matches', $status === 200 && $body === $bytes);
        [$status] = $request('https://' . $bucket . '.s3.us-east-1.amazonaws.com/' . $key);
        $check('anonymous_download_denied', $status === 403);
        [$status] = $request('http://' . $bucket . '.s3.us-east-1.amazonaws.com/' . $key);
        $check('plain_http_denied', $status === 403);
        $expiredUrl = $storage->get_signed_url($key, 1);
        sleep(2);
        [$status] = $request($expiredUrl);
        $check('expired_download_denied', $status === 403);
        $denied = false;
        try { $client->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'unencrypted']); }
        catch (\Aws\S3\Exception\S3Exception $e) { $denied = $e->getStatusCode() === 403; }
        $check('missing_encryption_denied', $denied);
        $postKey = $storage->build_key('people', 999, 'document', 'storage-probe', 'synthetic-post.txt');
        $post = $storage->get_presigned_post($postKey, ['policy' => [['content-length-range', 1, 1024]]]);
        $fields = $post['fields'];
        $fields['file'] = new CURLStringFile($bytes, 'synthetic.txt', 'text/plain');
        [$status] = $request($post['form_action'], $fields);
        $check('presigned_post_matches', in_array($status, [200, 201, 204], true) && $storage->read($postKey, 1024) === $bytes);
        $fields['key'] .= '-unauthorized';
        [$status] = $request($post['form_action'], $fields);
        $check('presigned_post_other_key_denied', $status === 403);
        $storage->soft_delete($key);
        $check('soft_delete_hides_current_object', $storage->head($key) === null);
        $old = $client->getObject(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $put['version_id']]);
        $check('soft_delete_preserves_version', (string) $old->get('Body') === $bytes);
        return ['passed' => true, 'checks' => $checks, 'synthetic_only' => true,
            'active_application_backend' => $useActiveBackend,
            'sapi' => PHP_SAPI, 'bucket' => $bucket, 'key' => $key, 'version_id' => $put['version_id']];
    } finally {
        // Only versioned soft deletion; no historical version is permanently removed.
        if ($postKey !== null && $storage->head($postKey) !== null) $storage->soft_delete($postKey);
        if ($storage->head($key) !== null) $storage->soft_delete($key);
    }
}
