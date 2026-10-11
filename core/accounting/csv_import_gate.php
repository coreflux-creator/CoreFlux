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
    if (!$pdo) api_error('Import source could not be verified. No import was started.', 503);
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

function coreAccountingCsvOutcomeSummary(array $result): array
{
    $imported = max(0, (int) ($result['imported_row_count'] ?? $result['imported_count'] ?? 0));
    $skipped = max(0, (int) ($result['skipped_row_count'] ?? $result['skipped_count'] ?? 0));
    $total = max($imported + $skipped, (int) ($result['row_count'] ?? 0));
    $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
    $status = $imported === 0 ? 'failed' : ($skipped > 0 || $errors ? 'partial' : 'success');
    return compact('imported', 'skipped', 'total', 'errors', 'status');
}

/** Persist the source endpoint's result before returning it to the browser. */
function coreAccountingRecordCsvImportOutcome(array $result, int $tenantId, int $userId): array
{
    if (getenv('COREFLUX_ENV') !== 'coreaccounting') return $result;

    $body = api_json_body();
    $runId = (int) ($body['import_run_id'] ?? 0);
    if ($runId <= 0) api_error('Import run ID is required to record the outcome.', 422);

    $outcome = coreAccountingCsvOutcomeSummary($result);
    $pdo = getDB();
    if (!$pdo) api_error('Import may have changed records, but its outcome could not be recorded. Check Import History and source records before retrying.', 503);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT error_summary FROM csv_import_history
              WHERE id = :id AND tenant_id = :tenant_id AND created_by_user_id = :user_id
              FOR UPDATE'
        );
        $stmt->execute(['id' => $runId, 'tenant_id' => $tenantId, 'user_id' => $userId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        $markers = json_decode((string) ($run['error_summary'] ?? ''), true);
        if (!$run || !is_array($markers) || !isset($markers['_import_outcome_unconfirmed'])
            || !isset($markers['_source_commit_started'])) {
            throw new RuntimeException('The claimed import run is unavailable for its server outcome.');
        }
        $summary = array_slice($outcome['errors'], 0, 50, true);
        $summary['_server_outcome_recorded'] = [gmdate(DATE_ATOM)];
        $stmt = $pdo->prepare(
            'UPDATE csv_import_history
                SET rows_total = :total, rows_imported = :imported,
                    rows_skipped = :skipped, errors_count = :error_count,
                    error_summary = :errors, status = :status
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            'total' => $outcome['total'], 'imported' => $outcome['imported'],
            'skipped' => $outcome['skipped'], 'error_count' => count($outcome['errors']),
            'errors' => json_encode($summary, JSON_THROW_ON_ERROR),
            'status' => $outcome['status'], 'id' => $runId, 'tenant_id' => $tenantId,
        ]);
        $pdo->commit();
        $result['import_run_id'] = $runId;
        $result['server_outcome_recorded'] = true;
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('CoreAccounting CSV import outcome failed: ' . $error->getMessage());
        api_error('Import may have changed records, but its outcome could not be recorded. Check Import History and source records before retrying.', 503);
    }
}
