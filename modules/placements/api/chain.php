<?php
/**
 * Placements API — vendor chain
 *
 *   GET    /api/placements/chain?placement_id=N
 *   POST   /api/placements/chain?placement_id=N
 *   PATCH  /api/placements/chain?id=N
 *   DELETE /api/placements/chain?id=N
 *
 *   POST   /api/placements/chain?action=set_portal&id=N    body: {url?, username?, password?, notes?}
 *   POST   /api/placements/chain?action=clear_portal&id=N
 *   GET    /api/placements/chain?action=reveal_portal&id=N → decrypted creds (audited)
 *
 * SPEC §3.2.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/placements.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'contract_upload_url') {
    RBAC::requirePermission($user, 'placements.manage');
    require_once __DIR__ . '/../../../core/StorageService.php';
    $cid = (int) ($_GET['id'] ?? 0);
    if ($cid <= 0) api_error('id required', 400);
    $fileName = (string) ($_GET['file_name'] ?? 'contract.pdf');
    $svc = Core\StorageService::getInstance();
    $key  = $svc->build_key('placements', currentTenantId(), 'chain_contract', $cid, $fileName);
    $post = $svc->get_presigned_post($key);
    api_ok(['storage_key' => $key, 'upload' => $post]);
}

if ($method === 'POST' && $action === 'extract_contract') {
    // AI-assist — read an MSA / SOW / vendor contract PDF and surface key
    // commercial terms for the chain row. Suggestion only; nothing auto-applied.
    RBAC::requirePermission($user, 'placements.manage');
    require_once __DIR__ . '/../../../core/StorageService.php';
    require_once __DIR__ . '/../../../core/ai_service.php';
    $cid = (int) ($_GET['id'] ?? 0);
    if ($cid <= 0) api_error('id required', 400);
    $row = scopedFind('SELECT id, placement_id FROM placement_client_chain WHERE tenant_id = :tenant_id AND id = :id', ['id' => $cid]);
    if (!$row) api_error('Not found', 404);
    $body = api_json_body();
    api_require_fields($body, ['storage_key']);
    $signedUrl = Core\StorageService::getInstance()->get_signed_url((string) $body['storage_key']);

    $schemaHint = <<<JSON
{
  "counterparty_name":         string|null,
  "agreement_type":            "msa"|"sow"|"work_order"|"po"|"nda"|"amendment"|"other"|null,
  "effective_date":            string|null,
  "term_end_date":             string|null,
  "renewal_clause":            string|null,        // 1-line summary
  "rate_caps": {
    "max_bill_rate":           number|null,
    "max_pay_rate":            number|null,
    "currency":                string|null
  },
  "payment_terms":             string|null,        // e.g. NET30
  "termination_notice_days":   number|null,
  "non_compete_summary":       string|null,
  "ip_assignment_summary":     string|null,
  "indemnity_summary":         string|null,
  "key_clauses":               [{ "title": string, "summary": string }],
  "submittal_id_in_doc":       string|null,
  "vms_job_id_in_doc":         string|null,
  "portal_url_in_doc":         string|null,
  "warnings":                  [string]            // anything unusual / risky
}
JSON;
    try {
        $res = aiExtract([
            'feature_key' => 'placements.chain.from_contract',
            'instruction' => 'Read this staffing-industry contract (MSA / SOW / amendment / vendor work order) and surface commercial terms for review. Be conservative — null fields you cannot find verbatim. List anything unusual (e.g. uncapped indemnity, exclusivity, perpetual rate freeze, broad non-compete) under warnings.',
            'schema_hint' => $schemaHint,
            'images'      => [['url' => $signedUrl, 'mime' => 'application/pdf']],
            'max_output_tokens' => 2500,
        ]);
    } catch (\Throwable $e) { api_error('Extraction failed: ' . $e->getMessage(), 502); }
    placementsAudit('placement.chain.contract_extracted', [
        'chain_id' => $cid, 'placement_id' => (int) $row['placement_id'],
        'model' => $res['model'], 'interaction_id' => $res['interaction_id'],
    ], (int) $row['placement_id']);
    api_ok(['draft' => $res['data'], 'model' => $res['model'], 'interaction_id' => $res['interaction_id'], 'review_required' => true]);
}

if ($method === 'GET' && $action === 'reveal_portal') {
    RBAC::requirePermission($user, 'placements.portal_credentials.view');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    // Tenant-scope check (lib bypasses it for the read).
    $row = scopedFind('SELECT id, placement_id FROM placement_client_chain WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    $creds = placementChainRevealPortalCredentials($id);
    placementsAudit('placement.chain.portal.viewed', ['chain_id' => $id, 'placement_id' => (int) $row['placement_id']], (int) $row['placement_id']);
    api_ok(['credentials' => $creds]);
}

if ($method === 'GET') {
    RBAC::requirePermission($user, 'placements.view');
    $pid = (int) api_query('placement_id', 0);
    if ($pid <= 0) api_error('placement_id required', 400);
    api_ok(['chain' => placementChain($pid)]);
}

if ($method === 'POST' && $action === 'set_portal') {
    RBAC::requirePermission($user, 'placements.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $row = scopedFind('SELECT id, placement_id FROM placement_client_chain WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    $body = api_json_body();
    $allowed = ['url','username','password','notes'];
    $clean = [];
    foreach ($allowed as $k) if (array_key_exists($k, $body) && $body[$k] !== '') $clean[$k] = (string) $body[$k];
    if (!$clean) api_error('At least one credential field required', 422);
    placementChainSetPortalCredentials($id, $clean);
    // Audit MUST NOT include plaintext — only the field names that were stored.
    placementsAudit('placement.chain.portal.set', [
        'chain_id'     => $id,
        'placement_id' => (int) $row['placement_id'],
        'fields'       => array_keys($clean),
    ], (int) $row['placement_id']);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'clear_portal') {
    RBAC::requirePermission($user, 'placements.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $row = scopedFind('SELECT id, placement_id FROM placement_client_chain WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    placementChainClearPortalCredentials($id);
    placementsAudit('placement.chain.portal.cleared', [
        'chain_id'     => $id,
        'placement_id' => (int) $row['placement_id'],
    ], (int) $row['placement_id']);
    api_ok(['ok' => true]);
}

if ($method === 'POST') {
    RBAC::requirePermission($user, 'placements.manage');
    $pid = (int) api_query('placement_id', 0);
    if ($pid <= 0) api_error('placement_id required', 400);
    $body = api_json_body();
    api_require_fields($body, ['position', 'party_role']);
    if (!in_array($body['party_role'], ['end_client','msp','prime_vendor','sub_vendor','direct'], true)) {
        api_error('Invalid party_role', 422);
    }
    if (empty($body['party_name']) && empty($body['company_id'])) {
        api_error('Either company_id (preferred) or party_name is required', 422);
    }

    // Resolve the company FK + display name in either direction.
    require_once __DIR__ . '/../../people/lib/companies.php';
    $companyId   = !empty($body['company_id']) ? (int) $body['company_id'] : null;
    $partyName   = $body['party_name'] ?? null;
    $roleForDir  = $body['party_role'] === 'end_client'
                   ? 'client'
                   : ($body['party_role'] === 'direct' ? 'client' : $body['party_role']);

    if ($companyId) {
        $co = companiesGet($companyId);
        if (!$co) api_error('company_id not found in this tenant', 422);
        $partyName = $co['name'];
        companiesAddRole($companyId, $roleForDir);
        companiesBumpUsage($companyId);
    } else {
        $companyId = companiesUpsertByName(currentTenantId(), (string) $partyName, [
            'created_by_user_id' => $user['id'] ?? null,
        ], [$roleForDir]);
        companiesBumpUsage($companyId);
    }

    $id = scopedInsert('placement_client_chain', [
        'placement_id'    => $pid,
        'position'        => (int) $body['position'],
        'party_name'      => $partyName,
        'party_role'      => $body['party_role'],
        'company_id'      => $companyId,
        'vendor_portal_id'=> $body['vendor_portal_id'] ?? null,
        'portal_fee_pct'  => $body['portal_fee_pct']   ?? null,
        'portal_fee_flat' => $body['portal_fee_flat']  ?? null,
        'submittal_id'    => $body['submittal_id']     ?? null,
        'vms_job_id'      => $body['vms_job_id']       ?? null,
        'contract_storage_object_id' => $body['contract_storage_object_id'] ?? null,
    ]);
    placementsAudit('placement.chain.updated', ['placement_id' => $pid, 'op' => 'add', 'chain_id' => $id, 'company_id' => $companyId], $pid);
    api_ok(['id' => $id, 'company_id' => $companyId], 201);
}

if ($method === 'PATCH') {
    RBAC::requirePermission($user, 'placements.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    // Strip identity + sensitive fields. Portal creds go through set_portal/clear_portal.
    foreach (['id','tenant_id','placement_id','portal_credentials_ct','kms_key_version','has_portal_credentials'] as $k) {
        unset($body[$k]);
    }
    if (!$body) api_error('No fields to update', 422);
    $rows = scopedUpdate('placement_client_chain', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    placementsAudit('placement.chain.updated', ['chain_id' => $id, 'op' => 'patch', 'fields' => array_keys($body)], $id);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE') {
    RBAC::requirePermission($user, 'placements.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $rows = scopedDelete('placement_client_chain', $id);
    if ($rows === 0) api_error('Not found', 404);
    placementsAudit('placement.chain.updated', ['chain_id' => $id, 'op' => 'delete'], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
