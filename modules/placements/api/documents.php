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
rbac_legacy_require($ctx['user'],$method==='GET'&&!in_array($action,['upload_url','upload_status'],true)?'placements.docs.view':'placements.docs.manage');
header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
try {
    $pdo=getDB(); $tenant=(int)$ctx['tenant_id']; $owner=placementDocumentDirectory($pdo,$tenant);
    $scope=['tenant_id'=>$tenant,'placements_tenant_id'=>$owner,'actor_user_id'=>(int)$ctx['user']['id']];
    $placement=(int)api_query('placement_id',0); $id=(int)api_query('id',0);
    $head=static fn(string $key):?array=>StorageService::getInstance()->head($key);
    $context=$scope+['placement_id'=>$placement,'permissions'=>['manage'=>rbac_legacy_can($ctx['user'],'placements.docs.manage')]];
    if ($method==='GET' && $action==='upload_status') {
        placementDocumentPlacement($pdo,$owner,$placement);
        $key=placementDocumentKey($owner,$placement,api_query('storage_key'));
        $physical=$head($key);
        if ($physical!==null) placementDocumentHead(static fn()=>$physical,$key);
        placementDocumentScope($pdo,$scope);
        api_ok(['present'=>$physical!==null,'context'=>$context]);
    }
    if ($method==='GET' && $action==='upload_url') {
        placementDocumentPlacement($pdo,$owner,$placement);
        $metadata=placementDocumentMetadata(['doc_type'=>'other','file_name'=>api_query('file_name','document')]);
        $storage=StorageService::getInstance();
        if ($storage->driver_name()==='local') api_error('Private document storage is not configured.',503);
        $key=api_query('storage_key') ?: $storage->build_key('placements',$owner,'document',$placement,$metadata['file_name']);
        placementDocumentKey($owner,$placement,$key);
        // Renew only an absent, never-registered upload. Never overwrite retained evidence.
        $q=$pdo->prepare('SELECT id FROM storage_objects WHERE tenant_id=? AND s3_key=?'); $q->execute([$owner,$key]);
        if ($q->fetchColumn()!==false || $head($key)!==null) throw new PlacementDocumentConflict('This file already exists. Check the original upload before retrying.');
        $post=$storage->get_presigned_post($key,['policy'=>[['content-length-range',1,26214400]]]);
        placementDocumentScope($pdo,$scope);
        api_ok(['storage_key'=>$key,'upload'=>['url'=>$post['form_action'],'fields'=>$post['fields']],'context'=>$context]);
    }
    if ($method==='GET' && $id>0) {
        $result=JobdivaStoredReviewTransaction::run(static function()use($pdo,$scope,$id,$placement,$head):array{
            $owner=placementDocumentScope($pdo,$scope,true);
            $document=placementDocumentGet($pdo,$scope,$id,true);
            if ($placement>0 && (int)$document['placement_id']!==$placement) throw new PlacementDocumentConflict('Document belongs to a different placement.');
            $object=placementDocumentObject($pdo,$owner,(int)$document['placement_id'],(int)$document['storage_object_id'],true);
            placementDocumentVerifyObject($object,$head,null);
            return ['document'=>placementDocumentPublic($document),'signed_url'=>StorageService::getInstance()->get_signed_url($object['s3_key'])];
        },true);
        api_ok($result);
    }
    if ($method==='GET') {
        $result=JobdivaStoredReviewTransaction::run(static function()use($pdo,$scope,$placement,$context):array{
            $owner=placementDocumentScope($pdo,$scope,true);
            $identity=placementDocumentReadablePlacement($pdo,$owner,$placement);
            $q=$pdo->prepare('SELECT * FROM placement_documents WHERE tenant_id=? AND placement_id=? AND deleted_at IS NULL ORDER BY id FOR UPDATE');
            $q->execute([$owner,$placement]); $documents=array_map('placementDocumentPublic',$q->fetchAll(PDO::FETCH_ASSOC));
            if ($identity['merged']) $context['permissions']['manage']=false;
            return ['documents'=>$documents,'context'=>$context];
        },true);
        api_ok($result);
    }
    $audit=static function(string $event,int $id,array $meta):void { placementRequiredAudit($event,$meta,$id); };
    if ($method==='POST') {
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')) api_error('Use a JSON document request.',415);
        $result=placementDocumentRegister($pdo,$scope,$placement,api_json_body(),$head,$audit);
        $result['document']=placementDocumentPublic(placementDocumentGet($pdo,$scope,$result['id']));
        api_ok($result+['context'=>$context],$result['replayed']?200:201);
    }
    if ($method==='DELETE') {
        if ($id<=0) api_error('id required',400);
        api_ok(placementDocumentRetire($pdo,$scope,$id,$audit));
    }
    api_error('Method not allowed',405);
} catch (InvalidArgumentException $e) { api_error($e->getMessage(),422); }
catch (PlacementDocumentConflict $e) { api_error($e->getMessage(),409); }
catch (Throwable $e) { error_log('Placement document request failed: '.get_class($e)); api_error('Document storage is unavailable. Please try again.',503); }
