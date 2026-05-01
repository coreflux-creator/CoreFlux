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

if ($method === 'GET' && !empty($_GET['id'])) {
    RBAC::requirePermission($user, 'ap.view');
    $id = (int) $_GET['id'];
    $row = scopedFind('SELECT * FROM ap_vendors_index WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
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
    $where = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['q'])) {
        $where[] = 'vendor_name LIKE :q';
        $params['q'] = '%' . str_replace(['%','_'], ['\\%','\\_'], $_GET['q']) . '%';
    }
    if (!empty($_GET['type'])) { $where[] = 'vendor_type = :vt'; $params['vt'] = $_GET['type']; }
    $rows = scopedQuery(
        'SELECT id, vendor_name, vendor_type, tax_id_last4, requires_1099, default_terms, last_bill_at
         FROM ap_vendors_index WHERE ' . implode(' AND ', $where) . ' ORDER BY vendor_name ASC LIMIT 200',
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

    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO ap_vendors_index
           (tenant_id, vendor_name, vendor_type, default_terms, tax_id_last4, tax_id_full_ct, kms_key_version, requires_1099)
         VALUES
           (:t, :v, :vt, :terms, :last4, :ct, :kms, :r)
         ON DUPLICATE KEY UPDATE
           vendor_type    = VALUES(vendor_type),
           default_terms  = VALUES(default_terms),
           tax_id_last4   = COALESCE(VALUES(tax_id_last4), tax_id_last4),
           tax_id_full_ct = COALESCE(VALUES(tax_id_full_ct), tax_id_full_ct),
           kms_key_version= COALESCE(VALUES(kms_key_version), kms_key_version),
           requires_1099  = VALUES(requires_1099)'
    )->execute([
        't'     => $tid,
        'v'     => (string) $body['vendor_name'],
        'vt'    => (string) ($body['vendor_type'] ?? 'other'),
        'terms' => (string) ($body['default_terms'] ?? 'NET30'),
        'last4' => $last4,
        'ct'    => $ct,
        'kms'   => $kms,
        'r'     => !empty($body['requires_1099']) ? 1 : 0,
    ]);
    $id = (int) $pdo->lastInsertId();
    if ($id === 0) {
        $findStmt = $pdo->prepare('SELECT id FROM ap_vendors_index WHERE tenant_id = :t AND vendor_name = :v');
        $findStmt->execute(['t' => $tid, 'v' => (string) $body['vendor_name']]);
        $id = (int) $findStmt->fetchColumn();
    }
    apAudit($taxIdFull ? 'ap.vendor.tax_id_updated' : 'ap.vendor.created', [
        'vendor_id' => $id, 'vendor_name' => $body['vendor_name'],
    ], $id);
    api_ok(['id' => $id], 201);
}

api_error('Method not allowed', 405);
