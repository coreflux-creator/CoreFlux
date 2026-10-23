<?php
/** Person-vault ownership and verified storage registration. No worker or approval authority. */
declare(strict_types=1);

class PeopleDocumentConflict extends RuntimeException {}
const PEOPLE_DOCUMENT_TYPES = ['resume','offer','i9','w4','w9','nda','contract','passport','visa','license','other'];

function peopleDocumentDirectory(PDO $pdo, int $operatingTenant, bool $lock = false): int {
    if ($operatingTenant <= 0 || ($lock && !$pdo->inTransaction())) throw new LogicException('Authorized workspace transaction required.');
    $suffix = $lock ? ' FOR UPDATE' : '';
    $s = $pdo->prepare('SELECT id,parent_id,tenant_type,is_active FROM tenants WHERE id=:t'.$suffix);
    $s->execute(['t'=>$operatingTenant]); $tenant=$s->fetch(PDO::FETCH_ASSOC);
    if (!$tenant || !(int)$tenant['is_active']) throw new PeopleDocumentConflict('Workspace is unavailable.');
    $s=$pdo->prepare("SELECT scope_mode FROM tenant_module_scope WHERE tenant_id=:t AND module_key='people'".$suffix);
    $s->execute(['t'=>$operatingTenant]); $mode=$s->fetchColumn();
    // People is a shared catalog by default; read the current policy, never the request cache.
    if ($mode === false) $mode=defined('SUBTENANT_MODULE_SCOPE_DEFAULTS') ? SUBTENANT_MODULE_SCOPE_DEFAULTS['people'] : 'shared';
    if (!in_array($mode,['shared','isolated'],true)) throw new PeopleDocumentConflict('People directory policy requires review.');
    $owner=$tenant['tenant_type']==='sub' && $tenant['parent_id'] && $mode==='shared' ? (int)$tenant['parent_id'] : $operatingTenant;
    if ($owner!==$operatingTenant) {
        $s=$pdo->prepare('SELECT id FROM tenants WHERE id=:t AND is_active=1'.$suffix); $s->execute(['t'=>$owner]);
        if (!$s->fetchColumn()) throw new PeopleDocumentConflict('People directory is unavailable.');
    }
    return $owner;
}

function peopleDocumentPerson(PDO $pdo, int $owner, int $person, bool $lock = false): void {
    if ($owner<=0 || $person<=0) throw new InvalidArgumentException('Choose an existing person.');
    $s=$pdo->prepare('SELECT id FROM people WHERE tenant_id=:t AND id=:p AND deleted_at IS NULL'.($lock?' FOR UPDATE':''));
    $s->execute(['t'=>$owner,'p'=>$person]);
    if (!$s->fetchColumn()) throw new PeopleDocumentConflict('Person is unavailable in this directory.');
}

function peopleDocumentKey(int $owner, int $person, $key): string {
    $prefix="people/{$owner}/document/{$person}/";
    if (!is_string($key) || !str_starts_with($key,$prefix) || strlen($key)>1024
        || count(explode('/',$key))!==5 || substr($key,strlen($prefix))===''
        || str_contains($key,'..') || str_contains($key,'\\') || preg_match('/[\x00-\x1f\x7f]/',$key)) {
        throw new PeopleDocumentConflict('Storage does not belong to this person.');
    }
    return $key;
}

function peopleDocumentObject(PDO $pdo, int $owner, int $person, int $objectId, bool $lock=false): array {
    $s=$pdo->prepare('SELECT * FROM storage_objects WHERE tenant_id=:t AND id=:id AND soft_deleted_at IS NULL'.($lock?' FOR UPDATE':''));
    $s->execute(['t'=>$owner,'id'=>$objectId]); $object=$s->fetch(PDO::FETCH_ASSOC);
    if (!$object || $object['module']!=='people' || $object['entity_type']!=='document' || (string)$object['entity_id']!==(string)$person) {
        throw new PeopleDocumentConflict('Storage does not belong to this person.');
    }
    peopleDocumentKey($owner,$person,$object['s3_key']);
    return $object;
}

function peopleDocumentHead(callable $head, string $key, ?int $maxBytes=25*1024*1024): array {
    $object=$head($key);
    if (!is_array($object) || !is_string($object['etag']??null) || trim($object['etag'])===''
        || !is_int($object['size_bytes']??null) || $object['size_bytes']<1 || ($maxBytes!==null && $object['size_bytes']>$maxBytes)) {
        throw new PeopleDocumentConflict('The uploaded file is missing, incomplete or exceeds 25 MB.');
    }
    return $object;
}

function peopleDocumentMetadata(array $body, bool $create=false): array {
    $allowed=['doc_type','file_name','signed','signed_at','expires_at'];
    if ($create) $allowed=array_merge($allowed,['storage_object_id','storage_key','filename','mime','size_bytes']);
    if (array_diff(array_keys($body),$allowed)) throw new InvalidArgumentException('Unsupported document fields.');
    $result=array_intersect_key($body,array_flip(['doc_type','file_name','signed','signed_at','expires_at']));
    if ($create) $result+=['doc_type'=>null,'file_name'=>$body['filename']??null,'signed'=>0,'signed_at'=>null,'expires_at'=>null];
    if (!$result) throw new InvalidArgumentException('Choose document metadata to update.');
    if (array_key_exists('doc_type',$result) && !in_array($result['doc_type'],PEOPLE_DOCUMENT_TYPES,true)) throw new InvalidArgumentException('Invalid document type.');
    if (isset($result['file_name']) && (!is_string($result['file_name']) || trim($result['file_name'])==='' || strlen($result['file_name'])>255 || preg_match('/[\x00-\x1f\x7f]/',$result['file_name']))) throw new InvalidArgumentException('Use a valid document filename.');
    if (array_key_exists('signed',$result)) {
        if (!in_array($result['signed'],[true,false,0,1],true)) throw new InvalidArgumentException('Signed must be a boolean.');
        $result['signed']=(int)$result['signed'];
    }
    foreach (['signed_at','expires_at'] as $key) if (array_key_exists($key,$result)) {
        $value=$result[$key];
        if ($value===null || $value==='') { $result[$key]=null; continue; }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/D',$value)) throw new InvalidArgumentException('Use a valid document date.');
        $date=DateTimeImmutable::createFromFormat('!'.(strlen($value)===10?'Y-m-d':'Y-m-d H:i:s'),$value,new DateTimeZone('UTC'));
        if (!$date || $date->format(strlen($value)===10?'Y-m-d':'Y-m-d H:i:s')!==$value) throw new InvalidArgumentException('Use a valid document date.');
        $result[$key]=$date->format('Y-m-d H:i:s');
    }
    return $result;
}

function peopleDocumentPublic(array $row): array {
    return array_intersect_key($row,array_flip(['id','person_id','doc_type','storage_object_id','file_name','signed','signed_at','expires_at','uploaded_by_user_id','created_at']));
}

/** Person lock serializes replay; registry, document and resume pointer commit together. */
function peopleDocumentRegister(PDO $pdo, array $scope, int $person, array $body, callable $head): array {
    if ($pdo->inTransaction()) throw new LogicException('Document registration owns its transaction.');
    $metadata=peopleDocumentMetadata($body,true);
    $pdo->beginTransaction();
    try {
        $owner=peopleDocumentDirectory($pdo,$scope['tenant_id'],true);
        if ($owner!==$scope['people_tenant_id']) throw new PeopleDocumentConflict('People directory changed. Reload before uploading.');
        peopleDocumentPerson($pdo,$owner,$person,true);
        $objectId=$body['storage_object_id']??null;
        if ($objectId!==null && (!is_int($objectId) || $objectId<=0)) throw new InvalidArgumentException('Use a valid storage object identifier.');
        if ($objectId) {
            $object=peopleDocumentObject($pdo,$owner,$person,$objectId,true); $key=$object['s3_key'];
            if (isset($body['storage_key']) && $body['storage_key']!==$key) throw new PeopleDocumentConflict('Storage identifiers disagree.');
        } else {
            $key=peopleDocumentKey($owner,$person,$body['storage_key']??null);
            $s=$pdo->prepare('SELECT id FROM storage_objects WHERE tenant_id=:t AND s3_key=:k FOR UPDATE'); $s->execute(['t'=>$owner,'k'=>$key]);
            $objectId=(int)($s->fetchColumn()?:0);
            $object=$objectId ? peopleDocumentObject($pdo,$owner,$person,$objectId,true) : null;
        }
        $blob=peopleDocumentHead($head,$key);
        $metadata['file_name']??=$object['filename']??null;
        if (!$metadata['file_name']) throw new InvalidArgumentException('A document filename is required.');
        if (!$objectId) {
            $mime=$blob['mime']??null;
            if (!is_string($mime) || strlen($mime)>120 || !preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~iD',$mime)) $mime='application/octet-stream';
            $s=$pdo->prepare("INSERT INTO storage_objects(tenant_id,module,entity_type,entity_id,s3_key,filename,mime,size_bytes,created_by_user_id,created_at) VALUES(:t,'people','document',:p,:k,:f,:m,:s,:a,NOW())");
            $s->execute(['t'=>$owner,'p'=>(string)$person,'k'=>$key,'f'=>$metadata['file_name'],'m'=>$mime,'s'=>$blob['size_bytes'],'a'=>$scope['actor_user_id']]);
            $objectId=(int)$pdo->lastInsertId();
        }
        $s=$pdo->prepare('SELECT * FROM people_documents WHERE tenant_id=:t AND storage_object_id=:o FOR UPDATE');
        $s->execute(['t'=>$owner,'o'=>$objectId]); $existing=$s->fetchAll(PDO::FETCH_ASSOC);
        if ($existing) {
            $row=$existing[0];
            if (count($existing)!==1 || (int)$row['person_id']!==$person || $row['deleted_at']!==null) throw new PeopleDocumentConflict('This file already has another or retired document registration.');
            foreach ($metadata as $keyName=>$value) if ((string)($row[$keyName]??'')!==(string)($value??'')) throw new PeopleDocumentConflict('This file is already registered with different metadata. Edit the existing document.');
            $pdo->commit(); return ['id'=>(int)$row['id'],'storage_object_id'=>$objectId,'replayed'=>true];
        }
        $s=$pdo->prepare('INSERT INTO people_documents(tenant_id,person_id,doc_type,storage_object_id,file_name,signed,signed_at,expires_at,uploaded_by_user_id) VALUES(:t,:p,:doc_type,:o,:file_name,:signed,:signed_at,:expires_at,:a)');
        $s->execute(['t'=>$owner,'p'=>$person,'o'=>$objectId,'a'=>$scope['actor_user_id']]+$metadata); $id=(int)$pdo->lastInsertId();
        if ($metadata['doc_type']==='resume') {
            $s=$pdo->prepare('UPDATE people SET resume_storage_object_id=:o WHERE tenant_id=:t AND id=:p');
            $s->execute(['o'=>$objectId,'t'=>$owner,'p'=>$person]);
        }
        $pdo->commit(); return ['id'=>$id,'storage_object_id'=>$objectId,'replayed'=>false];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function peopleDocumentMutate(PDO $pdo, array $scope, int $id, ?array $body): int {
    if ($pdo->inTransaction()) throw new LogicException('Document metadata owns its transaction.');
    $metadata=$body===null ? null : peopleDocumentMetadata($body);
    $pdo->beginTransaction();
    try {
        $owner=peopleDocumentDirectory($pdo,$scope['tenant_id'],true);
        if ($owner!==$scope['people_tenant_id']) throw new PeopleDocumentConflict('People directory changed. Reload the document.');
        $s=$pdo->prepare('SELECT * FROM people_documents WHERE tenant_id=:t AND id=:id AND deleted_at IS NULL FOR UPDATE');
        $s->execute(['t'=>$owner,'id'=>$id]); $row=$s->fetch(PDO::FETCH_ASSOC);
        if (!$row || !(int)$row['person_id']) throw new PeopleDocumentConflict('Document is unavailable in this directory.');
        $person=(int)$row['person_id']; peopleDocumentPerson($pdo,$owner,$person,true);
        if ($metadata===null) {
            $s=$pdo->prepare('UPDATE people_documents SET deleted_at=NOW() WHERE tenant_id=:t AND id=:id');
            $s->execute(['t'=>$owner,'id'=>$id]);
        } else {
            $set=implode(',',array_map(static fn($key)=>$key.'=:'.$key,array_keys($metadata)));
            $s=$pdo->prepare('UPDATE people_documents SET '.$set.' WHERE tenant_id=:_t AND id=:_id');
            $s->execute($metadata+['_t'=>$owner,'_id'=>$id]);
        }
        if ($metadata!==null && ($metadata['doc_type']??null)==='resume') {
            peopleDocumentObject($pdo,$owner,$person,(int)$row['storage_object_id'],true);
            $s=$pdo->prepare('UPDATE people SET resume_storage_object_id=:o WHERE tenant_id=:t AND id=:p');
            $s->execute(['o'=>$row['storage_object_id'],'t'=>$owner,'p'=>$person]);
        } elseif ($metadata===null || (isset($metadata['doc_type']) && $metadata['doc_type']!=='resume')) {
            $s=$pdo->prepare('UPDATE people SET resume_storage_object_id=NULL WHERE tenant_id=:t AND id=:p AND resume_storage_object_id=:o');
            $s->execute(['o'=>$row['storage_object_id'],'t'=>$owner,'p'=>$person]);
        }
        $pdo->commit(); return $person;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
