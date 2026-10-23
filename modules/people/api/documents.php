<?php
/**
 * People API — documents (refs Core StorageService)
 *
 *   GET    /api/people/documents?person_id=N      → list
 *   GET    /api/people/documents?id=N             → get one + signed URL
 *   POST   /api/people/documents?person_id=N      → register an uploaded file
 *                                                  body: { storage_object_id, doc_type, file_name?, signed?, signed_at?, expires_at? }
 *   PATCH  /api/people/documents?id=N             → update metadata
 *   DELETE /api/people/documents?id=N             → soft delete
 *   GET    /api/people/documents?action=upload_url&person_id=N&doc_type=resume&file_name=X.pdf
 *                                                  → returns presigned POST form for direct browser upload
 *
 * SPEC: /app/modules/people/SPEC.md §3.1, §5.3, §11 (S3 via StorageService)
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/StorageService.php';
require_once __DIR__ . '/../lib/people.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/documents.php';

use Core\StorageService;

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';
$pdo = getDB();
try {
$operatingTenant = (int) $ctx['tenant_id'];
$tid = peopleDocumentDirectory($pdo, $operatingTenant);
$scope = ['tenant_id'=>$operatingTenant, 'people_tenant_id'=>$tid, 'actor_user_id'=>(int)$user['id']];

$requirePerson = static function (int $personId) use ($pdo, $tid): void {
    peopleDocumentPerson($pdo, $tid, $personId);
};
$findDocument = static function (int $id) use ($pdo, $tid, $requirePerson): array {
    $stmt=$pdo->prepare('SELECT * FROM people_documents WHERE tenant_id=:t AND id=:id AND deleted_at IS NULL');
    $stmt->execute(['t'=>$tid,'id'=>$id]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !(int)$row['person_id']) throw new PeopleDocumentConflict('Document is unavailable in this directory.');
    $requirePerson((int)$row['person_id']); return $row;
};
$head = static fn(string $key): ?array => StorageService::getInstance()->head($key);

if ($method === 'GET' && $action === 'upload_url') {
    rbac_legacy_require($user, 'people.docs.manage');
    $personId = (int) api_query('person_id', 0);
    $docType  = (string) api_query('doc_type', 'other');
    $fileName = (string) api_query('file_name', 'document');
    $requirePerson($personId);
    peopleDocumentMetadata(['doc_type'=>$docType, 'file_name'=>$fileName]);
    if (StorageService::getInstance()->driver_name()==='local' && getenv('COREFLUX_ENV')!=='coreaccounting') {
        api_error('Document uploads require configured private storage for this Core environment.', 503);
    }

    $key = StorageService::getInstance()->build_key(
        'people', $tid, 'document', $personId, $fileName
    );
    $post = StorageService::getInstance()->get_presigned_post($key, ['policy'=>[['content-length-range',1,25*1024*1024]]]);
    api_ok([
        'storage_key' => $key,
        'upload'      => ['url'=>$post['form_action'], 'fields'=>$post['fields']],
        'doc_type'    => $docType,
    ]);
}

if ($method === 'GET') {
    if ($id = (int) api_query('id', 0)) {
        rbac_legacy_require($user, 'people.docs.view');
        $row = $findDocument($id);
        $obj = peopleDocumentObject($pdo, $tid, (int)$row['person_id'], (int)$row['storage_object_id']);
        peopleDocumentHead($head, $obj['s3_key'], null);
        $signed = StorageService::getInstance()->get_signed_url($obj['s3_key']);
        peopleLogPIIAccess(
            (int) ($user['id'] ?? 0),
            (int) $row['person_id'],
            'document.downloaded',
            ['document_id' => $id, 'doc_type' => $row['doc_type'], 'people_tenant_id'=>$tid]
        );
        api_ok(['document' => peopleDocumentPublic($row), 'signed_url' => $signed]);
    }

    rbac_legacy_require($user, 'people.docs.view');
    $personId = (int) api_query('person_id', 0);
    $requirePerson($personId);
    $stmt=$pdo->prepare('SELECT * FROM people_documents WHERE tenant_id=:t AND person_id=:p AND deleted_at IS NULL ORDER BY created_at DESC');
    $stmt->execute(['t'=>$tid,'p'=>$personId]);
    api_ok(['documents' => array_map('peopleDocumentPublic',$stmt->fetchAll(PDO::FETCH_ASSOC))]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'people.docs.manage');
    $personId = (int) api_query('person_id', 0);
    $requirePerson($personId);
    $body = api_json_body();
    $result=peopleDocumentRegister($pdo,$scope,$personId,$body,$head);
    if (!$result['replayed']) peopleAudit('people.document.uploaded', [
        'person_id'=>$personId, 'people_tenant_id'=>$tid, 'doc_type'=>$body['doc_type'], 'document_id'=>$result['id']
    ],$personId);
    api_ok($result,$result['replayed']?200:201);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'people.docs.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $body=api_json_body();
    $personId=peopleDocumentMutate($pdo,$scope,$id,$body);
    peopleAudit('people.document.updated',['document_id'=>$id,'fields'=>array_keys($body),'people_tenant_id'=>$tid],$personId);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE') {
    rbac_legacy_require($user, 'people.docs.manage');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $personId=peopleDocumentMutate($pdo,$scope,$id,null);
    peopleAudit('people.document.deleted', ['document_id' => $id,'people_tenant_id'=>$tid], $personId);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
} catch (InvalidArgumentException $e) { api_error($e->getMessage(),422); }
catch (PeopleDocumentConflict $e) { api_error($e->getMessage(),409); }
catch (Throwable $e) { error_log('People document request failed: '.$e->getMessage()); api_error('Document storage is unavailable.',503); }
