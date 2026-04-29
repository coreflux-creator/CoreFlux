<?php
/**
 * S3Driver — AWS S3 implementation of StorageDriver via aws-sdk-php.
 *
 * Activated by STORAGE_DRIVER=s3 in .env.
 * Requires composer dependencies: aws/aws-sdk-php.
 *
 * Per /app/core/StorageService.SPEC.md and the AWS setup guide
 * at /app/memory/AWS_SETUP_GUIDE.md.
 *
 * NOTE: This file is dormant until `composer install` runs and AWS credentials
 * are present. Until then, the platform uses LocalDriver. The class-exists
 * guard at the top defers errors until S3Driver is actually instantiated.
 */

namespace Core\Storage;

class S3Driver implements StorageDriver
{
    /** @var \Aws\S3\S3Client */
    private $client;

    private string $bucket;
    private string $kmsKeyId;
    private int    $defaultTtl;

    public function __construct(array $cfg = [])
    {
        if (!class_exists('\Aws\S3\S3Client')) {
            throw new \RuntimeException(
                'S3Driver requires aws/aws-sdk-php. Run `composer install` and ensure ' .
                'STORAGE_DRIVER=s3 has the supporting STORAGE_S3_* env vars set. ' .
                'See /app/memory/AWS_SETUP_GUIDE.md.'
            );
        }

        $this->bucket     = $cfg['bucket']      ?? (string) getenv('STORAGE_S3_BUCKET');
        $this->kmsKeyId   = $cfg['kms_key_id']  ?? (string) getenv('STORAGE_S3_KMS_KEY_ID');
        $this->defaultTtl = (int) ($cfg['default_ttl'] ?? (getenv('STORAGE_SIGNED_URL_DEFAULT_TTL') ?: 300));

        if ($this->bucket === '') {
            throw new \RuntimeException('S3Driver: STORAGE_S3_BUCKET is required.');
        }

        $clientArgs = [
            'version' => 'latest',
            'region'  => $cfg['region'] ?? (getenv('STORAGE_S3_REGION') ?: 'us-east-1'),
        ];

        $accessKey = $cfg['access_key_id']     ?? getenv('STORAGE_S3_ACCESS_KEY_ID');
        $secretKey = $cfg['secret_access_key'] ?? getenv('STORAGE_S3_SECRET_ACCESS_KEY');
        if ($accessKey && $secretKey) {
            $clientArgs['credentials'] = ['key' => $accessKey, 'secret' => $secretKey];
        }
        // If credentials not provided, SDK falls back to AWS_PROFILE / IAM role / env.

        $clientClass = '\Aws\S3\S3Client';
        $this->client = new $clientClass($clientArgs);
    }

    public function put(string $key, string $localPathOrStream, array $opts = []): array
    {
        $params = [
            'Bucket' => $this->bucket,
            'Key'    => $key,
            'Body'   => is_resource($localPathOrStream)
                ? $localPathOrStream
                : (is_file($localPathOrStream) ? fopen($localPathOrStream, 'rb') : $localPathOrStream),
        ];

        if (!empty($opts['mime']))     $params['ContentType']      = $opts['mime'];
        if (!empty($opts['metadata'])) $params['Metadata']         = $opts['metadata'];

        if ($this->kmsKeyId !== '') {
            $params['ServerSideEncryption'] = 'aws:kms';
            $params['SSEKMSKeyId']          = $this->kmsKeyId;
        }

        if (!empty($opts['tags'])) {
            $tagPairs = [];
            foreach ($opts['tags'] as $k => $v) {
                $tagPairs[] = rawurlencode($k) . '=' . rawurlencode((string) $v);
            }
            $params['Tagging'] = implode('&', $tagPairs);
        }

        if (!empty($opts['lock_until']) && $opts['lock_until'] instanceof \DateTimeInterface) {
            $params['ObjectLockMode']            = $opts['lock_mode'] ?? 'GOVERNANCE';
            $params['ObjectLockRetainUntilDate'] = $opts['lock_until']->format(DATE_ATOM);
        }

        $result = $this->client->putObject($params);
        $headLength = method_exists($result, 'get') ? ($result->get('ContentLength') ?? null) : null;

        return [
            'version_id' => method_exists($result, 'get') ? $result->get('VersionId') : null,
            'etag'       => method_exists($result, 'get') ? $result->get('ETag')      : null,
            'size_bytes' => $headLength,
        ];
    }

    public function get_signed_url(string $key, int $ttlSeconds = 0, array $opts = []): string
    {
        $ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : $this->defaultTtl;
        $cmdArgs = ['Bucket' => $this->bucket, 'Key' => $key];
        if (!empty($opts['filename_for_download'])) {
            $cmdArgs['ResponseContentDisposition'] =
                'attachment; filename="' . str_replace('"', '', $opts['filename_for_download']) . '"';
        }
        $cmd = $this->client->getCommand('GetObject', $cmdArgs);
        $request = $this->client->createPresignedRequest($cmd, '+' . $ttlSeconds . ' seconds');
        return (string) $request->getUri();
    }

    public function get_presigned_post(string $key, array $constraints = []): array
    {
        $defaults = [
            'acl'                  => 'private',
            'ServerSideEncryption' => 'aws:kms',
        ];
        if ($this->kmsKeyId !== '') {
            $defaults['SSEKMSKeyId'] = $this->kmsKeyId;
            $defaults['x-amz-server-side-encryption']                = 'aws:kms';
            $defaults['x-amz-server-side-encryption-aws-kms-key-id'] = $this->kmsKeyId;
        }
        $defaults['key'] = $key;

        $postClass = '\Aws\S3\PostObjectV4';
        $post = new $postClass(
            $this->client,
            $this->bucket,
            $defaults,
            $constraints['policy'] ?? [],
            $constraints['expires'] ?? '+15 minutes'
        );

        return [
            'form_action' => $post->getFormAttributes()['action'],
            'fields'      => $post->getFormInputs(),
        ];
    }

    public function head(string $key): ?array
    {
        try {
            $r = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
            return [
                'size_bytes'    => method_exists($r, 'get') ? $r->get('ContentLength') : null,
                'mime'          => method_exists($r, 'get') ? $r->get('ContentType') : null,
                'etag'          => method_exists($r, 'get') ? $r->get('ETag')        : null,
                'version_id'    => method_exists($r, 'get') ? $r->get('VersionId')   : null,
                'last_modified' => method_exists($r, 'get') && $r->get('LastModified')
                    ? $r->get('LastModified')->format(DATE_ATOM) : null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function delete(string $key): void
    {
        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
    }

    public function set_retention(string $key, \DateTimeInterface $retainUntil, string $mode = 'GOVERNANCE'): void
    {
        $this->client->putObjectRetention([
            'Bucket' => $this->bucket,
            'Key'    => $key,
            'Retention' => [
                'Mode'            => $mode,
                'RetainUntilDate' => $retainUntil,
            ],
        ]);
    }

    public function set_legal_hold(string $key, bool $on): void
    {
        $this->client->putObjectLegalHold([
            'Bucket'    => $this->bucket,
            'Key'       => $key,
            'LegalHold' => ['Status' => $on ? 'ON' : 'OFF'],
        ]);
    }

    public function driver_name(): string { return 's3'; }
}
