<?php
/** Manage CoreOne v1 accounting credentials with the existing CoreFlux login and RBAC. */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/accounting/coreone_v1.php';

$ctx = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
$user = $ctx['user'];
rbac_legacy_require($user, 'accounting.manage_integrations');
$pdo = getDB();

if (api_method() === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT c.id, c.entity_id, e.code AS entity_code, c.label, c.scopes_json, c.token_last4,
                c.expires_at, c.last_used_at, c.revoked_at, c.created_at
           FROM coreone_accounting_credentials c
           JOIN accounting_entities e ON e.tenant_id = c.tenant_id AND e.id = c.entity_id
          WHERE c.tenant_id = :tenant_id ORDER BY c.id DESC LIMIT 100'
    );
    $stmt->execute(['tenant_id' => $tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['scopes'] = json_decode((string) $row['scopes_json'], true) ?: [];
        unset($row['scopes_json']);
    }
    unset($row);
    api_ok(['credentials' => $rows]);
}

if (api_method() === 'POST') {
    $body = api_json_body();
    try {
        $scopes = $body['scopes'] ?? COREONE_V1_DEFAULT_SCOPES;
        if (!is_array($scopes)) api_error('scopes must be a list', 422);
        if (in_array('journals:write', $scopes, true)) rbac_legacy_require($user, 'accounting.je.post');
        if (in_array('reports:read', $scopes, true)) rbac_legacy_require($user, 'accounting.reports.view');
        if (in_array('invoices:draft', $scopes, true)) rbac_legacy_require($user, 'billing.invoice.draft');
        if (in_array('invoices:request_approval', $scopes, true)) rbac_legacy_require($user, 'billing.invoice.draft');
        if (in_array('bills:prepare', $scopes, true)) rbac_legacy_require($user, 'ap.bill.create');
        $result = coreoneV1IssueCredential($tenantId, (int) ($body['entity_id'] ?? 0),
            (string) ($body['label'] ?? ''), (int) ($body['days'] ?? 30),
            isset($user['id']) ? (int) $user['id'] : null, $scopes);
        api_ok($result, 201); // Plain token is visible only in this response.
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
}

if (api_method() === 'DELETE') {
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('Credential ID required', 422);
    $stmt = $pdo->prepare(
        'UPDATE coreone_accounting_credentials SET revoked_at = NOW()
          WHERE tenant_id = :tenant_id AND id = :id AND revoked_at IS NULL'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);
    if ($stmt->rowCount() !== 1) api_error('Credential not active or not found', 404);
    api_ok(['id' => $id, 'revoked' => true]);
}

api_error('Method not allowed', 405);
