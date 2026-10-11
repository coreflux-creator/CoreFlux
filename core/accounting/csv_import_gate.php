<?php
/** Source-evidence gate for standalone CoreAccounting finance CSV commits. */
declare(strict_types=1);

require_once __DIR__ . '/../StorageService.php';

function coreAccountingCsvContentMatches(string $archived, string $submitted): bool
{
    // FileReader removes a UTF-8 BOM when decoding the browser upload.
    $archived = preg_replace('/^\xEF\xBB\xBF/', '', $archived);
    $submitted = preg_replace('/^\xEF\xBB\xBF/', '', $submitted);
    return hash_equals(hash('sha256', $archived), hash('sha256', $submitted));
}

function coreAccountingClaimCsvImport(string $entity, string $csv, int $tenantId, int $userId): void
{
    if (getenv('COREFLUX_ENV') !== 'coreaccounting') return;

    $body = api_json_body();
    $runId = (int) ($body['import_run_id'] ?? 0);
    if ($runId <= 0) api_error('Reserve an import run and archive the source CSV before importing.', 422);

    $pdo = getDB();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT entity, bytes_processed, error_summary FROM csv_import_history
              WHERE id = :id AND tenant_id = :tenant_id AND created_by_user_id = :user_id
              FOR UPDATE'
        );
        $stmt->execute(['id' => $runId, 'tenant_id' => $tenantId, 'user_id' => $userId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$run || $run['entity'] !== $entity) {
            throw new InvalidArgumentException('Import run does not belong to this user, company, or import type.');
        }
        $errors = json_decode((string) ($run['error_summary'] ?? ''), true);
        if (!is_array($errors) || !isset($errors['_import_outcome_unconfirmed'])
            || isset($errors['_source_commit_started'])) {
            throw new InvalidArgumentException('This import run has already been used or finalized. Check Import History before retrying.');
        }

        $stmt = $pdo->prepare(
            "SELECT storage_key, size_bytes FROM evidence_attachments
              WHERE tenant_id = :tenant_id AND subject_type = 'csv_import_run'
                AND subject_id = :id AND document_type = 'csv_source'
                AND attached_by_user_id = :user_id AND deleted_at IS NULL
              FOR UPDATE"
        );
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $runId, 'user_id' => $userId]);
        $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($sources) !== 1) {
            throw new InvalidArgumentException('Exactly one archived source CSV is required before importing.');
        }
        $key = (string) ($sources[0]['storage_key'] ?? '');
        if (!str_starts_with($key, "csv_imports/{$tenantId}/csv_import_run/{$runId}/")) {
            throw new InvalidArgumentException('The archived source file is not linked to this import run.');
        }
        $size = (int) ($sources[0]['size_bytes'] ?? 0);
        if ($size < 1 || $size > 25 * 1024 * 1024 || $size !== (int) $run['bytes_processed']) {
            throw new InvalidArgumentException('The archived source file size does not match the reserved import.');
        }
        $source = \Core\StorageService::getInstance()->read($key, 25 * 1024 * 1024);
        if ($source === null || strlen($source) !== $size) {
            throw new InvalidArgumentException('The archived source CSV is missing or incomplete.');
        }
        if (!coreAccountingCsvContentMatches($source, $csv)) {
            throw new InvalidArgumentException('The CSV being imported differs from its archived source. Select the file again.');
        }

        $errors['_source_commit_started'] = [gmdate(DATE_ATOM)];
        $stmt = $pdo->prepare(
            'UPDATE csv_import_history SET error_summary = :errors
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            'errors' => json_encode($errors, JSON_UNESCAPED_SLASHES),
            'id' => $runId,
            'tenant_id' => $tenantId,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof InvalidArgumentException) api_error($error->getMessage(), 422);
        error_log('CoreAccounting CSV import claim failed: ' . $error->getMessage());
        api_error('Import source could not be verified. No import was started.', 503);
    }
}
