<?php
/** Stored-review isolation and rollback; invented business rows never commit. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) exit(2);
require_once __DIR__ . '/../core/jobdiva/sync.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if($tenant<=0)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$failure=static function(callable $fn):string{try{$fn();return '';}catch(Throwable $e){return $e->getMessage();}};
$read=static fn(string $table):array=>getDB()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$financial=[$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines')];
$defaultIsolation=$pdo->query('SELECT @@tx_isolation')->fetchColumn();
$pdo->beginTransaction();
try{
    $check(str_contains($failure(fn()=>JobdivaStoredReviewTransaction::run(fn()=>true)),'own protected transaction')&&$pdo->inTransaction(),'An unprotected caller transaction is rejected without committing or rolling it back');
}finally{$pdo->rollBack();}
try{
JobdivaStoredReviewTransaction::run(static function()use($pdo,$tenant,&$checks,$check,$failure,$read,$financial):void{
    $check(JobdivaStoredReviewTransaction::active()&&$pdo->inTransaction(),'Review scope starts its protected transaction');
    // This row is committed by the fixture bootstrap. Its plain SELECT must
    // block an independent no-op UPDATE; no fixture INSERT created this lock.
    $q=$pdo->prepare('SELECT id FROM tenants WHERE id=?');$q->execute([$tenant]);$q->fetchAll();
    $second=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $second->exec('SET SESSION innodb_lock_wait_timeout=1');
    try{$second->prepare('UPDATE tenants SET id=id WHERE id=?')->execute([$tenant]);$check(false,'Plain review reads must retain their locks');}
    catch(PDOException $e){$check((int)($e->errorInfo[1]??0)===1205,'Plain SELECT in the actual review transaction blocks a competing writer');}
    $second=null;
    $check(str_contains($failure(fn()=>jobdivaCall($tenant,'GET','/must-not-be-called')),'Refresh JobDiva source evidence'),'Provider fetch is refused before authentication while review locks are held');
    $person=scopedInsert('people',['first_name'=>'CI Atomic','last_name'=>'Review','email_primary'=>'ci-atomic-review@example.invalid','classification'=>'w2','external_id'=>'jd:99180002']);
    $company=companiesUpsertByName($tenant,'CI Atomic Review Billing',[],['client']);
    $client=staffingClientEnsureForCompany($tenant,$company,'CI Atomic Review Billing')['client_id'];
    $placement=scopedInsert('placements',['person_id'=>$person,'title'=>'CI before atomic review','status'=>'on_hold','engagement_type'=>'w2','start_date'=>'2026-10-01','end_date'=>'2026-12-31','external_id'=>'jd:99180001',
        'client_id'=>$client,'end_client_company_id'=>$company,'end_client_name'=>'CI Atomic Review Billing','client_bill_cycle'=>'monthly','client_payment_terms_override'=>'NET30','vendor_pay_cycle'=>'biweekly','vendor_payment_terms_override'=>'NET30','vendor_pwp_enabled'=>0,'worksite_country'=>'US']);
    scopedInsert('placement_rates',['placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
    placementEconomicsReconcile($tenant,$placement);
    $payload=['startId'=>'99180001','candidateId'=>'99180002','jobId'=>'99180003','startDate'=>'2026-10-01','title'=>'CI reviewed source title','mappedTitle'=>'CI mapped title','status'=>'on_hold','endClientName'=>'CI Atomic Review Billing','worksiteCountry'=>'United States',
        '_jd_contract'=>['start_id'=>'99180001','engagement_type'=>'w2','placement_status'=>'on_hold','start_date'=>'2026-10-01','end_date'=>'2026-12-31','client_company_name'=>'CI Atomic Review Billing','end_client_name'=>'CI Atomic Review Billing','client_bill_cycle'=>'monthly','vendor_pay_cycle'=>'biweekly','client_payment_terms'=>'NET30','vendor_payment_terms'=>'NET30','paid_when_paid'=>false,'worksite_country'=>'United States','bill_rate'=>100,'pay_rate'=>50,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD']];
    mappingUpsert($tenant,'jobdiva','jobdiva_assignment','99180001',$placement,$payload);
    $preview=static fn():array=>jobdivaStoredAssignmentProjectionPlan($tenant,20,[],false);
    $apply=static fn(string $token):array=>jobdivaApplyStoredAssignmentProjection($tenant,null,['99180001'],$token,20);
    $graph=static fn():array=>jobdivaPlacementProjectionAuditSnapshot($tenant,$placement);
    $ready=$preview();$before=$graph();
    $mapping=tenantIntegrationFieldMapUpsert($tenant,['integration'=>'jobdiva','entity_type'=>'placement','internal_field'=>'title','external_field'=>'mappedTitle','source_path'=>'mappedTitle','transform'=>'none','enabled'=>true],null);
    $changed=$preview();
    $check($changed['dry_run_token']!==$ready['dry_run_token'],'A nonclassification field-map change invalidates the reviewed assignment');
    $check(str_contains($failure(fn()=>$apply($ready['dry_run_token'])),'changed after preview')&&$graph()===$before,'Stale mapping review leaves the placement unchanged');
    tenantIntegrationFieldMapDelete($tenant,(int)$mapping['id'],null);
    $ready=$preview();$payload['title']='CI newer source title';mappingUpsert($tenant,'jobdiva','jobdiva_assignment','99180001',$placement,$payload);
    $check(str_contains($failure(fn()=>$apply($ready['dry_run_token'])),'changed after preview')&&$graph()===$before,'Changed saved source evidence is rejected before writes');
    $ready=$preview();$stableCompanies=$read('companies');$stableMappings=$read('external_entity_mappings');
    $message=$failure(static function()use($tenant,$apply,$ready):void{
        JobdivaStoredReviewTransaction::run(static function()use($tenant,$apply,$ready):void{
            $apply($ready['dry_run_token']);
            jobdivaSyncResolveSubcontractCompany($tenant,'99180011',null,['corporation_id'=>'99180011','corporation_name'=>'CI Atomic New Corporation']);
            throw new RuntimeException('Synthetic later-row failure');
        });
    });
    $check($message==='Synthetic later-row failure'&&$graph()===$before&&$read('companies')===$stableCompanies&&$read('external_entity_mappings')===$stableMappings,'A later failure rolls back placement writes and newly resolved company identities together');
    $check($pdo->inTransaction()&&JobdivaStoredReviewTransaction::active(),'Failed nested apply preserves the protected outer transaction');
    $message=$failure(fn()=>jobdivaSyncResolveSubcontractCompany($tenant,'99180021'));
    $check(str_contains($message,'Refresh the saved JobDiva company evidence')&&$read('companies')===$stableCompanies,'Missing stored subcontract evidence requests refresh without creating a company or fetching remotely');
    $corp=jobdivaSyncResolveSubcontractCompany($tenant,'99180011',null,['corporation_id'=>'99180011','corporation_name'=>'CI Atomic New Corporation']);
    $bound=mappingFindInternal($tenant,'jobdiva','company','99180011');
    $check($corp['name']==='CI Atomic New Corporation'&&(int)$bound['internal_entity_id']===$corp['id'],'Exact named subcontract evidence resolves a canonical company inside the review');
    $ready=$preview();$result=$apply($ready['dry_run_token']);
    $check($result['projected']===1&&placementAuditRow($placement)['title']==='CI newer source title','Fresh reviewed source applies through the canonical writer');
    $check($pdo->inTransaction()&&JobdivaStoredReviewTransaction::active(),'Successful nested apply does not commit its protected caller');
    $check([$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines')]===$financial,'Protected source reconciliation creates no financial posting');
},true);
    $check(!$pdo->inTransaction()&&!JobdivaStoredReviewTransaction::active(),'Rollback-only scope releases its transaction and request context');
    $check($pdo->query('SELECT @@tx_isolation')->fetchColumn()===$defaultIsolation,'Review isolation leaves the connection session default unchanged');
    $check(JobdivaStoredReviewTransaction::run(static fn()=>17)===17&&!$pdo->inTransaction(),'A fresh top-level review commits and closes its empty transaction');
}finally{if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);}
echo "$checks stored review transaction checks passed.\n";
