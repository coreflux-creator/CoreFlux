<?php
/**
 * Sprint 8a / Slice A2 smoke — Integration-agnostic external entity mappings.
 *
 * Static / contract assertions only (matches the rest of the platform suite):
 *   - Migration 022_external_entity_mappings.sql shape: idempotent CREATE
 *     TABLE IF NOT EXISTS, agnostic source_system VARCHAR (no enum), JSON
 *     payload_snapshot, dual UNIQUE KEYs (external + internal), supporting
 *     indexes, sync_status enum.
 *   - core/integrations/entity_mappings.php public surface (parses, exports
 *     all expected functions, hash canonicalisation, upsert race-safe via
 *     ON DUPLICATE KEY UPDATE, status whitelist, direction whitelist).
 *   - mappingHash(): canonical, key-order-stable, deterministic.
 *   - mappingMarkStatus(): rejects unknown statuses; clamps error to 500 chars.
 *   - mappingUpsert(): rejects bad inputs (empty source, empty external_id,
 *     non-positive ids, unknown direction).
 *
 * NO database connection required (matches existing platform smoke tests).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Migration — 022_external_entity_mappings.sql\n";
$mig = (string) file_get_contents("{$ROOT}/core/migrations/022_external_entity_mappings.sql");
$assert('migration exists',                       strlen($mig) > 0);
$assert('idempotent CREATE TABLE IF NOT EXISTS',  strpos($mig, 'CREATE TABLE IF NOT EXISTS external_entity_mappings') !== false);
$assert('source_system is VARCHAR (agnostic, no enum)',
    preg_match('/source_system\s+VARCHAR\(\d+\)\s+NOT NULL/', $mig) === 1
    && stripos($mig, "ENUM('jobdiva'") === false);
$assert('internal_entity_type VARCHAR (agnostic)',
    preg_match('/internal_entity_type\s+VARCHAR\(\d+\)\s+NOT NULL/', $mig) === 1);
$assert('external_id stored as VARCHAR (numeric+GUID friendly)',
    preg_match('/external_id\s+VARCHAR\(\d+\)\s+NOT NULL/', $mig) === 1);
$assert('internal_entity_id BIGINT UNSIGNED',
    preg_match('/internal_entity_id\s+BIGINT UNSIGNED\s+NOT NULL/', $mig) === 1);
$assert('payload_snapshot JSON column present',
    preg_match('/payload_snapshot\s+JSON\s+DEFAULT NULL/', $mig) === 1);
$assert('content_hash CHAR(64) for sha256 hex',
    preg_match('/content_hash\s+CHAR\(64\)/', $mig) === 1);
$assert('direction enum 4 values',
    strpos($mig, "ENUM('pull','push','two_way','off')") !== false);
$assert('sync_status enum 4 values',
    strpos($mig, "ENUM('ok','stale','error','deleted_in_source')") !== false);
$assert('UNIQUE KEY uk_external (external→internal lookup)',
    strpos($mig, 'UNIQUE KEY uk_external (tenant_id, source_system, internal_entity_type, external_id)') !== false);
$assert('UNIQUE KEY uk_internal (internal→external lookup)',
    strpos($mig, 'UNIQUE KEY uk_internal (tenant_id, source_system, internal_entity_type, internal_entity_id)') !== false);
$assert('reverse-lookup index (cross-source on internal record)',
    strpos($mig, 'KEY ix_internal_lookup (tenant_id, internal_entity_type, internal_entity_id)') !== false);
$assert('source last-sync index for worker drivers',
    strpos($mig, 'KEY ix_source_last_sync (tenant_id, source_system, last_synced_at)') !== false);
$assert('utf8mb4_unicode_ci collation',
    strpos($mig, 'utf8mb4_unicode_ci') !== false);
$assert('last_synced_at + last_seen_at columns',
    preg_match('/last_synced_at\s+TIMESTAMP/', $mig) === 1
    && preg_match('/last_seen_at\s+TIMESTAMP/', $mig) === 1);
$assert('updated_at on update CURRENT_TIMESTAMP',
    strpos($mig, 'ON UPDATE CURRENT_TIMESTAMP') !== false);

echo "\nLibrary — core/integrations/entity_mappings.php\n";
$libPath = "{$ROOT}/core/integrations/entity_mappings.php";
$lib = (string) file_get_contents($libPath);
$assert('library exists',                        strlen($lib) > 0);
$assert('parses',                                $lint($libPath));
$assert('declares strict_types',                 strpos($lib, 'declare(strict_types=1)') !== false);
$assert('exposes mappingHash()',                 strpos($lib, 'function mappingHash(') !== false);
$assert('exposes mappingUpsert()',               strpos($lib, 'function mappingUpsert(') !== false);
$assert('exposes mappingFindInternal()',         strpos($lib, 'function mappingFindInternal(') !== false);
$assert('exposes mappingFindExternal()',         strpos($lib, 'function mappingFindExternal(') !== false);
$assert('exposes mappingMarkStatus()',           strpos($lib, 'function mappingMarkStatus(') !== false);
$assert('exposes mappingDelete()',               strpos($lib, 'function mappingDelete(') !== false);
$assert('exposes mappingListForInternal()',      strpos($lib, 'function mappingListForInternal(') !== false);
$assert('directions whitelist constant',
    strpos($lib, "EXTERNAL_MAPPING_DIRECTIONS = ['pull', 'push', 'two_way', 'off']") !== false);
$assert('statuses whitelist constant',
    strpos($lib, "EXTERNAL_MAPPING_STATUSES   = ['ok', 'stale', 'error', 'deleted_in_source']") !== false);
$assert('upsert is race-safe via ON DUPLICATE KEY UPDATE',
    strpos($lib, 'ON DUPLICATE KEY UPDATE') !== false);
$assert('upsert binds source_system + entity_type as separate params',
    strpos($lib, ':src')  !== false
    && strpos($lib, ':et') !== false
    && strpos($lib, ':ext') !== false);
$assert('upsert sets sync_status="ok" on insert+update',
    substr_count($lib, '"ok"') >= 2);
$assert('upsert clears last_error on successful sync',
    strpos($lib, 'last_error         = NULL') !== false);
$assert('upsert bumps last_seen_at even when unchanged',
    strpos($lib, 'SET last_seen_at = NOW()') !== false);
$assert('upsert validates direction whitelist',
    strpos($lib, "in_array(\$direction, EXTERNAL_MAPPING_DIRECTIONS, true)") !== false);
$assert('mappingMarkStatus validates status whitelist',
    strpos($lib, "in_array(\$status, EXTERNAL_MAPPING_STATUSES, true)") !== false);
$assert('mappingMarkStatus clamps error to 500 chars',
    strpos($lib, "substr(\$error, 0, 500)") !== false);
$assert('all queries are tenant-scoped (WHERE tenant_id binding)',
    substr_count($lib, 'tenant_id = :t') >= 4);
$assert('mappingHash uses sha256',                strpos($lib, "hash('sha256'") !== false);
$assert('mappingHash canonicalises (sorts keys recursively)',
    strpos($lib, 'function mappingCanonicalise(') !== false
    && strpos($lib, 'ksort($out)') !== false);
$assert('mappingFindInternal uses external_id key path',
    strpos($lib, 'AND external_id = :ext') !== false);
$assert('mappingFindExternal uses internal_id key path',
    strpos($lib, 'AND internal_entity_id = :iid') !== false);
$assert('mappingDelete is tenant + source + type + external scoped',
    strpos($lib, 'DELETE FROM external_entity_mappings') !== false
    && strpos($lib, 'AND source_system = :src') !== false
    && strpos($lib, 'AND external_id = :ext') !== false);

echo "\nmappingHash() canonical behaviour — runtime\n";
require_once $libPath;

// Behavioural assertions (no DB needed for these).
$h1 = mappingHash(['name' => 'Acme', 'id' => 42, 'tags' => ['A', 'B']]);
$h2 = mappingHash(['id' => 42, 'tags' => ['A', 'B'], 'name' => 'Acme']);
$h3 = mappingHash(['name' => 'Acme', 'id' => 43, 'tags' => ['A', 'B']]);
$h4 = mappingHash(['name' => 'Acme', 'id' => 42, 'tags' => ['B', 'A']]);
$assert('mappingHash() is 64-char sha256 hex',    strlen($h1) === 64 && ctype_xdigit($h1));
$assert('mappingHash() is key-order stable',      $h1 === $h2);
$assert('mappingHash() is content-sensitive (different id ⇒ different hash)', $h1 !== $h3);
$assert('mappingHash() preserves list order (lists are NOT sorted)',          $h1 !== $h4);
$assert('mappingHash() handles nested objects',
    mappingHash(['outer' => ['b' => 1, 'a' => 2]])
    === mappingHash(['outer' => ['a' => 2, 'b' => 1]]));

echo "\nmappingUpsert() input validation — runtime\n";
$reject = function (callable $fn): bool {
    try { $fn(); return false; } catch (\InvalidArgumentException $e) { return true; }
};
// Provide a stub getDB() so validation runs before any PDO call.
if (!function_exists('getDB')) {
    function getDB(): \PDO { throw new \RuntimeException('getDB() should not be reached during validation tests'); }
}
$assert('upsert rejects tenant_id=0',
    $reject(fn() => mappingUpsert(0, 'jobdiva', 'company', 'JD-1', 5)));
$assert('upsert rejects empty source',
    $reject(fn() => mappingUpsert(1, '', 'company', 'JD-1', 5)));
$assert('upsert rejects empty entity_type',
    $reject(fn() => mappingUpsert(1, 'jobdiva', '', 'JD-1', 5)));
$assert('upsert rejects empty external_id',
    $reject(fn() => mappingUpsert(1, 'jobdiva', 'company', '', 5)));
$assert('upsert rejects internal_id=0',
    $reject(fn() => mappingUpsert(1, 'jobdiva', 'company', 'JD-1', 0)));
$assert('upsert rejects unknown direction',
    $reject(fn() => mappingUpsert(1, 'jobdiva', 'company', 'JD-1', 5, null, 'bogus')));

$assert('mappingMarkStatus rejects unknown status',
    $reject(fn() => mappingMarkStatus(1, 99, 'gibberish')));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
