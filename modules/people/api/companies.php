<?php
/**
 * People API — companies directory.
 *
 *   GET    /api/people/companies                       → list with filters
 *   GET    /api/people/companies?id=N                  → detail (roles + contacts)
 *   GET    /api/people/companies?q=&role=client        → typeahead
 *   POST   /api/people/companies                       → create (with roles[])
 *   POST   /api/people/companies?action=upsert         → idempotent by name
 *   PATCH  /api/people/companies?id=N                  → update header fields / roles
 *   DELETE /api/people/companies?id=N                  → soft delete (sets deleted_at)
 *
 *   POST   /api/people/companies?action=add-role&id=N    body: {role}
 *   POST   /api/people/companies?action=remove-role&id=N body: {role}
 *   POST   /api/people/companies?action=add-contact&id=N body: {name,title,email,phone,contact_role,is_primary}
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/companies.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && !empty($_GET['id'])) {
    rbac_legacy_require($user, 'people.view');
    $row = companiesGet((int) $_GET['id']);
    if (!$row) api_error('Not found', 404);
    api_ok(['company' => $row]);
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'people.view');
    $res = companiesList([
        'q'        => $_GET['q']        ?? null,
        'role'     => $_GET['role']     ?? null,
        'page'     => $_GET['page']     ?? 1,
        'per_page' => $_GET['per_page'] ?? 50,
    ]);
    api_ok($res + ['available_roles' => COMPANY_ROLES]);
}

if ($method === 'GET' && $action === 'duplicates') {
    rbac_legacy_require($user, 'people.manage');
    api_ok(['groups' => companiesDuplicateCandidates($tid)]);
}

if ($method === 'POST' && $action === 'merge') {
    rbac_legacy_require($user, 'people.manage');
    $survivorId = (int) ($_GET['id'] ?? 0);
    $body = api_json_body();
    $victimId = (int) ($body['victim_id'] ?? 0);
    if ($survivorId <= 0 || $victimId <= 0) api_error('survivor id (query) and victim_id (body) required', 422);
    try {
        $res = companiesMerge($tid, $survivorId, $victimId, $user['id'] ?? null);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 409);
    }
    api_ok($res);
}

if ($method === 'POST' && $action === 'upsert') {
    rbac_legacy_require($user, 'people.manage');
    $body = api_json_body();
    api_require_fields($body, ['name']);
    $roles = (array) ($body['roles'] ?? []);
    $extra = array_intersect_key($body, array_flip([
        'legal_name','website','phone','primary_contact_name','primary_contact_email','primary_contact_phone',
        'address_line1','address_line2','city','state','postal_code','country','notes',
        'account_manager_user_id','default_terms','currency','status','tax_classification',
        'industry','employee_size_range',
    ]));
    $extra['created_by_user_id'] = $user['id'] ?? null;
    $id = companiesUpsertByName($tid, (string) $body['name'], $extra, $roles);
    companiesAudit('company.upserted', ['id' => $id, 'name' => $body['name'], 'roles' => $roles], $id);
    api_ok(['id' => $id, 'company' => companiesGet($id)], 201);
}

if ($method === 'POST' && $action === '') {
    rbac_legacy_require($user, 'people.manage');
    $body = api_json_body();
    api_require_fields($body, ['name']);
    $name = trim((string) $body['name']);

    $existing = scopedFind('SELECT id FROM companies WHERE tenant_id = :tenant_id AND name = :n AND deleted_at IS NULL', ['n' => $name]);
    if ($existing) api_error('A company with that name already exists for this tenant', 409, ['conflict_id' => $existing['id']]);

    $insert = array_intersect_key($body, array_flip([
        'name','legal_name','duns','website','phone',
        'primary_contact_name','primary_contact_email','primary_contact_phone',
        'address_line1','address_line2','city','state','postal_code','country','notes',
        'account_manager_user_id','default_terms','currency','status','tax_classification',
        'industry','employee_size_range',
        'w9_on_file','w9_expires_on','w9_storage_object_id',
        'coi_on_file','coi_expires_on','coi_storage_object_id',
    ]));
    if (isset($body['tags']) && is_array($body['tags'])) {
        $insert['tags_json'] = json_encode(array_values(array_unique(array_filter(array_map('strval', $body['tags'])))));
    }
    foreach (['w9_on_file','coi_on_file'] as $b) {
        if (isset($insert[$b])) $insert[$b] = !empty($insert[$b]) ? 1 : 0;
    }
    $insert['tenant_id']          = $tid;
    $insert['country']            = $insert['country'] ?? 'US';
    $insert['currency']           = $insert['currency'] ?? 'USD';
    $insert['default_terms']      = $insert['default_terms'] ?? 'NET30';
    $insert['status']             = $insert['status'] ?? 'active';
    $insert['created_by_user_id'] = $user['id'] ?? null;
    $id = scopedInsert('companies', $insert);

    foreach ((array) ($body['roles'] ?? []) as $role) {
        if (in_array($role, COMPANY_ROLES, true)) {
            companiesAddRole($id, $role);
        }
    }
    companiesAudit('company.created', ['id' => $id, 'name' => $name], $id);
    api_ok(['id' => $id, 'company' => companiesGet($id)], 201);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'people.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    foreach (['id','tenant_id','created_at','created_by_user_id','deleted_at'] as $k) unset($body[$k]);
    $allowed = array_intersect_key($body, array_flip([
        'name','legal_name','duns','website','phone',
        'primary_contact_name','primary_contact_email','primary_contact_phone',
        'address_line1','address_line2','city','state','postal_code','country','notes',
        'msa_signed_at','msa_storage_object_id',
        'account_manager_user_id','default_terms','currency','status','tax_classification',
        'industry','employee_size_range',
        'w9_on_file','w9_expires_on','w9_storage_object_id',
        'coi_on_file','coi_expires_on','coi_storage_object_id',
    ]));
    foreach (['w9_on_file','coi_on_file'] as $b) {
        if (isset($allowed[$b])) $allowed[$b] = !empty($allowed[$b]) ? 1 : 0;
    }
    if (isset($body['tags']) && is_array($body['tags'])) {
        $allowed['tags_json'] = json_encode(array_values(array_unique(array_filter(array_map('strval', $body['tags'])))));
    }
    if ($allowed) {
        $rows = scopedUpdate('companies', $id, $allowed);
        if ($rows === 0 && !isset($body['roles'])) api_error('Not found or no change', 404);
    }
    if (isset($body['roles']) && is_array($body['roles'])) {
        getDB()->prepare('DELETE FROM company_roles WHERE company_id = :c')->execute(['c' => $id]);
        foreach ($body['roles'] as $role) {
            if (in_array($role, COMPANY_ROLES, true)) companiesAddRole($id, $role);
        }
    }
    companiesAudit('company.updated', ['id' => $id, 'fields' => array_keys($allowed)], $id);
    api_ok(['company' => companiesGet($id)]);
}

if ($method === 'POST' && $action === 'add-role') {
    rbac_legacy_require($user, 'people.manage');
    $id = (int) ($_GET['id'] ?? 0);
    $body = api_json_body();
    api_require_fields($body, ['role']);
    companiesAddRole($id, (string) $body['role']);
    api_ok(['ok' => true, 'roles' => companyRoles($id)]);
}

if ($method === 'POST' && $action === 'remove-role') {
    rbac_legacy_require($user, 'people.manage');
    $id = (int) ($_GET['id'] ?? 0);
    $body = api_json_body();
    api_require_fields($body, ['role']);
    companiesRemoveRole($id, (string) $body['role']);
    api_ok(['ok' => true, 'roles' => companyRoles($id)]);
}

if ($method === 'POST' && $action === 'add-contact') {
    rbac_legacy_require($user, 'people.manage');
    $id = (int) ($_GET['id'] ?? 0);
    $body = api_json_body();
    api_require_fields($body, ['name']);
    $cid = scopedInsert('company_contacts', [
        'tenant_id'    => $tid,
        'company_id'   => $id,
        'name'         => $body['name'],
        'title'        => $body['title'] ?? null,
        'email'        => $body['email'] ?? null,
        'phone'        => $body['phone'] ?? null,
        'contact_role' => $body['contact_role'] ?? 'other',
        'is_primary'   => !empty($body['is_primary']) ? 1 : 0,
        'notes'        => $body['notes'] ?? null,
    ]);
    if (!empty($body['is_primary'])) {
        // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
        getDB()->prepare('UPDATE company_contacts SET is_primary = 0 WHERE company_id = :c AND id != :id')
            ->execute(['c' => $id, 'id' => $cid]);
    }
    companiesAudit('company.contact.added', ['company_id' => $id, 'contact_id' => $cid], $id);
    api_ok(['contact_id' => $cid, 'contacts' => companyContacts($id)], 201);
}

if ($method === 'DELETE') {
    rbac_legacy_require($user, 'people.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    getDB()->prepare('UPDATE companies SET deleted_at = NOW() WHERE id = :id AND tenant_id = :t')
        ->execute(['id' => $id, 't' => $tid]);
    companiesAudit('company.deleted', ['id' => $id], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'add-address') {
    rbac_legacy_require($user, 'people.manage');
    $id = (int) ($_GET['id'] ?? 0);
    $body = api_json_body();
    api_require_fields($body, ['line1','city']);
    $kind = (string) ($body['kind'] ?? 'hq');
    $allowedKinds = ['hq','billing','remit_to','worksite','mailing'];
    if (!in_array($kind, $allowedKinds, true)) api_error("invalid kind: {$kind}", 422);

    if (!empty($body['is_primary'])) {
        // Demote any existing primary of the same kind so there's at most one.
        // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
        getDB()->prepare(
            'UPDATE company_addresses SET is_primary = 0
             WHERE company_id = :c AND kind = :k'
        )->execute(['c' => $id, 'k' => $kind]);
    }
    $aid = scopedInsert('company_addresses', [
        'tenant_id'   => $tid,
        'company_id'  => $id,
        'kind'        => $kind,
        'label'       => $body['label']       ?? null,
        'line1'       => $body['line1'],
        'line2'       => $body['line2']       ?? null,
        'city'        => $body['city'],
        'state'       => $body['state']       ?? null,
        'postal_code' => $body['postal_code'] ?? null,
        'country'     => $body['country']     ?? 'US',
        'is_primary'  => !empty($body['is_primary']) ? 1 : 0,
        'notes'       => $body['notes']       ?? null,
    ]);
    companiesAudit('company.address.added', ['company_id' => $id, 'address_id' => $aid, 'kind' => $kind], $id);
    api_ok(['address_id' => $aid, 'addresses' => companyAddresses($id)], 201);
}

if ($method === 'PATCH' && $action === 'address') {
    rbac_legacy_require($user, 'people.manage');
    $aid = (int) ($_GET['id'] ?? 0);
    $body = api_json_body();
    foreach (['id','tenant_id','company_id','created_at'] as $k) unset($body[$k]);
    if (!$body) api_error('No fields to update', 422);
    $rows = scopedUpdate('company_addresses', $aid, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE' && $action === 'address') {
    rbac_legacy_require($user, 'people.manage');
    $aid = (int) ($_GET['id'] ?? 0);
    $rows = scopedDelete('company_addresses', $aid);
    if ($rows === 0) api_error('Not found', 404);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
