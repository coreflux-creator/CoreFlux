<?php
/** Real native ending and canonical JobDiva writer; disposable database only. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'
    ||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    ||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/db.php';
require_once __DIR__.'/../modules/placements/lib/end.php';
require_once __DIR__.'/../core/jobdiva/sync.php';
require_once __DIR__.'/../core/integrations/field_map_apply.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if($tenant<=0)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$checks=0;$failures=[];
$check=static function(bool $ok,string $message)use(&$checks,&$failures):void{
    if($ok){$checks++;echo "PASS $message\n";}else{$failures[]=$message;echo "FAIL $message\n";}
};
$read=static fn(string $table):array=>$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$financialBefore=[$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('accounting_events')];
$pdo->beginTransaction();
try{
    $person=scopedInsert('people',['first_name'=>'CI Lifecycle','last_name'=>'Owner','email_primary'=>'ci-lifecycle-owner@example.invalid','classification'=>'w2']);
    $placement=scopedInsert('placements',['person_id'=>$person,'status'=>'active','start_date'=>'2026-11-01','end_date'=>'2026-12-01','engagement_type'=>'w2','title'=>'CI source lifecycle','external_id'=>'jd:99110001','coreflux_overridden_fields'=>'["notes"]','notes'=>'Core-owned note']);
    scopedInsert('placement_rates',['placement_id'=>$placement,'effective_from'=>'2026-11-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-11-01 12:00:00']);
    $rates=$read('placement_rates');$people=$read('people');
    placementEnd($placement,['actual_end_date'=>'2026-11-10']);
    $ended=placementAuditRow($placement);
    $check(json_decode((string)$ended['coreflux_overridden_fields'],true)===['notes','status','actual_end_date'],'Core ending claims lifecycle ownership and preserves unrelated overrides');
    $payload=['startId'=>'99110001','candidateId'=>'99110002','jobId'=>'99110003','startDate'=>'2026-11-01','status'=>'active','title'=>'CI source lifecycle','worksiteCountry'=>'United States',
        '_jd_contract'=>['start_id'=>'99110001','engagement_type'=>'w2','placement_status'=>'active','start_date'=>'2026-11-01','end_date'=>'2026-12-01','worksite_country'=>'United States']];
    $same=jobdivaSyncUpsertPlacement($tenant,$person,null,$payload,'99110001');
    $row=placementAuditRow($placement);
    $check($same===$placement&&$row['status']==='ended'&&$row['actual_end_date']==='2026-11-10','Ordinary JobDiva refresh cannot reopen the Core-ended placement');
    $check($row['external_id']==='jd:99110001'&&(int)$row['person_id']===$person&&$row['notes']==='Core-owned note','Refresh retains stable provider identity, person and unrelated Core note');
    // Independent fixture setup also reproduces the historical force-contract bypass.
    scopedUpdate('placements',$placement,['status'=>'ended','actual_end_date'=>'2026-11-10','coreflux_overridden_fields'=>'["notes","status","actual_end_date"]']);
    $forced=$payload+['__cf_force_source_contract'=>true];$forced['actualEndDate']='2026-11-11';
    jobdivaSyncUpsertPlacement($tenant,$person,null,$forced,'99110001');
    $row=placementAuditRow($placement);
    $check($row['status']==='ended'&&$row['actual_end_date']==='2026-11-10','Contract reconciliation cannot discard Core lifecycle ownership or change its date');
    $check(json_decode((string)$row['coreflux_overridden_fields'],true)===['notes','status','actual_end_date'],'Contract reconciliation retains the explicit lifecycle ownership flags');
    $check($read('placement_rates')===$rates&&$read('people')===$people,'Source refresh preserves approved rates and person records');
    $audit=$read('audit_log');$graph=$read('placement_economic_parties');
    $again=placementEnd($placement,[]);
    $check(!$again['changed']&&placementAuditRow($placement)===$row&&$read('audit_log')===$audit&&$read('placement_economic_parties')===$graph,'Ending replay after source refresh remains a no-op');
    // Exercise the actual configurable mapping path, including a legacy
    // payload without a normalized assignment contract and a mixed bucket.
    $pdo->prepare('DELETE FROM tenant_integration_field_map WHERE tenant_id=? AND integration="jobdiva" AND entity_type="placement"')->execute([$tenant]);
    $mapping=$pdo->prepare('INSERT INTO tenant_integration_field_map(tenant_id,integration,entity_type,external_field,internal_field,source_path,target_module,target_table,target_column,linked_entity,enabled) VALUES(:t,"jobdiva","placement",:source,:column,:source_path,"placements","placements",:target,"self",1)');
    foreach(['status','actual_end_date','notes','title','coreflux_overridden_fields'] as $column){
        $mapping->execute(['t'=>$tenant,'source'=>'mapped_'.$column,'column'=>$column,'source_path'=>'mapped_'.$column,'target'=>$column]);
    }
    $mapped=['mapped_status'=>'active','mapped_actual_end_date'=>'2026-11-11','mapped_notes'=>'Source replacement','mapped_title'=>'Source-owned title','mapped_coreflux_overridden_fields'=>'[]'];
    $summary=integrationFieldMapApplyAll($tenant,'jobdiva','placement',$mapped,['self'=>$placement]);
    $row=placementAuditRow($placement);
    $check($summary['written']===1&&$summary['skipped']===4&&$summary['errors']===[]
        &&$row['status']==='ended'&&$row['actual_end_date']==='2026-11-10'&&$row['notes']==='Core-owned note'
        &&$row['title']==='Source-owned title'&&json_decode($row['coreflux_overridden_fields'],true)===['notes','status','actual_end_date'],
        'Configured mappings retain Core-owned fields and ownership metadata while updating source-owned fields');
    $check(count(array_filter($summary['skipped_reasons'],static fn($reason)=>str_starts_with($reason,'core_owned_placement_field')))===3
        &&count(array_filter($summary['skipped_reasons'],static fn($reason)=>str_starts_with($reason,'protected_target')))===1,
        'Mapping review explains each retained Core field and protected ownership target');
    $caseWrite=integrationFieldMapWritePlacement($pdo,$tenant,$placement,['STATUS'=>'active','ACTUAL_END_DATE'=>'2026-11-12']);
    $check($caseWrite['written']===0&&count($caseWrite['skipped'])===2&&placementAuditRow($placement)===$row,
        'Case variations in mapping targets cannot bypass lifecycle ownership');
    // The existing clear-override review can explicitly hand both fields back.
    scopedUpdate('placements',$placement,['coreflux_overridden_fields'=>'["notes"]']);
    jobdivaSyncUpsertPlacement($tenant,$person,null,$forced,'99110001');
    $row=placementAuditRow($placement);
    $check($row['status']==='active'&&$row['actual_end_date']==='2026-11-11','Explicit lifecycle ownership release permits the next source update');
    scopedUpdate('placements',$placement,['coreflux_overridden_fields'=>'{}']);
    $before=placementAuditRow($placement);
    try{placementEnd($placement,['actual_end_date'=>'2026-11-10']);$check(false,'Malformed ownership is refused');}
    catch(RuntimeException $e){$check($e->getCode()===422&&placementAuditRow($placement)===$before&&$pdo->inTransaction(),'Malformed ownership is refused without overwriting state or losing the caller transaction');}
    try{jobdivaSyncUpsertPlacement($tenant,$person,null,$forced,'99110001');$check(false,'Malformed ownership stops the source writer');}
    catch(RuntimeException $e){$check($e->getCode()===422&&placementAuditRow($placement)===$before&&$pdo->inTransaction(),'Malformed ownership stops the source writer without partial placement changes');}
    $summary=integrationFieldMapApplyAll($tenant,'jobdiva','placement',$mapped,['self'=>$placement]);
    $check($summary['written']===0&&count($summary['errors'])===1&&placementAuditRow($placement)===$before&&$pdo->inTransaction(),
        'Malformed ownership blocks the whole mapped placement bucket and preserves the caller transaction');
    $foreign=$tenant+1000000;
    setRequestTenantId($foreign);$_SESSION['tenant_id']=$foreign;
    try{placementEnd($placement,[]);$check(false,'Cross-tenant ending is refused');}
    catch(RuntimeException $e){$check($e->getCode()===404,'Cross-tenant ending is refused');}
    try{integrationFieldMapWritePlacement($pdo,$foreign,$placement,['title'=>'Wrong tenant']);$check(false,'Cross-tenant mapping is refused');}
    catch(RuntimeException $e){$check($e->getMessage()==='Mapped placement not found.'&&$pdo->inTransaction(),'Cross-tenant mapped placement writes are refused');}
    setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
    $check([$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('accounting_events')]===$financialBefore,'End and source reconciliation create no financial postings');
}finally{if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);}
if($failures)throw new RuntimeException(implode('; ',$failures));
echo "$checks placement end JobDiva ownership checks passed.\n";
