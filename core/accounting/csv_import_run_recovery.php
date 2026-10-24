<?php
/** Locked resolution of a CoreAccounting CSV reservation with no committed source. */
declare(strict_types=1);

require_once __DIR__ . '/csv_import_scope.php';

function coreAccountingCsvCanAbandonUnstarted(array $run): bool
{
    $markers = json_decode((string) ($run['error_summary'] ?? ''), true);
    return getenv('COREFLUX_ENV') === 'coreaccounting'
        && coreAccountingCsvEntityAllowed((string) ($run['entity'] ?? ''))
        && is_array($markers)
        && isset($markers['_import_outcome_unconfirmed'])
        && !isset($markers['_source_commit_started'])
        && !isset($markers['_server_outcome_recorded'])
        && (string) ($run['status'] ?? '') === 'failed'
        && (int) ($run['rows_imported'] ?? -1) === 0
        && (int) ($run['rows_skipped'] ?? -1) === 0;
}

function coreAccountingCsvAbandonUnstarted(PDO $pdo, int $runId, int $tenantId, int $userId): array
{
    if (getenv('COREFLUX_ENV') !== 'coreaccounting' || $pdo->inTransaction()) {
        throw new RuntimeException('Only an isolated CoreAccounting run can be closed here.');
    }
    if (!$pdo->beginTransaction()) {
        throw new RuntimeException('Could not lock the import run for closure.');
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT id, entity, status, error_summary, rows_total, rows_imported, rows_skipped
               FROM csv_import_history
              WHERE id = :id AND tenant_id = :tenant_id AND created_by_user_id = :user_id
              FOR UPDATE'
        );
        $stmt->execute(['id' => $runId, 'tenant_id' => $tenantId, 'user_id' => $userId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            $pdo->rollBack();
            return ['state' => 'not_found'];
        }
        $markers = json_decode((string) ($run['error_summary'] ?? ''), true);
        if (!is_array($markers) || !coreAccountingCsvEntityAllowed((string) $run['entity'])) {
            $pdo->rollBack();
            return ['state' => 'needs_review'];
        }
        if (isset($markers['_import_abandoned']) && !isset($markers['_import_outcome_unconfirmed'])) {
            $pdo->commit();
            return ['state' => 'already_abandoned', 'id' => $runId];
        }
        if (isset($markers['_server_outcome_recorded']) && !isset($markers['_import_outcome_unconfirmed'])) {
            $pdo->commit();
            return ['state' => 'already_recorded', 'id' => $runId];
        }
        if (!coreAccountingCsvCanAbandonUnstarted($run)) {
            $pdo->rollBack();
            return ['state' => 'needs_review'];
        }
        unset($markers['_import_outcome_unconfirmed']);
        $markers['_import_abandoned'] = [
            'at' => gmdate(DATE_ATOM),
            'by_user_id' => $userId,
            'reason' => 'No source commit was recorded under the import run lock.',
        ];
        $update = $pdo->prepare(
            "UPDATE csv_import_history SET status = 'failed', errors_count = 0,
                    error_summary = :errors
              WHERE id = :id AND tenant_id = :tenant_id AND created_by_user_id = :user_id"
        );
        $update->execute([
            'errors' => json_encode($markers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'id' => $runId, 'tenant_id' => $tenantId, 'user_id' => $userId,
        ]);
        if ($update->rowCount() !== 1) throw new RuntimeException('Import closure was not saved.');
        $pdo->commit();
        return ['state' => 'abandoned', 'id' => $runId];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
