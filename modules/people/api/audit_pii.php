<?php
/**
 * People API — PII access audit log (SOC2 self-serve)
 *
 *   GET /api/people/audit_pii
 *     filters: actor_user_id, target_person_id, event_type, since, until, page, per_page
 *
 * Visible to anyone with `people.pii.audit.view` (default: tenant_admin +
 * master_admin per HARD_RULES decision log 2026-02-XX).
 *
 * SPEC: /app/modules/people/SPEC.md §5.2, §11.3
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';

$ctx = api_require_auth();
$user = $ctx['user'];
if (api_method() !== 'GET') api_error('Method not allowed', 405);
RBAC::requirePermission($user, 'people.pii.audit.view');

$where = ['tenant_id = :tenant_id'];
$params = [];

if (!empty($_GET['actor_user_id'])) {
    $where[] = 'actor_user_id = :actor';
    $params['actor'] = (int) $_GET['actor_user_id'];
}
if (!empty($_GET['target_person_id'])) {
    $where[] = 'target_person_id = :target';
    $params['target'] = (int) $_GET['target_person_id'];
}
if (!empty($_GET['event_type'])) {
    $where[] = 'event_type = :event_type';
    $params['event_type'] = $_GET['event_type'];
}
if (!empty($_GET['since'])) {
    $where[] = 'created_at >= :since';
    $params['since'] = $_GET['since'];
}
if (!empty($_GET['until'])) {
    $where[] = 'created_at <= :until';
    $params['until'] = $_GET['until'];
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(500, max(1, (int) ($_GET['per_page'] ?? 50)));
$offset  = ($page - 1) * $perPage;

$whereSql = implode(' AND ', $where);

$total = (int) (scopedFind("SELECT COUNT(*) AS c FROM people_pii_access_log WHERE {$whereSql}", $params)['c'] ?? 0);

$rows = scopedQuery(
    "SELECT * FROM people_pii_access_log
     WHERE {$whereSql}
     ORDER BY created_at DESC
     LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
    $params
);

api_ok([
    'rows'     => $rows,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
]);
