<?php
/**
 * /api/staffing/clients — Clients CRUD.
 *
 *   GET    list   ?q=&status=&limit=
 *   GET    get    ?id=N
 *   POST   create body: { name, legal_name, industry, primary_contact_*, billing_*, status, payment_terms_days, notes }
 *   POST   update body: { id, ...fields }
 *   POST   bulk_update body: { ids[], field, value }
 *   POST   delete body: { id } → status=closed (soft delete)
 *
 *   GET    stats  ?id=N → { active_placements, mtd_revenue, ar_outstanding }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';
require_once __DIR__ . '/../lib/client_audit.php';
require_once __DIR__ . '/../lib/clients.php';
require_once __DIR__ . '/../lib/client_write.php';
require_once __DIR__ . '/../../../core/record_numbers.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$activeTenantId = (int) ($ctx['tenant_id'] ?? currentTenantId());
$tenantId = staffingClientCatalogTenantId($activeTenantId);
$placementsTenantId = $tenantId;
setRequestModuleScope('placements');
$actorUserId = isset($user['id']) ? (int) $user['id'] : null;
$method = api_method();
$action = $_GET['action'] ?? 'list';

function staffingClientApiSave(int $workspace,?int $id,array $patch,int $actor,?string $revision=null,string $event=''): array
{
    try{$saved=staffingClientSaveNative($workspace,$id,$patch,$actor,$revision,$event);unset($saved['coreflux_source_state']);return$saved;}
    catch(Throwable $e){
        $status=in_array((int)$e->getCode(),[404,409],true)?(int)$e->getCode():($e instanceof InvalidArgumentException?422:500);
        if($e instanceof PDOException&&(string)$e->getCode()==='23000')api_error('This client or company conflicts with an existing record. Refresh and review it.',409);
        api_error($e->getMessage(),$status);
    }
}

function staffingClientApiId(mixed $value): int
{
    try{$id=companyRelationshipId($value);}
    catch(InvalidArgumentException $e){api_error('Choose a valid client number.',422);}
    if($id===null)api_error('id required',422);
    return$id;
}

if(in_array($action,['source_review','source_accept'],true)){
    api_require_legacy_permission($ctx,'placements.manage');
    try{
        $id=staffingClientApiId($_GET['id']??null);
        if($method==='GET'&&$action==='source_review')api_ok(['review'=>staffingClientSourceReview($activeTenantId,$id)]);
        if($method==='POST'&&$action==='source_accept'){
            $body=api_json_body();
            if(($body['confirm_source']??null)!==true||!is_string($body['source_key']??null)||!is_string($body['review_token']??null)||!is_array($body['fields']??null))api_error('Review and confirm the selected source values.',422);
            api_ok(staffingClientSourceAccept($activeTenantId,$id,$body['source_key'],$body['fields'],$body['review_token'],(int)$actorUserId));
        }
        api_error('Method not allowed',405);
    }catch(Throwable $e){$code=(int)$e->getCode();api_error($e->getMessage(),in_array($code,[404,409],true)?$code:($e instanceof InvalidArgumentException?422:500));}
}


if ($method === 'GET' && $action === 'list') {
    api_require_legacy_permission($ctx, 'placements.view');
    $where  = ['c.tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['status'])) { $where[] = 'c.status = :s'; $params['s'] = $_GET['status']; }
    if (!empty($_GET['q']))      {
        // Distinct placeholders required by PDO_MYSQL native prepares.
        $where[] = '(c.name LIKE :q_name
                  OR c.legal_name LIKE :q_legal
                  OR c.industry LIKE :q_industry
                  OR c.primary_contact_name LIKE :q_contact
                  OR c.primary_contact_email LIKE :q_email
                  OR c.primary_contact_phone LIKE :q_phone
                  OR c.billing_city LIKE :q_city
                  OR c.billing_state LIKE :q_state
                  OR c.billing_country LIKE :q_country
                  OR c.external_id LIKE :q_external
                  OR c.source_system LIKE :q_source
                  OR c.status LIKE :q_status
                  OR c.msa_status LIKE :q_msa
                  OR CAST(c.payment_terms_days AS CHAR) LIKE :q_terms OR ' . coreRecordNumberSearchSql('client', 'c', 'qnumber') . ')';
        $params['qnumber'] = strtoupper(trim((string) $_GET['q']));
        $like = '%' . trim((string) $_GET['q']) . '%';
        foreach (['q_name','q_legal','q_industry','q_contact','q_email','q_phone','q_city','q_state','q_country','q_external','q_source','q_status','q_msa','q_terms'] as $key) {
            $params[$key] = $like;
        }
    }
    $source = strtolower(trim((string) ($_GET['source'] ?? '')));
    if ($source === 'placement') {
        $where[] = 'EXISTS (
            SELECT 1 FROM placements sp
             WHERE sp.tenant_id = :source_placement_tid
               AND sp.client_id = c.id
               AND sp.status = \'active\'
               AND sp.deleted_at IS NULL
        )';
        $params['source_placement_tid'] = $placementsTenantId;
    } elseif ($source === 'accounting') {
        $where[] = 'EXISTS (
            SELECT 1 FROM external_entity_mappings sem
             WHERE sem.tenant_id = c.tenant_id
               AND sem.internal_entity_type = \'customer\'
               AND sem.internal_entity_id = c.id
        )';
    } elseif ($source === 'jobdiva') {
        $where[] = 'LOWER(c.source_system) = \'jobdiva\'';
    } elseif ($source === 'manual') {
        $where[] = 'COALESCE(NULLIF(LOWER(c.source_system), \'\'), \'manual\') = \'manual\'
                    AND NOT EXISTS (
                        SELECT 1 FROM placements smp
                         WHERE smp.tenant_id = :source_manual_tid
                           AND smp.client_id = c.id
                           AND smp.status = \'active\'
                           AND smp.deleted_at IS NULL
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM external_entity_mappings sme
                         WHERE sme.tenant_id = c.tenant_id
                           AND sme.internal_entity_type = \'customer\'
                           AND sme.internal_entity_id = c.id
                    )';
        $params['source_manual_tid'] = $placementsTenantId;
    }
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
    $sortMap = [
        'name'                  => 'c.name',
        'industry'              => 'c.industry',
        'active_placements'     => 'active_placements',
        'primary_contact_email' => 'c.primary_contact_email',
        'payment_terms_days'    => 'c.payment_terms_days',
        'msa_status'            => 'c.msa_status',
        'source'                => 'source_label',
        'status'                => 'c.status',
        'created_at'            => 'c.created_at',
    ];
    $sortKey = (string) ($_GET['sort'] ?? 'name');
    $sortExpr = $sortMap[$sortKey] ?? $sortMap['name'];
    $sortDir = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $sql = "SELECT c.*,
                   CASE
                     WHEN COALESCE(p.cnt, 0) > 0 THEN 'Placement billing company'
                     WHEN EXISTS (
                       SELECT 1 FROM external_entity_mappings em
                        WHERE em.tenant_id = c.tenant_id
                          AND em.internal_entity_type = 'customer'
                          AND em.internal_entity_id = c.id
                     ) THEN 'Accounting customer'
                     WHEN c.source_system <> 'manual' THEN c.source_system
                     ELSE 'Manual'
                   END AS source_label,
                   COALESCE(p.cnt, 0) AS active_placements
              FROM staffing_clients c
              LEFT JOIN (
                  SELECT client_id, COUNT(*) AS cnt
                    FROM placements
                   WHERE tenant_id = :placements_tid AND status = 'active'
                     AND deleted_at IS NULL
                     AND client_id IS NOT NULL
                   GROUP BY client_id
              ) p ON p.client_id = c.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY {$sortExpr} {$sortDir}, c.id DESC
             LIMIT " . $limit;
    $numberedRows = coreRecordNumbersAttach(getDB(), $tenantId, 'client', staffingClientCatalogQuery(
        $tenantId,
        $sql,
        array_merge($params, ['placements_tid' => $placementsTenantId])
    ));
    $scope=companyRelationshipScope(getDB(),$activeTenantId,'placements',false);
    if($scope['owner']!==$tenantId)api_error('The client directory changed. Refresh and try again.',409);
    foreach($numberedRows as&$row){$row['edit_revision']=staffingClientRevision(getDB(),$scope,$row);unset($row['coreflux_source_state']);}unset($row);
    $numberedRows = coreRecordNumbersAttachRelated(getDB(), $scope['company_owner'], 'company', $numberedRows, 'company_id');
    api_ok(['rows' => $numberedRows]);
}

if ($method === 'POST' && $action === 'bulk_update') {
    api_require_legacy_permission($ctx, 'placements.manage');
    $b = api_json_body();
    $ids = is_array($b['ids'] ?? null) ? array_values(array_unique(array_map('staffingClientApiId', $b['ids']))) : [];
    $field = (string) ($b['field'] ?? '');
    $value = $b['value'] ?? null;
    $allowed = ['status','payment_terms_days','industry','msa_status'];
    if (!$ids) api_error('ids[] required', 422);
    if (count($ids) > 500) api_error('Too many ids (max 500 per call)', 422);
    if (!in_array($field, $allowed, true)) api_error('Invalid bulk field', 422, ['allowed' => $allowed]);

    if ($field === 'status') {
        $value = strtolower(trim((string) $value));
        $statuses = ['active','prospect','on_hold','inactive','closed'];
        if (!in_array($value, $statuses, true)) api_error('Invalid status', 422, ['allowed' => $statuses]);
    } elseif ($field === 'msa_status') {
        $value = strtolower(trim((string) $value));
        $msaStatuses = ['none','draft','executed','expired'];
        if (!in_array($value, $msaStatuses, true)) api_error('Invalid msa_status', 422, ['allowed' => $msaStatuses]);
    } elseif ($field === 'payment_terms_days') {
        try{$value=staffingClientValidateNative(['payment_terms_days'=>$value])['payment_terms_days'];}
        catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}
    } else {
        $value = trim((string) $value);
        if ($value === '') api_error('industry cannot be blank', 422);
        if (strlen($value) > 120) api_error('industry is too long', 422);
    }

    $revisions=$b['edit_revisions']??[];
    if(!is_array($revisions))api_error('Refresh the client list before editing.',422);
    $updated = 0; $skipped = 0; $failed = 0; $results = [];
    foreach($ids as$id){
        try{
            staffingClientSaveNative($activeTenantId,$id,[$field=>$value],(int)$actorUserId,
                is_string($revisions[$id]??null)?$revisions[$id]:null);
            $updated++;$results[]=['id'=>$id,'ok'=>true];
        }catch(Throwable $e){$failed++;$results[]=['id'=>$id,'ok'=>false,'reason'=>$e instanceof PDOException?'The client conflicts with an existing record. Refresh and review it.':$e->getMessage()];}
    }
    api_ok([
        'ok' => $failed === 0,
        'updated' => $updated,
        'skipped' => $skipped,
        'failed' => $failed,
        'field' => $field,
        'results' => $results,
    ]);
}

if ($method === 'GET' && $action === 'get') {
    api_require_legacy_permission($ctx, 'placements.view');
    $id = staffingClientApiId($_GET['id'] ?? null);
    $row = staffingClientCatalogFind(
        $tenantId,
        'SELECT * FROM staffing_clients WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    if (!$row) api_error('Not found', 404);
    $row = coreRecordNumbersAttach(getDB(), $tenantId, 'client', [$row])[0];
    $scope=companyRelationshipScope(getDB(),$activeTenantId,'placements',false);
    $row['edit_revision']=staffingClientRevision(getDB(),$scope,$row);
    unset($row['coreflux_source_state']);
    $row = coreRecordNumbersAttachRelated(getDB(), $scope['company_owner'], 'company', [$row], 'company_id')[0];
    api_ok(['client' => $row]);
}

if ($method === 'POST' && $action === 'create') {
    api_require_legacy_permission($ctx, 'placements.manage');
    $b=api_json_body();$patch=array_intersect_key($b,array_flip(staffingClientNativeFields()));
    $client=staffingClientApiSave($activeTenantId,null,$patch,(int)$actorUserId);
    api_ok(['client'=>$client]);
}

if ($method === 'POST' && $action === 'update') {
    api_require_legacy_permission($ctx, 'placements.manage');
    $b=api_json_body();$id=staffingClientApiId($b['id']??null);
    $patch=array_intersect_key($b,array_flip(staffingClientNativeFields()));
    $client=staffingClientApiSave($activeTenantId,$id,$patch,(int)$actorUserId,is_string($b['edit_revision']??null)?$b['edit_revision']:null);
    api_ok(['client'=>$client]);
}

if ($method === 'POST' && $action === 'delete') {
    api_require_legacy_permission($ctx, 'placements.manage');
    $b=api_json_body();$id=staffingClientApiId($b['id']??null);
    staffingClientApiSave($activeTenantId,$id,['status'=>'closed'],(int)$actorUserId,is_string($b['edit_revision']??null)?$b['edit_revision']:null,'staffing.client.closed');
    api_ok(['ok'=>true,'closed_id'=>$id]);
}

if ($method === 'GET' && $action === 'stats') {
    api_require_legacy_permission($ctx, 'placements.view');
    $id = staffingClientApiId($_GET['id'] ?? null);

    $activeStmt = getDB()->prepare(
        "SELECT COUNT(*) AS c
           FROM placements p
           JOIN staffing_clients sc
             ON sc.tenant_id = p.tenant_id
            AND sc.id = p.client_id
          WHERE p.tenant_id = :placements_tid
            AND sc.id = :id
            AND p.status = 'active'
            AND p.deleted_at IS NULL"
    );
    $activeStmt->execute(['placements_tid' => $placementsTenantId, 'id' => $id]);
    $active = $activeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats = ['active_placements' => (int) ($active['c'] ?? 0)];

    // MTD revenue from the staffing reports view if it exists.
    try {
        $stmt = getDB()->prepare(
            "SELECT COALESCE(SUM(v.revenue), 0) AS r
               FROM v_timesheet_day_fin v
               JOIN placements p ON p.id = v.placement_id AND p.tenant_id = :placements_tid
               JOIN staffing_clients sc ON sc.tenant_id = p.tenant_id AND sc.id = p.client_id
              WHERE v.tenant_id = :tenant_id
                AND sc.id = :id
                AND v.work_date >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')"
        );
        $stmt->execute(['tenant_id' => $activeTenantId, 'placements_tid' => $placementsTenantId, 'id' => $id]);
        $rev = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['mtd_revenue'] = (float) ($rev['r'] ?? 0);
    } catch (\Throwable $_) {
        $stats['mtd_revenue'] = null;
    }
    api_ok($stats);
}

api_error('Unknown action', 404);
