<?php
/** Native MariaDB schema and actual local object bytes; disposable CI database only. */
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('CI')!=='true')throw new RuntimeException('Isolated CI only');
require_once __DIR__.'/../core/db.php';
require_once __DIR__.'/../core/StorageService.php';
require_once __DIR__.'/../modules/placements/lib/documents.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')throw new RuntimeException('Exact CI database required');
$fixture='coreflux_placement_docs_ci';$pdo->exec('CREATE DATABASE '.$fixture);$pdo->exec('USE '.$fixture);
$checks=0;$objects=[];$root=sys_get_temp_dir().'/cf-placement-vault-'.bin2hex(random_bytes(8));
$driver=new \Core\Storage\LocalDriver($root,'synthetic-only');$storage=new \Core\StorageService($driver);
$check=static function(bool $ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$reject=static function(callable $run,string $label)use($check){try{$run();}catch(InvalidArgumentException|PlacementDocumentConflict $e){$check(true,$label);return;}throw new RuntimeException('Not rejected: '.$label);};
try {
    foreach(['tenants','tenant_module_scope','placements','storage_objects','placement_documents','document_ownership_transfers','audit_log']as$table)$pdo->exec('CREATE TABLE '.$table.' LIKE coreaccounting_ci.'.$table);
    $pdo->exec("INSERT INTO tenants(id,name,parent_id,tenant_type,is_active) VALUES(10,'Synthetic owner',NULL,'master',1),(11,'Synthetic child',10,'sub',1),(12,'Synthetic foreign',NULL,'master',1)");
    $pdo->exec("INSERT INTO placements(id,tenant_id,person_id,title,status,engagement_type,start_date) VALUES(100,10,100,'Synthetic contract','draft','w2','2026-11-01'),(101,10,100,'Other contract','draft','w2','2026-11-01'),(102,12,102,'Foreign contract','draft','w2','2026-11-01'),(103,11,103,'Isolated child contract','draft','w2','2026-11-01')");
    $scope=['tenant_id'=>11,'placements_tenant_id'=>10,'actor_user_id'=>90];
    $head=static fn(string $key):?array=>$storage->head($key);
    $audit=static function(string $event,int $id,array $meta)use($pdo){$q=$pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,created_at) VALUES(11,90,?,?,?,NOW())');$q->execute([$event,$id,json_encode($meta,JSON_THROW_ON_ERROR)]);};
    $put=static function(int $owner,int $placement,string $name,string $bytes)use($storage,&$objects){$result=$storage->put('placements',$owner,'document',$placement,$name,$bytes,['mime'=>'application/pdf','if_not_exists'=>true]);$objects[]=$result['key'];return $result['key'];};
    $register=static fn(array $body,int $placement=100)=>placementDocumentRegister($pdo,$scope,$placement,$body,$head,$audit);
    $snapshot=static function()use($pdo){$out=[];foreach(['storage_objects','placement_documents','audit_log']as$table)$out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);return $out;};
    $check(placementDocumentDirectory($pdo,11)===10,'child resolves current shared placement directory');
    $bytes='%PDF-1.4 invented placement agreement';$key=$put(10,100,'synthetic.pdf',$bytes);
    $body=['doc_type'=>'sow','storage_key'=>$key,'filename'=>'synthetic.pdf','effective_from'=>'2026-11-01','effective_to'=>'2026-11-30','mime'=>'text/html','size_bytes'=>1];
    $result=$register($body);$objectId=$result['storage_object_id'];$documentId=$result['id'];
    $object=placementDocumentObject($pdo,10,100,$objectId);
    $check((int)$object['size_bytes']===strlen($bytes)&&$object['mime']==='application/pdf'&&$object['etag']==='"'.md5($bytes).'"','registry takes size MIME and fingerprint from actual bytes');
    $check((int)$object['tenant_id']===10&&(int)$object['entity_id']===100,'shared document uses exact directory and placement');
    $before=$snapshot();$again=$register($body);
    $check($again['replayed']&&$again['id']===$documentId&&$snapshot()===$before,'response-loss replay retains one identity and one audit receipt');
    $reject(fn()=>$register(array_replace($body,['effective_to'=>'2026-12-31'])),'changed registration metadata is a conflict');
    $reject(fn()=>$register(['doc_type'=>'sow','storage_object_id'=>$objectId,'file_name'=>'synthetic.pdf'],101),'same-company file cannot bind another placement');
    $reject(fn()=>placementDocumentObject($pdo,12,102,$objectId),'foreign company cannot download object');
    $reject(fn()=>placementDocumentGet($pdo,['tenant_id'=>12,'placements_tenant_id'=>12],$documentId),'foreign company cannot read document');
    $reject(fn()=>$register(array_replace($body,['storage_object_id'=>$objectId,'storage_key'=>$key.'different'])),'object and key mismatch rejected');
    $reject(fn()=>$register(['doc_type'=>'other','storage_key'=>'placements/10/document/100/missing.pdf','filename'=>'missing.pdf']),'missing physical upload rejected');
    $foreign=$put(12,102,'foreign.pdf','%PDF other tenant');
    $reject(fn()=>$register(array_replace($body,['storage_key'=>$foreign])),'foreign physical upload cannot enter shared directory');
    $check($snapshot()===$before,'all rejected bindings preserve complete registry document and audit rows');
    $caseKey=preg_replace('/synthetic\.pdf$/','SYNTHETIC.pdf',$key);
    $driver->put($caseKey,$bytes,['mime'=>'application/pdf']);$objects[]=$caseKey;
    $reject(fn()=>$register(array_replace($body,['storage_key'=>$caseKey])),'case-insensitive database lookup cannot substitute a different object key');
    $driver->put($key,'%PDF overwritten file',['mime'=>'application/pdf']);clearstatcache();
    $reject(fn()=>$register($body),'changed physical bytes invalidate registration replay');
    $reject(fn()=>placementDocumentVerifyObject($object,$head),'changed physical bytes invalidate download');
    $driver->put($key,$bytes,['mime'=>'application/pdf']);clearstatcache();
    foreach([['module','people'],['entity_type','other'],['entity_id','101'],['s3_key','placements/10/document/101/wrong.pdf'],['soft_deleted_at','2026-11-12 00:00:00']]as[$field,$value]){
        $pdo->prepare('UPDATE storage_objects SET '.$field.'=? WHERE id=?')->execute([$value,$objectId]);
        $reject(fn()=>placementDocumentObject($pdo,10,100,$objectId),'invalid registry '.$field.' prevents download');
        $pdo->prepare('UPDATE storage_objects SET '.$field.'=? WHERE id=?')->execute([$object[$field],$objectId]);
    }
    $pdo->exec("INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(11,'placements','isolated')");
    $reject(fn()=>$register($body),'current directory drift rejects registration');
    $reject(fn()=>placementDocumentRetire($pdo,$scope,$documentId,$audit),'current directory drift rejects retirement');
    $check(placementDocumentDirectory($pdo,11)===11,'explicit isolated directory uses child');
    $isolatedKey=$put(11,103,'isolated.pdf','%PDF isolated child');
    $isolated=placementDocumentRegister($pdo,array_replace($scope,['placements_tenant_id'=>11]),103,['doc_type'=>'other','filename'=>'isolated.pdf','storage_key'=>$isolatedKey],$head,$audit);
    $check((int)placementDocumentObject($pdo,11,103,$isolated['storage_object_id'])['tenant_id']===11,'isolated registration stays in child directory');
    $pdo->exec('DELETE FROM tenant_module_scope WHERE tenant_id=11');
    $atomicKey=$put(10,100,'atomic.pdf','%PDF atomic audit');$atomicBody=['doc_type'=>'other','filename'=>'atomic.pdf','storage_key'=>$atomicKey];
    $before=$snapshot();
    $pdo->exec("CREATE TRIGGER doc_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
    try{$register($atomicBody);throw new LogicException('Expected audit failure');}catch(PDOException $e){$check($snapshot()===$before,'audit failure rolls back registry and document together');}
    try{placementDocumentRetire($pdo,$scope,$documentId,$audit);throw new LogicException('Expected retirement audit failure');}catch(PDOException $e){$check($snapshot()===$before,'audit failure rolls back retirement');}
    $pdo->exec('DROP TRIGGER doc_audit_failure');
    $retired=placementDocumentRetire($pdo,$scope,$documentId,$audit);$before=$snapshot();
    $check(!$retired['replayed']&&$storage->read($key,1024)===$bytes,'retirement preserves original bytes');
    $check(placementDocumentRetire($pdo,$scope,$documentId,$audit)['replayed']&&$snapshot()===$before,'retirement retry makes no duplicate audit');
    $reject(fn()=>$register($body),'upload replay cannot resurrect retired document');
    $reject(fn()=>placementDocumentGet($pdo,$scope,$documentId),'retired document cannot issue download');
    $pdo->exec('UPDATE placements SET deleted_at=NOW() WHERE id=100');
    $reject(fn()=>$register($atomicBody),'deleted placement cannot receive new document');
    $pdo->exec('UPDATE tenants SET is_active=0 WHERE id=10');
    $reject(fn()=>placementDocumentDirectory($pdo,11),'inactive shared parent rejected');
    echo "PASS {$checks} native placement vault checks\n";
} finally {
    if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('USE coreaccounting_ci');$pdo->exec('DROP DATABASE '.$fixture);
    foreach($objects as$key)$driver->delete($key);
    if(is_dir($root)&&str_starts_with($root,sys_get_temp_dir().'/cf-placement-vault-')&&!is_link($root)){
        $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($entries as$entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($root);
    }
}
