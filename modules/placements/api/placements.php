<?php
/**
 * Placements API — main resource (CRUD + end action)
 *
 * Routes:
 *   GET    /api/placements/placements                  → list
 *   GET    /api/placements/placements?id=N             → get one (full)
 *   POST   /api/placements/placements                  → create draft
 *   PATCH  /api/placements/placements?id=N             → update
 *   POST   /api/placements/placements?action=end&id=N  → set status='ended'
 *
 * SPEC: /app/modules/placements/SPEC.md §6.1
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/placements.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();

const ALLOWED_STATUS = ['draft','pending_start','active','on_hold','ended','cancelled'];
const ALLOWED_ETYPE  = ['w2','1099','c2c','temp_to_perm','direct_hire','internal'];

if ($method === 'GET') {
    $id = (int) api_query('id', 0);
    if ($id > 0) {
        RBAC::requirePermission($user, 'placements.view');
        $row = placementGet($id);
        if (!$row) api_error('Not found', 404);
        api_ok([
            'placement'   => $row,
            'chain'       => placementChain($id),
            'rates'       => placementRates($id),
            'current_rate'=> placementCurrentRate($id),
            'commissions' => placementCommissions($id),
            'referrals'   => placementReferrals($id),
            'documents'   => placementDocuments($id),
        ]);
    }
    RBAC::requirePermission($user, 'placements.view');
    api_ok(placementsList([
        'q'               => $_GET['q']               ?? null,
        'status'          => $_GET['status']          ?? null,
        'person_id'       => $_GET['person_id']       ?? null,
        'end_client'      => $_GET['end_client']      ?? null,
        'engagement_type' => $_GET['engagement_type'] ?? null,
        'start_after'     => $_GET['start_after']     ?? null,
        'end_before'      => $_GET['end_before']      ?? null,
        'due_before'      => $_GET['due_before']      ?? null,
        'page'            => $_GET['page']            ?? 1,
        'per_page'        => $_GET['per_page']        ?? 25,
    ]));
}

if ($method === 'POST') {
    $action = $_GET['action'] ?? '';

    if ($action === 'end') {
        $id = (int) api_query('id', 0);
        if ($id <= 0) api_error('id required', 400);
        RBAC::requirePermission($user, 'placements.terminate');
        $body = api_json_body();
        $newStatus = in_array(($body['status'] ?? 'ended'), ['ended', 'cancelled'], true)
                   ? $body['status'] : 'ended';
        $rows = scopedUpdate('placements', $id, [
            'status'           => $newStatus,
            'actual_end_date'  => $body['actual_end_date'] ?? date('Y-m-d'),
        ]);
        if ($rows === 0) api_error('Not found or no change', 404);
        placementsAudit('placement.ended', ['id' => $id, 'status' => $newStatus, 'reason' => $body['reason'] ?? null], $id);
        api_ok(['ok' => true, 'placement' => placementGet($id)]);
    }

    // Default POST = create
    RBAC::requirePermission($user, 'placements.manage');
    $body = api_json_body();
    api_require_fields($body, ['person_id', 'title', 'start_date', 'engagement_type']);
    if (!in_array($body['engagement_type'], ALLOWED_ETYPE, true)) {
        api_error('Invalid engagement_type', 422, ['allowed' => ALLOWED_ETYPE]);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['start_date'])) {
        api_error('start_date must be YYYY-MM-DD', 422);
    }

    // person_id must belong to the same tenant
    $person = scopedFind('SELECT id FROM people WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL',
        ['id' => (int) $body['person_id']]);
    if (!$person) api_error('person_id not found in this tenant', 422);

    $statusInput = $body['status'] ?? 'draft';
    $insert = [
        'person_id'        => (int) $body['person_id'],
        'external_id'      => $body['external_id']      ?? null,
        'status'           => in_array($statusInput, ALLOWED_STATUS, true) ? $statusInput : 'draft',
        'start_date'       => $body['start_date'],
        'end_date'         => $body['end_date']         ?? null,
        'due_date'         => $body['due_date']         ?? null,
        'engagement_type'  => $body['engagement_type'],
        'worksite_state'   => $body['worksite_state']   ?? null,
        'worksite_country' => $body['worksite_country'] ?? null,
        'remote_policy'    => $body['remote_policy']    ?? null,
        'title'            => $body['title'],
        'end_client_name'  => $body['end_client_name']  ?? null,
        'end_client_company_id' => !empty($body['end_client_company_id']) ? (int) $body['end_client_company_id'] : null,
        'client_approver_name'  => $body['client_approver_name']  ?? null,
        'client_approver_email' => $body['client_approver_email'] ?? null,
        'notes'            => $body['notes']            ?? null,
        'created_by_user_id' => $user['id'] ?? null,
    ];

    // If a company_id is provided, prefer its canonical name and tag it 'client'.
    // If only a free-text end_client_name is provided, upsert into companies for
    // future picks. Either path leaves us with a clean FK + display string.
    require_once __DIR__ . '/../../people/lib/companies.php';
    if (!empty($insert['end_client_company_id'])) {
        $co = companiesGet((int) $insert['end_client_company_id']);
        if ($co) {
            $insert['end_client_name'] = $co['name'];
            companiesAddRole((int) $co['id'], 'client');
            companiesBumpUsage((int) $co['id']);
        }
    } elseif (!empty($insert['end_client_name'])) {
        $cid = companiesUpsertByName(currentTenantId(), (string) $insert['end_client_name'], [
            'created_by_user_id' => $user['id'] ?? null,
        ], ['client']);
        $insert['end_client_company_id'] = $cid;
        companiesBumpUsage($cid);
    }

    $id = scopedInsert('placements', $insert);

    // Bump end-client typeahead
    if (!empty($body['end_client_name'])) {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare(
                'INSERT INTO tenant_end_clients (tenant_id, client_name, use_count, last_used_at)
                 VALUES (:tenant_id, :name, 1, NOW())
                 ON DUPLICATE KEY UPDATE use_count = use_count + 1, last_used_at = NOW()'
            );
            $stmt->execute(['tenant_id' => currentTenantId(), 'name' => $body['end_client_name']]);
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    placementsAudit('placement.created', ['id' => $id, 'engagement_type' => $insert['engagement_type']], $id);
    api_ok(['placement' => placementGet($id)], 201);
}

if ($method === 'PATCH') {
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    RBAC::requirePermission($user, 'placements.manage');
    $body = api_json_body();
    foreach (['id','tenant_id','created_at','created_by_user_id','deleted_at'] as $k) unset($body[$k]);
    if (isset($body['engagement_type']) && !in_array($body['engagement_type'], ALLOWED_ETYPE, true)) {
        api_error('Invalid engagement_type', 422);
    }
    if (isset($body['status']) && !in_array($body['status'], ALLOWED_STATUS, true)) {
        api_error('Invalid status', 422);
    }
    if (!$body) api_error('No fields to update', 422);
    $rows = scopedUpdate('placements', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    if (isset($body['status'])) {
        placementsAudit('placement.status_changed', ['id' => $id, 'status' => $body['status']], $id);
    } else {
        placementsAudit('placement.updated', ['id' => $id, 'fields' => array_keys($body)], $id);
    }
    api_ok(['placement' => placementGet($id)]);
}

api_error('Method not allowed', 405);
