<?php
/**
 * Engagements API — list + create.
 *
 *   GET  /modules/engagements/api/list.php?status=&entity_id=&client=&limit=&offset=
 *   POST /modules/engagements/api/list.php   { client_name, project_name, total_fee?, currency?,
 *                                              entity_id?, start_date?, end_date?, description?,
 *                                              notes?, milestones?: [{name, amount, target_date?}] }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../lib/engagements.php';

$ctx = api_require_auth();
$tid = (int) $ctx['tenant_id'];
$uid = (int) ($ctx['user']['id'] ?? $ctx['user_id'] ?? 0);

$method = api_method();

if ($method === 'GET') {
    $filters = [
        'status'    => $_GET['status']    ?? null,
        'entity_id' => $_GET['entity_id'] ?? null,
        'client'    => $_GET['client']    ?? null,
        'limit'     => $_GET['limit']     ?? 100,
        'offset'    => $_GET['offset']    ?? 0,
    ];
    $rows = engagementsList($tid, $filters);

    // Counts by status for the tab strip on the list page.
    $countsStmt = getDB()->prepare(
        'SELECT status, COUNT(*) AS n FROM engagements WHERE tenant_id = :t GROUP BY status'
    );
    $countsStmt->execute(['t' => $tid]);
    $counts = ['draft' => 0, 'active' => 0, 'completed' => 0, 'archived' => 0];
    foreach ($countsStmt->fetchAll(\PDO::FETCH_ASSOC) as $c) {
        $counts[$c['status']] = (int) $c['n'];
    }
    api_ok(['rows' => $rows, 'counts' => $counts]);
}

if ($method === 'POST') {
    rbac_legacy_require_any($ctx['user'], ['master_admin', 'tenant_admin', 'admin', 'billing.manage', '*']);
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    try {
        $id = engagementsCreate($tid, $body, $uid);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 400);
    }
    api_ok(['id' => $id, 'engagement' => engagementsGet($tid, $id)]);
}

api_error('Method not allowed', 405);
