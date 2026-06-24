<?php
/**
 * Engagements API — detail + patch.
 *
 *   GET   /modules/engagements/api/detail.php?id=N
 *   PATCH /modules/engagements/api/detail.php?id=N
 *         Body: any subset of { client_name, project_name, total_fee, currency,
 *                               status, start_date, end_date, description,
 *                               notes, entity_id }
 *   DELETE /modules/engagements/api/detail.php?id=N
 *         Archives (soft-delete) the engagement.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../lib/engagements.php';

$ctx = api_require_auth();
$tid = (int) $ctx['tenant_id'];
$uid = (int) ($ctx['user']['id'] ?? $ctx['user_id'] ?? 0);
$id  = (int) ($_GET['id'] ?? 0);
if ($id <= 0) api_error('id required', 400);

$method = api_method();

if ($method === 'GET') {
    $row = engagementsGet($tid, $id);
    if (!$row) api_error('Engagement not found', 404);
    api_ok(['engagement' => $row]);
}

if ($method === 'PATCH') {
    rbac_legacy_require_any($ctx['user'], ['master_admin', 'tenant_admin', 'admin', 'billing.manage', '*']);
    $patch = json_decode((string) file_get_contents('php://input'), true) ?: [];
    try {
        engagementsUpdate($tid, $id, $patch, $uid);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 400);
    }
    api_ok(['engagement' => engagementsGet($tid, $id)]);
}

if ($method === 'DELETE') {
    rbac_legacy_require_any($ctx['user'], ['master_admin', 'tenant_admin', 'admin', '*']);
    try {
        engagementsArchive($tid, $id, $uid);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 400);
    }
    api_ok(['archived_id' => $id]);
}

api_error('Method not allowed', 405);
