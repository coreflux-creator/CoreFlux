<?php
/** Placement-private documents. Authorization is enforced by the calling route. */
declare(strict_types=1);

class PlacementDocumentConflict extends RuntimeException {}
const PLACEMENT_DOCUMENT_TYPES = ['msa','sow','work_order','rate_sheet','timesheet_template','poc','noc','other'];

function placementDocumentDirectory(PDO $pdo, int $tenantId, bool $lock=false): int
{
    if ($tenantId<=0 || ($lock && !$pdo->inTransaction())) throw new LogicException('Authorized workspace transaction required.');
    $suffix=$lock?' FOR UPDATE':'';
    $q=$pdo->prepare('SELECT id,parent_id,tenant_type,is_active FROM tenants WHERE id=?'.$suffix);
    $q->execute([$tenantId]); $tenant=$q->fetch(PDO::FETCH_ASSOC);
    if (!$tenant || !(int)$tenant['is_active']) throw new PlacementDocumentConflict('Workspace is unavailable.');
    $q=$pdo->prepare("SELECT scope_mode FROM tenant_module_scope WHERE tenant_id=? AND module_key='placements'".$suffix);
    $q->execute([$tenantId]); $mode=$q->fetchColumn();
    if ($mode===false) $mode=defined('SUBTENANT_MODULE_SCOPE_DEFAULTS') ? SUBTENANT_MODULE_SCOPE_DEFAULTS['placements'] : 'shared';
    if (!in_array($mode,['shared','isolated'],true)) throw new PlacementDocumentConflict('Placement directory policy requires review.');
    $owner=$tenant['tenant_type']==='sub' && $tenant['parent_id'] && $mode==='shared' ? (int)$tenant['parent_id'] : $tenantId;
    if ($owner!==$tenantId) {
        $q=$pdo->prepare('SELECT id FROM tenants WHERE id=? AND is_active=1'.$suffix); $q->execute([$owner]);
        if (!$q->fetchColumn()) throw new PlacementDocumentConflict('Placement directory is unavailable.');
    }
    return $owner;
}

function placementDocumentScope(PDO $pdo, array $scope, bool $lock=false): int
{
    $owner=placementDocumentDirectory($pdo,(int)$scope['tenant_id'],$lock);
    if ($owner!==(int)$scope['placements_tenant_id']) throw new PlacementDocumentConflict('Placement directory changed. Reload the placement.');
    return $owner;
}

function placementDocumentPlacement(PDO $pdo, int $owner, int $placement, bool $lock=false): void
{
    if ($owner<=0 || $placement<=0) throw new InvalidArgumentException('Choose an existing placement.');
    $q=$pdo->prepare('SELECT id FROM placements WHERE tenant_id=? AND id=? AND deleted_at IS NULL'.($lock?' FOR UPDATE':''));
    $q->execute([$owner,$placement]);
    if (!$q->fetchColumn()) throw new PlacementDocumentConflict('Placement is unavailable in this directory.');
}

function placementDocumentKey(int $owner, int $placement, mixed $key): string
{
    $prefix="placements/{$owner}/document/{$placement}/";
    if ($owner<=0 || $placement<=0 || !is_string($key) || !str_starts_with($key,$prefix) || strlen($key)>1024
        || count(explode('/',$key))!==5 || substr($key,strlen($prefix))===''
        || str_contains($key,'..') || str_contains($key,'\\') || preg_match('/[\x00-\x1f\x7f]/',$key)) {
        throw new PlacementDocumentConflict('Storage does not belong to this placement.');
    }
    return $key;
}

function placementDocumentObject(PDO $pdo, int $owner, int $placement, int $objectId, bool $lock=false): array
{
    $q=$pdo->prepare('SELECT * FROM storage_objects WHERE tenant_id=? AND id=? AND soft_deleted_at IS NULL'.($lock?' FOR UPDATE':''));
    $q->execute([$owner,$objectId]); $object=$q->fetch(PDO::FETCH_ASSOC);
    if (!$object || $object['module']!=='placements' || $object['entity_type']!=='document' || (string)$object['entity_id']!==(string)$placement) {
        throw new PlacementDocumentConflict('Stored file is unavailable for this placement.');
    }
    placementDocumentKey($owner,$placement,$object['s3_key']);
    return $object;
}

function placementDocumentHead(callable $head, string $key, ?int $maxBytes=26214400): array
{
    $object=$head($key);
    if (!is_array($object) || !is_int($object['size_bytes']??null) || $object['size_bytes']<=0
        || ($maxBytes!==null && $object['size_bytes']>$maxBytes) || !is_string($object['etag']??null) || $object['etag']==='') {
        throw new PlacementDocumentConflict('Upload is missing, empty, or exceeds the file size limit.');
    }
    return $object;
}

function placementDocumentMetadata(array $body): array
{
    $allowed=['doc_type','file_name','effective_from','effective_to','storage_object_id','storage_key','filename','mime','size_bytes'];
    if (array_diff(array_keys($body),$allowed)) throw new InvalidArgumentException('Unsupported document fields.');
    if (!in_array($body['doc_type']??null,PLACEMENT_DOCUMENT_TYPES,true)) throw new InvalidArgumentException('Choose a valid document type.');
    $name=$body['file_name']??$body['filename']??null;
    if ($name!==null && (!is_string($name) || trim($name)===''
        || preg_match('/\A[^\x00-\x1f\x7f]{1,255}\z/u',$name)!==1)) throw new InvalidArgumentException('Enter a valid file name.');
    $result=['doc_type'=>$body['doc_type'],'file_name'=>$name];
    foreach (['effective_from','effective_to'] as $field) {
        $date=$body[$field]??null;
        if ($date==='') $date=null;
        if ($date!==null && (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$date,$m)
            || !checkdate((int)$m[2],(int)$m[3],(int)$m[1]))) throw new InvalidArgumentException('Enter a valid effective date.');
        $result[$field]=$date;
    }
    if ($result['effective_from']!==null && $result['effective_to']!==null && $result['effective_to']<$result['effective_from']) {
        throw new InvalidArgumentException('The effective end cannot precede the start.');
    }
    return $result;
}

function placementDocumentVerifyObject(array $object, callable $head, ?int $maxBytes=26214400): array
{
    $physical=placementDocumentHead($head,$object['s3_key'],$maxBytes);
    if (is_string($object['etag']??null) && $object['etag']!==''
        && ($physical['etag']!==$object['etag'] || (int)$object['size_bytes']!==$physical['size_bytes'])) {
        throw new PlacementDocumentConflict('Stored file changed after registration. Upload it as a new document for review.');
    }
    if (is_string($object['s3_version_id']??null) && $object['s3_version_id']!=='' && ($physical['version_id']??null)!==$object['s3_version_id']) {
        throw new PlacementDocumentConflict('Stored file version changed after registration. Review the document.');
    }
    return $physical;
}

function placementDocumentPublic(array $row): array
{
    return array_intersect_key($row,array_flip(['id','placement_id','doc_type','storage_object_id','file_name','effective_from','effective_to','uploaded_by_user_id','created_at']));
}

function placementDocumentGet(PDO $pdo, array $scope, int $id): array
{
    $owner=placementDocumentScope($pdo,$scope);
    $q=$pdo->prepare('SELECT * FROM placement_documents WHERE tenant_id=? AND id=? AND deleted_at IS NULL');
    $q->execute([$owner,$id]); $row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new PlacementDocumentConflict('Document is unavailable in this directory.');
    placementDocumentPlacement($pdo,$owner,(int)$row['placement_id']);
    return $row;
}

/** A locked placement serializes registration and retirement, including lost-response retries. */
function placementDocumentRegister(PDO $pdo, array $scope, int $placement, array $body, callable $head, callable $audit): array
{
    if ($pdo->inTransaction() || (int)($scope['actor_user_id']??0)<=0) throw new LogicException('Document registration requires its own authenticated transaction.');
    $metadata=placementDocumentMetadata($body);
    $pdo->beginTransaction();
    try {
        $owner=placementDocumentScope($pdo,$scope,true);
        placementDocumentPlacement($pdo,$owner,$placement,true);
        $objectId=$body['storage_object_id']??null; $object=null;
        if ($objectId!==null) {
            if (!is_int($objectId) || $objectId<=0) throw new InvalidArgumentException('Choose a valid stored file.');
            $object=placementDocumentObject($pdo,$owner,$placement,$objectId,true); $key=$object['s3_key'];
            if (isset($body['storage_key']) && $body['storage_key']!==$key) throw new PlacementDocumentConflict('Stored file and upload key do not agree.');
        } else {
            $key=placementDocumentKey($owner,$placement,$body['storage_key']??null);
            $q=$pdo->prepare('SELECT id FROM storage_objects WHERE s3_key=? FOR UPDATE'); $q->execute([$key]);
            $existing=$q->fetchColumn();
            if ($existing!==false) {
                $objectId=(int)$existing; $object=placementDocumentObject($pdo,$owner,$placement,$objectId,true);
                if ($object['s3_key']!==$key) throw new PlacementDocumentConflict('Storage key must match the registered file exactly.');
            }
        }
        $physical=$object===null?placementDocumentHead($head,$key):placementDocumentVerifyObject($object,$head);
        $metadata=placementDocumentMetadata(array_replace($metadata,['file_name'=>$metadata['file_name']??($object['filename']??null)]));
        if ($metadata['file_name']===null) throw new InvalidArgumentException('Enter a file name.');
        if ($object===null) {
            $mime=$physical['mime']??null;
            if (!is_string($mime) || strlen($mime)>120 || preg_match('/[\x00-\x1f\x7f]/',$mime)) $mime='application/octet-stream';
            $q=$pdo->prepare("INSERT INTO storage_objects(tenant_id,module,entity_type,entity_id,s3_key,s3_version_id,filename,mime,size_bytes,etag,created_by_user_id,created_at) VALUES(?,'placements','document',?,?,?,?,?,?,?,?,NOW())");
            $q->execute([$owner,(string)$placement,$key,$physical['version_id']??null,$metadata['file_name'],$mime,$physical['size_bytes'],$physical['etag'],$scope['actor_user_id']]);
            $objectId=(int)$pdo->lastInsertId();
        }
        $q=$pdo->prepare('SELECT * FROM placement_documents WHERE tenant_id=? AND storage_object_id=? FOR UPDATE');
        $q->execute([$owner,$objectId]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $row=$rows[0];
            if (count($rows)!==1 || $row['deleted_at']!==null || (int)$row['placement_id']!==$placement
                || array_intersect_key($row,$metadata)!==$metadata) throw new PlacementDocumentConflict('This file already has a different or retired document binding. Review it before uploading again.');
            $pdo->commit(); return ['id'=>(int)$row['id'],'storage_object_id'=>$objectId,'replayed'=>true];
        }
        $q=$pdo->prepare('INSERT INTO placement_documents(tenant_id,placement_id,doc_type,storage_object_id,file_name,effective_from,effective_to,uploaded_by_user_id) VALUES(?,?,?,?,?,?,?,?)');
        $q->execute([$owner,$placement,$metadata['doc_type'],$objectId,$metadata['file_name'],$metadata['effective_from'],$metadata['effective_to'],$scope['actor_user_id']]);
        $id=(int)$pdo->lastInsertId();
        $audit('placement.document.uploaded',$id,['placement_id'=>$placement,'document_id'=>$id,'storage_object_id'=>$objectId]+$metadata);
        $pdo->commit(); return ['id'=>$id,'storage_object_id'=>$objectId,'replayed'=>false];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function placementDocumentRetire(PDO $pdo, array $scope, int $id, callable $audit): array
{
    if ($pdo->inTransaction() || (int)($scope['actor_user_id']??0)<=0) throw new LogicException('Document retirement requires its own authenticated transaction.');
    $pdo->beginTransaction();
    try {
        $owner=placementDocumentScope($pdo,$scope,true);
        $q=$pdo->prepare('SELECT placement_id FROM placement_documents WHERE tenant_id=? AND id=?');
        $q->execute([$owner,$id]); $placement=(int)$q->fetchColumn();
        if (!$placement) throw new PlacementDocumentConflict('Document is unavailable in this directory.');
        placementDocumentPlacement($pdo,$owner,$placement,true);
        $q=$pdo->prepare('SELECT * FROM placement_documents WHERE tenant_id=? AND id=? FOR UPDATE');
        $q->execute([$owner,$id]); $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['placement_id']!==$placement) throw new PlacementDocumentConflict('Document ownership changed. Reload the placement.');
        if ($row['deleted_at']!==null) { $pdo->commit(); return ['ok'=>true,'replayed'=>true]; }
        $q=$pdo->prepare('UPDATE placement_documents SET deleted_at=NOW() WHERE tenant_id=? AND id=?'); $q->execute([$owner,$id]);
        $audit('placement.document.deleted',$id,['placement_id'=>$placement,'document_id'=>$id,'storage_object_id'=>(int)$row['storage_object_id']]);
        $pdo->commit(); return ['ok'=>true,'replayed'=>false];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
