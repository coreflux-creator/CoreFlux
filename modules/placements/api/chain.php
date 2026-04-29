<?php
/**
 * Placements API — vendor chain
 *
 *   GET    /api/placements/chain?placement_id=N
 *   POST   /api/placements/chain?placement_id=N
 *   PATCH  /api/placements/chain?id=N
 *   DELETE /api/placements/chain?id=N
 *
 * SPEC §3.2.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/placements.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();

if ($method === 'GET') {
    RBAC::requirePermission($user, 'placements.view');
    $pid = (int) api_query('placement_id', 0);
    if ($pid <= 0) api_error('placement_id required', 400);
    api_ok(['chain' => placementChain($pid)]);
}

if ($method === 'POST') {
    RBAC::requirePermission($user, 'placements.manage');
    $pid = (int) api_query('placement_id', 0);
    if ($pid <= 0) api_error('placement_id required', 400);
    $body = api_json_body();
    api_require_fields($body, ['position', 'party_name', 'party_role']);
    if (!in_array($body['party_role'], ['end_client','msp','prime_vendor','sub_vendor','direct'], true)) {
        api_error('Invalid party_role', 422);
    }
    $id = scopedInsert('placement_client_chain', [
        'placement_id'    => $pid,
        'position'        => (int) $body['position'],
        'party_name'      => $body['party_name'],
        'party_role'      => $body['party_role'],
        'vendor_portal_id'=> $body['vendor_portal_id'] ?? null,
        'portal_fee_pct'  => $body['portal_fee_pct']   ?? null,
        'portal_fee_flat' => $body['portal_fee_flat']  ?? null,
        'contract_storage_object_id' => $body['contract_storage_object_id'] ?? null,
    ]);
    placementsAudit('placement.chain.updated', ['placement_id' => $pid, 'op' => 'add', 'chain_id' => $id], $pid);
    api_ok(['id' => $id], 201);
}

if ($method === 'PATCH') {
    RBAC::requirePermission($user, 'placements.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    unset($body['id'], $body['tenant_id'], $body['placement_id']);
    if (!$body) api_error('No fields to update', 422);
    $rows = scopedUpdate('placement_client_chain', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE') {
    RBAC::requirePermission($user, 'placements.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $rows = scopedDelete('placement_client_chain', $id);
    if ($rows === 0) api_error('Not found', 404);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
