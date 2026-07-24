<?php
/**
 * Placements API — commissions
 * SPEC §3.5, §6.2.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/placements.php';
require_once __DIR__ . '/../lib/economics.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();

if ($method === 'GET') {
    rbac_legacy_require($user, 'placements.commissions.view');
    $pid = (int) api_query('placement_id', 0);
    if ($pid <= 0) api_error('placement_id required', 400);
    api_ok(['commissions' => placementCommissions($pid)]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'placements.commissions.manage');
    $pid = (int) api_query('placement_id', 0);
    if ($pid <= 0) api_error('placement_id required', 400);
    $body = api_json_body();
    api_require_fields($body, ['role', 'effective_from']);
    if (!in_array($body['role'], ['account_manager','lead','recruiter','team','other'], true)) {
        api_error('Invalid role', 422);
    }
    $recipientUserId = (int) ($body['user_id'] ?? 0);
    if ($recipientUserId <= 0) api_error('Commission recipient is required', 422);
    $memberSt = getDB()->prepare(
        'SELECT 1 FROM ' . membershipReadSourceSql() . ' m
          WHERE m.tenant_id = :t AND m.user_id = :u LIMIT 1'
    );
    $memberSt->execute(['t' => (int) $ctx['tenant_id'], 'u' => $recipientUserId]);
    if (!$memberSt->fetchColumn()) api_error('Commission recipient is not an active user in this tenant', 422);
    $id = scopedInsert('placement_commissions', [
        'placement_id'   => $pid,
        'plan_id'        => $body['plan_id']     ?? null,
        'role'           => $body['role'],
        'user_id'        => $recipientUserId,
        'split_pct'      => $body['split_pct']   ?? null,
        'basis'          => $body['basis']       ?? 'net_margin',
        'flat_amount'    => $body['flat_amount'] ?? null,
        'effective_from' => $body['effective_from'],
        'effective_to'   => $body['effective_to']?? null,
        'notes'          => $body['notes']       ?? null,
    ]);
    placementsAudit('placement.commission.added', ['placement_id' => $pid, 'commission_id' => $id, 'role' => $body['role']], $pid);
    placementEconomicsReconcile((int) $ctx['tenant_id'], $pid);
    api_ok(['id' => $id], 201);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'placements.commissions.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $existing = scopedFind(
        'SELECT placement_id FROM placement_commissions WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    if (!$existing) api_error('Not found', 404);
    $body = api_json_body();
    unset($body['id'], $body['tenant_id'], $body['placement_id']);
    if (array_key_exists('user_id', $body)) {
        $recipientUserId = (int) $body['user_id'];
        if ($recipientUserId <= 0) api_error('Commission recipient is required', 422);
        $memberSt = getDB()->prepare(
            'SELECT 1 FROM ' . membershipReadSourceSql() . ' m
              WHERE m.tenant_id = :t AND m.user_id = :u LIMIT 1'
        );
        $memberSt->execute(['t' => (int) $ctx['tenant_id'], 'u' => $recipientUserId]);
        if (!$memberSt->fetchColumn()) api_error('Commission recipient is not an active user in this tenant', 422);
        $body['user_id'] = $recipientUserId;
    }
    if (!$body) api_error('No fields to update', 422);
    $rows = scopedUpdate('placement_commissions', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    $pid = (int) $existing['placement_id'];
    placementsAudit('placement.commission.updated', ['commission_id' => $id, 'fields' => array_keys($body)], $pid);
    placementEconomicsReconcile((int) $ctx['tenant_id'], $pid);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE') {
    rbac_legacy_require($user, 'placements.commissions.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $existing = scopedFind(
        'SELECT placement_id FROM placement_commissions WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    if (!$existing) api_error('Not found', 404);
    $rows = scopedDelete('placement_commissions', $id);
    if ($rows === 0) api_error('Not found', 404);
    $pid = (int) $existing['placement_id'];
    placementsAudit('placement.commission.removed', ['commission_id' => $id], $pid);
    placementEconomicsReconcile((int) $ctx['tenant_id'], $pid);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
