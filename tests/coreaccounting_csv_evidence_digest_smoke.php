<?php
/** Finance CSV source hashes are computed from stored bytes and checked before use. */
declare(strict_types=1);

require_once __DIR__ . '/../core/accounting/csv_import_evidence.php';

use Core\Storage\StorageDriver;
use Core\StorageService;

final class CsvDigestMemoryDriver implements StorageDriver {
    public int $reads = 0;
    public function __construct(public ?string $bytes) {}
    public function put(string $key, string $localPathOrStream, array $opts = []): array { return []; }
    public function get_signed_url(string $key, int $ttlSeconds = 300, array $opts = []): string { return ''; }
    public function get_presigned_post(string $key, array $constraints = []): array { return []; }
    public function head(string $key): ?array { return $this->bytes === null ? null : ['size_bytes' => strlen($this->bytes)]; }
    public function read(string $key, int $maxBytes): ?string {
        $this->reads++;
        return $this->bytes !== null && strlen($this->bytes) <= $maxBytes ? $this->bytes : null;
    }
    public function delete(string $key): void {}
    public function set_retention(string $key, DateTimeInterface $retainUntil, string $mode = 'GOVERNANCE'): void {}
    public function set_legal_hold(string $key, bool $on): void {}
    public function driver_name(): string { return 'memory'; }
}

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $ok) use (&$passed, &$failed): void {
    if ($ok) { $passed++; echo "  ok    {$name}\n"; }
    else { $failed++; echo "  FAIL  {$name}\n"; }
};
$rejects = static function (callable $action, string $type): bool {
    try { $action(); } catch (Throwable $e) { return $e instanceof $type; }
    return false;
};

$csv = "Vendor name,Vendor type\nQA Digest,other\n";
$driver = new CsvDigestMemoryDriver($csv);
$storage = new StorageService($driver);
$hash = hash('sha256', $csv);
$check('archive hash comes from stored bytes',
    coreAccountingCsvArchiveHash($storage, 'csv_imports/1/csv_import_run/1/test.csv', strlen($csv)) === $hash);
$check('archive was read once', $driver->reads === 1);
$check('exact archived bytes match the recorded hash', coreAccountingCsvArchiveHashMatches($csv, $hash));
$check('changed archived bytes are rejected', !coreAccountingCsvArchiveHashMatches($csv . 'x', $hash));
$check('missing hash is rejected at the commit gate', !coreAccountingCsvArchiveHashMatches($csv, null));
$check('malformed hash is rejected', !coreAccountingCsvArchiveHashMatches($csv, str_repeat('x', 64)));
$check('uppercase hash is rejected', !coreAccountingCsvArchiveHashMatches($csv, strtoupper($hash)));
$check('empty archive is rejected before reading',
    $rejects(static fn() => coreAccountingCsvArchiveHash($storage, 'source', 0), InvalidArgumentException::class)
    && $driver->reads === 1);
$check('oversized archive is rejected before reading',
    $rejects(static fn() => coreAccountingCsvArchiveHash(
        $storage, 'source', CORE_ACCOUNTING_CSV_ARCHIVE_MAX_BYTES + 1
    ), InvalidArgumentException::class) && $driver->reads === 1);
$check('mismatched recorded size is rejected',
    $rejects(static fn() => coreAccountingCsvArchiveHash($storage, 'source', strlen($csv) + 1),
        RuntimeException::class));
$driver->bytes = null;
$check('missing storage object is rejected',
    $rejects(static fn() => coreAccountingCsvArchiveHash($storage, 'source', strlen($csv)),
        RuntimeException::class));

$api = (string) file_get_contents(__DIR__ . '/../api/accounting/evidence.php');
$gate = (string) file_get_contents(__DIR__ . '/../core/accounting/csv_import_gate.php');
$check('registration overrides the client hash with a server-computed hash',
    str_contains($api, "\$body['sha256_hash'] = coreAccountingCsvArchiveHash("));
$check('signed download verifies a recorded import hash',
    str_contains($api, "&& !empty(\$row['sha256_hash'])")
    && str_contains($api, 'Archived import file failed its integrity check.'));
$check('legacy undigested evidence remains downloadable',
    str_contains($api, "&& !empty(\$row['sha256_hash'])"));
$check('finance import claim selects and verifies the persisted hash',
    str_contains($gate, 'SELECT storage_key, size_bytes, sha256_hash')
    && str_contains($gate, "coreAccountingCsvArchiveHashMatches(\$source, \$sources[0]['sha256_hash'] ?? null)"));

echo "\n--- {$passed} passed, {$failed} failed ---\n";
exit($failed === 0 ? 0 : 1);
