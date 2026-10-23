<?php
/**
 * Core\StorageService — single platform-wide file storage abstraction.
 *
 * Modules MUST go through this service. Never call S3 / filesystem directly
 * from module code (HARD_RULES — see /app/core/StorageService.SPEC.md §1).
 *
 * Path convention (LOCKED, enforced here):
 *   {module}/{tenant_id}/{entity_type}/{entity_id}/{filename}
 *
 * Backends:
 *   - LocalDriver  (default; STORAGE_DRIVER not set or =local)
 *   - S3Driver     (STORAGE_DRIVER=s3; requires aws-sdk-php)
 *
 * MVP: this class returns a structured StorageObject array. Persistence to the
 * `storage_objects` MySQL table is wired by the modules' own DAO layer (per SPEC §4.2)
 * — we record the row, then call StorageService::put with the resulting key.
 * StorageService itself is stateless apart from the driver instance.
 */

namespace Core;

use Core\Storage\StorageDriver;
use Core\Storage\LocalDriver;
use Core\Storage\S3Driver;

require_once __DIR__ . '/storage/StorageDriver.php';
require_once __DIR__ . '/storage/LocalDriver.php';
require_once __DIR__ . '/storage/S3Driver.php';

class StorageService
{
    private static ?self $instance = null;
    private StorageDriver $driver;

    public static function getInstance(): self
    {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    /** Test reset hook. */
    public static function reset(?StorageDriver $driver = null): self
    {
        self::$instance = new self($driver);
        return self::$instance;
    }

    public function __construct(?StorageDriver $driver = null)
    {
        if ($driver !== null) {
            $this->driver = $driver;
            return;
        }
        $name = strtolower((string) (getenv('STORAGE_DRIVER') ?: 'local'));
        if ($name === 's3') {
            $this->driver = new S3Driver();
        } elseif (getenv('COREFLUX_ENV') === 'coreaccounting') {
            require_once __DIR__ . '/accounting/private_storage.php';
            if (!defined('DB_PASS') || (string) DB_PASS === '') {
                throw new \RuntimeException('CoreAccounting local storage secret is unavailable.');
            }
            $privateRoot = coreAccountingPrivateStorageRoot(dirname(__DIR__));
            $secret = hash_hmac('sha256', 'coreaccounting-local-storage-v1', (string) DB_PASS);
            $this->driver = new LocalDriver($privateRoot . '/coreaccounting-storage', $secret);
        } else {
            $this->driver = new LocalDriver();
        }
    }

    public function driver_name(): string { return $this->driver->driver_name(); }
    public function location_fingerprint(): string { return $this->driver->location_fingerprint(); }

    /** Local upload/download endpoints use this; modules continue through StorageService. */
    public function local_driver(): ?LocalDriver
    {
        return $this->driver instanceof LocalDriver ? $this->driver : null;
    }

    /**
     * Persist a file. Returns a structured payload describing the stored object.
     *
     * @param string                    $module       e.g. 'people', 'placements', 'time'
     * @param int                       $tenantId
     * @param string                    $entityType   e.g. 'person', 'placement', 'timesheet'
     * @param int|string                $entityId
     * @param string                    $filename     original / display filename
     * @param string|resource           $localPathOrStream  file path or stream (or raw bytes for tests)
     * @param array                     $opts        ['mime', 'metadata', 'tags', 'lock_until' (DateTime), 'lock_mode', 'source_is_bytes', 'if_not_exists']
     * @return array {key, version_id, etag, size_bytes, signed_url}
     */
    public function put(
        string $module,
        int    $tenantId,
        string $entityType,
        $entityId,
        string $filename,
        $localPathOrStream,
        array  $opts = []
    ): array {
        $key = $this->build_key($module, $tenantId, $entityType, $entityId, $filename);
        $r = $this->driver->put($key, $localPathOrStream, $opts);
        return [
            'key'         => $key,
            'version_id'  => $r['version_id'] ?? null,
            'etag'        => $r['etag']       ?? null,
            'size_bytes'  => $r['size_bytes'] ?? null,
            'signed_url'  => $this->driver->get_signed_url($key),
            'driver'      => $this->driver->driver_name(),
        ];
    }

    public function get_signed_url(string $key, int $ttlSeconds = 300, array $opts = []): string
    {
        return $this->driver->get_signed_url($key, $ttlSeconds, $opts);
    }

    /** Write an already-reserved server-side key, refusing any overwrite or scope change. */
    public function put_reserved(string $module,int $tenantId,string $entityType,$entityId,string $key,string $bytes,array $opts=[]): array
    {
        $prefix=$this->sanitize_segment($module).'/'.$tenantId.'/'.$this->sanitize_segment($entityType).'/'.$this->sanitize_segment((string)$entityId).'/';
        $name=str_starts_with($key,$prefix)?substr($key,strlen($prefix)):'';
        if($tenantId<=0||$name===''||!preg_match('/^[a-f0-9]{8}-.+$/D',$name)||$this->sanitize_filename(substr($name,9))!==substr($name,9)
            ||strlen($name)>209)throw new \InvalidArgumentException('StorageService: invalid reserved object identity');
        // Module callers cannot turn a reserved write into an overwrite or local-file read.
        $opts['if_not_exists']=true;$opts['source_is_bytes']=true;
        return ['key'=>$key,'driver'=>$this->driver->driver_name()]+$this->driver->put($key,$bytes,$opts);
    }

    public function get_presigned_post(string $key, array $constraints = []): array
    {
        return $this->driver->get_presigned_post($key, $constraints);
    }

    public function head(string $key): ?array
    {
        return $this->driver->head($key);
    }

    public function read(string $key, int $maxBytes): ?string
    {
        return $this->driver->read($key, $maxBytes);
    }

    public function soft_delete(string $key): void
    {
        $this->driver->delete($key);
    }

    public function apply_retention(string $key, \DateTimeInterface $retainUntil, string $mode = 'GOVERNANCE'): void
    {
        $this->driver->set_retention($key, $retainUntil, $mode);
    }

    public function apply_legal_hold(string $key, bool $on): void
    {
        $this->driver->set_legal_hold($key, $on);
    }

    /**
     * Build canonical S3 key from path components.
     * Enforces: tenant_id mandatory, no traversal, sanitized filename.
     */
    public function build_key(string $module, int $tenantId, string $entityType, $entityId, string $filename): string
    {
        $module     = $this->sanitize_segment($module);
        $entityType = $this->sanitize_segment($entityType);
        $entityId   = $this->sanitize_segment((string) $entityId);
        $filename   = $this->sanitize_filename($filename);

        if ($tenantId <= 0) {
            throw new \InvalidArgumentException("StorageService: tenant_id must be > 0");
        }

        // Add a short uuid prefix to the filename to prevent collisions.
        $unique = bin2hex(random_bytes(4));
        return "{$module}/{$tenantId}/{$entityType}/{$entityId}/{$unique}-{$filename}";
    }

    private function sanitize_segment(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9._-]+/', '-', $s) ?? '';
        $s = trim($s, '-_.');
        if ($s === '') {
            throw new \InvalidArgumentException("StorageService: empty path segment");
        }
        return $s;
    }

    private function sanitize_filename(string $f): string
    {
        $f = preg_replace('/[\x00-\x1F\x7F]/', '', $f) ?? '';   // strip control chars
        $f = str_replace(['..', '/', '\\'], '', $f);
        if (strlen($f) > 200) $f = substr($f, 0, 200);
        if ($f === '') $f = 'file.bin';
        return $f;
    }
}
