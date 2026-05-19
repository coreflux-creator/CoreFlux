<?php
/**
 * People API — main person resource
 *
 * Routes (resolved by core/api_router.php):
 *   GET    /api/people/people                  → list (filters in query)
 *   GET    /api/people/people?id=N             → get one (non-PII, unless ?include_pii=1 + permission)
 *   POST   /api/people/people                  → create
 *   PATCH  /api/people/people?id=N             → partial update
 *   POST   /api/people/people?action=terminate&id=N → set status/inactive
 *
 * SPEC: /app/modules/people/SPEC.md §5.1, §5.2
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/people.php';
require_once __DIR__ . '/../lib/audit.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$method = api_method();

if ($method === 'GET') {
    $id = (int) api_query('id', 0);
    if ($id > 0) {
        rbac_legacy_require($user, 'people.view');
        $includePII = !empty($_GET['include_pii']);
        if ($includePII) {
            rbac_legacy_require($user, 'people.pii.view');
            peopleLogPIIAccess(
                (int) ($user['id'] ?? 0),
                $id,
                'pii.viewed',
                ['fields' => ['dob', 'ssn_last4', 'home_address']]
            );
            peopleAudit('people.pii.viewed', ['id' => $id], $id);
            $row = peopleGetWithPII($id);
        } else {
            $row = peopleGet($id);
        }
        if (!$row) api_error('Not found', 404);
        api_ok(['person' => $row]);
    }

    rbac_legacy_require($user, 'people.view');
    $filters = [
        'q'                       => $_GET['q']                       ?? null,
        'classification'          => $_GET['classification']          ?? null,
        'status'                  => $_GET['status']                  ?? null,
        'work_auth_expiry_before' => $_GET['work_auth_expiry_before'] ?? null,
        'skill'                   => $_GET['skill']                   ?? null,
        'pipeline_stage'          => $_GET['pipeline_stage']          ?? null,
        'page'                    => $_GET['page']                    ?? 1,
        'per_page'                => $_GET['per_page']                ?? 25,
    ];
    api_ok(peopleList($filters));
}

if ($method === 'POST') {
    $action = $_GET['action'] ?? '';

    if ($action === 'terminate') {
        $id = (int) api_query('id', 0);
        if ($id <= 0) api_error('id required', 400);
        rbac_legacy_require($user, 'people.terminate');
        $body = api_json_body();
        api_require_fields($body, ['reason']);
        $newStatus = in_array(($body['status'] ?? 'inactive'), ['inactive', 'do_not_rehire'], true)
                   ? $body['status'] : 'inactive';
        $rows = scopedUpdate('people', $id, ['status' => $newStatus]);
        if ($rows === 0) api_error('Not found or no change', 404);
        peopleAudit('people.terminated', [
            'id' => $id, 'status' => $newStatus, 'reason' => $body['reason'],
        ], $id);
        api_ok(['ok' => true, 'id' => $id, 'status' => $newStatus]);
    }

    // Default POST = create
    rbac_legacy_require($user, 'people.manage');
    $body = api_json_body();
    api_require_fields($body, ['first_name', 'last_name', 'email_primary', 'classification']);

    $allowedClassifications = ['w2','1099','c2c','temp','perm','candidate','alumni'];
    if (!in_array($body['classification'], $allowedClassifications, true)) {
        api_error('Invalid classification', 422, ['allowed' => $allowedClassifications]);
    }
    if (!filter_var($body['email_primary'], FILTER_VALIDATE_EMAIL)) {
        api_error('Invalid email_primary', 422);
    }

    // Uniqueness check (case-insensitive per SPEC §9)
    $existing = scopedFind(
        'SELECT id FROM people WHERE tenant_id = :tenant_id AND LOWER(email_primary) = LOWER(:email) AND deleted_at IS NULL',
        ['email' => $body['email_primary']]
    );
    if ($existing) api_error('email_primary already exists for this tenant', 409, ['conflict_id' => $existing['id']]);

    $insert = [
        'first_name'           => $body['first_name'],
        'middle_name'          => $body['middle_name']     ?? null,
        'last_name'            => $body['last_name'],
        'preferred_name'       => $body['preferred_name']  ?? null,
        'email_primary'        => $body['email_primary'],
        'email_secondary'      => $body['email_secondary'] ?? null,
        'phone_primary'        => $body['phone_primary']   ?? null,
        'phone_secondary'      => $body['phone_secondary'] ?? null,
        'classification'       => $body['classification'],
        'status'               => $body['status']          ?? 'active',
        'work_auth_status'     => $body['work_auth_status'] ?? 'unknown',
        'work_auth_expiry'     => $body['work_auth_expiry'] ?? null,
        'requires_sponsorship' => !empty($body['requires_sponsorship']) ? 1 : 0,
        'linkedin_url'         => $body['linkedin_url']    ?? null,
        'recruiter_notes'      => $body['recruiter_notes'] ?? null,
        'source'               => $body['source']          ?? null,
        'external_id'          => $body['external_id']     ?? null,
        'created_by_user_id'   => $user['id']              ?? null,
    ];

    // Additive employment/HR fields (migration 006). All optional; whitelisted.
    $extraEmp = ['employment_type','hire_date','termination_date','pay_frequency','gender','marital_status'];
    foreach ($extraEmp as $k) {
        if (array_key_exists($k, $body) && $body[$k] !== '' && $body[$k] !== null) {
            $insert[$k] = $body[$k];
        }
    }

    // PII / address fields are gated by people.pii.manage on create just like PATCH.
    $piiKeys = ['dob', 'ssn_last4', 'home_address_line1', 'home_address_line2',
                'home_city', 'home_state', 'home_postal_code', 'home_country',
                'mailing_address_line1', 'mailing_address_line2',
                'mailing_city', 'mailing_state', 'mailing_postal_code', 'mailing_country'];
    $piiTouched = [];
    foreach ($piiKeys as $k) {
        if (array_key_exists($k, $body) && $body[$k] !== '' && $body[$k] !== null) {
            $piiTouched[] = $k;
            $insert[$k] = $body[$k];
        }
    }
    if ($piiTouched) {
        rbac_legacy_require($user, 'people.pii.manage');
    }
    if (array_key_exists('referred_by_person_id', $body) && $body['referred_by_person_id']) {
        $insert['referred_by_person_id'] = (int) $body['referred_by_person_id'];
    }
    if (array_key_exists('entity_id', $body) && !empty($body['entity_id'])) {
        $insert['entity_id'] = (int) $body['entity_id'];
    }

    $id = scopedInsert('people', $insert);
    if ($piiTouched) {
        peopleLogPIIAccess(
            (int) ($user['id'] ?? 0), $id, 'pii.set',
            ['fields' => $piiTouched, 'on' => 'create']
        );
    }
    peopleAudit('people.created', ['id' => $id, 'classification' => $insert['classification']], $id);
    api_ok(['person' => peopleGet($id)], 201);
}

if ($method === 'PATCH') {
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    rbac_legacy_require($user, 'people.manage');
    $body = api_json_body();

    // Strip fields not allowed via PATCH on this endpoint.
    $disallowed = ['id', 'tenant_id', 'created_at', 'created_by_user_id', 'deleted_at'];
    foreach ($disallowed as $k) unset($body[$k]);

    // PII fields require people.pii.manage
    $piiKeys = ['dob', 'ssn_last4', 'home_address_line1', 'home_address_line2',
                'home_city', 'home_state', 'home_postal_code', 'home_country',
                'mailing_address_line1', 'mailing_address_line2',
                'mailing_city', 'mailing_state', 'mailing_postal_code', 'mailing_country'];
    $touchingPII = false;
    foreach ($piiKeys as $k) if (array_key_exists($k, $body)) $touchingPII = true;
    if ($touchingPII) {
        rbac_legacy_require($user, 'people.pii.manage');
        peopleLogPIIAccess(
            (int) ($user['id'] ?? 0), $id, 'pii.viewed',
            ['fields' => array_values(array_intersect(array_keys($body), $piiKeys)), 'on' => 'update']
        );
    }

    if (!empty($body['classification'])) {
        $allowed = ['w2','1099','c2c','temp','perm','candidate','alumni'];
        if (!in_array($body['classification'], $allowed, true)) {
            api_error('Invalid classification', 422, ['allowed' => $allowed]);
        }
    }
    if (!empty($body['email_primary']) && !filter_var($body['email_primary'], FILTER_VALIDATE_EMAIL)) {
        api_error('Invalid email_primary', 422);
    }

    if (!$body) api_error('No fields to update', 422);
    $rows = scopedUpdate('people', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    peopleAudit('people.updated', ['id' => $id, 'fields' => array_keys($body)], $id);
    api_ok(['person' => peopleGet($id)]);
}

api_error('Method not allowed', 405);
