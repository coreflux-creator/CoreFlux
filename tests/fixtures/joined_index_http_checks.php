<?php
/** Native source-field refresh on its own synthetic original workspace. */
$indexTenant=900015;
if($pdo->query('SELECT id FROM tenants WHERE id=900015')->fetchColumn())throw new RuntimeException('Source-field HTTP workspace already exists');
$pdo->prepare('INSERT INTO tenants(id,name,parent_id,tenant_type,is_active,is_simulation) VALUES(?,?,?,"sub",1,1)')->execute([$indexTenant,'Synthetic source-field workspace',$tenant]);
$indexMembership=(int)provisionMembership($actor,$indexTenant,'admin',['is_primary'=>false,'persona_label'=>'Synthetic source-field reviewer','status'=>'active'])['membership_id'];
try{
    foreach(['companies','people','placements','staffing','accounting']as$module)$pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,?,"shared")')->execute([$indexTenant,$module]);
    $pdo->prepare('INSERT INTO placements(tenant_id,person_id,title,status,engagement_type,start_date,external_id,worksite_country) VALUES(?,?,"Native field refresh title","draft","w2","2026-10-01","retained-field-refresh-number","US")')->execute([$tenant,$httpRatePerson]);$indexPlacement=(int)$pdo->lastInsertId();
    $indexRoot=['startId'=>'99199001','candidateId'=>'99199002','jobId'=>'99199003','companyId'=>'99199004','startDate'=>'2026-10-01','status'=>'Active'];
    $indexPayload=$indexRoot+['_jd_contract'=>['start_id'=>'99199001','candidate_id'=>'99199002','job_id'=>'99199003','bill_rate'=>100,'pay_rate'=>50,'currency'=>'USD']];
    mappingUpsert($indexTenant,'jobdiva','person','jd:99199002',$httpRatePerson,['id'=>'99199002']);
    mappingUpsert($indexTenant,'jobdiva','jobdiva_candidate','99199002',$httpRatePerson,['id'=>'99199002','firstName'=>'HTTP source-field worker']);
    mappingUpsert($indexTenant,'jobdiva','jobdiva_job','99199003',99199003,['id'=>'99199003','title'=>'HTTP source-field job']);
    mappingUpsert($indexTenant,'jobdiva','company','99199004',$own['company'],['id'=>'99199004','name'=>'HTTP source-field company']);
    mappingUpsert($indexTenant,'jobdiva','jobdiva_assignment','99199001',$indexPlacement,$indexRoot);
    $indexMapping=mappingUpsert($indexTenant,'jobdiva','placement','jd:99199001',$indexPlacement,$indexPayload);$indexMappingId=(int)$indexMapping['id'];
    $indexPath='/api/admin/integrations/reindex_jobdiva_subpayloads.php';
    $indexTables=array_values(array_unique([...$graphTables,'external_entity_mappings','entity_sync_history','integration_payload_field_index','audit_log']));
    RBACResolver::grantModule($indexMembership,'integrations','read',[$indexTenant],$actor);$before=$snapshot($indexTables);
    $expect($request('POST',$indexPath,[],true,$indexTenant)[0]===403&&$snapshot($indexTables)===$before,'Read-only integration access cannot refresh source evidence');
    RBACResolver::grantModule($indexMembership,'integrations','admin',[$indexTenant],$actor);
    $expect($request('POST',$indexPath,[],false,$indexTenant)[0]===401&&$snapshot($indexTables)===$before,'Source-field refresh requires native sign-in');
    $expect($request('GET',$indexPath,null,true,$indexTenant)[0]===405&&$snapshot($indexTables)===$before,'Source-field refresh cannot mutate on GET');
    $nativeBefore=$snapshot(['people','placements','placement_rates','placement_economic_parties','core_record_numbers','entity_sync_history']);
    $pdo->exec("CREATE TRIGGER cf_http_joined_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900015 AND BINARY NEW.event=BINARY 'jobdiva.source_fields.refreshed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic source-field audit failure'; END IF; END");
    try{[$status,$failed]=$request('POST',$indexPath,[],true,$indexTenant);$expect($status===409&&$snapshot($indexTables)===$before,'Late native source-field audit failure leaves snapshot and indexes unchanged');}
    finally{$pdo->exec('DROP TRIGGER cf_http_joined_audit');}
    [$status,$refreshed]=$request('POST',$indexPath,[],true,$indexTenant);
    $expect($status===200&&(int)$refreshed['placements_walked']===1&&!empty($refreshed['sub_records_indexed']['person']),'Native source-field refresh indexes original-workspace fields for a shared placement: '.json_encode([$status,$refreshed],JSON_THROW_ON_ERROR));
    $expect((int)$refreshed['enrichment_ran_for']===0&&(int)$refreshed['snapshots_refreshed']<=1,'Local source-field refresh reports completed snapshots separately from remote lookups');
    $expect($snapshot(['people','placements','placement_rates','placement_economic_parties','core_record_numbers','entity_sync_history'])===$nativeBefore,'Native field refresh preserves business rows, approved history and permanent numbers');
    $q=$pdo->prepare('SELECT actor_user_id,meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event="jobdiva.source_fields.refreshed"');$q->execute([$indexTenant,$indexMappingId]);$receipt=$q->fetch(PDO::FETCH_ASSOC);$meta=json_decode($receipt['meta_json'],true,512,JSON_THROW_ON_ERROR);
    $expect((int)$receipt['actor_user_id']===$actor&&$meta['directory_owner_id']===$tenant&&$meta['source_external_id']==='jd:99199001','Native refresh receipt retains authenticated actor, owner and original source pair');
    $pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$indexTenant]);$before=$snapshot($indexTables);
    $expect($request('POST',$indexPath,[],true,$indexTenant)[0]===409&&$snapshot($indexTables)===$before,'Changed directory access blocks native field refresh without leaking shared records');
}finally{RBACResolver::revokeModule($indexMembership,'integrations',$actor);deactivateMembership($actor,$indexTenant);}
