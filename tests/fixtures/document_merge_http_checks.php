<?php
/** Included by the real-session disposable HTTP harness. */
RBACResolver::grantModule($membership,'people','admin',[$tenant],$actor);
RBACResolver::grantModule($childMembership,'people','admin',[$childTenant],$actor);
$mergePath='/modules/people/api/merge.php';$peopleDocs='/modules/people/api/documents.php';
$mergePeople=[];
foreach(['primary','duplicate','third','foreign']as$label){
    $owner=$label==='foreign'?$foreignTenant:$tenant;
    $q=$pdo->prepare("INSERT INTO people(tenant_id,first_name,last_name,email_primary,external_id,classification,status) VALUES(?,'Synthetic',?,?,?,'w2','active')");
    $q->execute([$owner,'Merge '.$label,'http-merge-'.$label.'@example.invalid','preserved-provider-'.$label]);$mergePeople[$label]=(int)$pdo->lastInsertId();
}
$primary=$mergePeople['primary'];$duplicate=$mergePeople['duplicate'];$third=$mergePeople['third'];
$key='people/'.$tenant.'/document/'.$duplicate.'/synthetic-http-merge.txt';
$documentDriver->put($key,'Original synthetic HTTP merge bytes',['mime'=>'text/plain']);
[$status,$saved]=$request('POST',$peopleDocs.'?person_id='.$duplicate,['doc_type'=>'other','filename'=>'synthetic-http-merge.txt','storage_key'=>$key]);
$expect($status===201,'Native person vault creates the synthetic merge document');$mergeDoc=(int)$saved['id'];$mergeObject=(int)$saved['storage_object_id'];
$mergeTables=['people','people_documents','storage_objects','document_ownership_transfers','core_record_numbers'];
$body=['primary_person_id'=>$primary,'duplicate_person_id'=>$duplicate,'field_resolutions'=>['external_id'=>'duplicate']];
$before=$snapshot($mergeTables);
$expect($request('POST',$mergePath,array_replace($body,['primary_person_id'=>$mergePeople['foreign']]))[0]===404,'Native person merge refuses a foreign survivor');
// Prime persisted public numbers before testing a later transaction failure.
require_once __DIR__.'/../../core/record_numbers.php';
coreRecordNumbersAttach($pdo,$tenant,'person',[['id'=>$primary],['id'=>$duplicate],['id'=>$third]]);
$before=$snapshot($mergeTables);
$pdo->exec("CREATE TRIGGER cf_http_merge_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='people.merged' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic merge audit failure'; END IF; END");
try{$expect($request('POST',$mergePath,$body)[0]===500&&$snapshot($mergeTables)===$before,'Native merge audit failure restores person, document, alias and transfer history together');}
finally{$pdo->exec('DROP TRIGGER cf_http_merge_audit_failure');}
RBACResolver::grantModule($membership,'people','read',[$tenant],$actor);
$expect($request('POST',$mergePath,$body)[0]===403&&$snapshot($mergeTables)===$before,'Read-only native member cannot merge or transfer documents');
RBACResolver::grantModule($membership,'people','admin',[$tenant],$actor);
[$status,$merged]=$request('POST',$mergePath,$body,true,$childTenant);
$expect($status===200&&(int)$merged['merged_counts']['people_documents']===1,'Authorized child workspace merges parent-owned documents in the resolved directory');
[$status,$opened]=$request('GET',$peopleDocs.'?id='.$mergeDoc,null,true,$childTenant);
$expect($status===200&&(int)$opened['document']['person_id']===$primary&&(int)$opened['document']['storage_object_id']===$mergeObject,'Native merged document download retains original document and storage identities');
$q=$pdo->prepare('SELECT external_id FROM people WHERE tenant_id=? AND id=?');$q->execute([$tenant,$primary]);
$expect($q->fetchColumn()==='preserved-provider-primary','Merge cannot overwrite the surviving provider ID through field resolution');
$before=$snapshot($mergeTables);
$expect($request('POST',$mergePath,$body,true,$childTenant)[0]===404&&$snapshot($mergeTables)===$before,'Repeated native merge does not duplicate document or transfer history');
$expect($request('GET',$peopleDocs.'?person_id='.$duplicate)[0]===409,'Retired source person cannot issue document access');
[$status,$again]=$request('POST',$mergePath,['primary_person_id'=>$third,'duplicate_person_id'=>$primary],true,$childTenant);
$expect($status===200&&(int)$again['merged_counts']['people_documents']===1,'A second native merge retains the original document through two owners');
[$status,$opened]=$request('GET',$peopleDocs.'?id='.$mergeDoc);
$expect($status===200&&(int)$opened['document']['person_id']===$third,'Native download follows the entire authorized transfer chain');
$q=$pdo->prepare('SELECT * FROM document_ownership_transfers WHERE tenant_id=? AND module="people" AND document_id=? ORDER BY sequence_no');$q->execute([$tenant,$mergeDoc]);$history=$q->fetchAll(PDO::FETCH_ASSOC);
$expect(count($history)===2&&(int)$history[0]['from_record_id']===$duplicate&&(int)$history[1]['to_record_id']===$third&&(int)$history[1]['actor_user_id']===$actor,'Both native merge receipts retain original owner, survivor and authenticated operator');
$expect($documentDriver->read($key,1024)==='Original synthetic HTTP merge bytes','Native merges leave the original physical file unchanged');
$expect($request('DELETE',$peopleDocs.'?id='.$mergeDoc)[0]===200&&$request('GET',$peopleDocs.'?id='.$mergeDoc)[0]===409,'Native retirement after merging preserves the download prohibition');
RBACResolver::revokeModule($membership,'people',$actor);RBACResolver::revokeModule($childMembership,'people',$actor);
