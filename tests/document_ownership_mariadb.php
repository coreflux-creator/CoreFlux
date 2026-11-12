<?php
/** Native database transactions and physical files; no provider or hosted data. */
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('CI')!=='true')throw new RuntimeException('Isolated CI only');
require_once __DIR__.'/../core/db.php';
require_once __DIR__.'/../core/StorageService.php';
require_once __DIR__.'/../modules/people/lib/documents.php';
require_once __DIR__.'/../modules/placements/lib/documents.php';
require_once __DIR__.'/../modules/people/lib/onboarding.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')throw new RuntimeException('Exact CI database required');
$fixture='coreflux_document_merge_ci';$pdo->exec('CREATE DATABASE '.$fixture);$pdo->exec('USE '.$fixture);
$checks=0;$keys=[];$root=sys_get_temp_dir().'/cf-document-merge-'.bin2hex(random_bytes(8));
$driver=new \Core\Storage\LocalDriver($root,'synthetic-only');$storage=new \Core\StorageService($driver);
$check=static function(bool $ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$reject=static function(callable $run,string $label)use($check){try{$run();}catch(DocumentOwnershipConflict|PeopleDocumentConflict|PlacementDocumentConflict|InvalidArgumentException $e){$check(true,$label);return;}throw new RuntimeException('Not rejected: '.$label);};
$head=static fn($key)=>$storage->head($key);
$transfer=static function(string $module,int $from,int $to,array $override=[])use($pdo):int {
    $pdo->beginTransaction();
    try{$count=documentOwnershipTransfer($pdo,array_replace(['tenant_id'=>11,$module.'_tenant_id'=>10,'actor_user_id'=>90],$override),$module,$from,$to,$module==='people'?'people.merge':'jobdiva.placement_consolidation');$pdo->commit();return $count;}
    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
};
$snapshot=static function()use($pdo){$out=[];foreach(['people_documents','placement_documents','storage_objects','document_ownership_transfers']as$table)$out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);return $out;};
try{
    foreach(['tenants','tenant_module_scope','people','placements','people_documents','placement_documents','storage_objects','document_ownership_transfers']as$table)$pdo->exec('CREATE TABLE '.$table.' LIKE coreaccounting_ci.'.$table);
    $pdo->exec("INSERT INTO tenants(id,name,parent_id,tenant_type,is_active) VALUES(10,'Synthetic owner',NULL,'master',1),(11,'Synthetic child',10,'sub',1),(12,'Synthetic foreign',NULL,'master',1)");
    foreach([100,101,102,103,104]as$id){
        $owner=$id===103?12:10;
        $pdo->prepare("INSERT INTO people(id,tenant_id,first_name,last_name,email_primary,classification,status) VALUES(?,?,'Synthetic','Merge',?,'w2','active')")->execute([$id,$owner,'merge'.$id.'@example.invalid']);
        $pdo->prepare("INSERT INTO placements(id,tenant_id,person_id,title,status,engagement_type,start_date) VALUES(?,?,?,'Synthetic merge','draft','w2','2026-11-01')")->execute([$id,$owner,$id]);
    }
    foreach(['people','placements']as$module){
        $bytes='Original synthetic '.$module.' contract';
        $key=$storage->put($module,10,'document',100,'synthetic.txt',$bytes,['mime'=>'text/plain','if_not_exists'=>true])['key'];$keys[]=$key;
        $scope=['tenant_id'=>11,$module.'_tenant_id'=>10,'actor_user_id'=>90];
        $body=['doc_type'=>'other','filename'=>'synthetic.txt','storage_key'=>$key];
        $result=$module==='people'?peopleDocumentRegister($pdo,$scope,100,$body,$head):placementDocumentRegister($pdo,$scope,100,$body,$head,static function(){});
        $objectId=$result['storage_object_id'];$docId=$result['id'];
        $get=static fn(int $record)=>$module==='people'?peopleDocumentObject($pdo,10,$record,$objectId):placementDocumentObject($pdo,10,$record,$objectId);
        $object=$get(100);$before=$snapshot();
        $originalEvidence=$module==='people'?onboardingDocument($pdo,['people_tenant_id'=>10,'person_id'=>100],$docId,$head):null;
        $reject(fn()=>$transfer($module,100,103),'foreign merge refused: '.$module);
        $check($snapshot()===$before,'foreign merge preserves every document and receipt: '.$module);
        $pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(11,?,"isolated")')->execute([$module]);
        $reject(fn()=>$transfer($module,100,101),'current directory drift refused: '.$module);
        $pdo->prepare('DELETE FROM tenant_module_scope WHERE tenant_id=11 AND module_key=?')->execute([$module]);
        $check($transfer($module,100,101)===1,'authorized shared-directory transfer: '.$module);
        $check($get(101)===$object && $storage->read($key,1024)===$bytes,'original object identity metadata and physical bytes retained: '.$module);
        if($module==='people'){
            $evidence=onboardingDocument($pdo,['people_tenant_id'=>10,'person_id'=>101],$docId,$head);
            $check($evidence['revision']!==$originalEvidence['revision'],'transferred onboarding file requires fresh evidence review');
        }
        $reject(fn()=>$get(100),'former owner cannot download transferred file: '.$module);
        $reject(fn()=>$get(102),'unrelated same-directory record cannot download: '.$module);
        $before=$snapshot();$check($transfer($module,100,101)===0&&$snapshot()===$before,'lost-response retry adds no duplicate receipt: '.$module);
        $check($transfer($module,101,102)===1&&$get(102)===$object,'second merge follows the complete original lineage: '.$module);
        $binding=documentOwnershipBinding($pdo,$module,10,102,$object);
        $check(count($binding['history'])===2&&(int)$binding['document']['id']===$docId,'same document ID and both transfers retained: '.$module);
        $before=$snapshot();$reject(fn()=>$transfer($module,102,100),'cycle to original owner refused: '.$module);
        $check($snapshot()===$before,'cycle refusal is atomic: '.$module);
        $receipt=$binding['history'][1];
        $pdo->prepare('UPDATE document_ownership_transfers SET actor_user_id=91 WHERE id=?')->execute([$receipt['id']]);
        $reject(fn()=>$get(102),'changed receipt invalidates access: '.$module);
        $pdo->prepare('UPDATE document_ownership_transfers SET actor_user_id=? WHERE id=?')->execute([$receipt['actor_user_id'],$receipt['id']]);
        $pdo->prepare('UPDATE storage_objects SET s3_key=CONCAT(s3_key,"different") WHERE id=?')->execute([$objectId]);
        $reject(fn()=>$get(102),'changed original storage identity invalidates lineage: '.$module);
        $pdo->prepare('UPDATE storage_objects SET s3_key=? WHERE id=?')->execute([$key,$objectId]);
        $table=$module==='people'?'people_documents':'placement_documents';$field=$module==='people'?'person_id':'placement_id';
        $pdo->prepare("UPDATE {$table} SET deleted_at=NOW() WHERE id=?")->execute([$docId]);
        $reject(fn()=>$get(102),'retirement still prevents download after merge: '.$module);
        $check($transfer($module,102,104)===1,'retired document history follows a later merge: '.$module);
        $reject(fn()=>$get(104),'transferring retired history cannot reactivate download: '.$module);
        $check($storage->read($key,1024)===$bytes,'retired document original bytes remain: '.$module);
        foreach(['atomic-a.txt','atomic-b.txt']as$name){
            $newKey=$storage->put($module,10,'document',100,$name,'Synthetic atomic '.$name,['mime'=>'text/plain','if_not_exists'=>true])['key'];$keys[]=$newKey;
            $newBody=['doc_type'=>'other','filename'=>$name,'storage_key'=>$newKey];
            $newResult=$module==='people'?peopleDocumentRegister($pdo,$scope,100,$newBody,$head):placementDocumentRegister($pdo,$scope,100,$newBody,$head,static function(){});
        }
        $before=$snapshot();$failId=(int)$newResult['id'];
        $pdo->exec("CREATE TRIGGER merge_receipt_failure BEFORE INSERT ON document_ownership_transfers FOR EACH ROW BEGIN IF NEW.module='{$module}' AND NEW.document_id={$failId} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic receipt failure'; END IF; END");
        try{$transfer($module,100,101);throw new LogicException('Expected transfer failure');}
        catch(PDOException $e){$check($snapshot()===$before,'second receipt failure rolls back the entire multi-document transfer: '.$module);}
        finally{$pdo->exec('DROP TRIGGER merge_receipt_failure');}
        $check($transfer($module,100,101)===2,'complete retry transfers both documents once: '.$module);
    }
    echo "PASS {$checks} native document transfer checks\n";
}finally{
    if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('USE coreaccounting_ci');$pdo->exec('DROP DATABASE '.$fixture);
    foreach($keys as$key)$driver->delete($key);
    if(is_dir($root)&&str_starts_with($root,sys_get_temp_dir().'/cf-document-merge-')&&!is_link($root)){
        $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($entries as$entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($root);
    }
}
