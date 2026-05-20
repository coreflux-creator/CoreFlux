<?php
/**
 * Shared helper for registering a uploaded S3 object into the
 * `storage_objects` table after a presigned-POST direct browser upload.
 *
 * The presigned POST flow is:
 *   1. Client calls e.g. /api/ap/bills?action=upload_url
 *      → server returns {storage_key, upload: {url, fields}}
 *   2. Client POSTs the file to S3 directly with that form (no PHP touches it)
 *   3. Client calls e.g. /api/ap/bills?action=attach&id=N body {storage_key, ...}
 *      → server calls registerStorageObject() to materialise the row + record
 *        the FK on the parent entity.
 *
 * Per StorageService.php SPEC §4.2 the row is owned by the calling module's
 * DAO. This helper centralises the INSERT so each module doesn't reinvent it.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tenant_scope.php';

/**
 * Insert a `storage_objects` row pointing at a previously-uploaded S3 key.
 *
 * @param int    $tenantId
 * @param string $module       e.g. 'ap', 'billing', 'people'
 * @param string $entityType   e.g. 'bill', 'bill_line', 'invoice'
 * @param int|string $entityId
 * @param string $s3Key        Output of StorageService::build_key()
 * @param string $filename     Display filename
 * @param string|null $mime
 * @param int|null    $sizeBytes
 * @param int|null    $uploadedByUserId
 *
 * @return int The new storage_objects.id
 */
function registerStorageObject(
    int $tenantId,
    string $module,
    string $entityType,
    $entityId,
    string $s3Key,
    string $filename,
    ?string $mime = null,
    ?int $sizeBytes = null,
    ?int $uploadedByUserId = null
): int {
    $pdo = getDB();
    // Idempotent on s3_key (UNIQUE in storage_objects). If the same key
    // was registered before, return the existing row id.
    // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
    $existing = $pdo->prepare('SELECT id FROM storage_objects WHERE s3_key = :k LIMIT 1');
    $existing->execute(['k' => $s3Key]);
    $found = (int) ($existing->fetchColumn() ?: 0);
    if ($found > 0) return $found;

    $pdo->prepare(
        'INSERT INTO storage_objects
           (tenant_id, module, entity_type, entity_id, s3_key, filename, mime, size_bytes, created_by_user_id, created_at)
         VALUES
           (:t, :m, :et, :ei, :k, :fn, :mi, :sz, :u, NOW())'
    )->execute([
        't'  => $tenantId,
        'm'  => $module,
        'et' => $entityType,
        'ei' => (string) $entityId,
        'k'  => $s3Key,
        'fn' => $filename,
        'mi' => $mime,
        'sz' => $sizeBytes,
        'u'  => $uploadedByUserId,
    ]);
    return (int) $pdo->lastInsertId();
}
