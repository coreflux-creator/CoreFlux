<?php
/** Included only by the disposable native document-schema fixture. */
require_once __DIR__.'/../../modules/people/lib/documents.php';
require_once __DIR__.'/../../modules/people/lib/onboarding.php';
require_once __DIR__.'/../../core/StorageService.php';
$vaultChecks=0;
$vc=static function(bool $ok,string $label)use(&$vaultChecks,$check){$check($ok,$label);$vaultChecks++;};
$vr=static function(callable $run,string $label)use($vc){try{$run();}catch(InvalidArgumentException|PeopleDocumentConflict|OnboardingConflict $e){$vc(true,$label);return;}throw new RuntimeException('Did not reject '.$label);};
$pdo->exec('CREATE TABLE tenants(id BIGINT PRIMARY KEY,parent_id BIGINT NULL,tenant_type VARCHAR(20),is_active INT)');
$pdo->exec('CREATE TABLE tenant_module_scope(tenant_id BIGINT,module_key VARCHAR(30),scope_mode VARCHAR(20),PRIMARY KEY(tenant_id,module_key))');
$pdo->exec('CREATE TABLE people(id BIGINT PRIMARY KEY,tenant_id BIGINT,resume_storage_object_id BIGINT NULL,deleted_at DATETIME NULL)');
$pdo->exec("INSERT INTO tenants VALUES(10,NULL,'master',1),(11,10,'sub',1),(12,NULL,'master',1)");
$pdo->exec('INSERT INTO people VALUES(100,10,NULL,NULL),(101,10,NULL,NULL),(102,12,NULL,NULL)');
$scope=['tenant_id'=>11,'people_tenant_id'=>10,'actor_user_id'=>90];
$vc(peopleDocumentDirectory($pdo,11)===10,'active subtenant resolves the current shared People owner');
$root=sys_get_temp_dir().'/cf-person-vault-'.bin2hex(random_bytes(8));
$driver=new \Core\Storage\LocalDriver($root,'synthetic-vault-secret');
$storage=new \Core\StorageService($driver);$objects=[];
$put=static function(int $owner,int $person,string $name,string $bytes)use($storage,&$objects){$r=$storage->put('people',$owner,'document',$person,$name,$bytes,['mime'=>'application/pdf','if_not_exists'=>true]);$objects[]=$r['key'];return $r['key'];};
$head=static fn(string $key):?array=>$storage->head($key);
$register=static fn(array $body,int $person=100)=>peopleDocumentRegister($pdo,$scope,$person,$body,$head);
try{
    $bytes='%PDF-1.4 synthetic person-vault evidence';$key=$put(10,100,'resume.pdf',$bytes);
    $body=['doc_type'=>'resume','storage_key'=>$key,'filename'=>'resume.pdf','size_bytes'=>1,'mime'=>'text/html'];
    $r=$register($body);$objectId=$r['storage_object_id'];$documentId=$r['id'];
    $object=peopleDocumentObject($pdo,10,100,$objectId);
    $vc((int)$object['tenant_id']===10 && (int)$object['entity_id']===100,'registry belongs to the People directory and exact person');
    $vc((int)$object['size_bytes']===strlen($bytes) && $object['mime']==='application/pdf','stored-object metadata overrides untrusted browser size and MIME');
    $vc((int)$pdo->query('SELECT resume_storage_object_id FROM people WHERE id=100')->fetchColumn()===$objectId,'resume pointer and document commit together');
    $again=$register($body);$vc($again['replayed'] && $again['id']===$documentId,'response-loss retry returns one document identity');
    $vc((int)$pdo->query('SELECT COUNT(*) FROM people_documents WHERE person_id=100')->fetchColumn()===1,'registration retry creates no duplicate document');
    $vr(fn()=>$register(array_replace($body,['doc_type'=>'offer'])),'retry with changed metadata requires explicit document edit');
    $vr(fn()=>$register(['doc_type'=>'offer','storage_object_id'=>$objectId,'file_name'=>'resume.pdf'],101),'existing object cannot bind to another person');
    $vr(fn()=>peopleDocumentObject($pdo,12,102,$objectId),'another tenant cannot obtain a person object');
    $vr(fn()=>$register(['doc_type'=>'offer','storage_object_id'=>$objectId,'storage_key'=>'people/10/document/100/other.pdf']),'object and key disagreement rejected');
    $foreign=$put(12,102,'foreign.pdf','%PDF synthetic other person');
    $vr(fn()=>$register(['doc_type'=>'offer','storage_key'=>$foreign,'filename'=>'foreign.pdf']),'foreign upload cannot enter the shared directory');
    $count=(int)$pdo->query('SELECT COUNT(*) FROM storage_objects')->fetchColumn();
    $vr(fn()=>$register(['doc_type'=>'offer','storage_key'=>'people/10/document/100/missing.pdf','filename'=>'missing.pdf']),'missing physical upload cannot create metadata');
    $vc((int)$pdo->query('SELECT COUNT(*) FROM storage_objects')->fetchColumn()===$count,'failed storage verification leaves registry unchanged');
    $pdo->exec("INSERT INTO tenant_module_scope VALUES(11,'people','isolated')");
    $vr(fn()=>$register($body),'directory changed after request resolution rejects registration');
    $vr(fn()=>peopleDocumentMutate($pdo,$scope,$documentId,['signed'=>true]),'directory drift rejects metadata changes too');
    $pdo->exec('DELETE FROM tenant_module_scope WHERE tenant_id=11');
    $evidenceCase=['people_tenant_id'=>10,'person_id'=>100];
    $first=onboardingDocument($pdo,$evidenceCase,$documentId,$head);
    $vc($first['document_id']===$documentId && strlen($first['revision'])===64,'onboarding verifies actual LocalDriver bytes and owned metadata');
    $driver->put($key,'%PDF-1.4 changed physical evidence',['mime'=>'application/pdf']);clearstatcache();
    $second=onboardingDocument($pdo,$evidenceCase,$documentId,$head);
    $vc($first['revision']!==$second['revision'],'physical overwrite invalidates document evidence revision');
    $pdo->exec('UPDATE storage_objects SET soft_deleted_at=NOW() WHERE id='.$objectId);
    $vr(fn()=>peopleDocumentObject($pdo,10,100,$objectId),'retired registry object cannot generate a download');
    $vr(fn()=>onboardingDocument($pdo,$evidenceCase,$documentId,$head),'retired registry object cannot remain onboarding evidence');
    $pdo->exec('UPDATE storage_objects SET soft_deleted_at=NULL WHERE id='.$objectId);
    peopleDocumentMutate($pdo,$scope,$documentId,['doc_type'=>'offer','expires_at'=>'2027-01-01']);
    $vc($pdo->query('SELECT resume_storage_object_id FROM people WHERE id=100')->fetchColumn()===null,'reclassifying a resume clears only its pointer');
    $third=onboardingDocument($pdo,$evidenceCase,$documentId,$head);
    $vc($third['revision']!==$second['revision'] && $third['expires_at']==='2027-01-01','metadata changes invalidate prior onboarding evidence');
    peopleDocumentMutate($pdo,$scope,$documentId,['doc_type'=>'resume']);
    peopleDocumentMutate($pdo,$scope,$documentId,null);
    $vc($pdo->query('SELECT resume_storage_object_id FROM people WHERE id=100')->fetchColumn()===null,'soft deletion clears current resume pointer');
    $vc($storage->read($key,1024)!==null,'document retirement preserves physical evidence');
    $vr(fn()=>$register($body),'retry cannot resurrect retired document registration');
    $vr(fn()=>onboardingDocument($pdo,$evidenceCase,$documentId,$head),'retired document invalidates evidence');
    $newKey=$put(10,100,'atomic.pdf','%PDF atomic rollback');
    $beforeRegistry=(int)$pdo->query('SELECT COUNT(*) FROM storage_objects')->fetchColumn();
    $beforeDocuments=(int)$pdo->query('SELECT COUNT(*) FROM people_documents')->fetchColumn();
    $pdo->exec("CREATE TRIGGER vault_fail_resume BEFORE UPDATE ON people FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic pointer failure'");
    try{$register(['doc_type'=>'resume','storage_key'=>$newKey,'filename'=>'atomic.pdf']);throw new LogicException('Expected pointer failure');}
    catch(PDOException $e){$vc((int)$pdo->query('SELECT COUNT(*) FROM storage_objects')->fetchColumn()===$beforeRegistry && (int)$pdo->query('SELECT COUNT(*) FROM people_documents')->fetchColumn()===$beforeDocuments,'pointer failure rolls back registry and document together');}
    finally{$pdo->exec('DROP TRIGGER vault_fail_resume');}
    try{peopleDocumentRegisterInTransaction($pdo,$scope,100,['doc_type'=>'other','storage_key'=>$newKey,'filename'=>'atomic.pdf'],$head);throw new RuntimeException('Expected explicit outer transaction');}
    catch(LogicException $e){$vc(true,'composed registration requires an outer transaction');}
    $pdo->beginTransaction();
    $composed=peopleDocumentRegisterInTransaction($pdo,$scope,100,['doc_type'=>'resume','storage_key'=>$newKey,'filename'=>'atomic.pdf'],$head);
    $vc($pdo->inTransaction()&&(int)$pdo->query('SELECT resume_storage_object_id FROM people WHERE id=100')->fetchColumn()===$composed['storage_object_id'],'composed registration leaves commit with its caller');
    $pdo->rollBack();
    $vc((int)$pdo->query('SELECT COUNT(*) FROM storage_objects')->fetchColumn()===$beforeRegistry
        &&(int)$pdo->query('SELECT COUNT(*) FROM people_documents')->fetchColumn()===$beforeDocuments
        &&$pdo->query('SELECT resume_storage_object_id FROM people WHERE id=100')->fetchColumn()===null,'outer rollback restores registry, document and resume pointer');
    $pdo->exec('UPDATE people SET deleted_at=NOW() WHERE id=100');
    $vr(fn()=>$register(['doc_type'=>'offer','storage_key'=>$newKey,'filename'=>'atomic.pdf']),'retired person cannot receive evidence');
    echo "PASS {$vaultChecks} real-storage person-vault checks\n";
}finally{
    foreach($objects as$key)$driver->delete($key);
    // Only the just-created private fixture tree is removed, after its exact root is checked.
    if(is_dir($root) && str_starts_with($root,sys_get_temp_dir().'/cf-person-vault-') && !is_link($root)){
        $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($entries as$entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}
        rmdir($root);
    }
}
