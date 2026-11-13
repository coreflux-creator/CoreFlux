<?php
/** Full schema, isolated invented records, no provider or financial execution. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('CI')!=='true'||getenv('DB_NAME')!=='coreaccounting_ci')exit(2);
require_once __DIR__.'/../core/jobdiva/mapping_alignment.php';
require_once __DIR__.'/../modules/staffing/lib/client_catalog_review.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$checks=[];$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks[]=$label;};
$fails=static function(callable $f):bool{try{$f();return false;}catch(Throwable){return true;}};
$read=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC)?:[];};
$all=static fn(string $table):array=>$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
$graph=static fn():array=>array_map($all,['companies','company_roles','staffing_clients','placements','external_entity_mappings','entity_sync_history','core_record_numbers','audit_log']);
$parent=990695;$child=990696;$shadow=false;
if($pdo->query('SELECT id FROM tenants WHERE id IN(990695,990696)')->fetchColumn())throw new RuntimeException('Repair fixture already exists');
$protected=[$all('accounting_events'),$all('accounting_journal_entries'),$all('time_entries')];$pdo->beginTransaction();
try{
    $pdo->exec('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type) VALUES(990695,"Synthetic repair parent",1,1,"master")');
    $pdo->exec('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type,parent_id) VALUES(990696,"Synthetic repair child",1,1,"sub",990695)');
    $pdo->exec('INSERT INTO users(tenant_id,name,email,role,is_active,password,password_hash) VALUES(990695,"Synthetic repair reviewer","repair-review@example.invalid","admin",1,NULL,NULL)');$actor=(int)$pdo->lastInsertId();
    foreach(['shared/shared','shared/isolated','isolated/isolated']as$index=>$mode){
        [$companyMode,$placementMode]=explode('/',$mode);$owner=$placementMode==='shared'?$parent:$child;$companyOwner=$companyMode==='shared'?$parent:$child;
        foreach(['companies'=>$companyMode,'placements'=>$placementMode]as$module=>$value)$pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,?,?) ON DUPLICATE KEY UPDATE scope_mode=VALUES(scope_mode)')->execute([$child,$module,$value]);
        $external='repair-bill-'.$index;$name='Synthetic bill '.$index;
        $company=jobdivaCompanyUpsertStable($child,$external,$name,[],['id'=>$external],$actor,['client']);
        $client=(int)mappingFindInternal($child,'jobdiva','staffing_client',$external)['internal_entity_id'];
        $other=jobdivaCompanyUpsertStable($child,'repair-other-'.$index,'Other bill '.$index,[],[],$actor,['client']);
        $otherClient=(int)mappingFindInternal($child,'jobdiva','staffing_client','repair-other-'.$index)['internal_entity_id'];
        $pdo->prepare('INSERT INTO people(tenant_id,first_name,last_name) VALUES(?,"Synthetic","Repair worker")')->execute([$owner]);$person=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO placements(tenant_id,person_id,title,status,engagement_type,start_date,client_id,end_client_company_id) VALUES(?,?,"Synthetic repair assignment","active","W2","2026-11-01",?,?)')->execute([$owner,$person,$otherClient,$other]);$placement=(int)$pdo->lastInsertId();
        $start=(string)(41001+$index);$payload=['startId'=>$start,'candidateId'=>'51001','jobId'=>'61001','_jd_contract'=>['source'=>'EmployeeAssignmentRecordsDetail','start_id'=>$start,'client_company_id'=>$external,'client_company_name'=>$name]];
        mappingUpsert($child,'jobdiva','placement',$start,$placement,$payload,'pull',$actor);$mapping=(int)mappingFindInternal($child,'jobdiva','placement',$start)['id'];
        $before=$read('placements',$placement);$providerBefore=$read('external_entity_mappings',$mapping);
        $result=jobdivaRepairPlacementClientLink($child,$mapping,$actor);$after=$read('placements',$placement);
        $check($result['status']==='repaired'&&(int)$after['client_id']===$client&&(int)$after['tenant_id']===$owner,$mode.': repair writes the independently resolved placement owner');
        unset($before['client_id'],$after['client_id'],$before['updated_at'],$after['updated_at']);
        $check($before===$after&&$read('external_entity_mappings',$mapping)===$providerBefore,$mode.': exact repair preserves provider mapping, recipient, person and other placement fields');
        $q=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=? AND event="placement.client_link_repaired" AND target_id=?');$q->execute([$child,$placement]);$receipt=json_decode($q->fetchColumn(),true);
        $check((int)$receipt['directory_owner_id']===$owner&&(int)$receipt['before']['client_id']===$otherClient&&(int)$receipt['after']['client_id']===$client,$mode.': required audit records original workspace, owning directory and both client IDs');
        $before=$graph();$again=jobdivaRepairPlacementClientLink($child,$mapping,$actor);
        $check($again['reason']==='already_linked'&&$graph()===$before,$mode.': identical repair replay changes no business or identity rows');
        $pdo->prepare('UPDATE placements SET client_id=?,coreflux_overridden_fields=? WHERE id=?')->execute([$otherClient,'["client_id"]',$placement]);
        $bad=$payload;$bad['_jd_contract']['client_company_id']='uncreated-'.$index;$bad['_jd_contract']['client_company_name']='Uncreated billing '.$index;
        mappingUpsert($child,'jobdiva','placement',$start,$placement,$bad,'pull',$actor);$before=$graph();
        $held=jobdivaRepairPlacementClientLink($child,$mapping,$actor);
        $check($held['reason']==='core_owned'&&$graph()===$before,$mode.': native hold is read before any company/client/source side effect');
        $pdo->prepare('UPDATE placements SET coreflux_overridden_fields=? WHERE id=?')->execute(['{"client_id":true}',$placement]);$before=$graph();
        $check($fails(fn()=>jobdivaRepairPlacementClientLink($child,$mapping,$actor))&&$graph()===$before,$mode.': malformed stored ownership fails closed');
        $pdo->prepare('UPDATE placements SET coreflux_overridden_fields=NULL WHERE id=?')->execute([$placement]);
        $weak=$payload;unset($weak['_jd_contract']['client_company_id']);mappingUpsert($child,'jobdiva','placement',$start,$placement,$weak,'pull',$actor);$before=$graph();
        $held=jobdivaRepairPlacementClientLink($child,$mapping,$actor);
        $check($held['reason']==='exact_billing_identity_required'&&$graph()===$before,$mode.': name-only evidence never changes the retained billing client');
        $wrong=$payload;$wrong['startId']='999999';mappingUpsert($child,'jobdiva','placement',$start,$placement,$wrong,'pull',$actor);$before=$graph();
        $check($fails(fn()=>jobdivaRepairPlacementClientLink($child,$mapping,$actor))&&$graph()===$before,$mode.': another assignment snapshot cannot repair this placement');
        mappingUpsert($child,'jobdiva','placement',$start,$placement,$payload,'pull',$actor);
        staffingClientSaveNative($child,$client,['status'=>'closed'],$actor,staffingClientRevision($pdo,companyRelationshipScope($pdo,$child,'placements',false),$read('staffing_clients',$client)));
        jobdivaRepairPlacementClientLink($child,$mapping,$actor);
        $check($read('staffing_clients',$client)['status']==='closed',$mode.': billing repair never reactivates a Core-closed client');
        $pdo->prepare('INSERT INTO staffing_clients(tenant_id,company_id,name,status) VALUES(?,?,?,"active")')->execute([$owner,$company,'Retained additional client '.$index]);$additional=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE placements SET client_id=? WHERE id=?')->execute([$additional,$placement]);jobdivaRepairPlacementClientLink($child,$mapping,$actor);
        $check((int)$read('placements',$placement)['client_id']===$client&&$read('staffing_clients',$additional)!==[],$mode.': exact provider binding wins without deleting another retained CL');
        $before=$graph();$review=staffingClientCatalogReview($child);
        $check($review['company_owner_id']===$companyOwner&&$review['placement_owner_id']===$owner&&$review['counts']['companies_with_multiple_clients']>=1&&$graph()===$before,$mode.': read-only review identifies retained clients across independently shared directories');
        $pdo->prepare('DELETE FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND internal_entity_type="staffing_client" AND external_id=?')->execute([$child,$external]);$before=$graph();
        $check($fails(fn()=>jobdivaRepairPlacementClientLink($child,$mapping,$actor))&&$graph()===$before,$mode.': unbound multiple clients require review instead of lowest-ID selection');
        mappingBindStable($child,'jobdiva','staffing_client',$external,$client,[],'pull',$actor);
        $before=$graph();$check($fails(fn()=>jobdivaRepairPlacementClientLink($parent,$mapping,$actor))&&$graph()===$before,$mode.': another workspace cannot consume or repair this source link');
    }
    // Fail only the final placement audit, after a new company/client graph was
    // prepared. All preceding writes must roll back to the repair savepoint.
    $pdo->exec("CREATE TEMPORARY TABLE audit_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id INT,actor_user_id INT,event VARCHAR(128),target_id BIGINT,meta_json LONGTEXT,ip_address VARCHAR(45),created_at DATETIME,CHECK(event<>'placement.client_link_repaired'))");$shadow=true;
    $new=$payload;$new['_jd_contract']['client_company_id']='repair-new-atomic';$new['_jd_contract']['client_company_name']='Atomic new billing company';mappingUpsert($child,'jobdiva','placement',$start,$placement,$new,'pull',$actor);$before=$graph();
    $check($fails(fn()=>jobdivaRepairPlacementClientLink($child,$mapping,$actor))&&$pdo->inTransaction()&&$graph()===$before,'Required placement audit failure rolls back the entire new company/client/mapping/number graph while preserving the caller transaction');
    $pdo->exec('DROP TEMPORARY TABLE audit_log');$shadow=false;
    $check([$all('accounting_events'),$all('accounting_journal_entries'),$all('time_entries')]===$protected,'Client repairs execute no time, payroll or accounting operations');
    echo json_encode(['passed'=>true,'checks'=>count($checks),'labels'=>$checks,'provider_calls'=>0,'financial_execution'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}finally{if($shadow)$pdo->exec('DROP TEMPORARY TABLE audit_log');if($pdo->inTransaction())$pdo->rollBack();}
