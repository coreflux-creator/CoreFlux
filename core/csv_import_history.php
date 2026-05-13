<?php
/**
 * CSV import history — single chokepoint every csv_import endpoint calls
 * AFTER a commit completes (success, partial, OR failure during persist).
 *
 * Designed to NEVER throw. If the table doesn't exist yet (migration
 * 042 not run on this tenant) or the row write fails, we swallow the
 * error and continue. Audit-write is a nicety, not a critical path.
 *
 *   csvImportHistoryRecord([
 *     'entity'          => 'people',
 *     'file_name'       => 'people_jan.csv',          // optional
 *     'bytes_processed' => 14823,                     // optional
 *     'rows_total'      => 120,
 *     'rows_imported'   => 117,
 *     'rows_skipped'    => 3,
 *     'errors'          => [12 => ['email_primary: invalid email']],
 *     'skip_invalid'    => true,
 *     'update_existing' => false,
 *     'ai_used'         => true,
 *     'preset_id'       => 42,                        // optional
 *     'column_map'      => ['First name' => 'first_name', ...],
 *     'duration_ms'     => 412,
 *   ]);
 */

function csvImportHistoryRecord(array $args): void
{
    try {
        $pdo  = getDB();
        if (!$pdo) return;

        $errors      = is_array($args['errors'] ?? null) ? $args['errors'] : [];
        $errorsCount = count($errors);
        $imported    = (int) ($args['rows_imported'] ?? 0);
        $skipped     = (int) ($args['rows_skipped']  ?? 0);
        $total       = (int) ($args['rows_total']    ?? max(0, $imported + $skipped));

        $status = 'success';
        if ($imported === 0)                $status = 'failed';
        elseif ($skipped > 0 || $errorsCount > 0) $status = 'partial';

        $stmt = $pdo->prepare(
            'INSERT INTO csv_import_history
               (tenant_id, entity, file_name, bytes_processed,
                rows_total, rows_imported, rows_skipped, errors_count,
                skip_invalid, update_existing, ai_used, preset_id,
                column_map, error_summary, status, duration_ms, created_by_user_id)
             VALUES
               (:t, :e, :fn, :bp,
                :rt, :ri, :rs, :ec,
                :si, :ue, :ai, :pid,
                :cm, :es, :st, :dur, :uid)'
        );
        $stmt->execute([
            't'   => currentTenantId(),
            'e'   => (string) $args['entity'],
            'fn'  => $args['file_name']       ?? null,
            'bp'  => (int) ($args['bytes_processed'] ?? 0),
            'rt'  => $total,
            'ri'  => $imported,
            'rs'  => $skipped,
            'ec'  => $errorsCount,
            'si'  => !empty($args['skip_invalid'])    ? 1 : 0,
            'ue'  => !empty($args['update_existing']) ? 1 : 0,
            'ai'  => !empty($args['ai_used'])         ? 1 : 0,
            'pid' => isset($args['preset_id']) && $args['preset_id'] ? (int) $args['preset_id'] : null,
            'cm'  => $args['column_map']  ? json_encode($args['column_map']) : null,
            'es'  => $errorsCount         ? json_encode(array_slice($errors, 0, 50, true)) : null,
            'st'  => $status,
            'dur' => isset($args['duration_ms']) ? (int) $args['duration_ms'] : null,
            'uid' => $_SESSION['user']['id'] ?? null,
        ]);
    } catch (\Throwable $e) {
        // Swallow — never break a working import for a missing audit row.
        error_log('csvImportHistoryRecord failed: ' . $e->getMessage());
    }
}
