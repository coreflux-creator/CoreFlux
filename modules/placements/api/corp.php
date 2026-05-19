<?php
/**
 * Placements API — C2C corp details (encrypted EIN). SPEC §3.7.
 *
 *   GET /api/placements/corp?placement_id=N    → returns metadata + ein_last4
 *   PUT /api/placements/corp?placement_id=N    → upsert
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/encryption.php';
require_once __DIR__ . '/../lib/placements.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$pid = (int) api_query('placement_id', 0);
if ($pid <= 0) api_error('placement_id required', 400);

if ($method === 'GET') {
    rbac_legacy_require($user, 'placements.corp.view');
    $row = scopedFind(
        'SELECT placement_id, tenant_id, corp_legal_name, corp_ein_last4,
                corp_address_line1, corp_address_line2, corp_city, corp_state, corp_postal_code, corp_country,
                corp_contact_name, corp_contact_email, corp_contact_phone,
                msa_storage_object_id, coi_storage_object_id, coi_expiry, w9_storage_object_id, updated_at
         FROM placement_corp_details
         WHERE tenant_id = :tenant_id AND placement_id = :pid',
        ['pid' => $pid]
    );
    placementsAudit('placement.corp.viewed', ['placement_id' => $pid], $pid);
    api_ok(['corp' => $row]);
}

if ($method === 'PUT' || $method === 'POST') {
    rbac_legacy_require($user, 'placements.corp.manage');
    $body = api_json_body();
    api_require_fields($body, ['corp_legal_name']);

    $hasEin = !empty($body['corp_ein']);
    $pdo = getDB();
    if (!$pdo) api_error('No database connection', 500);

    $stmt = $pdo->prepare(
        'INSERT INTO placement_corp_details
            (placement_id, tenant_id, corp_legal_name,
             corp_ein_ct, corp_ein_last4,
             corp_address_line1, corp_address_line2, corp_city, corp_state, corp_postal_code, corp_country,
             corp_contact_name, corp_contact_email, corp_contact_phone,
             msa_storage_object_id, coi_storage_object_id, coi_expiry, w9_storage_object_id)
         VALUES (:pid, :tenant_id, :legal,
                 :ein_ct, :ein4,
                 :a1, :a2, :city, :state, :postal, :country,
                 :cname, :cemail, :cphone,
                 :msa, :coi, :coi_exp, :w9)
         ON DUPLICATE KEY UPDATE
            corp_legal_name = VALUES(corp_legal_name),
            ' . ($hasEin ? 'corp_ein_ct = VALUES(corp_ein_ct), corp_ein_last4 = VALUES(corp_ein_last4),' : '') . '
            corp_address_line1 = VALUES(corp_address_line1),
            corp_address_line2 = VALUES(corp_address_line2),
            corp_city = VALUES(corp_city),
            corp_state = VALUES(corp_state),
            corp_postal_code = VALUES(corp_postal_code),
            corp_country = VALUES(corp_country),
            corp_contact_name = VALUES(corp_contact_name),
            corp_contact_email = VALUES(corp_contact_email),
            corp_contact_phone = VALUES(corp_contact_phone),
            msa_storage_object_id = VALUES(msa_storage_object_id),
            coi_storage_object_id = VALUES(coi_storage_object_id),
            coi_expiry = VALUES(coi_expiry),
            w9_storage_object_id = VALUES(w9_storage_object_id),
            updated_at = NOW()'
    );
    $stmt->execute([
        'pid'       => $pid,
        'tenant_id' => currentTenantId(),
        'legal'     => $body['corp_legal_name'],
        'ein_ct'    => $hasEin ? encryptField($body['corp_ein']) : null,
        'ein4'      => $hasEin ? last4($body['corp_ein']) : null,
        'a1'        => $body['corp_address_line1'] ?? null,
        'a2'        => $body['corp_address_line2'] ?? null,
        'city'      => $body['corp_city']          ?? null,
        'state'     => $body['corp_state']         ?? null,
        'postal'    => $body['corp_postal_code']   ?? null,
        'country'   => $body['corp_country']       ?? null,
        'cname'     => $body['corp_contact_name']  ?? null,
        'cemail'    => $body['corp_contact_email'] ?? null,
        'cphone'    => $body['corp_contact_phone'] ?? null,
        'msa'       => $body['msa_storage_object_id'] ?? null,
        'coi'       => $body['coi_storage_object_id'] ?? null,
        'coi_exp'   => $body['coi_expiry']            ?? null,
        'w9'        => $body['w9_storage_object_id']  ?? null,
    ]);
    placementsAudit('placement.corp.updated', ['placement_id' => $pid, 'fields' => array_keys($body)], $pid);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
