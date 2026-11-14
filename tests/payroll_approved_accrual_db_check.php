<?php
/** Actual payroll accrual journals in the fully migrated, disposable CI ledger. */
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('COREFLUX_ENV')!=='coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture',$argv,true)) exit(2);
putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));
putenv('COREFLUX_INTEGRATION_INSTANCE_ID=381e452a-5d11-4e2e-9daa-e9427501898a');
require_once __DIR__.'/../modules/payroll/lib/payroll.php';
require_once __DIR__.'/../modules/payroll/lib/accounting_posting.php';
require_once __DIR__.'/../modules/people/lib/employment_links.php';
require_once __DIR__.'/../modules/staffing/lib/timesheets.php';
require_once __DIR__.'/../modules/staffing/lib/posting_rules_seed.php';
require_once __DIR__.'/../modules/time/lib/accounting_handoff.php';
require_once __DIR__.'/../modules/time/lib/settlement.php';
require_once __DIR__.'/../core/posting_engine/seed_defaults.php';
require_once __DIR__.'/fixtures/payroll_input_approval_evidence.php';
$pdo=getDB();
if ($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci') throw new RuntimeException('Disposable CI database required.');
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();
setRequestTenantId($tenant);
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{
    if (!$ok) throw new RuntimeException($label);
    $checks++;echo "PASS {$label}\n";
};
$refuses=static function(callable $fn,string $message):bool{
    try {$fn();return false;} catch(Throwable $error) {
        if (!str_contains($error->getMessage(),$message)) throw $error;
        return true;
    }
};
$scalar=static function(string $sql,array $args=[])use($pdo):mixed{$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();};
$counts=static fn():array=>array_map(static fn(string $table):int=>(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(),
    ['accounting_journal_entries','accounting_journal_entry_lines','accounting_events','accounting_subledger_links']);
$baseline=$counts();
// Test a late source-link failure after the native engine creates the journal.
// Install the disposable trigger before the rollback scope; DDL cannot be inside it.
$pdo->exec("CREATE TRIGGER ci_payroll_accrual_reject BEFORE INSERT ON accounting_subledger_links FOR EACH ROW
    BEGIN IF NEW.link_kind='accrual' AND EXISTS (SELECT 1 FROM payroll_runs WHERE tenant_id=NEW.tenant_id
       AND CONCAT('payroll_run:',id)=NEW.source_record_id AND notes='CI force accrual failure')
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CI mandatory payroll link failure'; END IF; END");
$pdo->exec("CREATE TRIGGER ci_payroll_release_reject BEFORE INSERT ON audit_log FOR EACH ROW
    BEGIN IF NEW.event='time.payroll.sources_released' AND NEW.meta_json LIKE '%CI force release receipt failure%'
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CI release receipt failure'; END IF; END");
$pdo->beginTransaction();
try {
    $actor=(int)$scalar('SELECT MIN(id) FROM users WHERE tenant_id=?',[$tenant]);
    $entity=(int)accountingDefaultEntity($tenant)['id'];
    $check($actor>0 && $entity>0,'disposable payroll actor and legal entity exist');
    postingRulesSeedDefaults($tenant);staffingSeedPostingRules($tenant);
    $pdo->prepare('INSERT INTO people(tenant_id,first_name,last_name,email_primary,classification,status)
        VALUES (?,"CI Payroll","Approved Source","ci-payroll-accrual@example.invalid","w2","active")')->execute([$tenant]);
    $person=(int)$pdo->lastInsertId();
    $link=peopleEmploymentForNewPerson($person,$actor);
    $employee=(int)$link['employee_id'];
    $pdo->prepare('UPDATE people_employees SET status="active",department="CI",location="NJ" WHERE id=?')->execute([$employee]);
    $pdo->prepare('INSERT INTO payroll_pay_schedules(tenant_id,name,frequency,period_start_anchor,active)
        VALUES (?,"CI approved accrual","weekly","2026-10-05",1)')->execute([$tenant]);
    $schedule=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO payroll_pay_periods(tenant_id,schedule_id,period_number,period_start,period_end,pay_date,status)
        VALUES (?,?,1,"2026-10-05","2026-10-11","2026-10-16","approved")')->execute([$tenant,$schedule]);
    $period=(int)$pdo->lastInsertId();
    $makeRun=static function(string $note='')use($pdo,$tenant,$period,$actor,$employee):int{
        $pdo->prepare('INSERT INTO payroll_runs(tenant_id,pay_period_id,run_type,created_by_user_id,status,employee_count,
            gross_total_cents,taxes_total_cents,deductions_total_cents,net_total_cents,employer_taxes_cents,approved_by,approved_at,notes)
            VALUES (?,?,"off_cycle",?,"approved",1,20000,3600,1000,15400,1600,?,NOW(),?)')->execute([$tenant,$period,$actor,$actor,$note]);
        $run=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_line_items(tenant_id,run_id,employee_id,work_state,pay_type,pay_rate_cents,pay_frequency,
            hours_regular,gross_cents,pretax_cents,taxable_cents,employee_taxes_cents,posttax_cents,net_cents,employer_taxes_cents,payment_method,status)
            VALUES (?,?,?,"NJ","hourly",2500,"weekly",8,20000,600,19400,3600,400,15400,1600,"check","approved")')
            ->execute([$tenant,$run,$employee]);
        return $run;
    };
    $run=$makeRun();
    $pdo->prepare('INSERT INTO placements(tenant_id,person_id,accounting_entity_id,status,start_date,engagement_type,title)
        VALUES (?,?,?,"active","2026-10-01","w2","CI approved payroll accrual")')->execute([$tenant,$person,$entity]);
    $placement=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO placement_rates(tenant_id,placement_id,effective_from,pay_rate,pay_rate_unit,bill_rate,adder_pct,currency,approved_at)
        VALUES (?,?,"2026-10-01",25,"hour",50,0.08,"USD","2026-10-12 12:00:00")')->execute([$tenant,$placement]);
    $rate=(int)$pdo->lastInsertId();
    $sheet=(int)staffingTimesheetUpsert($person,'2026-10-05','2026-10-11',['tenant_id'=>$tenant,'source'=>'manual_entry'])['id'];
    $pdo->prepare('INSERT INTO time_periods(tenant_id,start_date,end_date,label) VALUES (?,"2026-10-05","2026-10-11","CI payroll accrual")')->execute([$tenant]);
    $timePeriod=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO time_entries(tenant_id,placement_id,person_id,period_id,timesheet_id,work_date,category,hour_type,
        hours,billable,payable,status,rate_snapshot_id,approved_at,approved_via,approved_by_user_id,payroll_extracted_ref,payroll_extracted_at)
        VALUES (?,?,?,?,?,"2026-10-06","regular_billable","regular",8,1,1,"approved",?,"2026-10-12 12:00:00","manual",?,?,NOW())')
        ->execute([$tenant,$placement,$person,$timePeriod,$sheet,$rate,$actor,$run]);
    $entry=(int)$pdo->lastInsertId();
    $dimensions=['placement'=>$placement,'worker'=>$person,'legal_entity'=>$entity,'client'=>'CI customer','work_state'=>'NJ','wc_class'=>'8810','branch'=>'CI','service_line'=>'Staffing'];
    payrollInputFixtureApprovalEvidence($pdo,[$entry],['placement'=>['engagement_type'=>'w2'],'event_entity_id'=>$entity,
        'dimensions'=>$dimensions,'missing'=>[],'vendor_dimension'=>null,'vendor_economic_party_id'=>null,'vendor_company_id'=>null,'vendor_ap_id'=>null]);
    $pdo->prepare('UPDATE staffing_timesheets SET status="approved",approved_at="2026-10-12 12:00:00",total_hours=8 WHERE id=?')->execute([$sheet]);
    $events=timeStaffingAccountingEvents($tenant,$sheet);
    $check(count($events)===1 && $events[0]['payload']['wage_cost']===200.0 && $events[0]['payload']['cost']===216.0,
        'native approved-time emitter retains wages and employer burden');
    $before=$counts();
    $check($refuses(fn()=>payrollPostRunAccrual($tenant,$run,$actor),'accounting request or posted source is missing') && $counts()===$before,
        'retained approval without its accounting handoff cannot become a second expense');
    $eventsJson=staffingDimensionSnapshotCanonicalJson($events);
    $pdo->prepare('INSERT INTO time_accounting_handoffs(tenant_id,timesheet_id,accounting_tenant_id,events_hash,events_json,status)
        VALUES (?,?,?,?,?,"pending")')->execute([$tenant,$sheet,$tenant,hash('sha256',$eventsJson),$eventsJson]);
    $handoff=(int)$pdo->lastInsertId();
    $check($refuses(fn()=>payrollPostRunAccrual($tenant,$run,$actor),'pending or failed accounting request') && $counts()===$before,
        'pending approved-week accounting must finish before payroll can recognize or clear labor');
    $pdo->prepare('UPDATE time_accounting_handoffs SET status="failed" WHERE id=?')->execute([$handoff]);
    $check($refuses(fn()=>payrollPostRunAccrual($tenant,$run,$actor),'pending or failed accounting request') && $counts()===$before,
        'failed week accounting cannot silently fall back to new payroll expense');
    $pdo->prepare('UPDATE time_accounting_handoffs SET status="posted" WHERE id=?')->execute([$handoff]);
    $check($refuses(fn()=>payrollPostRunAccrual($tenant,$run,$actor),'accounting request or posted source is missing') && $counts()===$before,
        'a posted handoff label without its actual source journal cannot authorize payroll');
    $source=accountingProcessEvent($tenant,$events[0],$actor);
    $check($source['status']==='posted','native staffing source posts to the actual disposable ledger');
    $pdo->prepare('UPDATE time_accounting_handoffs SET accounting_tenant_id=999 WHERE id=?')->execute([$handoff]);
    $check($refuses(fn()=>payrollPostRunAccrual($tenant,$run,$actor),'accounting owner changed'),
        'a saved accounting owner cannot silently move when payroll posts');
    $pdo->prepare('UPDATE time_accounting_handoffs SET accounting_tenant_id=? WHERE id=?')->execute([$tenant,$handoff]);
    $sourceJournal=(int)$source['journal_entry_id'];
    $employeeRows=payrollAccountingRunEmployeeRows($tenant,$run);
    $check((int)$employeeRows[$employee]['person_id']===$person,'payroll posting uses the canonical new-person employment link');
    $pdo->prepare('UPDATE people SET email_primary="changed-contact@example.invalid" WHERE id=?')->execute([$person]);
    $check((int)payrollAccountingRunEmployeeRows($tenant,$run)[$employee]['person_id']===$person,'contact changes cannot redirect payroll identity');
    $pdo->exec('SAVEPOINT missing_identity');
    $pdo->prepare('DELETE FROM people_employment_links WHERE id=?')->execute([$link['id']]);
    $before=$counts();
    $check($refuses(fn()=>payrollPostRunAccrual($tenant,$run,$actor),'reviewed person/employment link') && $counts()===$before && $pdo->inTransaction(),
        'missing employment authority refuses with no ledger writes and preserves caller transaction');
    $pdo->exec('ROLLBACK TO SAVEPOINT missing_identity');
    $pdo->prepare('UPDATE placement_rates SET pay_rate=999,adder_pct=0.9,currency="INR",pay_rate_unit="month" WHERE id=?')->execute([$rate]);
    $coverage=payrollAccountingStaffingAccrualGroups($tenant,$run,$employeeRows);
    $check($coverage[0]['accrued_wage_cents']===20000 && $coverage[0]['accrued_total_cents']===21600,
        'current rate, burden, currency and unit edits cannot reprice approved accrual coverage');
    $pdo->exec('SAVEPOINT changed_source');
    $pdo->prepare('UPDATE accounting_journal_entries SET status="reversed" WHERE id=?')->execute([$sourceJournal]);
    $check($refuses(fn()=>payrollPostRunAccrual($tenant,$run,$actor),'missing, reversed'),'reversed staffing journal cannot authorize payroll clearing');
    $pdo->exec('ROLLBACK TO SAVEPOINT changed_source');
    $pdo->prepare('UPDATE accounting_events SET status="failed" WHERE id=?')->execute([$source['event_id']]);
    $check($refuses(fn()=>payrollPostRunAccrual($tenant,$run,$actor),'not posted'),'failed staffing event is not treated as unaccrued payroll expense');
    $pdo->exec('ROLLBACK TO SAVEPOINT changed_source');
    $pdo->prepare('UPDATE accounting_journal_entry_lines SET credit=credit-1 WHERE je_id=? AND credit>0')->execute([$sourceJournal]);
    $check($refuses(fn()=>payrollPostRunAccrual($tenant,$run,$actor),'recorded staffing liability'),
        'changed source liability cannot authorize clearing its original amount');
    $pdo->exec('ROLLBACK TO SAVEPOINT changed_source');
    $check($refuses(fn()=>timeSettlementUnExtract([$entry],'payroll','CI protected approved run',$actor),'uncomputed draft')
        && (int)$scalar('SELECT payroll_extracted_ref FROM time_entries WHERE id=?',[$entry])===$run,
        'approved payroll cannot release time for a second payroll run');
    $pdo->exec('SAVEPOINT draft_release');
    $draft=$makeRun();
    $pdo->prepare('UPDATE payroll_runs SET status="draft",approved_at=NULL,approved_by=NULL WHERE id=?')->execute([$draft]);
    $pdo->prepare('UPDATE time_entries SET payroll_extracted_ref=? WHERE id=?')->execute([$draft,$entry]);
    $check($refuses(fn()=>timeSettlementUnExtract([$entry],'payroll','CI retained computed lines',$actor),'Computed payroll earnings'),
        'a draft label cannot release sources while computed employee earnings remain');
    $pdo->prepare('DELETE FROM payroll_line_items WHERE run_id=?')->execute([$draft]);
    $pdo->prepare('UPDATE payroll_runs SET computed_at=NOW() WHERE id=?')->execute([$draft]);
    $check($refuses(fn()=>timeSettlementUnExtract([$entry],'payroll','CI retained computation history',$actor),'uncomputed draft'),
        'retained computation history still prevents blind release after lines are removed');
    $pdo->prepare('UPDATE payroll_runs SET computed_at=NULL WHERE id=?')->execute([$draft]);
    $check($refuses(fn()=>timeSettlementUnExtract([$entry],'payroll','CI force release receipt failure',$actor),'CI release receipt failure')
        && (int)$scalar('SELECT payroll_extracted_ref FROM time_entries WHERE id=?',[$entry])===$draft && $pdo->inTransaction(),
        'a failed mandatory release receipt restores the source stamp and preserves caller transaction');
    $release=timeSettlementUnExtract([$entry],'payroll','CI reviewed uncomputed draft release',$actor);
    $check($release['unextracted_count']===1 && $pdo->inTransaction()
        && !$scalar('SELECT payroll_extracted_ref FROM time_entries WHERE id=?',[$entry]),
        'uncomputed draft releases its exact source without committing caller work');
    $receipt=json_decode((string)$scalar('SELECT meta_json FROM audit_log WHERE tenant_id=? AND event="time.payroll.sources_released" ORDER BY id DESC LIMIT 1',[$tenant]),true);
    $check((int)$receipt['sources'][0]['already_ref']===$draft && $receipt['reason']==='CI reviewed uncomputed draft release',
        'release retains its original run and operator reason in the same transaction');
    $check($refuses(fn()=>timeSettlementUnExtract([$entry],'payroll','CI repeated release',$actor),'valid original payroll run'),
        'released time cannot be released a second time');
    $pdo->exec('ROLLBACK TO SAVEPOINT draft_release');
    $pdo->prepare('UPDATE payroll_runs SET notes="CI force accrual failure" WHERE id=?')->execute([$run]);
    $before=$counts();
    $check($refuses(fn()=>payrollPostRunAccrual($tenant,$run,$actor),'CI mandatory payroll link failure')
        && $counts()===$before && !$scalar('SELECT journal_entry_id FROM payroll_runs WHERE id=?',[$run]) && $pdo->inTransaction(),
        'late mandatory-link failure rolls back journal, event, lines and run link inside caller transaction');
    $pdo->prepare('UPDATE payroll_runs SET notes=NULL WHERE id=?')->execute([$run]);
    $posted=payrollPostRunAccrual($tenant,$run,$actor);
    $check($posted['status']==='posted' && $pdo->inTransaction(),'native payroll journal posts without committing caller transaction');
    $journal=(int)$posted['je_id'];
    $q=$pdo->prepare('SELECT a.code,ROUND(SUM(l.debit)*100) AS debit,ROUND(SUM(l.credit)*100) AS credit
        FROM accounting_journal_entry_lines l JOIN accounting_accounts a ON a.tenant_id=l.tenant_id AND a.id=l.account_id
        WHERE l.tenant_id=? AND l.je_id=? GROUP BY a.code ORDER BY a.code');
    $q->execute([$tenant,$journal]);$amounts=[];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $amounts[$row['code']]=[(int)$row['debit'],(int)$row['credit']];
    $check($amounts===['2150'=>[21600,0],'2200'=>[0,15400],'2210'=>[0,5200],'2220'=>[0,1000]],
        'payroll clears original labor liability once and recognizes net, tax and deduction payables without duplicate expense');
    $before=$counts();$replay=payrollPostRunAccrual($tenant,$run,$actor);
    $check($replay['je_id']===$journal && $replay['idempotent_replay'] && $counts()===$before,'exact posting replay retains one event and journal');
    $check($refuses(fn()=>timeSettlementUnExtract([$entry],'payroll','CI posted run release',$actor),'uncomputed draft')
        && $counts()===$before,'posted payroll retains its source and ledger when release is attempted');
    $pdo->exec('SAVEPOINT changed_payroll');
    $pdo->prepare('UPDATE accounting_journal_entries SET status="reversed" WHERE id=?')->execute([$journal]);
    $check($refuses(fn()=>payrollPostRunAccrual($tenant,$run,$actor),'no longer posted'),'a reversed payroll journal cannot be reported as a successful replay');
    $pdo->exec('ROLLBACK TO SAVEPOINT changed_payroll');
    // The same People directory can serve a separate original payroll company.
    $pdo->prepare('UPDATE tenants SET tenant_type="sub",parent_id=? WHERE id=999')->execute([$tenant]);
    $pdo->exec('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(999,"people","shared") ON DUPLICATE KEY UPDATE scope_mode="shared"');
    $pdo->prepare('UPDATE payroll_runs SET tenant_id=999 WHERE id=?')->execute([$run]);
    $pdo->prepare('UPDATE payroll_line_items SET tenant_id=999 WHERE run_id=?')->execute([$run]);
    setRequestTenantId(999);
    $check((int)payrollAccountingRunEmployeeRows(999,$run)[$employee]['person_id']===$person,
        'original payroll company resolves employee in its shared People directory');
    $pdo->exec('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=999 AND module_key="people"');
    $check($refuses(fn()=>payrollAccountingRunEmployeeRows(999,$run),'reviewed person/employment link'),
        'current isolated People policy cannot borrow the former shared employee');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('DROP TRIGGER IF EXISTS ci_payroll_accrual_reject');
    $pdo->exec('DROP TRIGGER IF EXISTS ci_payroll_release_reject');
    setRequestTenantId($tenant);
}
$check($counts()===$baseline,'disposable source and payroll ledger fixture rolls back completely');
echo json_encode(['checks'=>$checks,'cleanup'=>true],JSON_PRETTY_PRINT)."\n";
