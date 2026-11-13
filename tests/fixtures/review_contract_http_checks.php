<?php
/** Exercise the existing native queue endpoint with a lock-free synthetic provider. */
$reviewPath='/api/jobdiva.php?action=review_assignment_contracts_batch';$reviewToken='synthetic-http-review';
$reviewRoot=array_replace($liveRoot,['status'=>'Offer accepted','__cf_jobdiva_review_run_token'=>$reviewToken]);
$reviewMap=mappingUpsert($liveTenant,'jobdiva','jobdiva_assignment_review','jd:99320001',99320001,$reviewRoot);$reviewId=(int)$reviewMap['id'];
$pdo->prepare('UPDATE external_entity_mappings SET last_seen_at="2026-10-03 04:05:06" WHERE id IN(?,?)')->execute([$reviewId,$liveMappingId]);
$before=$snapshot($liveTables);$calls=(string)file_get_contents($directory.'/jobdiva-live.log');
$expect($request('POST',$reviewPath,['run_token'=>$reviewToken],false,$liveTenant)[0]===401,'Queued contract review requires native sign-in');
$expect($request('GET',$reviewPath,null,true,$liveTenant)[0]===405,'Queued contract review cannot mutate on GET');
RBACResolver::grantModule($liveMembership,'integrations','read',[$liveTenant],$actor);
$expect($request('POST',$reviewPath,['run_token'=>$reviewToken],true,$liveTenant)[0]===403,'Read-only integration access cannot resolve queued contracts');
RBACResolver::grantModule($liveMembership,'integrations','admin',[$liveTenant],$actor);
$expect($snapshot($liveTables)===$before&&(string)file_get_contents($directory.'/jobdiva-live.log')===$calls,'Denied queue requests change no records and make no provider calls');
[$status,$otherRun]=$request('POST',$reviewPath,['run_token'=>'different-run'],true,$liveTenant);
$expect($status===200&&(int)$otherRun['processed']===0&&$snapshot($liveTables)===$before&&(string)file_get_contents($directory.'/jobdiva-live.log')===$calls,'Queue cursor only selects the requested reconciliation run');
$pdo->exec("CREATE TRIGGER cf_http_review_contract_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900016 AND BINARY NEW.event=BINARY 'jobdiva.contract_review.applied' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic queued contract audit failure'; END IF; END");
try{
    [$status,$failed]=$request('POST',$reviewPath,['run_token'=>$reviewToken],true,$liveTenant);
    $expect($status===200&&(int)$failed['failed']===1&&$snapshot($liveTables)===$before,'Native queue receipt failure rolls back business, source and observation writes: '.json_encode([$status,$failed]));
}finally{$pdo->exec('DROP TRIGGER cf_http_review_contract_audit');}
[$status,$applied]=$request('POST',$reviewPath,['run_token'=>$reviewToken,'limit'=>999],true,$liveTenant);
$expect($status===200&&(int)$applied['failed']===0&&(int)$applied['updated']===1&&(int)$applied['processed']===1&&(int)$applied['cursor']===$reviewId,'Native review queue projects one exact current shared assignment: '.json_encode([$status,$applied]));
$q=$pdo->prepare('SELECT external_id,internal_entity_id,last_seen_at,content_hash,payload_snapshot FROM external_entity_mappings WHERE id=?');$q->execute([$liveMappingId]);$queueLink=$q->fetch(PDO::FETCH_ASSOC);
$expect($queueLink['external_id']==='jd:99320001'&&(int)$queueLink['internal_entity_id']===$livePlacement&&$queueLink['last_seen_at']==='2026-10-03 04:05:06'
    &&$queueLink['content_hash']===mappingHash(json_decode($queueLink['payload_snapshot'],true)),'Native queue retains provider binding, original census timestamp and consistent fingerprint');
$q=$pdo->prepare('SELECT actor_user_id,meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event="jobdiva.contract_review.applied"');$q->execute([$liveTenant,$reviewId]);$queueReceipt=$q->fetch(PDO::FETCH_ASSOC);
$expect((int)$queueReceipt['actor_user_id']===$actor&&(int)json_decode($queueReceipt['meta_json'],true)['directory_owner_id']===$tenant,'Queue receipt identifies the authenticated reviewer and current directory');
[$status,$finished]=$request('POST',$reviewPath,['run_token'=>$reviewToken,'cursor'=>$reviewId],true,$liveTenant);
$expect($status===200&&!empty($finished['done'])&&(int)$finished['processed']===0,'Queue cursor drain does not process the same review twice');
$liveWrite(array_replace($liveFixture,['mode'=>'missing_contract']));$before=$snapshot($liveTables);
[$status,$missing]=$request('POST',$reviewPath,['run_token'=>$reviewToken],true,$liveTenant);
$expect($status===200&&(int)$missing['unavailable']===1&&(int)$missing['failed']===0&&(int)$missing['projected']===0&&$snapshot($liveTables)===$before,'Missing exact salary evidence remains unavailable without deletion or native writes');
$liveWrite($liveFixture);
$expect($snapshot($financialTables)===$financialBefore,'Queued contract review executes no payroll, payment or financial posting');
