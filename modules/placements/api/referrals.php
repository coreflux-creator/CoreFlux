<?php
/**
 * Placements API — referrals (SPEC §3.6)
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/placements.php';
require_once __DIR__ . '/../lib/economics.php';

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
    if (!in_array($body['referrer_type'], ['vendor','person','user'], true)) api_error('Invalid referrer_type', 422);

    // Vendor referrer? Resolve to canonical company_id (auto-create if needed).
    require_once __DIR__ . '/../../people/lib/companies.php';
    $companyId = !empty($body['referrer_company_id']) ? (int) $body['referrer_company_id'] : null;
    $vendorName = $body['referrer_vendor_name'] ?? null;
    if ($body['referrer_type'] === 'vendor') {
        if (!$companyId && trim((string) $vendorName) === '') api_error('Choose or create a vendor referrer', 422);
        if ($companyId) {
            $co = companiesGet($companyId);
            if (!$co) api_error('referrer_company_id not found in this tenant', 422);
            $vendorName = $co['name'];
            companiesAddRole($companyId, 'referrer');
            companiesAddRole($companyId, 'vendor');
            companiesBumpUsage($companyId);
        } elseif ($vendorName) {
            $companyId = companiesUpsertByName(currentTenantId(), (string) $vendorName, [
                'created_by_user_id' => $user['id'] ?? null,
            ], ['referrer', 'vendor']);
            companiesBumpUsage($companyId);
        }
    } elseif ($body['referrer_type'] === 'person') {
        $personId = (int) ($body['referrer_person_id'] ?? 0);
        if ($personId <= 0 || !scopedFind(
            'SELECT id FROM people WHERE tenant_id = :tenant_id AND id = :id',
            ['id' => $personId]
        )) api_error('Choose a valid person referrer', 422);
        $body['referrer_person_id'] = $personId;
    } else {
        $userId = (int) ($body['referrer_user_id'] ?? 0);
        if ($userId <= 0) api_error('Choose a valid user referrer', 422);
        $memberSt = getDB()->prepare(
            'SELECT 1 FROM ' . membershipReadSourceSql() . ' m
              WHERE m.tenant_id = :t AND m.user_id = :u LIMIT 1'
        );
        $memberSt->execute(['t' => (int) $ctx['tenant_id'], 'u' => $userId]);
        if (!$memberSt->fetchColumn()) api_error('User referrer is not active in this tenant', 422);
        $body['referrer_user_id'] = $userId;
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
        'payment_terms_override' => !empty($body['payment_terms_override'])
            ? placementEconomicsNormaliseTerms((string) $body['payment_terms_override']) : null,
        'pwp_enabled'            => !empty($body['pwp_enabled']) ? 1 : 0,
        'duration_months'        => $body['duration_months'] ?? null,
        'start_date'             => $body['start_date'],
        'end_date'               => $body['end_date'] ?? null,
        'notes'                  => $body['notes']    ?? null,
    ]);
    placementEconomicsReconcile(currentTenantId(), $pid);
    placementsAudit('placement.referral.added', ['placement_id' => $pid, 'referral_id' => $id, 'company_id' => $companyId], $pid);
    api_ok(['id' => $id, 'company_id' => $companyId], 201);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'placements.referrals.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    unset($body['id'], $body['tenant_id'], $body['placement_id']);
    if (array_key_exists('payment_terms_override', $body) && $body['payment_terms_override'] !== null) {
        $body['payment_terms_override'] = placementEconomicsNormaliseTerms((string) $body['payment_terms_override']);
    }
    if (array_key_exists('pwp_enabled', $body)) $body['pwp_enabled'] = !empty($body['pwp_enabled']) ? 1 : 0;
    if (!$body) api_error('No fields to update', 422);
    $source = scopedFind('SELECT placement_id FROM placement_referrals WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    $rows = scopedUpdate('placement_referrals', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    placementsAudit('placement.referral.updated', ['referral_id' => $id], $id);
    if ($source) placementEconomicsReconcile(currentTenantId(), (int) $source['placement_id']);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE') {
    rbac_legacy_require($user, 'placements.referrals.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $source = scopedFind('SELECT placement_id FROM placement_referrals WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    $rows = scopedDelete('placement_referrals', $id);
    if ($rows === 0) api_error('Not found', 404);
    if ($source) placementEconomicsReconcile(currentTenantId(), (int) $source['placement_id']);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
