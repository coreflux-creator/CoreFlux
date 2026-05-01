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
    RBAC::requirePermission($user, 'people.view');
    $row = companiesGet((int) $_GET['id']);
    if (!$row) api_error('Not found', 404);
    api_ok(['company' => $row]);
}

if ($method === 'GET') {
    RBAC::requirePermission($user, 'people.view');
    $res = companiesList([
        'q'        => $_GET['q']        ?? null,
        'role'     => $_GET['role']     ?? null,
        'page'     => $_GET['page']     ?? 1,
        'per_page' => $_GET['per_page'] ?? 50,
    ]);
    api_ok($res + ['available_roles' => COMPANY_ROLES]);
}

if ($method === 'POST' && $action === 'upsert') {
    RBAC::requirePermission($user, 'people.manage');
    $body = api_json_body();
    api_require_fields($body, ['name']);
    $roles = (array) ($body['roles'] ?? []);
    $extra = array_intersect_key($body, array_flip([
        'legal_name','website','phone','primary_contact_name','primary_contact_email','primary_contact_phone',
        'address_line1','address_line2','city','state','postal_code','country','notes',
    ]));
    $extra['created_by_user_id'] = $user['id'] ?? null;
    $id = companiesUpsertByName($tid, (string) $body['name'], $extra, $roles);
    companiesAudit('company.upserted', ['id' => $id, 'name' => $body['name'], 'roles' => $roles], $id);
    api_ok(['id' => $id, 'company' => companiesGet($id)], 201);
}

if ($method === 'POST' && $action === '') {
    RBAC::requirePermission($user, 'people.manage');
    $body = api_json_body();
    api_require_fields($body, ['name']);
    $name = trim((string) $body['name']);

    $existing = scopedFind('SELECT id FROM companies WHERE tenant_id = :tenant_id AND name = :n AND deleted_at IS NULL', ['n' => $name]);
    if ($existing) api_error('A company with that name already exists for this tenant', 409, ['conflict_id' => $existing['id']]);

    $insert = array_intersect_key($body, array_flip([
        'name','legal_name','duns','website','phone',
        'primary_contact_name','primary_contact_email','primary_contact_phone',
        'address_line1','address_line2','city','state','postal_code','country','notes',
    ]));
    $insert['tenant_id']          = $tid;
    $insert['country']            = $insert['country'] ?? 'US';
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
    RBAC::requirePermission($user, 'people.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    foreach (['id','tenant_id','created_at','created_by_user_id','deleted_at'] as $k) unset($body[$k]);
    $allowed = array_intersect_key($body, array_flip([
        'name','legal_name','duns','website','phone',
        'primary_contact_name','primary_contact_email','primary_contact_phone',
        'address_line1','address_line2','city','state','postal_code','country','notes',
        'msa_signed_at','msa_storage_object_id',
    ]));
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
    RBAC::requirePermission($user, 'people.manage');
    $id = (int) ($_GET['id'] ?? 0);
    $body = api_json_body();
    api_require_fields($body, ['role']);
    companiesAddRole($id, (string) $body['role']);
    api_ok(['ok' => true, 'roles' => companyRoles($id)]);
}

if ($method === 'POST' && $action === 'remove-role') {
    RBAC::requirePermission($user, 'people.manage');
    $id = (int) ($_GET['id'] ?? 0);
    $body = api_json_body();
    api_require_fields($body, ['role']);
    companiesRemoveRole($id, (string) $body['role']);
    api_ok(['ok' => true, 'roles' => companyRoles($id)]);
}

if ($method === 'POST' && $action === 'add-contact') {
    RBAC::requirePermission($user, 'people.manage');
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
        getDB()->prepare('UPDATE company_contacts SET is_primary = 0 WHERE company_id = :c AND id != :id')
            ->execute(['c' => $id, 'id' => $cid]);
    }
    companiesAudit('company.contact.added', ['company_id' => $id, 'contact_id' => $cid], $id);
    api_ok(['contact_id' => $cid, 'contacts' => companyContacts($id)], 201);
}

if ($method === 'DELETE') {
    RBAC::requirePermission($user, 'people.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    getDB()->prepare('UPDATE companies SET deleted_at = NOW() WHERE id = :id AND tenant_id = :t')
        ->execute(['id' => $id, 't' => $tid]);
    companiesAudit('company.deleted', ['id' => $id], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
