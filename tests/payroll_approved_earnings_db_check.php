<?php
/** Actual native compute/persistence in the fully migrated disposable CI database. */
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('COREFLUX_ENV')!=='coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture',$argv,true)) exit(2);
putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));
putenv('COREFLUX_INTEGRATION_INSTANCE_ID=381e452a-5d11-4e2e-9daa-e9427501898a');
require_once __DIR__.'/../modules/payroll/lib/run_compute.php';
require_once __DIR__.'/../modules/people/lib/employment_links.php';
require_once __DIR__.'/../modules/staffing/lib/timesheets.php';
require_once __DIR__.'/../modules/time/lib/approval_economics.php';
require_once __DIR__.'/fixtures/payroll_input_approval_evidence.php';
require_once __DIR__.'/fixtures/payroll_export_checks.php';
$pdo=getDB();
if ($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci') throw new RuntimeException('Disposable CI database required.');
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();
setRequestTenantId($tenant);setRequestModuleScope('payroll');
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS {$label}\n";};
$refuses=static function(callable $fn,string $message):bool{try{$fn();return false;}catch(Throwable $e){if(!str_contains($e->getMessage(),$message))throw $e;return true;}};
$one=static function(string $sql,array $args=[])use($pdo):array{$q=$pdo->prepare($sql);$q->execute($args);return $q->fetch(PDO::FETCH_ASSOC)?:[];};
$rows=static function(string $sql,array $args=[])use($pdo):array{$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);};
$tables=['people','people_employees','people_employment_links','people_compensation','people_tax_federal','payroll_profiles','payroll_runs','payroll_line_items','payroll_earnings','payroll_taxes','payroll_deductions','time_entries','time_approval_rates','placements','placement_rates','workflow_instances'];
$counts=static fn():array=>array_map(static fn($t)=>(int)$pdo->query('SELECT COUNT(*) FROM '.$t)->fetchColumn(),$tables);
$baseline=$counts();
$pdo->exec("CREATE TRIGGER ci_payroll_compute_reject BEFORE INSERT ON payroll_earnings FOR EACH ROW BEGIN
    IF EXISTS(SELECT 1 FROM payroll_line_items li JOIN payroll_runs r ON r.id=li.run_id AND r.tenant_id=li.tenant_id
        WHERE li.id=NEW.line_item_id AND r.notes='CI earnings persistence failure')
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CI earnings persistence failure'; END IF; END");
$pdo->exec("CREATE TRIGGER ci_payroll_review_reject BEFORE INSERT ON workflow_instances FOR EACH ROW BEGIN
    IF NEW.subject_type='payroll_run' AND EXISTS(SELECT 1 FROM payroll_runs WHERE id=NEW.subject_id AND tenant_id=NEW.tenant_id AND notes='CI workflow persistence failure')
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CI workflow persistence failure'; END IF; END");
$pdo->beginTransaction();
try {
    $actor=(int)$one('SELECT MIN(id) AS id FROM users WHERE tenant_id=?',[$tenant])['id'];
    $entity=(int)$one('SELECT MIN(id) AS id FROM accounting_entities WHERE tenant_id=?',[$tenant])['id'];
    $pdo->prepare('INSERT INTO people(tenant_id,first_name,last_name,email_primary,classification,status) VALUES (?,"CI Earnings","Approved Source","ci-native-earnings@example.invalid","w2","active")')->execute([$tenant]);
    $person=(int)$pdo->lastInsertId();$employee=(int)peopleEmploymentForNewPerson($person,$actor)['employee_id'];
    $pdo->prepare('UPDATE people_employees SET status="active",department="CI",location="NJ" WHERE id=?')->execute([$employee]);
    $pdo->prepare('INSERT INTO payroll_pay_schedules(tenant_id,name,frequency,period_start_anchor,active) VALUES (?,"CI approved earnings","weekly","2026-10-05",1)')->execute([$tenant]);
    $schedule=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO payroll_pay_periods(tenant_id,schedule_id,period_number,period_start,period_end,pay_date,status) VALUES (?,?,1,"2026-10-05","2026-10-11","2026-10-16","open")')->execute([$tenant,$schedule]);
    $period=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO payroll_profiles(tenant_id,employee_id,schedule_id,work_state,payment_method,default_hours_per_period,enabled) VALUES (?,?,?,"NJ","check",40,1)')->execute([$tenant,$employee,$schedule]);
    $pdo->prepare('INSERT INTO people_compensation(tenant_id,employee_id,pay_type,pay_rate_cents,pay_frequency,effective_from,currency) VALUES (?,?,"hourly",99999,"weekly","2026-01-01","USD")')->execute([$tenant,$employee]);
    $comp=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO people_tax_federal(tenant_id,employee_id,filing_status,effective_date) VALUES (?,?,"single","2026-01-01")')->execute([$tenant,$employee]);
    $pdo->prepare('INSERT INTO payroll_runs(tenant_id,pay_period_id,run_type,created_by_user_id,status) VALUES (?,?,"off_cycle",?,"draft")')->execute([$tenant,$period,$actor]);
    $run=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO placements(tenant_id,person_id,accounting_entity_id,status,start_date,engagement_type,title) VALUES (?,?,?,"active","2026-10-01","w2","CI retained earnings")')->execute([$tenant,$person,$entity]);
    $placement=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO placement_rates(tenant_id,placement_id,effective_from,pay_rate,pay_rate_unit,bill_rate,currency,ot_multiplier,dt_multiplier,approved_at) VALUES (?,?,"2026-10-01",65.005,"hour",98,"USD",1.75,2.25,"2026-10-12 12:00:00")')->execute([$tenant,$placement]);
    $rate=(int)$pdo->lastInsertId();
    $sheet=(int)staffingTimesheetUpsert($person,'2026-10-05','2026-10-11',['tenant_id'=>$tenant,'source'=>'manual_entry'])['id'];
    $pdo->prepare('INSERT INTO time_periods(tenant_id,start_date,end_date,label) VALUES (?,"2026-10-05","2026-10-11","CI native earnings")')->execute([$tenant]);
    $timePeriod=(int)$pdo->lastInsertId();$entryIds=[];
    foreach([['8','65.005','regular','regular_billable',1],['2','65.005','overtime','OT_billable',1],['1','80.125','doubletime','custom',1],['9','65.005','regular','unpaid_leave',0]] as [$hours,$base,$type,$category,$payable]) {
        $pdo->prepare('UPDATE placement_rates SET pay_rate=? WHERE id=?')->execute([$base,$rate]);
        $pdo->prepare('INSERT INTO time_entries(tenant_id,placement_id,person_id,period_id,timesheet_id,work_date,category,hour_type,hours,billable,payable,status,rate_snapshot_id,approved_at,approved_via,approved_by_user_id,payroll_extracted_ref,payroll_extracted_at)
            VALUES (?,?,?,?,?,"2026-10-06",?,?,?,1,?,"approved",?,"2026-10-12 12:00:00","manual",?,?,NOW())')->execute([$tenant,$placement,$person,$timePeriod,$sheet,$category,$type,$hours,$payable,$rate,$actor,$run]);
        $entry=(int)$pdo->lastInsertId();$entryIds[]=$entry;
        payrollInputFixtureApprovalEvidence($pdo,[$entry]);
    }
    $pdo->prepare('UPDATE placement_rates SET pay_rate=999,currency="INR",pay_rate_unit="month" WHERE id=?')->execute([$rate]);
    $pdo->prepare('UPDATE people_compensation SET pay_rate_cents=0,currency="CAD" WHERE id=?')->execute([$comp]);
    payrollComputeRun($run,[],$actor,0);
    $line=$one('SELECT * FROM payroll_line_items WHERE run_id=? AND tenant_id=?',[$run,$tenant]);
    $earnings=$rows('SELECT * FROM payroll_earnings WHERE line_item_id=? ORDER BY id',[$line['id']]);
    $computed=$one('SELECT * FROM payroll_runs WHERE id=?',[$run]);
    $check((int)$line['gross_cents']===92784 && (int)$computed['gross_total_cents']===92784,'actual native compute preserves original mixed rates despite edited rate and compensation records');
    $check($line['earnings_basis']==='approved_time' && (string)$line['hours_regular']==='8.0000' && (string)$line['hours_overtime']==='3.0000','stored hourly source basis excludes nonpayable hours and keeps precision');
    $check((int)$line['pay_rate_cents']===0 && count($earnings)===3 && array_column($earnings,'rate_cents')===[null,null,null],'no invented single or rounded rate replaces original source components');
    $check(hash('sha256',$line['source_time_json'])===$line['source_time_sha256'] && count(json_decode($line['source_time_json'],true)['sources'])===4,'persisted receipt retains every approved source including nonpayable time');
    $check(array_sum(array_map(static fn($e)=>(int)$e['amount_cents'],$earnings))===92784 && count(array_filter($earnings,static fn($e)=>$e['source_rate']==='65.0050'))===2,'stored earning amounts and precise rates tie to computed gross');
    $check((int)$computed['computation_revision']===1 && $pdo->inTransaction(),'compute increments revision and preserves caller transaction');
    $fingerprint=static function()use($rows,$run):array{return [
        $rows('SELECT * FROM payroll_runs WHERE id=?',[$run]),$rows('SELECT * FROM payroll_line_items WHERE run_id=? ORDER BY id',[$run]),
        $rows('SELECT e.* FROM payroll_earnings e JOIN payroll_line_items l ON l.id=e.line_item_id WHERE l.run_id=? ORDER BY e.id',[$run]),
        $rows('SELECT * FROM workflow_instances WHERE subject_type="payroll_run" AND subject_id=? ORDER BY id',[$run])];};
    $before=$fingerprint();
    $check($refuses(fn()=>payrollComputeRun($run,[],$actor,0),'changed since') && $fingerprint()===$before,'stale revision refuses before deleting prior earnings');
    $check($refuses(fn()=>payrollComputeRun($run,[$employee=>['hours_regular'=>99]],$actor,1),'cannot be overridden') && $fingerprint()===$before,'approved hours cannot be overridden through the actual compute service');
    $check($refuses(fn()=>payrollComputeRun($run,[$employee=>['approved_time_earnings'=>[]]],$actor,1),'Unsupported payroll override') && $fingerprint()===$before,'request fields cannot substitute internal approved earnings');
    $pdo->prepare('UPDATE payroll_runs SET notes="CI earnings persistence failure" WHERE id=?')->execute([$run]);$before=$fingerprint();
    $check($refuses(fn()=>payrollComputeRun($run,[],$actor,1),'CI earnings persistence failure') && $fingerprint()===$before && $pdo->inTransaction(),'late earning insert failure restores previous line IDs, totals and revision within caller transaction');
    $pdo->prepare('UPDATE payroll_runs SET notes="CI workflow persistence failure" WHERE id=?')->execute([$run]);$before=$fingerprint();
    $check($refuses(fn()=>payrollComputeRunForReview($run,[],$actor,1),'Could not start payroll approval workflow') && $fingerprint()===$before,'failure to create independent review rolls back recomputed earnings and workflow changes');
    $pdo->prepare('UPDATE payroll_runs SET notes=NULL WHERE id=?')->execute([$run]);
    $pdo->prepare('UPDATE time_entries SET hours=99 WHERE id=?')->execute([$entryIds[0]]);$before=$fingerprint();
    $check($refuses(fn()=>payrollComputeRun($run,[],$actor,1),'approved time or rate evidence changed') && $fingerprint()===$before,'changed approved source facts cannot silently recalculate payroll');
    $pdo->prepare('UPDATE time_entries SET hours=8 WHERE id=?')->execute([$entryIds[0]]);
    payrollComputeRun($run,[$employee=>['bonus_cents'=>100,'commission_cents'=>50]],$actor,1);
    $line2=$one('SELECT * FROM payroll_line_items WHERE run_id=?',[$run]);
    $check((int)$line2['gross_cents']===92934 && $line2['source_time_sha256']===$line['source_time_sha256'],'recompute retains source receipt and adds exact supplemental earnings');
    payrollExportCheckSourceFixture($pdo,$tenant,$run,$person,$employee,$check,$refuses);
    $before=$fingerprint();$main=$pdo;
    $second=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $second->exec('SET SESSION innodb_lock_wait_timeout=1');$pdo=$second;
    try {
        $check($refuses(fn()=>payrollComputeRun($run,[],$actor,2),'Lock wait timeout') && !$second->inTransaction(),'competing computation waits for the original run lock and rolls back on timeout');
        $check($refuses(fn()=>payrollExportRows($tenant,$run),'Lock wait timeout') && !$second->inTransaction(),'export waits for the native computation lock and rolls back its own failed transaction');
    } finally {$pdo=$main;$second=null;}
    $check($fingerprint()===$before && $pdo->inTransaction(),'competing compute cannot change source earnings or caller transaction');
    foreach(['approved','paid'] as $status){
        $pdo->prepare('UPDATE payroll_runs SET status=? WHERE id=?')->execute([$status,$run]);$before=$fingerprint();
        $check($refuses(fn()=>payrollComputeRun($run,[],$actor,2),'unapproved, unposted') && $fingerprint()===$before,$status.' payroll cannot be recomputed');
    }
    $pdo->prepare('UPDATE payroll_runs SET status="computed",approved_at=NOW() WHERE id=?')->execute([$run]);$before=$fingerprint();
    $check($refuses(fn()=>payrollComputeRun($run,[],$actor,2),'unapproved, unposted') && $fingerprint()===$before,'changing the status label cannot erase historical approval');
    $pdo->prepare('UPDATE payroll_runs SET approved_at=NULL WHERE id=?')->execute([$run]);
    $pdo->prepare('UPDATE time_entries SET payroll_extracted_ref=NULL WHERE payroll_extracted_ref=? AND id<>?')->execute([$run,$entryIds[3]]);
    payrollComputeRun($run,[],$actor,2);$zero=$one('SELECT * FROM payroll_line_items WHERE run_id=?',[$run]);
    $check((int)$zero['gross_cents']===0 && (float)$zero['hours_regular']===0.0 && $zero['earnings_basis']==='approved_time','nonpayable-only approved source does not fall back to forty profile hours');
    $pdo->prepare('UPDATE time_entries SET payroll_extracted_ref=NULL WHERE id=?')->execute([$entryIds[3]]);
    $pdo->prepare('UPDATE people_compensation SET pay_rate_cents=3000,currency="USD" WHERE id=?')->execute([$comp]);
    payrollComputeRun($run,[$employee=>['hours_regular'=>'1.2345']],$actor,3);$ordinary=$one('SELECT * FROM payroll_line_items WHERE run_id=?',[$run]);
    $check($ordinary['earnings_basis']==='compensation' && $ordinary['hours_regular']==='1.2345' && (int)$ordinary['gross_cents']===3704,'compensation-only native payroll retains explicit decimal-hour overrides');
    payrollExportCheckSharedDirectory($pdo,$tenant,$run,$check,$refuses);
    $pdo->prepare('UPDATE people_compensation SET pay_type="salary",pay_rate_cents=5200000 WHERE id=?')->execute([$comp]);
    payrollComputeRun($run,[],$actor,4);$salary=$one('SELECT * FROM payroll_line_items WHERE run_id=?',[$run]);
    $check((int)$salary['gross_cents']===100000 && $salary['earnings_basis']==='compensation','explicit annual salary remains weekly salary without hourly repricing');
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('DROP TRIGGER IF EXISTS ci_payroll_compute_reject');
    $pdo->exec('DROP TRIGGER IF EXISTS ci_payroll_review_reject');
}
$check($counts()===$baseline,'all synthetic payroll, source and identity records were rolled back');
echo "{$checks} native approved earnings database checks passed\n";
