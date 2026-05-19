<?php
/**
 * Time API — downstream feed (SPEC §5.7).
 *
 *   GET  /api/time/feed?bundle_type=ar&period_id=N    → ready bundles
 *   POST /api/time/feed?action=consume&id=N           → mark as consumed (system role)
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/time.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    rbac_legacy_require($user, 'time.view');
    $where  = ['tdf.tenant_id = :tenant_id', 'tdf.status = "ready"'];
    $params = [];
    if (!empty($_GET['bundle_type'])) { $where[] = 'tdf.bundle_type = :bt'; $params['bt'] = $_GET['bundle_type']; }
    if (!empty($_GET['period_id']))   { $where[] = 'tdf.period_id = :pid';  $params['pid'] = (int) $_GET['period_id']; }
    $rows = scopedQuery(
        'SELECT tdf.*, p.title AS placement_title, p.end_client_name
         FROM time_downstream_feed tdf
         LEFT JOIN placements p ON p.id = tdf.placement_id AND p.tenant_id = tdf.tenant_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY tdf.created_at DESC LIMIT 500',
        $params
    );
    api_ok(['rows' => $rows]);
}

if ($method === 'POST' && $action === 'consume') {
    rbac_legacy_require($user, 'time.feed.consume');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    api_require_fields($body, ['consumed_by_module', 'consumed_ref_id']);
    $rows = scopedUpdate('time_downstream_feed', $id, [
        'status'            => 'consumed',
        'consumed_at'       => date('Y-m-d H:i:s'),
        'consumed_by_module'=> $body['consumed_by_module'],
        'consumed_ref_id'   => (int) $body['consumed_ref_id'],
    ]);
    if ($rows === 0) api_error('Not found or no change', 404);
    timeAudit('time.feed.consumed', ['bundle_id' => $id, 'by' => $body['consumed_by_module'], 'ref' => (int) $body['consumed_ref_id']], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
