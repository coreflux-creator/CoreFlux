<?php
/**
 * CSV Import History — audit trail of every bulk import.
 *
 *   GET  /api/admin/csv_import_history
 *       [?entity=people&status=success|partial|failed&limit=200]
 *       [?from=YYYY-MM-DD&to=YYYY-MM-DD]
 *
 *   POST /api/admin/csv_import_history
 *       Body: { entity, file_name?, bytes_processed?, rows_total?,
 *               rows_imported?, rows_skipped?, errors?, skip_invalid?,
 *               update_existing?, ai_used?, preset_id?, column_map?,
 *               duration_ms? }
 *   POST ?action=reserve creates a durable, explicitly unconfirmed run
 *       before the source commit. POST ?action=finalize records its result.
 *       A crash or failed response leaves the unconfirmed run visible for
 *       reconciliation; it must never be interpreted as zero source writes.
 */
require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/csv_import_history.php';
require_once __DIR__ . '/../../core/accounting/csv_import_scope.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) api_query('action', '');
const CSV_IMPORT_UNCONFIRMED_KEY = '_import_outcome_unconfirmed';

// ---------- POST: record one history row ----------
if ($method === 'POST') {
    $body = api_json_body();
    if ($action === 'finalize') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) api_error('Import run ID is required', 422);
        $pdo = getDB();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT id, status, error_summary, rows_total, rows_imported, rows_skipped FROM csv_import_history
                  WHERE id = :id AND tenant_id = :tenant_id AND created_by_user_id = :user_id
                  FOR UPDATE'
            );
            $stmt->execute(['id' => $id, 'tenant_id' => $tid, 'user_id' => (int) $user['id']]);
            $run = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$run) {
                $pdo->rollBack();
                api_error('Import run not found for this user', 404);
            }
            $oldErrors = json_decode((string) ($run['error_summary'] ?? ''), true);
            if (!is_array($oldErrors) || !isset($oldErrors[CSV_IMPORT_UNCONFIRMED_KEY])) {
                $pdo->commit();
                api_ok([
                    'recorded' => true, 'id' => $id, 'already_finalized' => true,
                    'server_outcome_recorded' => isset($oldErrors['_server_outcome_recorded']),
                    'rows_total' => (int) $run['rows_total'],
                    'rows_imported' => (int) $run['rows_imported'],
                    'rows_skipped' => (int) $run['rows_skipped'],
                    'status' => $run['status'],
                ]);
            }
            if (getenv('COREFLUX_ENV') === 'coreaccounting') {
                $pdo->rollBack();
                api_error('The server has not recorded this import outcome. Check source records before retrying.', 409);
            }
            $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];
            $imported = max(0, (int) ($body['rows_imported'] ?? 0));
            $skipped = max(0, (int) ($body['rows_skipped'] ?? 0));
            $total = max($imported + $skipped, (int) ($body['rows_total'] ?? 0));
            $status = $imported === 0 ? 'failed' : ($skipped > 0 || $errors ? 'partial' : 'success');
            $update = $pdo->prepare(
                'UPDATE csv_import_history
                    SET rows_total = :total, rows_imported = :imported,
                        rows_skipped = :skipped, errors_count = :error_count,
                        error_summary = :errors, status = :status, duration_ms = :duration
                  WHERE id = :id AND tenant_id = :tenant_id'
            );
            $update->execute([
                'total' => $total, 'imported' => $imported, 'skipped' => $skipped,
                'error_count' => count($errors),
                'errors' => $errors ? json_encode(array_slice($errors, 0, 50, true)) : null,
                'status' => $status,
                'duration' => isset($body['duration_ms']) ? max(0, (int) $body['duration_ms']) : null,
                'id' => $id, 'tenant_id' => $tid,
            ]);
            $pdo->commit();
            api_ok(['recorded' => true, 'id' => $id, 'status' => $status]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('CSV import history finalization failed: ' . $error->getMessage());
            api_error('Import outcome could not be saved. Check the run in Import History before importing again.', 503);
        }
    }
    if (getenv('COREFLUX_ENV') === 'coreaccounting' && $action !== 'reserve') {
        api_error('CoreAccounting imports must reserve a run and archive the source before committing.', 422);
    }
    if ($action !== '' && $action !== 'reserve') api_error('Unknown action', 400);
    $entity = (string) ($body['entity'] ?? '');
    if ($entity === '') api_error('entity is required', 400);
    if (!coreAccountingCsvEntityAllowed($entity)) api_error('Import type is unavailable in CoreAccounting', 422);

    $newId = csvImportHistoryRecord([
        'entity'          => $entity,
        'file_name'       => $body['file_name']       ?? null,
        'bytes_processed' => (int) ($body['bytes_processed'] ?? 0),
        'rows_total'      => (int) ($body['rows_total']      ?? 0),
        'rows_imported'   => $action === 'reserve' ? 0 : (int) ($body['rows_imported'] ?? 0),
        'rows_skipped'    => $action === 'reserve' ? 0 : (int) ($body['rows_skipped'] ?? 0),
        'errors'          => $action === 'reserve'
            ? [CSV_IMPORT_UNCONFIRMED_KEY => ['Import outcome is unconfirmed. Check source records before retrying.']]
            : (is_array($body['errors'] ?? null) ? $body['errors'] : []),
        'errors_count_override' => $action === 'reserve' ? 0 : null,
        'skip_invalid'    => !empty($body['skip_invalid']),
        'update_existing' => !empty($body['update_existing']),
        'ai_used'         => !empty($body['ai_used']),
        'preset_id'       => isset($body['preset_id']) && $body['preset_id'] ? (int) $body['preset_id'] : null,
        'column_map'      => is_array($body['column_map'] ?? null) ? $body['column_map'] : null,
        'duration_ms'     => isset($body['duration_ms']) ? (int) $body['duration_ms'] : null,
        'created_by_user_id' => (int) $user['id'],
    ]);
    if (!$newId) api_error('Import history is unavailable. No import was started; try again later.', 503);
    api_ok(['recorded' => true, 'id' => $newId, 'needs_review' => $action === 'reserve']);
}

if ($method !== 'GET') api_error('Method not allowed', 405);

$where  = ['h.tenant_id = :tenant_id'];
$params = [];

if (getenv('COREFLUX_ENV') === 'coreaccounting') {
    foreach (coreAccountingCsvEntities() as $index => $allowedEntity) {
        $key = 'finance_entity_' . $index;
        $params[$key] = $allowedEntity;
    }
    $where[] = 'h.entity IN (' . implode(',', array_map(
        static fn(int $index): string => ':finance_entity_' . $index,
        array_keys(coreAccountingCsvEntities())
    )) . ')';
}

if (!empty($_GET['entity']))  {
    if (!coreAccountingCsvEntityAllowed((string) $_GET['entity'])) {
        api_error('Import type is unavailable in CoreAccounting', 422);
    }
    $where[] = 'h.entity = :e';
    $params['e'] = $_GET['entity'];
}
if (!empty($_GET['status'])) {
    $requestedStatus = (string) $_GET['status'];
    if (!in_array($requestedStatus, ['success', 'partial', 'failed', 'needs_review'], true)) {
        api_error('Unknown import history status', 422);
    }
    if ($requestedStatus === 'needs_review') {
        $where[] = 'LOCATE(:review_marker, h.error_summary) > 0';
        $params['review_marker'] = CSV_IMPORT_UNCONFIRMED_KEY;
    } else {
        $where[] = 'h.status = :s';
        $params['s'] = $requestedStatus;
        if ($requestedStatus === 'failed') {
            $where[] = '(h.error_summary IS NULL OR LOCATE(:review_marker, h.error_summary) = 0)';
            $params['review_marker'] = CSV_IMPORT_UNCONFIRMED_KEY;
        }
    }
}
if (!empty($_GET['from']))    { $where[] = 'h.created_at >= :f'; $params['f']  = $_GET['from']; }
if (!empty($_GET['to']))      { $where[] = 'h.created_at <= :t'; $params['t']  = $_GET['to'] . ' 23:59:59'; }

$limit = max(1, min(500, (int) ($_GET['limit'] ?? 200)));

try {
    $rows = scopedQuery(
        'SELECT h.id, h.entity, h.file_name, h.bytes_processed,
                h.rows_total, h.rows_imported, h.rows_skipped, h.errors_count,
                h.skip_invalid, h.update_existing, h.ai_used, h.preset_id,
                h.column_map, h.error_summary, h.status, h.duration_ms,
                h.created_by_user_id, h.created_at,
                u.email AS created_by_email,
                p.name  AS preset_name
           FROM csv_import_history h
           LEFT JOIN users u                  ON u.id = h.created_by_user_id
           LEFT JOIN csv_mapping_presets p    ON p.id = h.preset_id AND p.tenant_id = h.tenant_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY h.created_at DESC, h.id DESC
          LIMIT ' . $limit,
        $params
    );
} catch (\Throwable $e) {
    if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1146) {
        api_ok(['rows' => [], 'migration_pending' => true]);
    }
    error_log('CSV import history read failed: ' . $e->getMessage());
    api_error('Import history could not be loaded. Try again later.', 503);
}

// Decode JSON columns so the UI can render them directly.
foreach ($rows as &$r) {
    $r['column_map']    = is_string($r['column_map'])    ? json_decode($r['column_map'],    true) : $r['column_map'];
    $r['error_summary'] = is_string($r['error_summary']) ? json_decode($r['error_summary'], true) : $r['error_summary'];
    $r['needs_review'] = isset($r['error_summary'][CSV_IMPORT_UNCONFIRMED_KEY]);
    $r['server_outcome_recorded'] = isset($r['error_summary']['_server_outcome_recorded']);
}

api_ok(['rows' => $rows]);
