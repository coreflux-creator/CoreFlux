<?php
/**
 * AP API — vendors index (typeahead + metadata).
 *
 *   GET    /api/ap/vendors                → list with filters
 *   GET    /api/ap/vendors?q=acme          → typeahead search
 *   GET    /api/ap/vendors?id=N&reveal_pii=1 → detail (tax_id full requires ap.vendor.view_pii)
 *   POST   /api/ap/vendors                → create/upsert vendor
 *   PATCH  /api/ap/vendors?id=N            → edit (tax_id updates audited)
 *
 * SPEC: /app/modules/ap/SPEC.md §3.1, §8 (audit).
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/ap.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'upload_url') {
    // Presigned S3 POST for staging a W-9 / W-8BEN PDF before extraction.
    RBAC::requirePermission($user, 'ap.bill.create');
    require_once __DIR__ . '/../../../core/StorageService.php';
    $fileName = (string) ($_GET['file_name'] ?? 'w9.pdf');
    $svc = Core\StorageService::getInstance();
    $key  = $svc->build_key('ap', $tid, 'vendor_w9', 0, $fileName);
    $post = $svc->get_presigned_post($key);
    api_ok(['storage_key' => $key, 'upload' => $post]);
}

if ($method === 'POST' && $action === 'extract_w9') {
    // AI-assist for vendor onboarding — read a W-9 / W-8BEN form and return
    // a suggested vendor draft. User reviews + saves via normal POST.
    RBAC::requirePermission($user, 'ap.bill.create');
    require_once __DIR__ . '/../../../core/StorageService.php';
    require_once __DIR__ . '/../../../core/ai_service.php';
    $body = api_json_body();
    api_require_fields($body, ['storage_key']);
    $signedUrl = Core\StorageService::getInstance()->get_signed_url((string) $body['storage_key']);

    $schemaHint = <<<JSON
{
  "vendor_name":          string|null,
  "business_name":        string|null,
  "tax_classification":   "individual"|"sole_proprietor"|"c_corp"|"s_corp"|"partnership"|"trust"|"llc"|"other"|null,
  "tax_id_last4":         string|null,
  "address_line1":        string|null,
  "address_line2":        string|null,
  "city":                 string|null,
  "state":                string|null,
  "postal_code":          string|null,
  "country":              string|null,
  "vendor_type":          "1099_individual"|"c2c_corp"|"w9_business"|"other",
  "requires_1099":        boolean|null
}
JSON;
    try {
        $res = aiExtract([
            'feature_key' => 'ap.vendor.from_w9',
            'instruction' => 'Extract a US W-9 (or W-8BEN equivalent) into the JSON shape below. Map tax_classification to vendor_type: individual/sole_proprietor → 1099_individual; c_corp/s_corp → c2c_corp; partnership/trust/other → w9_business. Set requires_1099 = false only if tax_classification is c_corp or s_corp.',
            'schema_hint' => $schemaHint,
            'images'      => [['url' => $signedUrl, 'mime' => 'application/pdf']],
        ]);
    } catch (\Throwable $e) { api_error('Extraction failed: ' . $e->getMessage(), 502); }
    apAudit('ap.vendor.extracted_from_w9', ['model' => $res['model'], 'interaction_id' => $res['interaction_id']]);
    api_ok(['draft' => $res['data'], 'model' => $res['model'], 'interaction_id' => $res['interaction_id'], 'review_required' => true]);
}

if ($method === 'GET' && !empty($_GET['id'])) {
    RBAC::requirePermission($user, 'ap.view');
    $id = (int) $_GET['id'];
    $row = scopedFind(
        'SELECT v.*, c.name AS company_name, c.legal_name AS company_legal_name
         FROM ap_vendors_index v
         LEFT JOIN companies c ON c.id = v.company_id AND c.tenant_id = v.tenant_id AND c.deleted_at IS NULL
         WHERE v.tenant_id = :tenant_id AND v.id = :id',
        ['id' => $id]
    );
    if (!$row) api_error('Not found', 404);
    if (!empty($_GET['reveal_pii']) && !empty($row['tax_id_full_ct'])) {
        RBAC::requirePermission($user, 'ap.vendor.view_pii');
        $row['tax_id_full'] = decryptField($row['tax_id_full_ct']);
        apAudit('ap.vendor.tax_id_viewed', ['vendor_id' => $id, 'vendor_name' => $row['vendor_name']], $id);
    }
    unset($row['tax_id_full_ct']);
    api_ok(['vendor' => $row]);
}

if ($method === 'GET') {
    RBAC::requirePermission($user, 'ap.view');
    $where = ['v.tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['q'])) {
        $where[] = '(v.vendor_name LIKE :q OR c.name LIKE :q)';
        $params['q'] = '%' . str_replace(['%','_'], ['\\%','\\_'], $_GET['q']) . '%';
    }
    if (!empty($_GET['type'])) { $where[] = 'v.vendor_type = :vt'; $params['vt'] = $_GET['type']; }
    if (!empty($_GET['category'])) { $where[] = 'v.vendor_category = :cat'; $params['cat'] = $_GET['category']; }
    if (!empty($_GET['company_id'])) { $where[] = 'v.company_id = :cid'; $params['cid'] = (int) $_GET['company_id']; }
    $rows = scopedQuery(
        'SELECT v.id, v.vendor_name, v.company_id, c.name AS company_name,
                v.vendor_type, v.vendor_category, v.payment_method, v.remit_to_email,
                v.tax_id_last4, v.payment_account_last4, v.requires_1099, v.default_terms, v.last_bill_at
         FROM ap_vendors_index v
         LEFT JOIN companies c ON c.id = v.company_id AND c.tenant_id = v.tenant_id AND c.deleted_at IS NULL
         WHERE ' . implode(' AND ', $where) . ' ORDER BY v.vendor_name ASC LIMIT 200',
        $params
    );
    api_ok(['rows' => $rows]);
}

if ($method === 'POST') {
    RBAC::requirePermission($user, 'ap.bill.create');
    $body = api_json_body();
    api_require_fields($body, ['vendor_name']);
    $taxIdFull = isset($body['tax_id_full']) ? (string) $body['tax_id_full'] : null;
    $ct   = $taxIdFull ? encryptField($taxIdFull) : null;
    $last4 = $taxIdFull ? last4($taxIdFull) : null;
    $kms  = $taxIdFull ? 'v1' : null;
    $vendorType = (string) ($body['vendor_type'] ?? 'other');

    // Vendor category — explicit user choice. hourly_labor MUST be backed
    // by a fully-onboarded person/placement; service_provider only needs
    // basics + payment details.
    $allowedCats = ['hourly_labor','service_provider'];
    $vendorCat = (string) ($body['vendor_category']
        ?? (in_array($vendorType, ['1099_individual','c2c_corp'], true) ? 'hourly_labor' : 'service_provider'));
    if (!in_array($vendorCat, $allowedCats, true)) api_error('Invalid vendor_category', 422, ['allowed' => $allowedCats]);

    // Optional payment details (encrypt account number if supplied in full).
    $paymentMethod = $body['payment_method'] ?? null;
    if ($paymentMethod !== null && !in_array($paymentMethod, ['ach','wire','check','card','cash','plaid','other'], true)) {
        api_error('Invalid payment_method', 422);
    }
    $payAcctFull = isset($body['payment_account_full']) ? (string) $body['payment_account_full'] : null;
    $payAcctCt    = $payAcctFull ? encryptField($payAcctFull)        : null;
    $payAcctLast4 = $payAcctFull ? last4($payAcctFull)               : ($body['payment_account_last4'] ?? null);
    $payKms       = $payAcctFull ? 'v1'                              : null;

    // Resolve or auto-create the unified companies.id for non-individual vendors.
    // 1099 individuals stay as people-side records — no company row is created.
    $companyId = !empty($body['company_id']) ? (int) $body['company_id'] : null;
    if (!$companyId && in_array($vendorType, ['c2c_corp','w9_business','utility','other'], true)) {
        require_once __DIR__ . '/../../people/lib/companies.php';
        $companyId = companiesUpsertByName($tid, (string) $body['vendor_name'], [
            'created_by_user_id' => $user['id'] ?? null,
        ], ['vendor']);
        companiesBumpUsage($companyId);
    }

    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO ap_vendors_index
           (tenant_id, vendor_name, company_id, vendor_type, vendor_category,
            payment_method, remit_to_email, remit_to_phone,
            payment_account_last4, payment_account_ct, kms_key_version_payment,
            default_terms, tax_id_last4, tax_id_full_ct, kms_key_version, requires_1099)
         VALUES
           (:t, :v, :cid, :vt, :cat,
            :pm, :rmail, :rphone,
            :pal4, :pact, :pkms,
            :terms, :last4, :ct, :kms, :r)
         ON DUPLICATE KEY UPDATE
           company_id              = COALESCE(VALUES(company_id), company_id),
           vendor_type             = VALUES(vendor_type),
           vendor_category         = VALUES(vendor_category),
           payment_method          = COALESCE(VALUES(payment_method), payment_method),
           remit_to_email          = COALESCE(VALUES(remit_to_email), remit_to_email),
           remit_to_phone          = COALESCE(VALUES(remit_to_phone), remit_to_phone),
           payment_account_last4   = COALESCE(VALUES(payment_account_last4), payment_account_last4),
           payment_account_ct      = COALESCE(VALUES(payment_account_ct), payment_account_ct),
           kms_key_version_payment = COALESCE(VALUES(kms_key_version_payment), kms_key_version_payment),
           default_terms           = VALUES(default_terms),
           tax_id_last4            = COALESCE(VALUES(tax_id_last4), tax_id_last4),
           tax_id_full_ct          = COALESCE(VALUES(tax_id_full_ct), tax_id_full_ct),
           kms_key_version         = COALESCE(VALUES(kms_key_version), kms_key_version),
           requires_1099           = VALUES(requires_1099)'
    )->execute([
        't'      => $tid,
        'v'      => (string) $body['vendor_name'],
        'cid'    => $companyId,
        'vt'     => $vendorType,
        'cat'    => $vendorCat,
        'pm'     => $paymentMethod,
        'rmail'  => $body['remit_to_email'] ?? null,
        'rphone' => $body['remit_to_phone'] ?? null,
        'pal4'   => $payAcctLast4,
        'pact'   => $payAcctCt,
        'pkms'   => $payKms,
        'terms'  => (string) ($body['default_terms'] ?? 'NET30'),
        'last4'  => $last4,
        'ct'     => $ct,
        'kms'    => $kms,
        'r'      => !empty($body['requires_1099']) ? 1 : 0,
    ]);
    $id = (int) $pdo->lastInsertId();
    if ($id === 0) {
        $findStmt = $pdo->prepare('SELECT id FROM ap_vendors_index WHERE tenant_id = :t AND vendor_name = :v');
        $findStmt->execute(['t' => $tid, 'v' => (string) $body['vendor_name']]);
        $id = (int) $findStmt->fetchColumn();
    }
    apAudit($taxIdFull ? 'ap.vendor.tax_id_updated' : 'ap.vendor.created', [
        'vendor_id' => $id, 'vendor_name' => $body['vendor_name'], 'company_id' => $companyId,
    ], $id);
    api_ok(['id' => $id, 'company_id' => $companyId], 201);
}

api_error('Method not allowed', 405);
