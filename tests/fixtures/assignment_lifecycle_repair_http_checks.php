<?php
/** Actual native repair route with synthetic stored evidence; no provider requests. */
$repairPath='/api/admin/integrations/jobdiva_mapping_alignment.php?action=repair_stale_placements';
$repairPayload=$sharedRulePayload;$repairPayload['status']='Completed';
mappingBindStable($childTenant,'jobdiva','placement','jd:'.$sharedRuleStart,$sharedRulePlacement,$repairPayload,'pull',$actor);
$pdo->prepare('UPDATE placements SET status="active" WHERE tenant_id=? AND id=?')->execute([$tenant,$sharedRulePlacement]);
$before=$snapshot($ruleTables);$body=['dry_run'=>false,'limit'=>5000];
RBACResolver::grantModule($childMembership,'integrations','read',[$childTenant],$actor);
$expect($request('POST',$repairPath,$body,true,$childTenant)[0]===403&&$snapshot($ruleTables)===$before,'Native integration read access cannot repair assignment lifecycle');
$expect($request('POST',$repairPath,$body,false,$childTenant)[0]===401&&$snapshot($ruleTables)===$before,'Anonymous assignment repair requires native sign-in');
RBACResolver::grantModule($childMembership,'integrations','admin',[$childTenant],$actor);
[$status,$review]=$request('POST',$repairPath,['dry_run'=>true,'limit'=>5000],true,$childTenant);
$expect($status===200&&$review['repair']['ended']===1&&$review['repair']['failed']===0&&$snapshot($ruleTables)===$before,'Native lifecycle preview finds exact child source in shared directory without writes');
$pdo->prepare('UPDATE placements SET coreflux_overridden_fields=\'["notes","status"]\' WHERE tenant_id=? AND id=?')->execute([$tenant,$sharedRulePlacement]);$before=$snapshot($ruleTables);
[$status,$held]=$request('POST',$repairPath,$body,true,$childTenant);
$expect($status===200&&$held['repair']['updated']===0&&$snapshot($ruleTables)===$before,'Native lifecycle repair preserves a newly Core-owned status');
$pdo->prepare('UPDATE placements SET coreflux_overridden_fields=\'["notes"]\' WHERE tenant_id=? AND id=?')->execute([$tenant,$sharedRulePlacement]);
$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$childTenant]);$before=$snapshot($ruleTables);
[$status,$scope]=$request('POST',$repairPath,$body,true,$childTenant);
$expect($status===200&&$scope['repair']['failed']>=1&&$snapshot($ruleTables)===$before,'Native lifecycle repair refuses an assignment outside the current directory');
$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="shared" WHERE tenant_id=? AND module_key="placements"')->execute([$childTenant]);$before=$snapshot($ruleTables);
$pdo->exec("CREATE TRIGGER cf_http_lifecycle_repair_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='placement.status_repaired_from_jobdiva_lifecycle' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic native lifecycle audit failure'; END IF; END");
try{[$status,$failed]=$request('POST',$repairPath,$body,true,$childTenant);$expect($status===200&&$failed['repair']['failed']===1&&$snapshot($ruleTables)===$before,'Required native lifecycle audit failure rolls back the shared status and commercial graph');}
finally{$pdo->exec('DROP TRIGGER cf_http_lifecycle_repair_audit');}
$retained=$snapshot(['people','placement_rates','core_record_numbers','external_entity_mappings']);
[$status,$saved]=$request('POST',$repairPath,$body,true,$childTenant);
$q=$pdo->prepare('SELECT status,external_id,tenant_id FROM placements WHERE id=?');$q->execute([$sharedRulePlacement]);$row=$q->fetch(PDO::FETCH_ASSOC);
$expect($status===200&&$saved['repair']['ended']===1&&$saved['repair']['failed']===0&&$row['status']==='ended'&&(int)$row['tenant_id']===$tenant&&$row['external_id']==='http-shared-placement-legacy','Native lifecycle repair changes the existing shared assignment');
$expect($retained===$snapshot(['people','placement_rates','core_record_numbers','external_entity_mappings']),'Native lifecycle repair preserves workers, approved rates, permanent numbers and provider pairs');
$q=$pdo->prepare('SELECT actor_user_id,meta_json FROM audit_log WHERE tenant_id=? AND event="placement.status_repaired_from_jobdiva_lifecycle" AND target_id=?');$q->execute([$childTenant,$sharedRulePlacement]);$audit=$q->fetch(PDO::FETCH_ASSOC);$meta=json_decode($audit['meta_json'],true,512,JSON_THROW_ON_ERROR);
$expect((int)$audit['actor_user_id']===$actor&&$meta['directory_owner_id']===$tenant&&$meta['before']['placement']['status']==='active'&&$meta['after']['placement']['status']==='ended','Native lifecycle audit retains original workspace, authenticated actor and before/after graph');
$before=$snapshot($ruleTables);[$status,$again]=$request('POST',$repairPath,$body,true,$childTenant);
$expect($status===200&&$again['repair']['updated']===0&&$snapshot($ruleTables)===$before,'Native lifecycle replay does not repeat status, graph or audit writes');
RBACResolver::revokeModule($childMembership,'integrations',$actor);
$expect($request('POST',$repairPath,$body,true,$childTenant)[0]===403&&$snapshot($ruleTables)===$before,'Native integration revocation immediately removes lifecycle repair access');
$expect($snapshot($financialTables)===$financialBefore,'Native lifecycle repair creates no accounting, payroll or payment execution');
