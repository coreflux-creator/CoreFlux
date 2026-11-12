<?php
/** Exact document lineage for authorized merges. Never rewrites or copies object bytes. */
declare(strict_types=1);

class DocumentOwnershipConflict extends RuntimeException {}

function documentOwnershipDefinition(string $module): array
{
    return match($module) {
        'people'=>['people_documents','person_id','people'],
        'placements'=>['placement_documents','placement_id','placements'],
        default=>throw new InvalidArgumentException('Unsupported document directory.'),
    };
}

function documentOwnershipObjectHash(array $object): string
{
    $identity=[];
    foreach(['id','tenant_id','module','entity_type','entity_id','s3_key','s3_version_id','etag','size_bytes'] as $field) {
        $identity[$field]=isset($object[$field])?(string)$object[$field]:null;
    }
    return hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}

function documentOwnershipReceiptHash(array $row): string
{
    $identity=[];
    foreach(['tenant_id','operating_tenant_id','module','document_id','storage_object_id','sequence_no','from_record_id','to_record_id','object_sha256','previous_sha256','actor_user_id','source','created_at'] as $field) {
        $identity[$field]=isset($row[$field])?(string)$row[$field]:null;
    }
    return hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}

/** Caller validates the original canonical key and authorizes the current directory. */
function documentOwnershipBinding(PDO $pdo,string $module,int $owner,int $record,array $object,bool $lock=false,bool $allowRetired=false): array
{
    [$table,$field]=documentOwnershipDefinition($module);
    if ($owner<=0 || $record<=0 || ($lock&&!$pdo->inTransaction())
        || (int)$object['tenant_id']!==$owner || $object['module']!==$module || $object['entity_type']!=='document'
        || !preg_match('/^[1-9][0-9]*$/D',(string)$object['entity_id']) || !empty($object['soft_deleted_at'])) {
        throw new DocumentOwnershipConflict('Stored file ownership is unavailable.');
    }
    $suffix=$lock?' FOR UPDATE':'';
    $q=$pdo->prepare("SELECT * FROM {$table} WHERE tenant_id=? AND storage_object_id=? ORDER BY id".$suffix);
    $q->execute([$owner,$object['id']]);$documents=$q->fetchAll(PDO::FETCH_ASSOC);
    if (!$documents) {
        if ((string)$object['entity_id']!==(string)$record) throw new DocumentOwnershipConflict('File has no reviewed document transfer.');
        return ['document'=>null,'history'=>[]]; // first registration, still at its original owner
    }
    if (count($documents)!==1 || (int)$documents[0][$field]!==$record || (!$allowRetired&&$documents[0]['deleted_at']!==null)) {
        throw new DocumentOwnershipConflict('File has a different, ambiguous or retired document binding.');
    }
    $document=$documents[0];
    $q=$pdo->prepare('SELECT * FROM document_ownership_transfers WHERE tenant_id=? AND module=? AND document_id=? ORDER BY sequence_no'.$suffix);
    $q->execute([$owner,$module,$document['id']]);$history=$q->fetchAll(PDO::FETCH_ASSOC);
    $current=(int)$object['entity_id'];$seen=[$current=>true];$previous=null;$sequence=0;$objectHash=documentOwnershipObjectHash($object);
    foreach($history as $row) {
        $to=(int)$row['to_record_id'];
        if ((int)$row['sequence_no']!==++$sequence || (int)$row['storage_object_id']!==(int)$object['id']
            || (int)$row['from_record_id']!==$current || $to<=0 || isset($seen[$to])
            || $row['previous_sha256']!==$previous || !hash_equals($objectHash,$row['object_sha256'])
            || !hash_equals(documentOwnershipReceiptHash($row),$row['receipt_sha256'])) {
            throw new DocumentOwnershipConflict('Document transfer history requires review.');
        }
        $current=$to;$seen[$to]=true;$previous=$row['receipt_sha256'];
    }
    if ($current!==$record) throw new DocumentOwnershipConflict('File has no reviewed transfer to this record.');
    return ['document'=>$document,'history'=>$history];
}

/** Caller owns the enclosing merge transaction; its rollback includes all transfers. */
function documentOwnershipTransfer(PDO $pdo,array $scope,string $module,int $from,int $to,string $source): int
{
    [$table,$field,$records]=documentOwnershipDefinition($module);
    if (!$pdo->inTransaction() || $from<=0 || $to<=0 || $from===$to
        || !in_array($source,['people.merge','jobdiva.placement_consolidation'],true)
        || ($module==='people'&&($source!=='people.merge'||(int)($scope['actor_user_id']??0)<=0))
        || ($module==='placements'&&$source!=='jobdiva.placement_consolidation')) {
        throw new LogicException('An authorized merge transaction is required.');
    }
    $operating=(int)$scope['tenant_id'];
    $owner=$module==='people'?peopleDocumentDirectory($pdo,$operating,true):placementDocumentDirectory($pdo,$operating,true);
    if ($owner!==(int)$scope[$module.'_tenant_id']) throw new DocumentOwnershipConflict('Document directory changed. Reload the merge.');
    $q=$pdo->prepare("SELECT id FROM {$records} WHERE tenant_id=? AND id IN (?,?) AND deleted_at IS NULL ORDER BY id FOR UPDATE");
    $q->execute([$owner,$from,$to]);
    if (count($q->fetchAll(PDO::FETCH_COLUMN))!==2) throw new DocumentOwnershipConflict('Both merge records must be active in the same directory.');
    $q=$pdo->prepare("SELECT * FROM {$table} WHERE tenant_id=? AND {$field}=? ORDER BY id FOR UPDATE");
    $q->execute([$owner,$from]);$documents=$q->fetchAll(PDO::FETCH_ASSOC);$moved=0;
    foreach($documents as $document) {
        $q=$pdo->prepare('SELECT * FROM storage_objects WHERE tenant_id=? AND id=? FOR UPDATE');
        $q->execute([$owner,$document['storage_object_id']]);$object=$q->fetch(PDO::FETCH_ASSOC);
        if (!$object) throw new DocumentOwnershipConflict('A document lacks verified storage. Reconcile it before merging.');
        if ($module==='people') peopleDocumentKey($owner,(int)$object['entity_id'],$object['s3_key']);
        else placementDocumentKey($owner,(int)$object['entity_id'],$object['s3_key']);
        $binding=documentOwnershipBinding($pdo,$module,$owner,$from,$object,true,true);
        if ((int)$binding['document']['id']!==(int)$document['id']) throw new DocumentOwnershipConflict('Document binding changed.');
        $history=$binding['history'];
        if ((int)$object['entity_id']===$to || in_array($to,array_map(static fn($r)=>(int)$r['to_record_id'],$history),true)) {
            throw new DocumentOwnershipConflict('A document ownership transfer cannot return to a former owner.');
        }
        $row=['tenant_id'=>$owner,'operating_tenant_id'=>$operating,'module'=>$module,'document_id'=>(int)$document['id'],
            'storage_object_id'=>(int)$object['id'],'sequence_no'=>count($history)+1,'from_record_id'=>$from,'to_record_id'=>$to,
            'object_sha256'=>documentOwnershipObjectHash($object),'previous_sha256'=>$history?end($history)['receipt_sha256']:null,
            'actor_user_id'=>$scope['actor_user_id']??null,'source'=>$source,'created_at'=>gmdate('Y-m-d H:i:s')];
        $row['receipt_sha256']=documentOwnershipReceiptHash($row);
        $columns=array_keys($row);
        $q=$pdo->prepare('INSERT INTO document_ownership_transfers ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
        $q->execute(array_values($row));
        $q=$pdo->prepare("UPDATE {$table} SET {$field}=? WHERE tenant_id=? AND id=? AND {$field}=?");
        $q->execute([$to,$owner,$document['id'],$from]);
        if ($q->rowCount()!==1) throw new DocumentOwnershipConflict('Document ownership changed during merge.');
        $moved++;
    }
    return $moved;
}
