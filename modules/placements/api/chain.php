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
require_once __DIR__ . '/../lib/economics.php';
require_once __DIR__ . '/../lib/source_rule_edit.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'contract_upload_url') {
    rbac_legacy_require($user, 'placements.manage');
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
    rbac_legacy_require($user, 'placements.manage');
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
    rbac_legacy_require($user, 'placements.portal_credentials.view');
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
    rbac_legacy_require($user, 'placements.view');
    $pid = (int) api_query('placement_id', 0);
    if ($pid <= 0) api_error('placement_id required', 400);
    api_ok(['chain' => placementChain($pid)]);
}

if ($method === 'POST' && $action === 'set_portal') {
    rbac_legacy_require($user, 'placements.manage');
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
    rbac_legacy_require($user, 'placements.manage');
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

if (in_array($method,['POST','PATCH','DELETE'],true)) {
    rbac_legacy_require($user,'placements.manage');
    $tenant=(int)placementsGraphTenantId((int)$ctx['tenant_id']);
    $id=(int)api_query('id',0); $pid=(int)api_query('placement_id',0);
    try {
        if($method!=='POST') {
            if($id<=0) api_error('id required',400);
            $q=getDB()->prepare('SELECT placement_id FROM placement_client_chain WHERE tenant_id=? AND id=?');
            $q->execute([$tenant,$id]);$pid=(int)$q->fetchColumn();
            if($pid<=0) api_error('Rule not found',404);
        }
        if($pid<=0) api_error('placement_id required',400);
        $result=placementSourceRuleEdit($tenant,$pid,'chain',match($method){'POST'=>'create','PATCH'=>'update',default=>'delete'},
            $id,$method==='DELETE'?[]:api_json_body(),$user);
        api_ok($result,$method==='POST'?201:200);
    } catch(PDOException $e) {
        error_log('[placement chain edit] '.$e->getCode());
        api_error('The rule could not be saved. Refresh the contract and try again.',503);
    } catch(InvalidArgumentException|RuntimeException $e) {
        api_error($e->getMessage(),in_array($e->getCode(),[403,404,409,422,503],true)?$e->getCode():422);
    }
}
api_error('Method not allowed',405);
