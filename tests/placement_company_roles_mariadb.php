<?php
/** Real company/graph DDL and production repair paths in a disposable synthetic database. */
declare(strict_types=1);
$ci=getenv('GITHUB_ACTIONS')==='true'&&getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1'&&in_array('--confirm-disposable-fixture',$argv,true)&&(string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if(!$ci&&!$local)throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/jobdiva/mapping_alignment.php';
require_once __DIR__.'/../core/migrate.php';
require_once __DIR__.'/purepay_assignment_fixture.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$ci?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db='coreflux_company_roles_'.($ci?'ci':gmdate('YmdHis').'_'.getmypid());
$pdo->exec('CREATE DATABASE '.$db);$pdo->exec('USE '.$db);
$count=0;
$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;echo "PASS $label\n";};
$fails=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable){return true;}};
$insert=static function(string $table,array $row)use($pdo):void{
    $pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
};
$table=static function(string $name,array $row)use($pdo):void{
    $columns=[];foreach($row as $k=>$v)$columns[]='`'.$k.'` '.($k==='id'?'BIGINT PRIMARY KEY':(str_ends_with($k,'_id')&&!in_array($k,['external_id','jobdiva_job_id'])?'BIGINT':'LONGTEXT')).($k==='id'?'':' NULL');
    $pdo->exec('CREATE TABLE '.$name.' ('.implode(',',$columns).')');
};
try{
    $pdo->exec('CREATE TABLE tenants(id BIGINT PRIMARY KEY,parent_id BIGINT,tenant_type VARCHAR(20),name VARCHAR(80),is_active INT,billing_invoice_terms VARCHAR(20))');
    $pdo->exec("INSERT INTO tenants VALUES(1,NULL,'master','Synthetic Pilot',1,'NET30'),(2,NULL,'master','Other Pilot',1,'NET30')");
    $pdo->exec('CREATE TABLE tenant_module_scope(tenant_id BIGINT,module_key VARCHAR(40),scope_mode VARCHAR(20))');
    foreach(['modules/people/migrations/004_companies.sql','modules/staffing/migrations/003_clients.sql','core/migrations/126_staffing_economic_graph.sql','core/migrations/138_placement_one_time_items.sql']as$file){
        foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../'.$file))as$sql){
            if(preg_match('/CREATE TABLE IF NOT EXISTS (companies|company_roles|staffing_clients|staffing_operating_cycles|placement_economic_parties|placement_economic_obligations|placement_economic_items)\s*\(/',$sql))$pdo->exec($sql);
        }
    }
    foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/022_external_entity_mappings.sql'))as$sql)$pdo->exec($sql);
    $pdo->exec('ALTER TABLE companies ADD industry VARCHAR(80), ADD payment_terms_days INT');
    $pdo->exec("ALTER TABLE staffing_clients ADD company_id BIGINT, ADD source_system VARCHAR(40) DEFAULT 'manual', ADD external_id VARCHAR(80)");
    foreach([[10,1,'Billing Co',45],[11,1,'Work Recipient',90],[12,2,'Foreign Co',15],[13,1,'Another Bill-to',20]]as[$id,$tid,$name,$days])$insert('companies',['id'=>$id,'tenant_id'=>$tid,'name'=>$name,'payment_terms_days'=>$days]);
    foreach([[1,1,10,'Billing Co'],[2,1,11,'Work Recipient'],[3,2,12,'Foreign Co'],[4,1,13,'Another Bill-to']]as[$id,$tid,$co,$name])$insert('staffing_clients',['id'=>$id,'tenant_id'=>$tid,'company_id'=>$co,'name'=>$name,'source_system'=>'jobdiva','payment_terms_days'=>45]);
    $fixture=purepayAssignmentFixtureRows();
    $placement=array_diff_key($fixture['placement'],['billing_company_id'=>true])+['tenant_id'=>1,'deleted_at'=>null,'updated_at'=>'2026-10-01 00:00:00','coreflux_overridden_fields'=>null,
        'client_bill_cycle'=>'monthly','vendor_pay_cycle'=>'biweekly','vendor_payment_terms_override'=>null,'client_payment_terms_override'=>null,'vendor_pwp_enabled'=>0,'recruiter_name'=>null,'recruiter_email'=>null,'account_manager_name'=>null,'account_manager_email'=>null];
    $table('placements',$placement);$insert('placements',$placement);
    $insert('placements',array_replace($placement,['id'=>902,'tenant_id'=>2,'client_id'=>3,'end_client_company_id'=>12]));
    $person=$fixture['person']+['tenant_id'=>1,'email_primary'=>'synthetic@example.invalid','pay_frequency'=>'biweekly','entity_id'=>9,'deleted_at'=>null];$table('people',$person);$insert('people',$person);
    $pdo->exec('CREATE TABLE users(id BIGINT PRIMARY KEY,name VARCHAR(100))');
    $pdo->exec('CREATE TABLE placement_corp_details(tenant_id BIGINT,placement_id BIGINT)');
    $pdo->exec('CREATE TABLE placement_client_chain(id BIGINT PRIMARY KEY,tenant_id BIGINT,placement_id BIGINT,position INT)');
    $pdo->exec('CREATE TABLE placement_referrals(id BIGINT PRIMARY KEY,tenant_id BIGINT,placement_id BIGINT,referrer_company_id BIGINT,referrer_person_id BIGINT,referrer_user_id BIGINT)');
    $pdo->exec('CREATE TABLE placement_commissions(id BIGINT PRIMARY KEY,tenant_id BIGINT,placement_id BIGINT,user_id BIGINT,role VARCHAR(40),effective_from DATE,effective_to DATE)');
    $pdo->exec('CREATE TABLE ap_vendors_index(id BIGINT PRIMARY KEY,tenant_id BIGINT,vendor_name VARCHAR(100),vendor_type VARCHAR(30),default_terms VARCHAR(20),default_pwp INT)');
    $pdo->exec('CREATE TABLE staffing_jobs(id BIGINT PRIMARY KEY,tenant_id BIGINT,department VARCHAR(80),location_state VARCHAR(80))');
    $pdo->exec('CREATE TABLE accounting_entities(id BIGINT PRIMARY KEY,tenant_id BIGINT,active INT)');
    $pdo->exec('INSERT INTO accounting_entities VALUES(9,1,1)');
    $table('placement_rates',$fixture['rates'][0]);foreach($fixture['rates']as$r)$insert('placement_rates',$r);
    foreach($fixture['cycles']as$r)$insert('staffing_operating_cycles',$r+['tenant_id'=>1]);
    $snapshot=static fn():array=>$pdo->query('SELECT * FROM placements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $payload=['startId'=>'101','candidateId'=>'201','jobId'=>'301','_jd_contract'=>['source'=>'EmployeeAssignmentRecordsDetail','start_id'=>'101','client_company_id'=>'B-001','client_company_name'=>'Billing Co','end_client_company_id'=>'E-002','end_client_company_name'=>'Work Recipient']];
    mappingUpsert(1,'jobdiva','company','B-001',10,[],'pull',null);
    mappingUpsert(1,'jobdiva','company','E-002',11,[],'pull',null);
    mappingUpsert(1,'jobdiva','placement','101',901,$payload,'pull',null);
    $check(jobdivaProjectorResolveBillingClientCompany(1,$payload,null)===10&&jobdivaProjectorResolveEndClientCompany(1,$payload,null)===11,'Two source roles resolve to separate canonical companies');
    $before=$pdo->query('SELECT * FROM external_entity_mappings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $bad=$payload;$bad['_jd_contract']['client_company_name']='Another Bill-to';
    $check($fails(fn()=>jobdivaProjectorResolveBillingClientCompany(1,$bad,null)),'Conflicting name cannot retarget an existing billing provider ID');
    $bad=$payload;$bad['_jd_contract']['end_client_company_id']='E-NEW';
    $check($fails(fn()=>jobdivaProjectorResolveEndClientCompany(1,$bad,null)),'Same name cannot merge distinct provider companies');
    $check($pdo->query('SELECT * FROM external_entity_mappings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$before,'Rejected source identity conflicts leave mappings unchanged');
    $patch=placementsResolveCompanyRoles(1,['end_client_company_id'=>11],$placement);
    $check($patch===['end_client_company_id'=>11,'end_client_name'=>'Work Recipient'],'Editing work recipient does not write a billing profile');
    $check(placementsResolveCompanyRoles(1,['client_id'=>4],$placement)===['client_id'=>4],'Editing bill-to does not write end-client fields');
    $check(placementsResolveCompanyRoles(1,['end_client_name'=>''],$placement)===['end_client_company_id'=>null,'end_client_name'=>null],'Clearing recipient does not clear debtor');
    $check($fails(fn()=>placementsResolveCompanyRoles(1,['client_id'=>3]))&&$fails(fn()=>placementsResolveCompanyRoles(1,['end_client_company_id'=>12])),'Manual role edits reject another directory');
    $before=$snapshot();$repair=jobdivaMappingRepairStaffingClientLinks(1);
    $check($repair['failed']===0&&$snapshot()===$before,'Repair accepts a valid bill-to/end-client difference without modifying placement');
    $pdo->exec('UPDATE placements SET client_id=2 WHERE id=901');
    $repair=jobdivaMappingRepairStaffingClientLinks(1);
    $after=$snapshot();
    $check($repair['repaired']===1&&(int)$after[0]['client_id']===1&&(int)$after[0]['end_client_company_id']===11&&$after[1]===$before[1],'Exact billing repair changes only debtor and preserves recipient and foreign tenant');
    $pdo->exec('UPDATE placements SET client_id=4,coreflux_overridden_fields=\'["client_id"]\' WHERE id=901');
    $before=$snapshot();jobdivaMappingRepairStaffingClientLinks(1);staffingClientRelinkCanonicalPlacements(1);
    $check($snapshot()===$before,'Source and catalog repairs preserve a manual bill-to override');
    $pdo->exec('UPDATE placements SET client_id=1,coreflux_overridden_fields=NULL WHERE id=901');
    $history=$pdo->query('SELECT * FROM placement_rates ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $legacy=placementEconomicsUpsertParty(1,901,['source_ref'=>'placement:end_client','source_type'=>'placement','role'=>'end_client','display_name'=>'Work Recipient','company_id'=>11,'money_flow'=>'receivable','settlement_channel'=>'ar','source_system'=>'jobdiva']);
    $manual=placementEconomicsUpsertParty(1,901,['source_ref'=>'placement:manual_note','source_type'=>'placement','role'=>'review_note','display_name'=>'Manual retained','source_managed'=>0]);
    $pdo->exec('INSERT INTO placement_economic_obligations(tenant_id,placement_id,economic_party_id,source_type,source_ref_id,amount,currency,status) VALUES(1,901,'.$legacy.',"manual",101,25,"USD","paid")');
    $obligations=$pdo->query('SELECT * FROM placement_economic_obligations')->fetchAll(PDO::FETCH_ASSOC);
    $result=placementEconomicsReconcile(1,901);
    $check($result['available']&&$result['errors']===[],'Production economic reconciliation completes with distinct roles');
    $parties=placementEconomicsParties(1,901);$byRef=array_column($parties,null,'source_ref');
    $check((int)$byRef['placement:bill_to']['company_id']===10&&(int)$byRef['placement:bill_to']['client_id']===1
        &&$byRef['placement:bill_to']['money_flow']==='receivable'&&$byRef['placement:bill_to']['payment_terms']==='NET45','Receivable belongs to bill-to client with its terms');
    $check((int)$byRef['placement:work_recipient']['company_id']===11&&$byRef['placement:work_recipient']['settlement_channel']==='none','Recipient is informational and cannot generate a receivable');
    $check(!isset($byRef['placement:end_client'])&&isset($byRef['placement:manual_note']),'Obsolete source-owned debtor retires while manual note survives');
    $check($pdo->query('SELECT * FROM placement_rates ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$history
        &&$pdo->query('SELECT * FROM placement_economic_obligations')->fetchAll(PDO::FETCH_ASSOC)===$obligations,'Approved snapshots and posted obligations remain byte-for-byte unchanged');
    $before=$pdo->query('SELECT * FROM placement_economic_parties ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);placementEconomicsReconcile(1,901);
    $check($pdo->query('SELECT * FROM placement_economic_parties ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$before,'Identical reconciliation replay preserves party identities and values');
    placementEconomicsUpsertParty(1,901,['source_ref'=>'placement:manual_note','source_type'=>'placement','role'=>'replacement','display_name'=>'Source overwrite','source_managed'=>1]);
    $check($pdo->query('SELECT * FROM placement_economic_parties ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$before,'Source upsert atomically respects manual party ownership');
    require_once __DIR__.'/../modules/staffing/lib/dimensions.php';
    $context=staffingAssignmentDimensionContext(1,901,9,'2026-10-01');
    $check($context['dimensions']['client']===10,'Financial client dimension identifies the bill-to company, not the work recipient');
    $pdo->exec('UPDATE placements SET client_id=NULL WHERE id=901');
    $context=staffingAssignmentDimensionContext(1,901,9,'2026-10-01');
    $check(!isset($context['dimensions']['client']),'A work recipient alone cannot supply the financial client dimension');
    $pdo->exec('UPDATE placements SET client_id=1 WHERE id=901');
    $counts=jobdivaProjectorReadinessCounts(1);
    $check($counts['client_company_mismatch']===0,'Readiness does not report distinct roles as a broken company link');
    $pdo->exec('UPDATE placement_economic_parties SET active=1,payment_terms_overridden=1,payment_terms="NET17" WHERE id='.$legacy);
    placementEconomicsReconcile(1,901);
    $reviewed=$pdo->query('SELECT active,payment_terms FROM placement_economic_parties WHERE id='.$legacy)->fetch(PDO::FETCH_ASSOC);
    $check((int)$reviewed['active']===1&&$reviewed['payment_terms']==='NET17','Legacy user-reviewed debtor terms survive source reconciliation for review');
    $check($fails(fn()=>placementEconomicsReceivableContract(1,901,false)),'Conflicting legacy debtor blocks a new receivable contract until reviewed');
    echo "Company role database checks: {$count} passed\n";
}finally{$pdo->exec('DROP DATABASE '.$db);}
