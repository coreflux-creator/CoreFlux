<?php
/** Bounded, server-computed fingerprints for standalone finance CSV archives. */
declare(strict_types=1);

require_once __DIR__ . '/../StorageService.php';

const CORE_ACCOUNTING_CSV_ARCHIVE_MAX_BYTES = 25 * 1024 * 1024;

function coreAccountingCsvArchiveHash(\Core\StorageService $storage, string $key, int $expectedSize): string
{
    if ($expectedSize < 1 || $expectedSize > CORE_ACCOUNTING_CSV_ARCHIVE_MAX_BYTES) {
        throw new \InvalidArgumentException('Import evidence exceeds the allowed size.');
    }
    $bytes = $storage->read($key, CORE_ACCOUNTING_CSV_ARCHIVE_MAX_BYTES);
    if ($bytes === null || strlen($bytes) !== $expectedSize) {
        throw new \RuntimeException('Import evidence is missing or incomplete.');
    }
    return hash('sha256', $bytes);
}

function coreAccountingCsvArchiveHashMatches(string $bytes, ?string $expectedHash): bool
{
    return is_string($expectedHash)
        && preg_match('/\\A[a-f0-9]{64}\\z/D', $expectedHash) === 1
        && hash_equals($expectedHash, hash('sha256', $bytes));
}
