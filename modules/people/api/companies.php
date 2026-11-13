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
require_once __DIR__ . '/../lib/company_source_ownership.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = companiesUseDirectoryScope();
$method = api_method();
$action = $_GET['action'] ?? '';

if(in_array($action,['merge_preview','merge'],true)){
    rbac_legacy_require($user,'people.manage');
    rbac_legacy_require_any($user,['tenant_admin.integrations','integrations.jobdiva.manage']);
    if($method!=='POST')api_error('Method not allowed',405);
    require_once __DIR__.'/../lib/company_merge_review.php';
    try{
        $body=api_json_body();
        if($action==='merge_preview'){
            if(array_diff(array_keys($body),['survivor_id','retired_id'])||!is_int($body['survivor_id']??null)||!is_int($body['retired_id']??null))api_error('Choose the two current company records.',422);
            api_ok(['review'=>companyMergePreview((int)$ctx['tenant_id'],$user,$body['survivor_id'],$body['retired_id'])]);
        }
        if(array_diff(array_keys($body),['review_token','choices','confirm_merge'])||!is_string($body['review_token']??null)||!is_array($body['choices']??null)||($body['confirm_merge']??null)!==true)api_error('Preview the companies and confirm the reviewed merge choices.',422);
        api_ok(companyMergeApply((int)$ctx['tenant_id'],$user,$body['review_token'],$body['choices']));
    }catch(Throwable $e){$code=(int)$e->getCode();api_error($e->getMessage(),in_array($code,[404,409,422,503],true)?$code:($e instanceof InvalidArgumentException?422:500));}
}

$requireCompany = static function (int $companyId): array {
    if ($companyId <= 0) api_error('id required', 400);
    $company = companiesGet($companyId);
    if (!$company) api_error('Not found', 404);
    return $company;
};

if (in_array($action, ['source_review','source_accept'], true)) {
    rbac_legacy_require($user, 'people.manage');
    $table=(string)($_GET['record_type']??'companies');$id=(int)($_GET['id']??0);
    if(!in_array($table,['companies','company_contacts'],true))api_error('Choose a company or company contact.',422);
    try {
        if($method==='GET'&&$action==='source_review'){
            $review=companySourceWithRecord(getDB(),$tid,$table,$id,static fn(array $row):array=>companySourceReview($row,$table));
            api_ok(['review'=>$review]);
        }
        if($method==='POST'&&$action==='source_accept'){
            $body=api_json_body();
            if(($body['confirm_source']??null)!==true||!is_array($body['fields']??null)||!is_string($body['review_token']??null))api_error('Review and confirm the source fields first.',422);
            $result=companySourceAccept(getDB(),(int)$ctx['tenant_id'],$tid,$table,$id,$body['fields'],$body['review_token'],(int)$user['id']);
            api_ok($result);
        }
        api_error('Method not allowed',405);
    }catch(Throwable $e){$code=(int)$e->getCode();api_error($e->getMessage(),in_array($code,[404,409,422],true)?$code:($e instanceof InvalidArgumentException?422:500));}
}

if ($method === 'GET' && !empty($_GET['id'])) {
    rbac_legacy_require($user, 'people.view');
    $row = companiesGet((int) $_GET['id']);
    if (!$row) api_error('Not found', 404);
    $canManage=rbac_legacy_can($user,'people.manage');
    api_ok(['company' => $row, 'can_review_source'=>$canManage,
        'can_link_source'=>$canManage&&(rbac_legacy_can($user,'tenant_admin.integrations')||rbac_legacy_can($user,'integrations.jobdiva.manage'))]);
}

if ($method === 'GET' && $action === 'duplicates') {
    rbac_legacy_require($user, 'people.manage');
    api_ok(['groups' => companiesDuplicateCandidates($tid),'can_merge'=>rbac_legacy_can($user,'tenant_admin.integrations')||rbac_legacy_can($user,'integrations.jobdiva.manage')]);
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'people.view');
    $res = companiesList([
        'q'        => $_GET['q']        ?? null,
        'role'     => $_GET['role']     ?? null,
        'roles'    => $_GET['roles']    ?? null,
        'status'   => $_GET['status']   ?? null,
        'sort'     => $_GET['sort']     ?? null,
        'dir'      => $_GET['dir']      ?? null,
        'page'     => $_GET['page']     ?? 1,
        'per_page' => $_GET['per_page'] ?? 50,
    ]);
    api_ok($res + ['available_roles' => COMPANY_ROLES]);
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
    $pdo=getDB();$pdo->beginTransaction();
    try {
        $id=companiesUpsertByName($tid,(string)$body['name'],$extra,$roles);
        $owned=array_intersect_key($extra,array_flip(companySourceFields('companies')))+['name'=>(string)$body['name']];
        if(array_key_exists('roles',$body))$owned['roles']=array_values(array_intersect(COMPANY_ROLES,$roles));
        companyNativeFields($pdo,(int)$ctx['tenant_id'],$tid,'companies',$id,$owned,(int)$user['id']);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    api_ok(['id' => $id, 'company' => companiesGet($id)], 201);
}

if ($method === 'POST' && $action === 'bulk_update') {
    rbac_legacy_require($user, 'people.manage');
    $body = api_json_body();
    $ids = is_array($body['ids'] ?? null)
        ? array_values(array_unique(array_map('intval', $body['ids'])))
        : [];
    $ids = array_values(array_filter($ids, static fn($id) => $id > 0));
    $field = (string) ($body['field'] ?? '');
    $value = trim((string) ($body['value'] ?? ''));
    $allowed = ['status', 'default_terms', 'currency', 'industry'];

    if (!$ids) api_error('ids[] required', 422);
    if (count($ids) > 500) api_error('Too many ids (max 500 per call)', 422);
    if (!in_array($field, $allowed, true)) api_error('Invalid bulk field', 422, ['allowed' => $allowed]);
    if ($field === 'status' && !in_array(strtolower($value), ['prospect', 'active', 'inactive', 'blacklisted'], true)) {
        api_error('Invalid status', 422);
    }
    if ($field === 'currency') {
        $value = strtoupper($value);
        if (!preg_match('/^[A-Z]{3}$/', $value)) api_error('Currency must be a 3-letter code', 422);
    }
    if ($field === 'status') $value = strtolower($value);
    if ($field === 'default_terms' && ($value === '' || strlen($value) > 50)) api_error('Payment terms must be 1-50 characters', 422);
    if ($field === 'industry' && strlen($value) > 120) api_error('Industry must be 120 characters or fewer', 422);

    $updated = 0;
    $skipped = 0;
    $failed = 0;
    foreach ($ids as $id) {
        try {
            $company = companiesGet($id);
            if (!$company) {
                $skipped++;
                continue;
            }
            if ((string) ($company[$field] ?? '') === $value) {
                $skipped++;
                continue;
            }
            companyNativeFields(getDB(),(int)$ctx['tenant_id'],$tid,'companies',$id,[$field=>$value],(int)$user['id']);
            companiesAudit('company.updated', [
                'id' => $id,
                'source' => 'bulk_update',
                'fields' => [$field],
                'before' => [$field => $company[$field] ?? null],
                'after' => [$field => $value],
            ], $id);
            $updated++;
        } catch (\Throwable $e) {
            $failed++;
        }
    }
    api_ok(['ok' => $failed === 0, 'updated' => $updated, 'skipped' => $skipped, 'failed' => $failed]);
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

if ($method==='PATCH'&&$action==='contact') {
    rbac_legacy_require($user,'people.manage');$id=(int)($_GET['id']??0);$body=api_json_body();
    $values=array_intersect_key($body,array_flip(companySourceFields('company_contacts')));
    if(!$values)api_error('Choose the contact fields to update.',422);
    try{companyNativeFields(getDB(),(int)$ctx['tenant_id'],$tid,'company_contacts',$id,$values,(int)$user['id']);}
    catch(Throwable$e){$code=(int)$e->getCode();api_error($e->getMessage(),in_array($code,[404,409,422],true)?$code:500);}
    api_ok(['ok'=>true]);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'people.manage');
    $id = (int) ($_GET['id'] ?? 0);
    $requireCompany($id);
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
    if(isset($body['roles'])&&is_array($body['roles']))$allowed['roles']=array_values(array_intersect(COMPANY_ROLES,$body['roles']));
    companySourceWithRecord(getDB(),$tid,'companies',$id,static function(array $row)use($ctx,$user,$tid,$id,$allowed):void{
        $sourceFields=array_intersect_key($allowed,array_flip(companySourceFields('companies')));
        $otherFields=array_diff_key($allowed,$sourceFields);
        if($otherFields)scopedUpdate('companies',$id,$otherFields);
        companyNativeFields(getDB(),(int)$ctx['tenant_id'],$tid,'companies',$id,$sourceFields,(int)$user['id']);
    });
    api_ok(['company' => companiesGet($id)]);
}

if ($method === 'POST' && $action === 'add-role') {
    rbac_legacy_require($user, 'people.manage');
    $id = (int) ($_GET['id'] ?? 0);
    $requireCompany($id);
    $body = api_json_body();
    api_require_fields($body, ['role']);
    companySourceWithRecord(getDB(),$tid,'companies',$id,static function(array $row)use($ctx,$tid,$id,$body,$user):void{
        companyNativeFields(getDB(),(int)$ctx['tenant_id'],$tid,'companies',$id,['roles'=>array_values(array_unique(array_merge($row['roles'],[(string)$body['role']])))],(int)$user['id']);
    });
    api_ok(['ok' => true, 'roles' => companyRoles($id)]);
}

if ($method === 'POST' && $action === 'remove-role') {
    rbac_legacy_require($user, 'people.manage');
    $id = (int) ($_GET['id'] ?? 0);
    $requireCompany($id);
    $body = api_json_body();
    api_require_fields($body, ['role']);
    companySourceWithRecord(getDB(),$tid,'companies',$id,static function(array $row)use($ctx,$tid,$id,$body,$user):void{
        companyNativeFields(getDB(),(int)$ctx['tenant_id'],$tid,'companies',$id,['roles'=>array_values(array_diff($row['roles'],[(string)$body['role']]))],(int)$user['id']);
    });
    api_ok(['ok' => true, 'roles' => companyRoles($id)]);
}

if ($method === 'POST' && $action === 'add-contact') {
    rbac_legacy_require($user, 'people.manage');
    $id = (int) ($_GET['id'] ?? 0);
    $requireCompany($id);
    $body = api_json_body();
    api_require_fields($body, ['name']);
    $cid=companySourceWithRecord(getDB(),$tid,'companies',$id,static function(array $row)use($ctx,$tid,$id,$body,$user):int{
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
        foreach(companyContacts($id)as$contact)if((int)$contact['id']!==$cid&&!empty($contact['is_primary'])){
            companyNativeFields(getDB(),(int)$ctx['tenant_id'],$tid,'company_contacts',(int)$contact['id'],['is_primary'=>0],(int)$user['id']);
        }
    }
    companySourceAudit(getDB(),(int)$ctx['tenant_id'],$tid,'company_contacts',$cid,'company.contact.added',array_keys($body),(int)$user['id']);
    return $cid;
    });
    api_ok(['contact_id' => $cid, 'contacts' => companyContacts($id)], 201);
}

if ($method === 'DELETE' && $action === 'address') {
    rbac_legacy_require($user, 'people.manage');
    $aid = (int) ($_GET['id'] ?? 0);
    $rows = scopedDelete('company_addresses', $aid);
    if ($rows === 0) api_error('Not found', 404);
    api_ok(['ok' => true]);
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
    $requireCompany($id);
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

api_error('Method not allowed', 405);
