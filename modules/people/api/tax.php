<?php
/**
 * People API — tax (W-4 setup)
 *
 *   GET /api/people/tax?person_id=N
 *   PUT /api/people/tax?person_id=N
 *
 * SPEC: /app/modules/people/SPEC.md §3.1, §5.3
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
    RBAC::requirePermission($user, 'people.tax.view');
    $row = scopedFind(
        'SELECT person_id, filing_status, dependents, additional_withholding, state, w4_doc_id, updated_at
         FROM people_tax
         WHERE tenant_id = :tenant_id AND person_id = :person_id',
        ['person_id' => $personId]
    );
    peopleLogPIIAccess((int) ($user['id'] ?? 0), $personId, 'tax.viewed', []);
    api_ok(['tax' => $row]);
}

if ($method === 'PUT' || $method === 'POST') {
    RBAC::requirePermission($user, 'people.tax.manage');
    $body = api_json_body();

    $allowedFiling = ['single','mfj','mfs','hoh','qw'];
    if (isset($body['filing_status']) && !in_array($body['filing_status'], $allowedFiling, true)) {
        api_error('Invalid filing_status', 422, ['allowed' => $allowedFiling]);
    }

    $pdo = getDB();
    if (!$pdo) api_error('No database connection', 500);

    $hasSSN = !empty($body['ssn_full']);
    $stmt = $pdo->prepare(
        'INSERT INTO people_tax
            (person_id, tenant_id, filing_status, dependents, additional_withholding, state, ssn_full_ct, w4_doc_id)
         VALUES (:person_id, :tenant_id, :filing, :dep, :addl, :state, :ssn, :w4)
         ON DUPLICATE KEY UPDATE
            filing_status         = VALUES(filing_status),
            dependents            = VALUES(dependents),
            additional_withholding= VALUES(additional_withholding),
            state                 = VALUES(state),
            ' . ($hasSSN ? 'ssn_full_ct = VALUES(ssn_full_ct),' : '') . '
            w4_doc_id             = VALUES(w4_doc_id),
            updated_at            = NOW()'
    );
    $stmt->execute([
        'person_id' => $personId,
        'tenant_id' => currentTenantId(),
        'filing'    => $body['filing_status']         ?? null,
        'dep'       => isset($body['dependents'])     ? (int) $body['dependents'] : null,
        'addl'      => $body['additional_withholding']?? null,
        'state'     => $body['state']                 ?? null,
        'ssn'       => $hasSSN ? encryptField($body['ssn_full']) : null,
        'w4'        => $body['w4_doc_id']             ?? null,
    ]);

    if ($hasSSN) {
        scopedUpdate('people', $personId, ['ssn_last4' => last4($body['ssn_full'])]);
    }

    peopleLogPIIAccess((int) ($user['id'] ?? 0), $personId, 'tax.updated', array_keys($body));
    peopleAudit('people.tax.updated', ['person_id' => $personId, 'fields' => array_keys($body)], $personId);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
