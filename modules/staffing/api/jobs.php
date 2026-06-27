<?php
/**
 * /modules/staffing/api/jobs.php -- Jobs / Roles CRUD.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../lib/jobs.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) ($ctx['tenant_id'] ?? currentTenantId());
$actorUserId = isset($user['id']) ? (int) $user['id'] : null;
$method = api_method();
$action = $_GET['action'] ?? 'list';

function staffingJobApiAllowedFields(): array
{
    return [
        'client_id','company_id','title','status','description','department',
        'location_city','location_state','location_country','remote_policy',
        'opened_at','closed_at',
    ];
}

if ($method === 'GET' && $action === 'list') {
    rbac_legacy_require($user, 'staffing.view');
    $where = ['sj.tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['status'])) {
        $where[] = 'sj.status = :status';
        $params['status'] = (string) $_GET['status'];
    }
    if (!empty($_GET['client_id'])) {
        $where[] = 'sj.client_id = :client_id';
        $params['client_id'] = (int) $_GET['client_id'];
    }
    if (!empty($_GET['q'])) {
        $where[] = '(sj.title LIKE :q OR sj.department LIKE :q2 OR sj.external_id = :qexact)';
        $params['q'] = '%' . (string) $_GET['q'] . '%';
        $params['q2'] = $params['q'];
        $params['qexact'] = (string) $_GET['q'];
    }
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
    $sql = 'SELECT sj.id, sj.client_id, sj.company_id, sj.title, sj.status,
                   sj.external_id, sj.source_system, sj.department,
                   sj.location_city, sj.location_state, sj.location_country,
                   sj.remote_policy, sj.opened_at, sj.closed_at, sj.updated_at,
                   c.name AS client_name,
                   COALESCE(p.placement_count, 0) AS placement_count
              FROM staffing_jobs sj
         LEFT JOIN staffing_clients c ON c.id = sj.client_id AND c.tenant_id = sj.tenant_id
         LEFT JOIN (
                   SELECT tenant_id, staffing_job_id, COUNT(*) AS placement_count
                     FROM placements
                    WHERE staffing_job_id IS NOT NULL
                    GROUP BY tenant_id, staffing_job_id
              ) p ON p.tenant_id = sj.tenant_id AND p.staffing_job_id = sj.id
             WHERE ' . implode(' AND ', $where) . '
          ORDER BY FIELD(sj.status, "open", "active", "on_hold", "filled", "closed", "cancelled"), sj.title
             LIMIT ' . $limit;
    api_ok(['rows' => scopedQuery($sql, $params)]);
}

if ($method === 'GET' && $action === 'get') {
    rbac_legacy_require($user, 'staffing.view');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $job = scopedFind(
        'SELECT sj.*, c.name AS client_name
           FROM staffing_jobs sj
      LEFT JOIN staffing_clients c ON c.id = sj.client_id AND c.tenant_id = sj.tenant_id
          WHERE sj.tenant_id = :tenant_id AND sj.id = :id',
        ['id' => $id]
    );
    if (!$job) api_error('Not found', 404);
    $placements = scopedQuery(
        'SELECT p.id, p.title, p.status, p.start_date, p.end_date,
                pe.first_name, pe.last_name, pe.email_primary
           FROM placements p
      LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = p.tenant_id
          WHERE p.tenant_id = :tenant_id
            AND p.staffing_job_id = :id
            AND p.deleted_at IS NULL
       ORDER BY p.start_date DESC, p.id DESC',
        ['id' => $id]
    );
    api_ok(['job' => $job, 'placements' => $placements]);
}

if ($method === 'POST' && $action === 'create') {
    rbac_legacy_require($user, 'staffing.jobs.manage');
    $body = api_json_body();
    $title = trim((string) ($body['title'] ?? ''));
    if ($title === '') api_error('title required', 422);
    $payload = [
        'tenant_id' => $tenantId,
        'title' => $title,
        'status' => staffingJobNormalizeStatus((string) ($body['status'] ?? 'open')),
        'source_system' => 'manual',
        'created_by_user_id' => $actorUserId,
    ];
    foreach (staffingJobApiAllowedFields() as $field) {
        if (!array_key_exists($field, $body) || $field === 'title' || $field === 'status') continue;
        $payload[$field] = $body[$field];
    }
    $payload = array_filter($payload, static fn($value) => $value !== null && $value !== '');
    $id = (int) scopedInsert('staffing_jobs', $payload);
    $job = scopedFind('SELECT * FROM staffing_jobs WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    api_ok(['job' => $job]);
}

if ($method === 'POST' && $action === 'update') {
    rbac_legacy_require($user, 'staffing.jobs.manage');
    $body = api_json_body();
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $existing = scopedFind('SELECT * FROM staffing_jobs WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$existing) api_error('Not found', 404);
    $patch = [];
    foreach (staffingJobApiAllowedFields() as $field) {
        if (!array_key_exists($field, $body)) continue;
        $patch[$field] = $body[$field];
    }
    if (isset($patch['title'])) $patch['title'] = trim((string) $patch['title']);
    if (array_key_exists('status', $patch)) $patch['status'] = staffingJobNormalizeStatus((string) $patch['status']);
    if (!$patch) api_error('No updatable fields supplied', 422);
    scopedUpdate('staffing_jobs', $id, $patch);
    $job = scopedFind('SELECT * FROM staffing_jobs WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    api_ok(['job' => $job]);
}

if ($method === 'POST' && $action === 'close') {
    rbac_legacy_require($user, 'staffing.jobs.manage');
    $body = api_json_body();
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $existing = scopedFind('SELECT * FROM staffing_jobs WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$existing) api_error('Not found', 404);
    scopedUpdate('staffing_jobs', $id, ['status' => 'closed', 'closed_at' => date('Y-m-d')]);
    api_ok(['ok' => true, 'closed_id' => $id]);
}

api_error('Unknown action', 404);
