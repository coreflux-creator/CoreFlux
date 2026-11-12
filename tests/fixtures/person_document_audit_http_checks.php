<?php
/** Actual native session and storage reads, including audit/registry atomicity. */
RBACResolver::grantModule($membership,'people','admin',[$tenant],$actor);
RBACResolver::grantModule($childMembership,'people','admin',[$childTenant],$actor);
$pdo->prepare("INSERT INTO people(tenant_id,first_name,last_name,email_primary,classification,status) VALUES(?,'Synthetic','Document Audit','document-audit@example.invalid','w2','active')")->execute([$tenant]);
$auditPerson=(int)$pdo->lastInsertId();$auditPath='/modules/people/api/documents.php';
$auditKey='people/'.$tenant.'/document/'.$auditPerson.'/synthetic-audit.txt';$auditBytes='Original synthetic audited document bytes';
$documentDriver->put($auditKey,$auditBytes,['mime'=>'text/plain']);
$auditBody=['doc_type'=>'resume','filename'=>'synthetic-audit.txt','storage_key'=>$auditKey];
$auditTables=['people','people_documents','storage_objects','audit_log'];
$auditPost=$auditPath.'?person_id='.$auditPerson;
$auditFailure=static function(string $event,callable $requestFailure)use($pdo,$snapshot,$auditTables,$expect,$directory):void{
    $before=$snapshot($auditTables);
    $pdo->exec("CREATE TRIGGER cf_http_vault_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='{$event}' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic HTTP vault audit failure {$event}'; END IF; END");
    try{$expect($requestFailure()[0]===503&&str_contains((string)file_get_contents($directory.'/http.log'),'Synthetic HTTP vault audit failure '.$event)&&$snapshot($auditTables)===$before,'Native '.$event.' audit failure rolls back document, registry and resume pointer');}
    finally{$pdo->exec('DROP TRIGGER cf_http_vault_audit_failure');}
};
$auditFailure('people.document.uploaded',fn()=>$request('POST',$auditPost,$auditBody,true,$childTenant));
$expect($documentDriver->read($auditKey,1024)===$auditBytes,'Failed native registration retains the original private upload for retry');
[$auditStatus,$auditSaved]=$request('POST',$auditPost,$auditBody,true,$childTenant);
$expect($auditStatus===201&&!$auditSaved['replayed'],'Native retry after audit failure registers the original uploaded file');
$auditId=(int)$auditSaved['id'];$auditObject=(int)$auditSaved['storage_object_id'];$auditOne=$auditPath.'?id='.$auditId;
$q=$pdo->prepare("SELECT meta_json FROM audit_log WHERE tenant_id=? AND actor_user_id=? AND target_id=? AND event='people.document.uploaded'");$q->execute([$childTenant,$actor,$auditPerson]);$auditEvents=$q->fetchAll(PDO::FETCH_COLUMN);
$auditMeta=json_decode($auditEvents[0]??'{}',true);
$expect(count($auditEvents)===1&&($auditMeta['people_tenant_id']??null)===$tenant&&($auditMeta['document_id']??null)===$auditId,'One upload audit records the operating child, owning directory and committed document');
$q=$pdo->prepare('SELECT * FROM storage_objects WHERE id=? AND tenant_id=?');$q->execute([$auditObject,$tenant]);$auditRegistry=$q->fetch(PDO::FETCH_ASSOC);
$expect($auditRegistry['etag']==='"'.md5($auditBytes).'"'&&(int)$auditRegistry['size_bytes']===strlen($auditBytes),'Native person registration pins verified file fingerprint and length');
$before=$snapshot($auditTables);[$auditStatus,$auditRetry]=$request('POST',$auditPost,$auditBody,true,$childTenant);
$expect($auditStatus===200&&$auditRetry['replayed']&&$auditRetry['id']===$auditId&&$snapshot($auditTables)===$before,'Native upload replay adds no second document, object or audit event');
$documentDriver->put($auditKey,'Altered synthetic bytes',['mime'=>'text/plain']);clearstatcache();
$expect($request('GET',$auditOne)[0]===409&&$request('POST',$auditPost,$auditBody)[0]===409,'Changed original bytes cannot download or replay the saved person document');
$expect($snapshot($auditTables)===$before,'Physical mismatch changes no document, registry, resume or audit record');
$documentDriver->put($auditKey,$auditBytes,['mime'=>'text/plain']);clearstatcache();
$expect($request('GET',$auditOne)[0]===200,'Original verified bytes remain accessible after the rejected overwrite check');
$auditPatch=['doc_type'=>'offer','signed'=>true,'signed_at'=>'2026-11-12'];
$auditFailure('people.document.updated',fn()=>$request('PATCH',$auditOne,$auditPatch,true,$childTenant));
$expect($request('PATCH',$auditOne,$auditPatch,true,$childTenant)[0]===200,'Native metadata change succeeds with its required audit');
$before=$snapshot($auditTables);
$expect($request('PATCH',$auditOne,$auditPatch,true,$childTenant)[0]===200&&$snapshot($auditTables)===$before,'Identical native metadata retry adds no audit event');
$q=$pdo->prepare("SELECT meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event='people.document.updated' ORDER BY id DESC LIMIT 1");$q->execute([$childTenant,$auditPerson]);$auditChange=json_decode($q->fetchColumn(),true);
$expect(($auditChange['before']['doc_type']??null)==='resume'&&($auditChange['after']['doc_type']??null)==='offer'&&$auditChange['resume_storage_after']===null,'Native metadata audit preserves before/after values and the resume-pointer change');
$expect($request('PATCH',$auditOne,['doc_type'=>'resume'])[0]===200,'Native resume selection remains an audited metadata operation');
$auditFailure('people.document.deleted',fn()=>$request('DELETE',$auditOne,null,true,$childTenant));
$expect($request('DELETE',$auditOne,null,true,$childTenant)[0]===200,'Native retirement commits with its required audit');
$before=$snapshot($auditTables);
$expect($request('DELETE',$auditOne,null,true,$childTenant)[0]===200&&$snapshot($auditTables)===$before,'Native retirement replay creates no additional audit or resurrection');
$expect($request('PATCH',$auditOne,['signed'=>false])[0]===409&&$request('GET',$auditOne)[0]===409,'Retired person document cannot be edited or downloaded');
$expect($documentDriver->read($auditKey,1024)===$auditBytes,'Audit failure and retirement preserve original stored bytes');
RBACResolver::revokeModule($membership,'people',$actor);RBACResolver::revokeModule($childMembership,'people',$actor);
