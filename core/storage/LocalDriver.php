<?php
/**
 * LocalDriver — filesystem-backed implementation of StorageDriver.
 *
 * Files are written to STORAGE_LOCAL_ROOT (default: /app/storage/_dev).
 * Signed URLs are token-based and served by /api/storage/local/get.php
 * (verified by HMAC of key + expiry).
 *
 * Suitable for: dev, CI, single-server staging without AWS.
 * NOT suitable for: production at scale, audit-immutable retention,
 *                   multi-region resilience.
 *
 * SPEC: /app/core/StorageService.SPEC.md
 */

namespace Core\Storage;

class LocalDriver implements StorageDriver
{
    private string $root;
    private string $hmacSecret;
    private string $publicBaseUrl;

    public function __construct(?string $root = null, ?string $hmacSecret = null, ?string $publicBaseUrl = null)
    {
        $this->root = rtrim($root ?? (getenv('STORAGE_LOCAL_ROOT') ?: '/app/storage/_dev'), '/\\');
        $this->hmacSecret = $hmacSecret
            ?? (getenv('STORAGE_LOCAL_HMAC_SECRET') ?: 'dev-only-do-not-use-in-prod');
        $this->publicBaseUrl = rtrim($publicBaseUrl ?? (getenv('STORAGE_LOCAL_PUBLIC_BASE') ?: '/api/storage/local/get.php'), '/');

        if (!is_dir($this->root)) {
            @mkdir($this->root, getenv('COREFLUX_ENV') === 'coreaccounting' ? 0700 : 0775, true);
        }
        if (!is_dir($this->root) || is_link($this->root) || !is_writable($this->root)) {
            throw new \RuntimeException('LocalDriver: storage root is unavailable.');
        }
    }

    public function put(string $key, string $localPathOrStream, array $opts = []): array
    {
        $this->guard_key($key);
        $abs = $this->abs($key);
        @mkdir(dirname($abs), getenv('COREFLUX_ENV') === 'coreaccounting' ? 0700 : 0775, true);
        $abs = $this->abs($key);

        if (($opts['source_is_bytes'] ?? false) === true) {
            $bytes = $localPathOrStream;
        } elseif (is_resource($localPathOrStream)) {
            $bytes = stream_get_contents($localPathOrStream);
        } elseif (is_file($localPathOrStream)) {
            $bytes = file_get_contents($localPathOrStream);
        } else {
            // Treat as raw bytes (e.g. test inputs).
            $bytes = $localPathOrStream;
        }

        if ($bytes === false) {
            throw new \RuntimeException("LocalDriver: failed to read source for key={$key}");
        }

        if (!empty($opts['if_not_exists'])) {
            $handle = @fopen($abs, 'xb');
            if ($handle === false) throw new \RuntimeException('LocalDriver: object already exists or cannot be created.');
            try {
                $written = 0;
                $length = strlen($bytes);
                while ($written < $length) {
                    $chunk = fwrite($handle, substr($bytes, $written));
                    if ($chunk === false || $chunk === 0) {
                        throw new \RuntimeException('LocalDriver: incomplete object write.');
                    }
                    $written += $chunk;
                }
            } catch (\Throwable $error) {
                fclose($handle);
                @unlink($abs);
                throw $error;
            }
            fclose($handle);
        } else {
            $written = file_put_contents($abs, $bytes, LOCK_EX);
            if ($written === false) throw new \RuntimeException('LocalDriver: failed to write object.');
        }

        $meta = [
            'mime'         => $opts['mime']     ?? 'application/octet-stream',
            'metadata'     => $opts['metadata'] ?? [],
            'tags'         => $opts['tags']     ?? [],
            'lock_until'   => isset($opts['lock_until']) ? $opts['lock_until']->format(DATE_ATOM) : null,
            'put_at'       => date(DATE_ATOM),
        ];
        if (file_put_contents($abs . '.meta.json', json_encode($meta), LOCK_EX) === false) {
            if (!empty($opts['if_not_exists'])) @unlink($abs);
            throw new \RuntimeException('LocalDriver: failed to write object metadata.');
        }

        return [
            'version_id' => null,                              // local driver doesn't version
            'etag'       => '"' . md5($bytes) . '"',
            'size_bytes' => strlen($bytes),
        ];
    }

    public function get_signed_url(string $key, int $ttlSeconds = 300, array $opts = []): string
    {
        $this->guard_key($key);
        $expires = time() + max(1, min(3600, $ttlSeconds));
        $sig = hash_hmac('sha256', $key . '|' . $expires, $this->hmacSecret);
        $qs = http_build_query([
            'k'   => $key,
            'e'   => $expires,
            'sig' => $sig,
            'd'   => isset($opts['filename_for_download']) ? $opts['filename_for_download'] : null,
            'i'   => !empty($opts['inline']) ? '1' : null,
        ]);
        return $this->publicBaseUrl . '?' . $qs;
    }

    public function get_presigned_post(string $key, array $constraints = []): array
    {
        $this->guard_key($key);
        $expires = time() + 600;
        $sig = hash_hmac('sha256', 'POST|' . $key . '|' . $expires, $this->hmacSecret);
        return [
            'form_action' => '/api/storage/local/put.php',
            'fields' => [
                'key'     => $key,
                'expires' => (string) $expires,
                'sig'     => $sig,
            ],
        ];
    }

    public function head(string $key): ?array
    {
        $abs = $this->abs($key);
        if (!is_file($abs)) return null;
        $meta = is_file($abs . '.meta.json')
            ? json_decode(file_get_contents($abs . '.meta.json'), true)
            : [];
        return [
            'size_bytes'    => filesize($abs),
            'mime'          => $meta['mime'] ?? 'application/octet-stream',
            'etag'          => '"' . md5_file($abs) . '"',
            'version_id'    => null,
            'last_modified' => gmdate(DATE_ATOM, filemtime($abs)),
        ];
    }

    public function read(string $key, int $maxBytes): ?string
    {
        $abs = $this->abs($key);
        if (!is_file($abs)) return null;
        $size = filesize($abs);
        if ($size === false || $size > $maxBytes) {
            throw new \RuntimeException('LocalDriver: object exceeds the allowed read size.');
        }
        $bytes = file_get_contents($abs);
        if ($bytes === false || strlen($bytes) !== $size) {
            throw new \RuntimeException('LocalDriver: incomplete object read.');
        }
        return $bytes;
    }

    public function delete(string $key): void
    {
        $abs = $this->abs($key);
        if (is_file($abs)) @unlink($abs);
        if (is_file($abs . '.meta.json')) @unlink($abs . '.meta.json');
    }

    public function set_retention(string $key, \DateTimeInterface $retainUntil, string $mode = 'GOVERNANCE'): void
    {
        // Local driver: persist to .meta.json (advisory only).
        $abs = $this->abs($key);
        if (!is_file($abs)) return;
        $meta = is_file($abs . '.meta.json')
            ? json_decode(file_get_contents($abs . '.meta.json'), true)
            : [];
        $meta['lock_until'] = $retainUntil->format(DATE_ATOM);
        $meta['lock_mode']  = $mode;
        file_put_contents($abs . '.meta.json', json_encode($meta));
    }

    public function set_legal_hold(string $key, bool $on): void
    {
        $abs = $this->abs($key);
        if (!is_file($abs)) return;
        $meta = is_file($abs . '.meta.json')
            ? json_decode(file_get_contents($abs . '.meta.json'), true)
            : [];
        $meta['legal_hold'] = $on;
        file_put_contents($abs . '.meta.json', json_encode($meta));
    }

    public function driver_name(): string { return 'local'; }
    public function location_fingerprint(): string { return hash('sha256','local:'.(realpath($this->root)?:$this->root)); }

    /**
     * Verify a token returned by get_signed_url. Used by /api/storage/local/get.php.
     */
    public function verify_signed_token(string $key, int $expires, string $sig): bool
    {
        if ($expires < time() || $expires > time() + 3600) return false;
        $expected = hash_hmac('sha256', $key . '|' . $expires, $this->hmacSecret);
        return hash_equals($expected, $sig);
    }

    public function verify_signed_post_token(string $key, int $expires, string $sig): bool
    {
        if ($expires < time() || $expires > time() + 600) return false;
        $expected = hash_hmac('sha256', 'POST|' . $key . '|' . $expires, $this->hmacSecret);
        return hash_equals($expected, $sig);
    }

    public static function key_belongs_to_tenant(string $key, int $tenantId): bool
    {
        $segments = explode('/', $key);
        return $tenantId > 0 && count($segments) === 5
            && $segments[0] !== '' && $segments[1] === (string) $tenantId
            && $segments[2] !== '' && $segments[3] !== '' && $segments[4] !== ''
            && !str_contains($key, '..') && !str_contains($key, '\\')
            && !preg_match('/[\x00-\x1f\x7f]/', $key);
    }

    public function abs(string $key): string
    {
        $this->guard_key($key);
        $path = $this->root;
        foreach (explode('/', $key) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) throw new \RuntimeException('LocalDriver: linked storage path is not allowed.');
        }
        return $path;
    }

    /**
     * Reject keys that escape the root or contain unsafe segments.
     * Allows the canonical path convention {module}/{tenant_id}/...
     */
    private function guard_key(string $key): void
    {
        if ($key === '' || str_contains($key, '..') || str_contains($key, '\\')
            || str_starts_with($key, '/') || str_contains($key, '//')
            || preg_match('/[\x00-\x1f\x7f]/', $key)) {
            throw new \InvalidArgumentException("LocalDriver: unsafe key '{$key}'");
        }
    }
}
