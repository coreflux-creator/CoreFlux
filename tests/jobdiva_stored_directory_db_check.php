<?php
/** Original-workspace stored preview/apply/replay in a disposable shared directory. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/jobdiva/sync.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$owner=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();$workspace=990701;$checks=0;$trigger=false;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$failure=static function(callable $f):string{try{$f();return '';}catch(Throwable $e){return $e->getMessage();}};
$insert=static function(string $table,array $row)use($pdo):int{$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return(int)$pdo->lastInsertId();};
$all=static fn(string $table):array=>$pdo->query('SELECT * FROM '.$table.' ORDER BY '.match($table){'core_record_numbers'=>'tenant_id,entity_type,record_id','placement_corp_details'=>'placement_id',default=>'id'})->fetchAll(PDO::FETCH_ASSOC);
$state=static fn():array=>array_map($all,['people','placements','placement_rates','placement_client_chain','placement_corp_details','placement_economic_parties','companies','staffing_clients','staffing_jobs','external_entity_mappings','entity_sync_history','core_record_numbers','audit_log','jobdiva_sync_audit']);
$read=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC)?:[];};
$pairs=static fn():array=>$pdo->query('SELECT id,tenant_id,source_system,internal_entity_type,external_id,internal_entity_id FROM external_entity_mappings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$financial=array_map($all,['accounting_events','accounting_journal_entries','accounting_journal_entry_lines','time_entries','payroll_runs','billing_payments','ap_payments']);
try{
    if($pdo->query('SELECT id FROM tenants WHERE id=990701')->fetchColumn())throw new RuntimeException('Stored-directory fixture already exists');
    $pdo->exec("CREATE TRIGGER fixture_stored_directory_audit BEFORE INSERT ON jobdiva_sync_audit FOR EACH ROW BEGIN IF COALESCE(@fixture_stored_audit_start,'')<>'' AND NEW.action='stored_assignment_reconciliation' AND JSON_UNQUOTE(JSON_EXTRACT(NEW.detail,'$.start_id'))=@fixture_stored_audit_start THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic final stored audit failure'; END IF; END");$trigger=true;
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$check,$failure,$insert,$all,$state,$read,$pairs,$financial):void{
        $insert('tenants',['id'=>$workspace,'parent_id'=>$owner,'tenant_type'=>'sub','name'=>'Synthetic stored-source workspace','is_active'=>1,'is_simulation'=>1]);
        foreach(['people','placements','companies','staffing','accounting']as$module)$insert('tenant_module_scope',['tenant_id'=>$workspace,'module_key'=>$module,'scope_mode'=>'shared']);
        setRequestTenantId($workspace);$_SESSION['tenant_id']=$workspace;setRequestModuleScope('placements');
        $person=$insert('people',['tenant_id'=>$owner,'first_name'=>'Synthetic','last_name'=>'Retained candidate','email_primary'=>'stored-directory@example.invalid','classification'=>'w2','status'=>'active','external_id'=>'person-legacy-number']);
        mappingUpsert($workspace,'jobdiva','person','jd:99192002',$person,['candidateId'=>'99192002']);
        $bill=jobdivaUpsertCompanyMapped($workspace,'99192006','Synthetic stored debtor',[],['id'=>'99192006'],null,['client']);
        $client=staffingClientEnsureForCompany($workspace,$bill,'Synthetic stored debtor',['source_system'=>'jobdiva','source_external_id'=>'99192006'])['client_id'];
        $base=['tenant_id'=>$owner,'person_id'=>$person,'title'=>'Synthetic before source review','status'=>'on_hold','engagement_type'=>'w2','start_date'=>'2026-10-01','end_date'=>'2026-12-31','external_id'=>'placement-legacy-number','client_id'=>$client,'end_client_company_id'=>$bill,'end_client_name'=>'Synthetic stored debtor','client_bill_cycle'=>'monthly','client_payment_terms_override'=>'NET30','vendor_pay_cycle'=>'biweekly','vendor_payment_terms_override'=>'NET30','vendor_pwp_enabled'=>0,'worksite_country'=>'US','notes'=>'Retained native note','coreflux_overridden_fields'=>'["notes"]'];
        $placement=$insert('placements',$base);
        $insert('placement_rates',['tenant_id'=>$owner,'placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
        $payload=['startId'=>'99192001','candidateId'=>'99192002','jobId'=>'99192003','startDate'=>'2026-10-01','status'=>'on_hold','title'=>'Source title','originalTitle'=>'Original workspace title','wrongTitle'=>'Wrong owner title','employmentType'=>'W2','worksiteCountry'=>'US','endClientName'=>'Synthetic stored debtor','notes'=>'Incoming source note',
            '_jd_contract'=>['start_id'=>'99192001','engagement_type'=>'w2','placement_status'=>'on_hold','start_date'=>'2026-10-01','end_date'=>'2026-12-31','client_company_id'=>'99192006','client_company_name'=>'Synthetic stored debtor','end_client_company_id'=>'99192006','end_client_name'=>'Synthetic stored debtor','client_bill_cycle'=>'monthly','vendor_pay_cycle'=>'biweekly','client_payment_terms'=>'NET30','vendor_payment_terms'=>'NET30','paid_when_paid'=>false,'worksite_country'=>'US','worksite_city'=>'Hamilton','bill_rate'=>100,'pay_rate'=>50,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD']];
        mappingUpsert($workspace,'jobdiva','placement','jd:99192001',$placement,$payload);
        mappingUpsert($workspace,'jobdiva','jobdiva_assignment','99192001',$placement,$payload);
        foreach([$workspace=>'originalTitle',$owner=>'wrongTitle']as$t=>$field)$insert('tenant_integration_field_map',['tenant_id'=>$t,'integration'=>'jobdiva','entity_type'=>'placement','external_field'=>$field,'internal_field'=>'title','source_path'=>$field,'target_module'=>'placements','target_table'=>'placements','target_column'=>'title','linked_entity'=>'self']);
        $preview=static fn():array=>jobdivaStoredAssignmentProjectionPlan($workspace,20,[],false);
        $apply=static fn(string $token,array $ids=['99192001']):array=>jobdivaApplyStoredAssignmentProjection($workspace,null,$ids,$token,20);
        $row=static function(array $plan,string $id='99192001'):array{foreach($plan['rows']as$r)if($r['start_id']===$id)return$r;throw new RuntimeException('Missing selected fixture row');};
        $before=$state();$plan=$preview();
        $check($row($plan)['selectable']&&$row($plan)['placement_id']===$placement&&$row($plan)['outcome']==='update','Stored preview resolves a shared legacy placement through its original prefixed provider pair');
        $check($state()===$before&&$preview()['dry_run_token']===$plan['dry_run_token'],'Stored preview is read-only and stable inside its protected scope');
        $check(jobdivaStoredAssignmentProjectionPlan($owner,20,[],false)['summary']['assignments']===0,'Parent directory does not borrow the child assignment mirror');
        $initialPairs=$pairs();$rates=$all('placement_rates');$people=$all('people');
        foreach(['companies','placements','source','candidate','mapping','display','competing']as$change)JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$owner,$placement,$person,$payload,$plan,$apply,$state,$check,$failure,$change):void{
            if(in_array($change,['companies','placements'],true))$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key=?')->execute([$workspace,$change]);
            if($change==='source')mappingUpsert($workspace,'jobdiva','jobdiva_assignment','99192001',$placement,array_replace($payload,['originalTitle'=>'New source title']));
            if($change==='candidate')$pdo->prepare('UPDATE people SET status="inactive" WHERE tenant_id=? AND id=?')->execute([$owner,$person]);
            if($change==='mapping')$pdo->prepare('UPDATE tenant_integration_field_map SET source_path="wrongTitle" WHERE tenant_id=? AND internal_field="title"')->execute([$workspace]);
            if($change==='display')$pdo->prepare('UPDATE placements SET external_id="changed-legacy-number" WHERE tenant_id=? AND id=?')->execute([$owner,$placement]);
            if($change==='competing')mappingUpsert($owner,'jobdiva','placement','99192999',$placement,$payload);
            $before=$state();$check($failure(fn()=>$apply($plan['dry_run_token']))!==''&&$state()===$before,'Changed '.$change.' evidence refuses a stale shared review without writes');
        },true);
        $applied=$apply($plan['dry_run_token']);$saved=$read('placements',$placement);
        $check($applied['projected']===1&&$saved['title']==='Original workspace title'&&$saved['worksite_city']==='Hamilton'&&$saved['notes']==='Retained native note','Stored apply uses original-workspace fields while preserving Core-owned values');
        $check((int)$saved['tenant_id']===$owner&&(int)$saved['person_id']===$person&&$saved['external_id']==='placement-legacy-number'&&$people===$all('people'),'Sparse assignment evidence preserves both canonical records and legacy display numbers');
        foreach($initialPairs as$p)$check((array_column($pairs(),null,'id')[$p['id']]??null)===$p,'Retained source pair '.$p['internal_entity_type'].' '.$p['external_id'].' remains physically unchanged');
        $check($rates===$all('placement_rates'),'Approved rates remain unchanged by equal-rate stored projection');
        $q=$pdo->query('SELECT tenant_id,detail FROM jobdiva_sync_audit WHERE action="stored_assignment_reconciliation" ORDER BY id DESC LIMIT 1');$audit=$q->fetch(PDO::FETCH_ASSOC);$detail=json_decode($audit['detail'],true,512,JSON_THROW_ON_ERROR);
        $check((int)$audit['tenant_id']===$workspace&&$detail['placement_owner_id']===$owner&&(int)$detail['before']['placement']['id']===$placement&&(int)$detail['after']['placement']['id']===$placement,'Stored audit retains the source workspace and complete before/after shared placement snapshots');
        JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$placement,$preview,$row,$apply,$state,$check):void{
            $pdo->prepare('UPDATE placements SET deleted_at="2026-11-01 00:00:00",coreflux_overridden_fields=\'["notes","status"]\' WHERE tenant_id=? AND id=?')->execute([$owner,$placement]);
            $before=$state();$check(!$row($preview())['selectable']&&$state()===$before,'Core-owned lifecycle blocks archived restoration during preview');
            $pdo->prepare('UPDATE placements SET coreflux_overridden_fields=\'["notes"]\' WHERE tenant_id=? AND id=?')->execute([$owner,$placement]);
            $ready=$preview();$check($row($ready)['outcome']==='restore'&&$row($ready)['selectable'],'Current source lifecycle exposes the exact shared archived record for reviewed restoration');
            $saved=$apply($ready['dry_run_token']);$q=$pdo->prepare('SELECT deleted_at FROM placements WHERE tenant_id=? AND id=?');$q->execute([$owner,$placement]);
            $check($saved['restored']===1&&$q->fetchColumn()===null,'Canonical lifecycle restoration clears the archive only on the existing shared record');
        },true);
        $second=$insert('placements',array_replace($base,['external_id'=>'second-legacy-placement','title'=>'Second before review']));
        $insert('placement_rates',['tenant_id'=>$owner,'placement_id'=>$second,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
        $secondPayload=array_replace($payload,['startId'=>'99192011','originalTitle'=>'Second reviewed title']);$secondPayload['_jd_contract']['start_id']='99192011';
        foreach(['placement','jobdiva_assignment']as$type)mappingUpsert($workspace,'jobdiva',$type,'99192011',$second,$secondPayload);
        $changed=$payload;$changed['_jd_contract']['worksite_city']='Princeton';mappingUpsert($workspace,'jobdiva','jobdiva_assignment','99192001',$placement,$changed);
        $ready=$preview();$before=$state();$pdo->exec("SET @fixture_stored_audit_start='99192011'");
        $message=$failure(fn()=>$apply($ready['dry_run_token'],['99192001','99192011']));$pdo->exec("SET @fixture_stored_audit_start=''");
        $check(str_contains($message,'Synthetic final stored audit failure')&&$state()===$before,'Failure of the second required audit rolls back the whole selected shared batch');
        $replay=jobdivaReprojectStoredAssignmentGraphs($workspace,null,20);
        $check($replay['placements_projected']===2&&$replay['errors']===[]&&$read('placements',$placement)['worksite_city']==='Princeton'&&$read('placements',$second)['title']==='Second reviewed title','Automatic stored replay revalidates each current source graph and uses the same protected shared apply');
        $ids=$pdo->query('SELECT id,tenant_id,external_id,person_id FROM placements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$numbers=$all('core_record_numbers');
        $again=jobdivaReprojectStoredAssignmentGraphs($workspace,null,20);
        $check($again['placements_projected']===2&&$again['errors']===[]&&$ids===$pdo->query('SELECT id,tenant_id,external_id,person_id FROM placements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)&&$numbers===$all('core_record_numbers'),'Repeated stored replay preserves stable person and placement numbers without duplicate records');
        $check($financial===array_map($all,['accounting_events','accounting_journal_entries','accounting_journal_entry_lines','time_entries','payroll_runs','billing_payments','ap_payments']),'Shared stored reconciliation executes no time, accounting, payroll or payment operations');
    },true);
    $check(!$pdo->inTransaction()&&!JobdivaStoredReviewTransaction::inScope(),'Stored-directory fixture rolls back its business records and closes the protected scope');
}finally{
    if($pdo->inTransaction())$pdo->rollBack();if($trigger)$pdo->exec('DROP TRIGGER fixture_stored_directory_audit');setRequestTenantId(null);clearRequestModuleScope();
}
echo "$checks stored assignment directory checks passed.\n";
