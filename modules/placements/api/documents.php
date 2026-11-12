<?php
/** Placement-private uploads, downloads and retained evidence. */
require_once __DIR__.'/../../../core/api_bootstrap.php';
require_once __DIR__.'/../../../core/RBAC.php';
require_once __DIR__.'/../../../core/StorageService.php';
require_once __DIR__.'/../lib/placements.php';
require_once __DIR__.'/../lib/ownership.php';
require_once __DIR__.'/../lib/documents.php';

use Core\StorageService;

$ctx=api_require_auth(); $method=api_method(); $action=$_GET['action']??'';
rbac_legacy_require($ctx['user'],$method==='GET'&&$action!=='upload_url'?'placements.docs.view':'placements.docs.manage');
header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
try {
    $pdo=getDB(); $tenant=(int)$ctx['tenant_id']; $owner=placementDocumentDirectory($pdo,$tenant);
    $scope=['tenant_id'=>$tenant,'placements_tenant_id'=>$owner,'actor_user_id'=>(int)$ctx['user']['id']];
    $placement=(int)api_query('placement_id',0); $id=(int)api_query('id',0);
    $head=static fn(string $key):?array=>StorageService::getInstance()->head($key);
    if ($method==='GET' && $action==='upload_url') {
        placementDocumentPlacement($pdo,$owner,$placement);
        $metadata=placementDocumentMetadata(['doc_type'=>'other','file_name'=>api_query('file_name','document')]);
        $storage=StorageService::getInstance();
        if ($storage->driver_name()==='local') api_error('Private document storage is not configured.',503);
        $key=$storage->build_key('placements',$owner,'document',$placement,$metadata['file_name']);
        placementDocumentKey($owner,$placement,$key);
        $post=$storage->get_presigned_post($key,['policy'=>[['content-length-range',1,26214400]]]);
        placementDocumentScope($pdo,$scope);
        api_ok(['storage_key'=>$key,'upload'=>['url'=>$post['form_action'],'fields'=>$post['fields']]]);
    }
    if ($method==='GET' && $id>0) {
        $document=placementDocumentGet($pdo,$scope,$id);
        $object=placementDocumentObject($pdo,$owner,(int)$document['placement_id'],(int)$document['storage_object_id']);
        placementDocumentVerifyObject($object,$head,null); placementDocumentScope($pdo,$scope);
        api_ok(['document'=>placementDocumentPublic($document),'signed_url'=>StorageService::getInstance()->get_signed_url($object['s3_key'])]);
    }
    if ($method==='GET') {
        placementDocumentPlacement($pdo,$owner,$placement);
        $q=$pdo->prepare('SELECT * FROM placement_documents WHERE tenant_id=? AND placement_id=? AND deleted_at IS NULL ORDER BY id');
        $q->execute([$owner,$placement]); api_ok(['documents'=>array_map('placementDocumentPublic',$q->fetchAll(PDO::FETCH_ASSOC))]);
    }
    $audit=static function(string $event,int $id,array $meta):void { placementRequiredAudit($event,$meta,$id); };
    if ($method==='POST') {
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')) api_error('Use a JSON document request.',415);
        $result=placementDocumentRegister($pdo,$scope,$placement,api_json_body(),$head,$audit);
        api_ok($result,$result['replayed']?200:201);
    }
    if ($method==='DELETE') {
        if ($id<=0) api_error('id required',400);
        api_ok(placementDocumentRetire($pdo,$scope,$id,$audit));
    }
    api_error('Method not allowed',405);
} catch (InvalidArgumentException $e) { api_error($e->getMessage(),422); }
catch (PlacementDocumentConflict $e) { api_error($e->getMessage(),409); }
catch (Throwable $e) { error_log('Placement document request failed: '.get_class($e)); api_error('Document storage is unavailable. Please try again.',503); }
