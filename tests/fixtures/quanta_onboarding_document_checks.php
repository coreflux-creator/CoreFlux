<?php
/** Included in the disposable MariaDB exchange fixture; all physical files are synthetic. */
$docStart=$passed;
$pdo->exec('ALTER TABLE people_documents MODIFY id BIGINT NOT NULL AUTO_INCREMENT, ADD file_name VARCHAR(255) CHARACTER SET utf8mb4 NULL, ADD uploaded_by_user_id BIGINT NULL, ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP');
$pdo->exec('ALTER TABLE storage_objects MODIFY id BIGINT NOT NULL AUTO_INCREMENT, ADD filename VARCHAR(255) CHARACTER SET utf8mb4 NULL, ADD mime VARCHAR(120) NULL, ADD size_bytes BIGINT NULL, ADD created_by_user_id BIGINT NULL, ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP');
class QuantaVaultFixtureDriver extends \Core\Storage\LocalDriver {
    public array $keys=[];public int $writes=0;public $beforePut=null;public $afterPut=null;public $afterRead=null;
    public function put(string $key,string $value,array $opts=[]):array {
        if($this->beforePut)($this->beforePut)($key);
        $this->writes++;$this->keys[]=$key;$result=parent::put($key,$value,$opts);
        if($this->afterPut){$callback=$this->afterPut;$this->afterPut=null;$callback($key);}return $result;
    }
    public function read(string $key,int $maxBytes):?string {
        $result=parent::read($key,$maxBytes);
        if($this->afterRead){$callback=$this->afterRead;$this->afterRead=null;$callback($key);}return $result;
    }
}
$docRoot=sys_get_temp_dir().'/cf-quanta-vault-'.bin2hex(random_bytes(8));
$docDriver=new QuantaVaultFixtureDriver($docRoot,'synthetic-quanta-vault');
$docDriver->beforePut=static function($key)use($pdo,$observer,$check){
    $s=$observer->prepare('SELECT COUNT(*) FROM quanta_onboarding_document_transfers WHERE storage_key=?');$s->execute([$key]);
    $check(!$pdo->inTransaction()&&(int)$s->fetchColumn()===1,'physical write follows a committed, independently visible file reservation');
};
$docStorage=new \Core\StorageService($docDriver);$docHead=static fn($key)=>$docStorage->head($key);
$docScope=$qScope;$docScope['permissions']['documents_manage']=true;
$docView=static fn()=>onboardingView($pdo,$docScope,$qId,$docHead);
$docCommand=static fn($action,$fields=[])=>onboardingCommand($pdo,$docScope,['action'=>$action,'request_id'=>onboardingUuid(),
    'case_id'=>$qId,'expected_version'=>$docView()['version']]+$fields,$docHead);
$docBytes="%PDF-1.4\nSynthetic worker evidence.\n%%EOF";$docDownloads=0;$docFetchFail=false;
$docReceipt=null;$docDelivery=0;$docTask=null;
$GLOBALS['__quanta_document_transport']=static function($method,$url,$headers,$buffer)use(&$docDownloads,&$docReceipt,&$docFetchFail,$docBytes,$check,$pdo){
    $docDownloads++;$check(!$pdo->inTransaction(),'private provider file read happens outside the SQL transaction');
    if($docFetchFail)throw new RuntimeException('Synthetic interrupted file download');
    $check(str_ends_with($url,'/onboarding/'.$docReceipt['case_id'].'/documents/'.$docReceipt['id']),'private download uses selected receipt identity');
    $doc=$docReceipt['document'];
    foreach(['content-type'=>'application/octet-stream','content-length'=>(string)strlen($docBytes),'x-core-document-id'=>$doc['id'],'x-core-document-sha256'=>$doc['sha256']]as$k=>$v)$buffer->header($k.': '.$v."\r\n");
    $buffer->body($docBytes);return 200;
};
$newDocReceipt=static function()use(&$docReceipt,&$docDelivery,&$docTask,$docScope,$qId,$docHead,&$qRemote,$docBytes,$docView){
    $p=quantaOnboardingPreview($docScope,$qId,'published',$docHead);
    if($p['status']!=='unchanged')$docDelivery=(int)quantaOnboardingSend($docScope,$qId,(int)$p['delivery']['id'],$p['delivery']['review_hash'],$docHead)['id'];
    $request=onboardingUuid();$publication=$qRemote['publication'];
    $task=array_values(array_filter($publication['tasks'],static fn($t)=>$t['id']===$docTask))[0];
    $docReceipt=['id'=>'cfs_'.onboardingHash([$qRemote['id'],'worker-2',$request]),'request_id'=>$request,'request_hash'=>str_repeat('d',64),
        'worker_id'=>'worker-2','identity_id'=>'identity-2','case_id'=>$qRemote['id'],'core_case_id'=>$qId,'core_version'=>$publication['core_version'],
        'source_revision'=>$publication['source_revision'],'projection_hash'=>$qRemote['projection_hash'],'task_id'=>$docTask,'task_version'=>$task['version'],
        'kind'=>'document','acknowledged'=>true,'submitted_at'=>'2026-10-23T02:00:00+00:00','status'=>'awaiting_core_review',
        'document'=>['id'=>'cfof_'.onboardingHash([$qRemote['id'],'worker-2',$request]),'contract_version'=>QUANTA_ONBOARDING_DOCUMENT_VERSION,
            'filename'=>str_repeat('入',180).'.pdf','mime'=>'application/pdf','size_bytes'=>strlen($docBytes),'sha256'=>hash('sha256',$docBytes)]];
    $qRemote['submissions'][]=$docReceipt;$qRemote['version']++;return $docView()['version'];
};
$docCounts=static fn()=>[(int)$pdo->query('SELECT COUNT(*) FROM people_documents')->fetchColumn(),
    (int)$pdo->query('SELECT COUNT(*) FROM storage_objects')->fetchColumn(),(int)$pdo->query('SELECT COUNT(*) FROM staffing_onboarding_events')->fetchColumn()];
$docImport=static function($version)use($docScope,$qId,&$docReceipt,&$docDelivery,$docStorage){return quantaOnboardingImportDocument($docScope,$qId,$docReceipt['id'],$docDelivery,$version,$docStorage);};
$transfer=static function()use($pdo,&$docReceipt,$qWorkspace){return quantaOnboardingTransferGet($pdo,11,quantaOnboardingImportId($qWorkspace,$docReceipt['id']));};
try{
    $docTask=$docCommand('add_task',['title'=>'Worker placement file','instructions'=>'Provide the requested document.',
        'assignee'=>'worker','evidence_kind'=>'document','required'=>true])['task_id'];$docCommand('confirm_plan');
    $version=$newDocReceipt();$before=$docCounts();
    $reject(fn()=>$docImport($version),'private document import defaults off');
    putenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENTS=1');putenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENT_TENANTS=11');
    $reject(fn()=>quantaOnboardingImportDocument($qScope,$qId,$docReceipt['id'],$docDelivery,$version,$docStorage),'document import requires document-management permission');
    $reject(fn()=>$docImport($version-1),'stale document import version rejected before file IO');
    $check($docDownloads===0&&$docDriver->writes===0&&$before===$docCounts(),'rejected preflight downloads no file and creates no metadata');
    $result=$docImport($version);$task=array_values(array_filter($docView()['tasks'],static fn($t)=>$t['id']===$docTask))[0];
    $document=$pdo->query('SELECT * FROM people_documents WHERE id='.(int)$result['document_id'])->fetch(PDO::FETCH_ASSOC);
    $object=peopleDocumentObject($pdo,10,2,(int)$document['storage_object_id']);
    $check($docStorage->read($object['s3_key'],1024)===$docBytes,'registered Core vault contains exact verified provider bytes');
    $check($document['file_name']===$docReceipt['document']['filename']&&$document['doc_type']==='other'&&(int)$document['signed']===0,'original Unicode filename is retained without inventing document type or signature');
    $check($task['state']==='submitted'&&$task['review']===null&&$task['evidence']['document_id']===$result['document_id']
        &&$task['evidence']['provider_identity_id']==='identity-2','document and authenticated worker provenance commit as unapproved evidence');
    $check($task['effective_state']==='submitted'&&$docView()['payroll_authorization']==='not_evaluated','verified document still requires employer review and grants no payroll authority');
    $check($transfer()['state']==='registered'&&(int)$transfer()['document_id']===$result['document_id'],'the registered transfer identifies its committed person document');
    $counts=$docCounts();$downloads=$docDownloads;$writes=$docDriver->writes;
    $retry=$docImport($version);
    $check($retry['replayed']&&$retry['document_id']===$result['document_id']&&$counts===$docCounts(),'response-loss retry returns one committed document and history event');
    $check($downloads===$docDownloads&&$writes===$docDriver->writes,'committed retry performs no file retrieval or write');
    $docCommand('review',['task_id'=>$docTask,'decision'=>'approve','reason'=>'Employer checked the synthetic document']);
    $check($docImport($version)['replayed']&&array_values(array_filter($docView()['tasks'],static fn($t)=>$t['id']===$docTask))[0]['state']==='approved','receipt replay after employer approval does not reset review');
    $docCommand('reopen',['task_id'=>$docTask,'reason'=>'Synthetic new evidence request']);$version=$newDocReceipt();
    $before=$docCounts();
    $docDriver->afterPut=static function()use($pdo){$pdo->exec("UPDATE quanta_worker_links SET worker_id='changed-worker' WHERE tenant_id=11");};
    $reject(fn()=>$docImport($version),'worker relink during storage prevents evidence registration');
    $pdo->exec("UPDATE quanta_worker_links SET worker_id='worker-2' WHERE tenant_id=11");
    $check($before===$docCounts(),'worker relink leaves document registry and case history unchanged');
    $version=$newDocReceipt();
    $docDriver->afterPut=static function()use($pdo){$pdo->exec("INSERT INTO tenant_module_scope VALUES(11,'people','isolated')");};
    $reject(fn()=>$docImport($version),'directory change during storage prevents evidence registration');
    $pdo->exec("DELETE FROM tenant_module_scope WHERE tenant_id=11 AND module_key='people'");
    $check($before===$docCounts(),'directory drift leaves no registered document');
    $version=$newDocReceipt();
    $docDriver->afterPut=static function()use($pdo){$pdo->exec('UPDATE quanta_connections SET binding_revision=2 WHERE tenant_id=11');};
    $reject(fn()=>$docImport($version),'connection rotation during storage prevents evidence registration');
    $pdo->exec('UPDATE quanta_connections SET binding_revision=1 WHERE tenant_id=11');
    $check($before===$docCounts(),'connection drift leaves no registered document');
    $version=$newDocReceipt();
    $docDriver->afterRead=static function()use($observer){$observer->exec('UPDATE placements SET accounting_entity_id=99 WHERE id=2');};
    $reject(fn()=>$docImport($version),'placement source change after stored-byte verification rejects import');
    $observer->exec('UPDATE placements SET accounting_entity_id=8 WHERE id=2');
    $check($before===$docCounts(),'changed employer context leaves no evidence revision');
    $version=$newDocReceipt();
    $savedRemote=$qRemote;
    $docDriver->afterPut=static function()use(&$qRemote){$qRemote['publication']['state']='withdrawn';$qRemote['projection_hash']=onboardingHash($qRemote['publication']);};
    $reject(fn()=>$docImport($version),'provider withdrawal during storage rejects import');$qRemote=$savedRemote;
    $check($before===$docCounts(),'withdrawn publication leaves no registered document');
    $version=$newDocReceipt();
    $docDriver->afterPut=static function($key)use(&$qDuringRead,$docDriver){$qDuringRead=static function()use($key,$docDriver){$docDriver->put($key,'%PDF tampered',['mime'=>'application/pdf','source_is_bytes'=>true]);clearstatcache();};};
    $reject(fn()=>$docImport($version),'object overwrite after verification rejects the final database command');
    $check($before===$docCounts(),'object mismatch rolls back registration');
    $writes=$docDriver->writes;$reject(fn()=>$docImport($version),'a changed retained object cannot be replaced on retry');
    $check($docDriver->writes===$writes&&$transfer()['state']==='stored','mismatched retained bytes remain attributable and are never overwritten');
    $docDriver->delete($transfer()['storage_key']);
    $reject(fn()=>$docImport($version),'a missing previously verified object cannot be recreated by retry');
    $check($docDriver->writes===$writes,'a missing retained copy requires reconciliation without another write');
    $version=$newDocReceipt();
    $pdo->exec("CREATE TRIGGER fixture_reject_doc_event BEFORE INSERT ON staffing_onboarding_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic document history failure'");
    try{$docImport($version);throw new LogicException('Document event failure ignored');}catch(PDOException $e){$check(true,'document history failure is surfaced');}
    finally{$pdo->exec('DROP TRIGGER fixture_reject_doc_event');}
    $check($before===$docCounts()&&$docView()['version']===$version,'failed history insert rolls back document, storage registry and onboarding revision together');
    $writes=$docDriver->writes;$key=$transfer()['storage_key'];
    $check($transfer()['state']==='stored'&&$transfer()['document_id']===null,'history rollback retains the verified copy as an unfinished transfer');
    $result=$docImport($version);
    $check(!$result['replayed']&&$docCounts()[0]===$before[0]+1&&$docCounts()[1]===$before[1]+1,'retry after rolled-back history registers exactly one document');
    $check($docDriver->writes===$writes&&$transfer()['storage_key']===$key,'business retry reuses the original verified physical object');
    $check(!str_contains(onboardingJson($docView()),$object['s3_key'])&&!str_contains(onboardingJson($result),'signed_url'),'case and receipt responses disclose no physical path or signed file URL');
    $docCommand('reopen',['task_id'=>$docTask,'reason'=>'Interrupted source fetch']);$version=$newDocReceipt();
    $docFetchFail=true;$writes=$docDriver->writes;
    $reject(fn()=>$docImport($version),'interrupted source fetch leaves an attributable reservation');$docFetchFail=false;
    $reserved=$transfer();$check($reserved['state']==='reserved'&&$docDriver->writes===$writes&&$docStorage->head($reserved['storage_key'])===null,'failed fetch writes no physical object');
    $savedReceipt=$docReceipt;$savedRemote=$qRemote;$docReceipt['identity_id']='changed-identity';$qRemote['submissions'][count($qRemote['submissions'])-1]=$docReceipt;
    $downloads=$docDownloads;$reject(fn()=>$docImport($version),'the same receipt cannot replace its reserved source identity');
    $check($docDownloads===$downloads&&$docDriver->writes===$writes,'receipt conflict performs no file IO');$docReceipt=$savedReceipt;$qRemote=$savedRemote;
    $docImport($version);$check($transfer()['storage_key']===$reserved['storage_key']&&$docDriver->writes===$writes+1,'source-fetch retry uses the original reserved key');
    $docCommand('reopen',['task_id'=>$docTask,'reason'=>'Lost object-write acknowledgement']);$version=$newDocReceipt();$writes=$docDriver->writes;
    $docDriver->afterPut=static function(){throw new RuntimeException('Synthetic lost object PUT acknowledgement');};
    $docImport($version);$check($transfer()['state']==='registered'&&$docDriver->writes===$writes+1,'lost object PUT acknowledgement is reconciled by exact stored-byte verification');
    $docCommand('reopen',['task_id'=>$docTask,'reason'=>'File checkpoint interruption']);$version=$newDocReceipt();
    $pdo->exec("CREATE TRIGGER fixture_reject_file_checkpoint BEFORE UPDATE ON quanta_onboarding_document_transfers FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic file checkpoint failure'");
    try{$docImport($version);throw new LogicException('Checkpoint failure ignored');}catch(PDOException $e){$check(true,'file checkpoint failure is surfaced');}
    finally{$pdo->exec('DROP TRIGGER fixture_reject_file_checkpoint');}
    $reserved=$transfer();$writes=$docDriver->writes;
    $check($reserved['state']==='reserved'&&$docStorage->read($reserved['storage_key'],1024)===$docBytes,'file remains discoverable even when its stored checkpoint did not commit');
    $otherDriver=new QuantaVaultFixtureDriver($docRoot.'/alternative','synthetic-quanta-vault');$otherStorage=new \Core\StorageService($otherDriver);$downloads=$docDownloads;
    $reject(fn()=>quantaOnboardingImportDocument($docScope,$qId,$docReceipt['id'],$docDelivery,$version,$otherStorage),'a storage-location change cannot retarget an unfinished file copy');
    $check($otherDriver->writes===0&&$docDownloads===$downloads,'changed storage identity is rejected before download or write');
    $docImport($version);$check($docDriver->writes===$writes&&$transfer()['storage_key']===$reserved['storage_key'],'checkpoint recovery verifies and registers the existing object without another write');
    $transport=$GLOBALS['__quanta_transport'];$GLOBALS['__quanta_transport']=static function(){throw new LogicException('Provider must not be contacted for retained transfer history');};
    try{
        $history=quantaOnboardingTransfers($pdo,$docScope,$qId);
        $check(count($history['items'])>1&&!str_contains(onboardingJson($history),'storage_key')&&!str_contains(onboardingJson($history),'binding_hash'),'retained transfer history is available without provider access or private storage identity');
        $foreign=$docScope;$foreign['tenant_id']=12;$reject(fn()=>quantaOnboardingTransfers($pdo,$foreign,$qId),'another tenant cannot read retained document transfers');
        $foreign=$docScope;$foreign['people_tenant_id']=99;$reject(fn()=>quantaOnboardingTransfers($pdo,$foreign,$qId),'another People directory cannot read retained transfers');
    }finally{$GLOBALS['__quanta_transport']=$transport;}
    $beforeTransfer=$transfer();foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../../core/migrations/173_quanta_onboarding_document_transfers.sql'))as$sql)$pdo->exec($sql);
    $check($transfer()===$beforeTransfer,'transfer migration replay preserves file identity and registration');
    if($ci){
        $docCommand('reopen',['task_id'=>$docTask,'reason'=>'Overlapping import fixture']);$version=$newDocReceipt();
        $snapshot=quantaOnboardingSnapshot($docScope,$qId,$docHead);
        $fetched=quantaOnboardingDocumentFetch($snapshot['access']['api_key'],$qWorkspace,$snapshot['case'],$snapshot['remote'],$docReceipt,'worker-2',$qInstance);
        $pdo->beginTransaction();quantaOnboardingLock($pdo,$docScope,$snapshot,$docHead,false);
        $reserved=quantaOnboardingTransferReserve($pdo,$docScope,$snapshot['case'],$docReceipt,$snapshot['access'],$docStorage);$pdo->commit();
        $prepared=quantaOnboardingStoreDocument($docScope,$snapshot['case'],$fetched,$docStorage,$reserved);
        // The child reads the encrypted credential from MariaDB; do not serialize its binary ciphertext.
        unset($snapshot['access']['connection']['api_key_ct']);
        $packet=['scope'=>$docScope,'snapshot'=>$snapshot,'receipt'=>$docReceipt,'document'=>$prepared,'version'=>$version,'delivery_id'=>$docDelivery,'storage_root'=>$docRoot];
        $before=$docCounts();$children=[];$observer->beginTransaction();
        $lock=$observer->prepare('SELECT id FROM staffing_onboarding_cases WHERE tenant_id=11 AND id=? FOR UPDATE');$lock->execute([$qId]);$lock->fetchAll();
        try{
            foreach([1,2]as$i){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/../staffing_onboarding_mariadb.php','--document-retry-child',$db,base64_encode(onboardingJson($packet))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Could not start document overlap fixture');fclose($pipes[0]);$children[]=[$process,$pipes];}
            $waiting=0;$deadline=microtime(true)+8;
            do{$waiting=(int)$pdo->query('SELECT COUNT(*) FROM information_schema.innodb_lock_waits')->fetchColumn();if($waiting>=2)break;usleep(100000);}while(microtime(true)<$deadline);
            $check($waiting>=2,'two document requests overlap at the authority/case row locks');$observer->commit();$results=[];
            foreach($children as[$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0)throw new RuntimeException('Overlapping document command failed: '.$err);$results[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);}$children=[];
            $check($results[0]['document_id']===$results[1]['document_id']&&$results[0]['replayed']!==$results[1]['replayed'],'overlapping imports return one document and original durable receipt');
            $after=$docCounts();$check($after===[$before[0]+1,$before[1]+1,$before[2]+1],'overlapping import creates one registry, document and history event');
        }finally{if($observer->inTransaction())$observer->rollBack();foreach($children as[$process,$pipes]){if(is_resource($process))proc_terminate($process);foreach([1,2]as$i)if(is_resource($pipes[$i]))fclose($pipes[$i]);if(is_resource($process))proc_close($process);}}
    }
    echo 'PASS '.($passed-$docStart)." private document vault intake checks\n";
}finally{
    unset($GLOBALS['__quanta_document_transport']);putenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENTS');putenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENT_TENANTS');
    // Only synthetic files in the exact newly-created fixture root are removed.
    if(is_dir($docRoot)&&!is_link($docRoot)&&str_starts_with($docRoot,sys_get_temp_dir().'/cf-quanta-vault-')&&realpath($docRoot)===$docRoot){
        $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($entries as$entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($docRoot);
    }
}
