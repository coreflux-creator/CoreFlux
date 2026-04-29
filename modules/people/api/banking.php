<?php
/**
 * People API — banking (encrypted)
 *
 *   GET /api/people/banking?person_id=N    → returns last4 + masked metadata only
 *   PUT /api/people/banking?person_id=N    → upsert encrypted record
 *
 * Decisions:
 *   - One banking row per person (people_banking.person_id PK).
 *   - Plaintext NEVER returned. UI shows last4 only.
 *   - Every read writes a `banking.viewed` row to people_pii_access_log.
 *   - Every write writes a `banking.updated` row.
 *
 * SPEC: /app/modules/people/SPEC.md §3.1, §5.3, §11.2
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/encryption.php';
require_once __DIR__ . '/../lib/people.php';
require_once __DIR__ . '/../lib/audit.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$personId = (int) api_query('person_id', 0);
if ($personId <= 0) api_error('person_id required', 400);

if ($method === 'GET') {
    RBAC::requirePermission($user, 'people.banking.view');
    $row = scopedFind(
        'SELECT person_id, account_holder_name_last4, routing_number_last4, account_number_last4, account_type, updated_at, updated_by_user_id
         FROM people_banking
         WHERE tenant_id = :tenant_id AND person_id = :person_id',
        ['person_id' => $personId]
    );
    peopleLogPIIAccess((int) ($user['id'] ?? 0), $personId, 'banking.viewed', ['last4_only' => true]);
    peopleAudit('people.banking.viewed', ['person_id' => $personId], $personId);
    api_ok(['banking' => $row]);
}

if ($method === 'PUT' || $method === 'POST') {
    RBAC::requirePermission($user, 'people.banking.manage');
    $body = api_json_body();
    api_require_fields($body, ['account_holder_name', 'routing_number', 'account_number', 'account_type']);
    if (!in_array($body['account_type'], ['checking', 'savings'], true)) {
        api_error('Invalid account_type', 422);
    }
    if (!preg_match('/^\d{9}$/', preg_replace('/\D/', '', $body['routing_number']))) {
        api_error('routing_number must be 9 digits', 422);
    }

    $pdo = getDB();
    if (!$pdo) api_error('No database connection', 500);

    $stmt = $pdo->prepare(
        'INSERT INTO people_banking
            (person_id, tenant_id, account_holder_name_ct, routing_number_ct, account_number_ct,
             account_holder_name_last4, routing_number_last4, account_number_last4,
             account_type, updated_by_user_id)
         VALUES (:person_id, :tenant_id, :ahn_ct, :rn_ct, :an_ct, :ahn4, :rn4, :an4, :acct_type, :updated_by)
         ON DUPLICATE KEY UPDATE
            account_holder_name_ct    = VALUES(account_holder_name_ct),
            routing_number_ct         = VALUES(routing_number_ct),
            account_number_ct         = VALUES(account_number_ct),
            account_holder_name_last4 = VALUES(account_holder_name_last4),
            routing_number_last4      = VALUES(routing_number_last4),
            account_number_last4      = VALUES(account_number_last4),
            account_type              = VALUES(account_type),
            updated_by_user_id        = VALUES(updated_by_user_id),
            updated_at                = NOW()'
    );
    $stmt->execute([
        'person_id' => $personId,
        'tenant_id' => currentTenantId(),
        'ahn_ct'    => encryptField($body['account_holder_name']),
        'rn_ct'     => encryptField($body['routing_number']),
        'an_ct'     => encryptField($body['account_number']),
        'ahn4'      => last4($body['account_holder_name']),
        'rn4'       => last4($body['routing_number']),
        'an4'       => last4($body['account_number']),
        'acct_type' => $body['account_type'],
        'updated_by'=> $user['id'] ?? null,
    ]);

    peopleLogPIIAccess((int) ($user['id'] ?? 0), $personId, 'banking.updated', ['fields' => ['holder', 'routing', 'account', 'type']]);
    peopleAudit('people.banking.updated', ['person_id' => $personId], $personId);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
