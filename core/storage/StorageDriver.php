<?php
/**
 * StorageDriver — pluggable backend interface for Core\StorageService.
 *
 * Implementations: LocalDriver (filesystem), S3Driver (AWS S3 via aws-sdk-php).
 * The active driver is selected by STORAGE_DRIVER env var.
 *
 * SPEC: /app/core/StorageService.SPEC.md
 */

namespace Core\Storage;

interface StorageDriver
{
    /**
     * Persist bytes at $key. Returns provider-native version id (or null).
     * $opts may include: 'mime', 'metadata' (array), 'tags' (array),
     *                    'lock_until' (DateTimeInterface), 'sse' (bool).
     */
    public function put(string $key, string $localPathOrStream, array $opts = []): array;

    /**
     * Generate a signed URL for downloading $key for $ttlSeconds.
     * S3Driver returns a real presigned URL; LocalDriver returns a token URL
     * served by /api/storage/local/get.php (verified server-side).
     */
    public function get_signed_url(string $key, int $ttlSeconds = 300, array $opts = []): string;

    /**
     * Generate a presigned POST form for direct browser uploads to $key.
     * Returns ['form_action' => string, 'fields' => array<string,string>].
     */
    public function get_presigned_post(string $key, array $constraints = []): array;

    /** Returns ['size_bytes', 'mime', 'etag', 'version_id', 'last_modified']. */
    public function head(string $key): ?array;

    /** Soft delete (delete-marker for versioned backends). */
    public function delete(string $key): void;

    /** Apply Object Lock retention or legal hold. S3-only; LocalDriver no-op. */
    public function set_retention(string $key, \DateTimeInterface $retainUntil, string $mode = 'GOVERNANCE'): void;
    public function set_legal_hold(string $key, bool $on): void;

    public function driver_name(): string;
}
