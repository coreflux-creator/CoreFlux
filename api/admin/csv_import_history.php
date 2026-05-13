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
 *       Called from the SPA right after a successful CSV commit so the
 *       CFO/audit view captures who imported what, when, with which
 *       mapping. Never raises — audit-write is a nicety, not a critical
 *       path (matches csvImportHistoryRecord() semantics).
 */
require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/csv_import_history.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();

// ---------- POST: record one history row ----------
if ($method === 'POST') {
    $body = api_json_body();
    $entity = (string) ($body['entity'] ?? '');
    if ($entity === '') api_error('entity is required', 400);

    csvImportHistoryRecord([
        'entity'          => $entity,
        'file_name'       => $body['file_name']       ?? null,
        'bytes_processed' => (int) ($body['bytes_processed'] ?? 0),
        'rows_total'      => (int) ($body['rows_total']      ?? 0),
        'rows_imported'   => (int) ($body['rows_imported']   ?? 0),
        'rows_skipped'    => (int) ($body['rows_skipped']    ?? 0),
        'errors'          => is_array($body['errors'] ?? null) ? $body['errors'] : [],
        'skip_invalid'    => !empty($body['skip_invalid']),
        'update_existing' => !empty($body['update_existing']),
        'ai_used'         => !empty($body['ai_used']),
        'preset_id'       => isset($body['preset_id']) && $body['preset_id'] ? (int) $body['preset_id'] : null,
        'column_map'      => is_array($body['column_map'] ?? null) ? $body['column_map'] : null,
        'duration_ms'     => isset($body['duration_ms']) ? (int) $body['duration_ms'] : null,
    ]);
    api_ok(['recorded' => true]);
}

if ($method !== 'GET') api_error('Method not allowed', 405);

$where  = ['h.tenant_id = :tenant_id'];
$params = [];

if (!empty($_GET['entity']))  { $where[] = 'h.entity = :e';     $params['e']  = $_GET['entity']; }
if (!empty($_GET['status']))  { $where[] = 'h.status = :s';     $params['s']  = $_GET['status']; }
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
    // Migration may not have run on this tenant yet — return an empty
    // result rather than 500, the UI will render an empty state.
    api_ok(['rows' => [], 'migration_pending' => true]);
}

// Decode JSON columns so the UI can render them directly.
foreach ($rows as &$r) {
    $r['column_map']    = is_string($r['column_map'])    ? json_decode($r['column_map'],    true) : $r['column_map'];
    $r['error_summary'] = is_string($r['error_summary']) ? json_decode($r['error_summary'], true) : $r['error_summary'];
}

api_ok(['rows' => $rows]);
