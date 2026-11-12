<?php
/** Installed pilot verification. Original records are read only; merge fixtures use temporary tables. */
declare(strict_types=1);
const DM_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
if(PHP_SAPI!=='cli'||getcwd()!==DM_ROOT||getenv('COREFLUX_ENV')!=='staging'||posix_geteuid()!==1003)exit(2);
require_once DM_ROOT.'/core/db.php';
require_once DM_ROOT.'/core/integrations/identity.php';
require_once DM_ROOT.'/core/StorageService.php';
require_once DM_ROOT.'/modules/people/lib/documents.php';
require_once DM_ROOT.'/modules/placements/lib/documents.php';
require_once DM_ROOT.'/modules/people/lib/onboarding.php';
require_once DM_ROOT.'/core/integrations/staffing_field_contract.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002')exit(2);
function dmSnapshot(PDO $pdo):array{
    $result=[];
    foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$table){
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table');
        $rows=[];foreach($pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)as$row)$rows[]=hash('sha256',serialize($row));
        sort($rows);$result[$table]=['rows'=>count($rows),'hash'=>hash('sha256',implode("\n",$rows))];
    }ksort($result);return $result;
}
$report=['checked_at'=>gmdate('c'),'passed'=>false,'checks'=>[],'physical_writes'=>false,'provider_business_writes'=>false,'production_changed'=>false];
$check=static function(bool $ok,string $label)use(&$report){if(!$ok)throw new RuntimeException($label);$report['checks'][]=$label;};
$reject=static function(callable $run,string $label)use($check){try{$run();}catch(DocumentOwnershipConflict|PeopleDocumentConflict|PlacementDocumentConflict $e){$check(true,$label);return;}throw new RuntimeException('Accepted: '.$label);};
$before=dmSnapshot($pdo);$temporary=[];
try{
    $check((int)$pdo->query('SELECT COUNT(*) FROM document_ownership_transfers')->fetchColumn()===0,'Installed transfer history starts empty');
    $check(staffingFieldContractRules('document_ownership_transfers','receipt_sha256')['owner']==='core_document_ownership_history','Installed dictionary owns transfer evidence internally');
    $live=$pdo->query('SELECT id,person_id,storage_object_id FROM people_documents WHERE tenant_id=2002 AND deleted_at IS NULL')->fetchAll(PDO::FETCH_ASSOC);
    foreach($live as$row){
        $object=peopleDocumentObject($pdo,2002,(int)$row['person_id'],(int)$row['storage_object_id']);
        $physical=peopleDocumentHead(static fn($key)=>\Core\StorageService::getInstance()->head($key),$object['s3_key'],null);
        $check($physical['size_bytes']===(int)$object['size_bytes'],'Existing pilot person file retains verified physical length and ownership');
    }
    $report['existing_person_files_verified']=count($live);
    // Session-private table copies have the installed schema and no triggers or real data.
    foreach(['tenants','tenant_module_scope','people','placements','people_documents','placement_documents','storage_objects','document_ownership_transfers']as$table){
        $definition=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        if(!str_starts_with($definition,'CREATE TABLE `'.$table.'` (')||str_contains($definition,'FOREIGN KEY'))throw new RuntimeException('Temporary fixture schema requires review');
        $definition=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$definition,1);
        $pdo->exec($definition);$temporary[]=$table;
    }
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO tenants(id,name,parent_id,tenant_type,is_active,is_simulation) VALUES(2002,'Synthetic temporary owner',NULL,'master',1,1),(2003,'Synthetic temporary child',2002,'sub',1,1),(2004,'Synthetic temporary foreign',NULL,'master',1,1)");
    foreach([1,2,3,4]as$id){$owner=$id===4?2004:2002;
        $pdo->prepare("INSERT INTO people(id,tenant_id,first_name,last_name,email_primary,classification,status) VALUES(?,?,'Synthetic','Temporary',?,'w2','active')")->execute([$id,$owner,'temporary-merge-'.$id.'@example.invalid']);
        $pdo->prepare("INSERT INTO placements(id,tenant_id,person_id,title,status,engagement_type,start_date) VALUES(?,?,?,'Synthetic temporary placement','draft','w2','2026-11-01')")->execute([$id,$owner,$id]);
    }
    foreach(['people','placements']as$index=>$module){
        $id=$index+1;$key=$module.'/2002/document/1/synthetic-temporary.txt';
        $pdo->prepare("INSERT INTO storage_objects(id,tenant_id,module,entity_type,entity_id,s3_key,filename,mime,size_bytes,etag,created_at) VALUES(?,2002,?,'document','1',?,'synthetic-temporary.txt','text/plain',42,'synthetic-metadata-only',NOW())")->execute([$id,$module,$key]);
        [$table,$field]=documentOwnershipDefinition($module);
        $pdo->prepare("INSERT INTO {$table}(id,tenant_id,{$field},doc_type,storage_object_id,file_name) VALUES(?,2002,1,'other',?,'synthetic-temporary.txt')")->execute([$id,$id]);
        $scope=['tenant_id'=>2003,$module.'_tenant_id'=>2002,'actor_user_id'=>900090];
        $source=$module==='people'?'people.merge':'jobdiva.placement_consolidation';
        $get=static fn(int $record)=>$module==='people'?peopleDocumentObject($pdo,2002,$record,$id,true):placementDocumentObject($pdo,2002,$record,$id,true);
        $object=$get(1);
        $reject(fn()=>documentOwnershipTransfer($pdo,$scope,$module,1,4,$source),'Temporary cross-tenant transfer refused: '.$module);
        $check(documentOwnershipTransfer($pdo,$scope,$module,1,2,$source)===1&&$get(2)===$object,'Temporary transfer preserves original storage registry: '.$module);
        $reject(fn()=>$get(1),'Former temporary owner cannot access transferred object: '.$module);
        $check(documentOwnershipTransfer($pdo,$scope,$module,1,2,$source)===0,'Repeated temporary merge adds no transfer: '.$module);
        $check(documentOwnershipTransfer($pdo,$scope,$module,2,3,$source)===1&&$get(3)===$object,'Second temporary merge preserves original object: '.$module);
        $reject(fn()=>documentOwnershipTransfer($pdo,$scope,$module,3,1,$source),'Temporary ownership cycle refused: '.$module);
        $binding=documentOwnershipBinding($pdo,$module,2002,3,$object,true);
        $check(count($binding['history'])===2,'Both temporary transfer receipts retained: '.$module);
        $pdo->prepare("UPDATE {$table} SET deleted_at=NOW() WHERE id=?")->execute([$id]);
        $reject(fn()=>$get(3),'Retired temporary file remains inaccessible: '.$module);
    }
    $pdo->rollBack();
}catch(Throwable $e){$report['failure']=$e->getMessage();}
finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach(array_reverse($temporary)as$table)$pdo->exec('DROP TEMPORARY TABLE `'.$table.'`');
}
$after=dmSnapshot($pdo);
if($after!==$before)$report['failure']='Original database rows changed';
else{$report['original_tables_preserved']=count($before);$report['original_rows_preserved']=array_sum(array_column($before,'rows'));$report['temporary_tables_removed']=true;}
$report['passed']=!isset($report['failure']);$report['checks_passed']=count($report['checks']);
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);exit($report['passed']?0:1);
