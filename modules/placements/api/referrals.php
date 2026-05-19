<?php
/**
 * Placements API — referrals (SPEC §3.6)
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/placements.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();

if ($method === 'GET') {
    rbac_legacy_require($user, 'placements.view');
    $pid = (int) api_query('placement_id', 0);
    if ($pid <= 0) api_error('placement_id required', 400);
    api_ok(['referrals' => placementReferrals($pid)]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'placements.referrals.manage');
    $pid = (int) api_query('placement_id', 0);
    if ($pid <= 0) api_error('placement_id required', 400);
    $body = api_json_body();
    api_require_fields($body, ['referrer_type', 'fee_basis', 'start_date']);

    // Vendor referrer? Resolve to canonical company_id (auto-create if needed).
    require_once __DIR__ . '/../../people/lib/companies.php';
    $companyId = !empty($body['referrer_company_id']) ? (int) $body['referrer_company_id'] : null;
    $vendorName = $body['referrer_vendor_name'] ?? null;
    if ($body['referrer_type'] === 'vendor') {
        if ($companyId) {
            $co = companiesGet($companyId);
            if (!$co) api_error('referrer_company_id not found in this tenant', 422);
            $vendorName = $co['name'];
            companiesAddRole($companyId, 'referrer');
            companiesBumpUsage($companyId);
        } elseif ($vendorName) {
            $companyId = companiesUpsertByName(currentTenantId(), (string) $vendorName, [
                'created_by_user_id' => $user['id'] ?? null,
            ], ['referrer']);
            companiesBumpUsage($companyId);
        }
    }

    $id = scopedInsert('placement_referrals', [
        'placement_id'           => $pid,
        'referrer_type'          => $body['referrer_type'],
        'referrer_vendor_name'   => $vendorName,
        'referrer_company_id'    => $companyId,
        'referrer_person_id'     => $body['referrer_person_id']   ?? null,
        'referrer_user_id'       => $body['referrer_user_id']     ?? null,
        'fee_pct'                => $body['fee_pct']  ?? null,
        'fee_flat'               => $body['fee_flat'] ?? null,
        'fee_basis'              => $body['fee_basis'],
        'duration_months'        => $body['duration_months'] ?? null,
        'start_date'             => $body['start_date'],
        'end_date'               => $body['end_date'] ?? null,
        'notes'                  => $body['notes']    ?? null,
    ]);
    placementsAudit('placement.referral.added', ['placement_id' => $pid, 'referral_id' => $id, 'company_id' => $companyId], $pid);
    api_ok(['id' => $id, 'company_id' => $companyId], 201);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'placements.referrals.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    unset($body['id'], $body['tenant_id'], $body['placement_id']);
    if (!$body) api_error('No fields to update', 422);
    $rows = scopedUpdate('placement_referrals', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    placementsAudit('placement.referral.updated', ['referral_id' => $id], $id);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE') {
    rbac_legacy_require($user, 'placements.referrals.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $rows = scopedDelete('placement_referrals', $id);
    if ($rows === 0) api_error('Not found', 404);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
