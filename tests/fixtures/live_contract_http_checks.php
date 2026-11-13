<?php
/** Full signed-in batch with a synthetic transport and real source/native transactions. */
$liveTenant=900016;
if($pdo->query('SELECT id FROM tenants WHERE id=900016')->fetchColumn())throw new RuntimeException('Live-contract HTTP workspace already exists');
$pdo->prepare('INSERT INTO tenants(id,name,parent_id,tenant_type,is_active,is_simulation) VALUES(?,?,?,"sub",1,1)')->execute([$liveTenant,'Synthetic live-contract workspace',$tenant]);
$liveMembership=(int)provisionMembership($actor,$liveTenant,'admin',['is_primary'=>false,'persona_label'=>'Synthetic live contract reviewer','status'=>'active'])['membership_id'];
try{
    foreach(['companies','people','placements','staffing','accounting']as$module)$pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,?,"shared")')->execute([$liveTenant,$module]);
    $pdo->prepare('INSERT INTO people(tenant_id,first_name,last_name,email_primary,classification,status,external_id) VALUES(?,"Synthetic HTTP","Live contract","live-contract-http@example.invalid","w2","active","retained-live-http-person")')->execute([$tenant]);$livePerson=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO placements(tenant_id,person_id,title,status,engagement_type,start_date,external_id,worksite_country,coreflux_overridden_fields) VALUES(?,?,"Native contract title","on_hold","w2","2026-10-01","retained-live-http-placement","US",?)')->execute([$tenant,$livePerson,'["title"]']);$livePlacement=(int)$pdo->lastInsertId();
    $liveRoot=['startId'=>'99320001','candidateId'=>'99320002','jobId'=>'99320003','startDate'=>'2026-10-01','status'=>'on_hold','title'=>'New remote title','worksiteCountry'=>'US'];
    $liveRows=[['Start ID'=>'99320001','Candidate ID'=>'99320002','Job ID'=>'99320003','Employment Category'=>'Hourly Employee','W2'=>true,'Bill Rate'=>100,'Pay Rate'=>50,'Bill Rate Unit'=>'hour','Pay Rate Unit'=>'hour','Currency'=>'USD','Start Date'=>'2026-10-01',
        'BILLING'=>[['Approved'=>true,'Actual Start'=>false,'COMPANY'=>[['ID'=>'99320004','Name'=>'Synthetic live contract HTTP client']]]],'SALARY'=>[['Approved'=>true]]]];
    mappingUpsert($liveTenant,'jobdiva','person','jd:99320002',$livePerson,['candidateId'=>'99320002']);
    mappingUpsert($liveTenant,'jobdiva','jobdiva_job','99320003',99320003,['id'=>'99320003','title'=>'HTTP live contract job','Per Diem'=>777]);
    $liveMapping=mappingUpsert($liveTenant,'jobdiva','placement','jd:99320001',$livePlacement,$liveRoot);$liveMappingId=(int)$liveMapping['id'];
    jobdivaSaveConnection($liveTenant,['client_id'=>'99320000','username'=>'fixture@example.invalid','password'=>'synthetic-unused-password'],$actor);
    $pdo->prepare('UPDATE jobdiva_connections SET session_token_enc=?,session_token_exp=DATE_ADD(NOW(),INTERVAL 1 DAY),status="connected" WHERE tenant_id=?')->execute([encryptField('synthetic-live-contract-token'),$liveTenant]);
    $liveFixture=['root'=>$liveRoot,'rows'=>$liveRows,'placement'=>$livePlacement,'mode'=>'ok'];
    $liveWrite=static function(array $fixture)use($directory):void{$bytes=json_encode($fixture,JSON_THROW_ON_ERROR);if(file_put_contents($directory.'/jobdiva-live.json',$bytes)!==strlen($bytes))throw new RuntimeException('Fixture write failed');};
    $liveWrite($liveFixture);$livePath='/api/jobdiva.php?action=assignment_contracts_batch';
    $liveTables=array_values(array_unique([...$graphTables,'entity_sync_history','core_record_numbers',...$financialTables]));
    RBACResolver::grantModule($liveMembership,'integrations','read',[$liveTenant],$actor);$before=$snapshot($liveTables);
    $expect($request('POST',$livePath,[],true,$liveTenant)[0]===403&&$snapshot($liveTables)===$before,'Read-only integration access cannot run the live contract batch');
    RBACResolver::grantModule($liveMembership,'integrations','admin',[$liveTenant],$actor);
    $expect($request('POST',$livePath,[],false,$liveTenant)[0]===401,'Live contract sync requires native sign-in');
    $expect($request('GET',$livePath,null,true,$liveTenant)[0]===405,'Live contract sync cannot mutate on GET');
    $expect(!is_file($directory.'/jobdiva-live.log'),'Denied contract requests never contact the provider');
    $pdo->exec("CREATE TRIGGER cf_http_live_contract_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900016 AND BINARY NEW.event=BINARY 'placement.live_contract.refreshed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic live contract audit failure'; END IF; END");
    try{[$status,$failed]=$request('POST',$livePath,[],true,$liveTenant);$expect($status===200&&(int)$failed['failed']===1&&$snapshot($liveTables)===$before,'Native live-contract audit failure rolls back all business and mapping writes: '.json_encode([$status,$failed]));}
    finally{$pdo->exec('DROP TRIGGER cf_http_live_contract_audit');}
    $financialBefore=$snapshot($financialTables);
    [$status,$refreshed]=$request('POST',$livePath,['limit'=>999],true,$liveTenant);
    $expect($status===200&&(int)$refreshed['projected']===1&&(int)$refreshed['failed']===0&&(int)$refreshed['processed']===1&&(int)$refreshed['cursor']===$liveMappingId,'Native live batch refreshes a shared-owner placement through the original workspace: '.json_encode([$status,$refreshed]));
    $q=$pdo->prepare('SELECT title,external_id,person_id FROM placements WHERE id=?');$q->execute([$livePlacement]);$saved=$q->fetch(PDO::FETCH_ASSOC);
    $expect($saved['title']==='Native contract title'&&$saved['external_id']==='retained-live-http-placement'&&(int)$saved['person_id']===$livePerson,'Live API preserves native title, person identity and permanent placement number');
    $q=$pdo->prepare('SELECT external_id,content_hash,payload_snapshot FROM external_entity_mappings WHERE id=?');$q->execute([$liveMappingId]);$savedLink=$q->fetch(PDO::FETCH_ASSOC);
    $expect($savedLink['external_id']==='jd:99320001'&&$savedLink['content_hash']===mappingHash(json_decode($savedLink['payload_snapshot'],true)),'Live API retains the exact provider pair and consistent source fingerprint');
    $q=$pdo->prepare('SELECT actor_user_id,meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event="placement.live_contract.refreshed"');$q->execute([$liveTenant,$livePlacement]);$receipt=$q->fetch(PDO::FETCH_ASSOC);$meta=json_decode($receipt['meta_json'],true);
    $expect((int)$receipt['actor_user_id']===$actor&&$meta['directory_owner_id']===$tenant,'Live API receipt records the authenticated actor and actual directory owner');
    [$status,$finished]=$request('POST',$livePath,['cursor'=>$liveMappingId],true,$liveTenant);
    $expect($status===200&&!empty($finished['done'])&&(int)$finished['processed']===0,'Browser cursor drain completes without processing the same mapping twice');
    $counts=[];foreach(['people','placements','placement_rates','external_entity_mappings']as$table)$counts[$table]=(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    [$status,$replay]=$request('POST',$livePath,[],true,$liveTenant);
    foreach($counts as$table=>$count)$expect((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$count,'Live replay does not duplicate '.$table);
    $expect($status===200&&(int)$replay['failed']===0,'A fresh live replay succeeds');
    $liveWrite(array_replace($liveFixture,['mode'=>'stale']));$linkBefore=$snapshot(['external_entity_mappings','entity_sync_history','placement_rates']);
    [$status,$stale]=$request('POST',$livePath,[],true,$liveTenant);
    $q=$pdo->prepare('SELECT title FROM placements WHERE id=?');$q->execute([$livePlacement]);
    $expect($status===200&&(int)$stale['failed']===1&&$q->fetchColumn()==='Later committed native title'&&$snapshot(['external_entity_mappings','entity_sync_history','placement_rates'])===$linkBefore,'A native edit committed during lookup defeats the stale API save');
    $liveWrite(array_replace($liveFixture,['mode'=>'wrong_start']));$beforeNative=$snapshot(['people','placements','placement_rates','companies','placement_economic_parties']);
    $calls=substr_count((string)file_get_contents($directory.'/jobdiva-live.log'),'EmployeeAssignmentRecordsDetail');
    [$status,$unverified]=$request('POST',$livePath,[],true,$liveTenant);
    $expect($status===200&&(int)$unverified['failed']===1&&(int)$unverified['projected']===0&&$snapshot(['people','placements','placement_rates','companies','placement_economic_parties'])===$beforeNative
        &&substr_count((string)file_get_contents($directory.'/jobdiva-live.log'),'EmployeeAssignmentRecordsDetail')===$calls,'A different returned Start cannot trigger contract lookup or native financial projection');
    $liveWrite($liveFixture);$pdo->prepare('UPDATE jobdiva_connections SET sync_config=? WHERE tenant_id=?')->execute([json_encode(['placement'=>['source'=>'coreflux','direction'=>'off']]),$liveTenant]);
    $before=$snapshot($liveTables);$calls=(string)file_get_contents($directory.'/jobdiva-live.log');
    [$status,$disabled]=$request('POST',$livePath,[],true,$liveTenant);
    $expect($status===200&&(int)$disabled['failed']===1&&$snapshot($liveTables)===$before&&(string)file_get_contents($directory.'/jobdiva-live.log')===$calls,'Disabled source ownership policy prevents provider reads and native writes');
    $expect($snapshot($financialTables)===$financialBefore,'Live contract refresh never executes payroll, payments or financial postings');
    $alignmentPath='/api/admin/integrations/jobdiva_mapping_alignment.php?sample_limit=1';
    $expect($request('GET',$alignmentPath,null,false,$liveTenant)[0]===401,'Alignment report requires native sign-in');
    RBACResolver::grantModule($liveMembership,'integrations','read',[$liveTenant],$actor);
    $before=$snapshot($liveTables);$providerCalls=(string)file_get_contents($directory.'/jobdiva-live.log');
    [$status,$alignmentResponse]=$request('GET',$alignmentPath,null,true,$liveTenant);
    $expect($status===200&&($alignmentResponse['complete']??false)===true&&(int)$alignmentResponse['directory_owners']['placements']===$tenant
        &&(int)$alignmentResponse['relationships']['projector']['workflow_readiness']['missing_placement']===0,'Native read-only alignment follows current shared directories: '.json_encode([$status,$alignmentResponse['issues']??[]]));
    $expect($snapshot($liveTables)===$before&&(string)file_get_contents($directory.'/jobdiva-live.log')===$providerCalls,'Alignment HTTP read changes no records and contacts no provider');
    $pdo->prepare('UPDATE jobdiva_connections SET sync_config=? WHERE tenant_id=?')->execute(['{"placement":{"source":"invalid","direction":"pull"}}',$liveTenant]);
    [$status,$unavailable]=$request('GET',$alignmentPath,null,true,$liveTenant);
    $expect($status===200&&($unavailable['complete']??null)===false&&!isset($unavailable['relationships']),'Native failed alignment returns unavailable without zero readiness counters');
    RBACResolver::revokeModule($liveMembership,'integrations',$actor);
    $expect($request('GET',$alignmentPath,null,true,$liveTenant)[0]===403,'Alignment permission removal takes effect on the next request');
}finally{
    $pdo->prepare('DELETE FROM jobdiva_connections WHERE tenant_id=?')->execute([$liveTenant]);
    RBACResolver::revokeModule($liveMembership,'integrations',$actor);deactivateMembership($actor,$liveTenant);
    $pdo->prepare('UPDATE tenants SET is_active=0 WHERE id=?')->execute([$liveTenant]);
}
