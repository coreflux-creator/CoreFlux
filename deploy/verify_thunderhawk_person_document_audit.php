<?php
/** Installed pilot checks; only session-private synthetic tables may be changed. */
declare(strict_types=1);
const PDA_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
if(PHP_SAPI!=='cli'||getcwd()!==PDA_ROOT||getenv('COREFLUX_ENV')!=='staging'||posix_geteuid()!==1003)exit(2);
require_once PDA_ROOT.'/core/db.php';
require_once PDA_ROOT.'/core/integrations/identity.php';
require_once PDA_ROOT.'/core/StorageService.php';
require_once PDA_ROOT.'/modules/people/lib/documents.php';
require_once PDA_ROOT.'/modules/people/lib/onboarding.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
function pdaSnapshot(PDO $pdo,?array $tables=null):array{
    $result=[];$tables??=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    foreach($tables as$table){
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table');
        $rows=[];foreach($pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)as$row)$rows[]=hash('sha256',serialize($row));
        sort($rows);$result[$table]=['rows'=>count($rows),'hash'=>hash('sha256',implode("\n",$rows))];
    }ksort($result);return $result;
}
$report=['checked_at'=>gmdate('c'),'passed'=>false,'checks'=>[],'physical_writes'=>false,'provider_business_writes'=>false,'production_changed'=>false];
$check=static function(bool $ok,string $label)use(&$report){if(!$ok)throw new RuntimeException($label);$report['checks'][]=$label;};
$reject=static function(callable $run,string $label)use($check){try{$run();}catch(PeopleDocumentConflict|OnboardingConflict $e){$check(true,$label);return;}throw new RuntimeException('Accepted: '.$label);};
$before=pdaSnapshot($pdo);$temporary=[];
try{
    $live=$pdo->query('SELECT id,person_id,storage_object_id FROM people_documents WHERE tenant_id=2002 AND deleted_at IS NULL')->fetchAll(PDO::FETCH_ASSOC);
    foreach($live as$row){
        $object=peopleDocumentObject($pdo,2002,(int)$row['person_id'],(int)$row['storage_object_id']);
        $physical=peopleDocumentVerifyObject($object,static fn($key)=>\Core\StorageService::getInstance()->head($key),null);
        $check($physical['size_bytes']===(int)$object['size_bytes'],'Existing person file retains its physical length and valid recorded identity');
    }
    $report['existing_person_files_verified']=count($live);
    foreach(['tenants','tenant_module_scope','people','people_documents','storage_objects','document_ownership_transfers','audit_log']as$table){
        $definition=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        if(!str_starts_with($definition,'CREATE TABLE `'.$table.'` (')||str_contains($definition,'FOREIGN KEY'))throw new RuntimeException('Temporary fixture schema requires review');
        $pdo->exec(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$definition,1));$temporary[]=$table;
    }
    $pdo->exec("INSERT INTO tenants(id,name,parent_id,tenant_type,is_active,is_simulation) VALUES(2002,'Synthetic owner',NULL,'master',1,1),(2003,'Synthetic child',2002,'sub',1,1),(2004,'Synthetic foreign',NULL,'master',1,1)");
    $pdo->exec("INSERT INTO people(id,tenant_id,first_name,last_name,email_primary,classification,status) VALUES(1,2002,'Synthetic','Temporary','temporary-document@example.invalid','w2','active')");
    $scope=['tenant_id'=>2003,'people_tenant_id'=>2002,'actor_user_id'=>900090];
    $key='people/2002/document/1/temporary-document.txt';
    $physical=['etag'=>'synthetic-fingerprint','version_id'=>'synthetic-version','size_bytes'=>42,'mime'=>'text/plain'];
    $head=static function($given)use($key,&$physical){if($given!==$key)throw new RuntimeException('Unexpected physical key');return $physical;};
    $body=['doc_type'=>'resume','filename'=>'temporary-document.txt','storage_key'=>$key];
    $failure=static function(string $event,callable $run)use($pdo,$temporary,$check){
        $state=pdaSnapshot($pdo,$temporary);
        $pdo->exec("ALTER TABLE audit_log ADD CONSTRAINT cf_person_document_test_reject CHECK(event <> '{$event}')");
        try{$run();throw new LogicException('Audit failure ignored');}
        catch(PDOException $e){$check(str_contains($e->getMessage(),'cf_person_document_test_reject')&&pdaSnapshot($pdo,$temporary)===$state,'Required '.$event.' audit failure rolls back every synthetic row');}
        finally{$pdo->exec('ALTER TABLE audit_log DROP CONSTRAINT cf_person_document_test_reject');}
    };
    $register=static fn()=>peopleDocumentRegister($pdo,$scope,1,$body,$head);
    $failure('people.document.uploaded',$register);
    $saved=$register();$id=$saved['id'];$object=peopleDocumentObject($pdo,2002,1,$saved['storage_object_id']);
    $check(!$saved['replayed']&&$object['etag']===$physical['etag']&&$object['s3_version_id']===$physical['version_id']&&(int)$object['size_bytes']===42,'Successful retry retains verified physical identity');
    $audit=$pdo->query('SELECT * FROM audit_log')->fetchAll(PDO::FETCH_ASSOC);$meta=json_decode($audit[0]['meta_json'],true);
    $check(count($audit)===1&&(int)$audit[0]['tenant_id']===2003&&(int)$audit[0]['actor_user_id']===900090&&$meta['people_tenant_id']===2002&&$meta['person_id']===1&&$meta['document_id']===$id,'Upload audit retains child operator, shared directory and exact person/document');
    $state=pdaSnapshot($pdo,$temporary);$retry=$register();
    $check($retry['replayed']&&$retry['id']===$id&&pdaSnapshot($pdo,$temporary)===$state,'Exact registration replay preserves every row and audit');
    $case=['people_tenant_id'=>2002,'person_id'=>1];$evidence=onboardingDocument($pdo,$case,$id,$head);
    $physical['etag']='different-fingerprint';
    $reject(fn()=>peopleDocumentVerifyObject($object,$head),'Changed physical identity cannot authorize a download');
    $reject($register,'Changed physical identity cannot replay registration');
    $reject(fn()=>onboardingDocument($pdo,$case,$id,$head),'Changed physical identity cannot become onboarding evidence');
    $check(pdaSnapshot($pdo,$temporary)===$state,'Physical mismatch leaves all synthetic records unchanged');
    $physical['etag']='synthetic-fingerprint';$physical['version_id']='different-version';
    $reject(fn()=>peopleDocumentVerifyObject($object,$head),'Changed storage version is rejected even with the original fingerprint');
    $physical['version_id']='synthetic-version';
    $patch=['doc_type'=>'offer','signed'=>true,'signed_at'=>'2026-11-12'];
    $mutate=static fn()=>peopleDocumentMutate($pdo,$scope,$id,$patch);
    $failure('people.document.updated',$mutate);$mutate();
    $state=pdaSnapshot($pdo,$temporary);$mutate();
    $check(pdaSnapshot($pdo,$temporary)===$state,'Identical metadata replay creates no extra audit');
    $audit=$pdo->query("SELECT meta_json FROM audit_log WHERE event='people.document.updated'")->fetchAll(PDO::FETCH_COLUMN);$meta=json_decode($audit[0],true);
    $check(count($audit)===1&&$meta['before']['doc_type']==='resume'&&$meta['after']['doc_type']==='offer'&&$meta['resume_storage_after']===null,'Metadata audit preserves before/after state and resume pointer');
    $check(onboardingDocument($pdo,$case,$id,$head)['revision']!==$evidence['revision'],'Changed document metadata requires fresh onboarding review');
    $retire=static fn()=>peopleDocumentMutate($pdo,$scope,$id,null);
    $failure('people.document.deleted',$retire);$retire();$state=pdaSnapshot($pdo,$temporary);$retire();
    $check(pdaSnapshot($pdo,$temporary)===$state,'Retirement replay neither duplicates audit nor revives the document');
    $reject($mutate,'Retired document cannot be edited');
    $reject(fn()=>peopleDocumentObject($pdo,2002,1,$saved['storage_object_id']),'Retired document cannot authorize access');
}catch(Throwable $e){$report['failure']=$e->getMessage();}
finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach(array_reverse($temporary)as$table)$pdo->exec('DROP TEMPORARY TABLE `'.$table.'`');
}
$after=pdaSnapshot($pdo);
if($after!==$before)$report['failure']='Original database rows changed';
else{$report['original_tables_preserved']=count($before);$report['original_rows_preserved']=array_sum(array_column($before,'rows'));$report['temporary_tables_removed']=true;}
$report['passed']=!isset($report['failure']);$report['checks_passed']=count($report['checks']);
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);exit($report['passed']?0:1);
