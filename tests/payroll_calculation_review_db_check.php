<?php
/** Independent payroll review, exact calculation identity and transactional failure tests. */
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('COREFLUX_ENV')!=='coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture',$argv,true)) exit(2);
putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));
putenv('COREFLUX_INTEGRATION_INSTANCE_ID=381e452a-5d11-4e2e-9daa-e9427501898a');
require_once __DIR__.'/../modules/payroll/lib/run_compute.php';
require_once __DIR__.'/../modules/payroll/lib/workflow_sync.php';
require_once __DIR__.'/../modules/payroll/lib/approval_settings.php';
require_once __DIR__.'/../modules/payroll/lib/accounting_posting.php';
require_once __DIR__.'/../modules/people/lib/employment_links.php';
$pdo=getDB();
if ($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci') throw new RuntimeException('Disposable CI database required.');
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();
setRequestTenantId($tenant);setRequestModuleScope('payroll');
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS {$label}\n";};
$refuses=static function(callable $fn,string $message):bool{try{$fn();return false;}catch(Throwable $e){if(!str_contains($e->getMessage(),$message))throw $e;return true;}};
$rows=static function(string $sql,array $args=[])use($pdo):array{$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);};
$one=static fn(string $sql,array $args=[]):array=>$rows($sql,$args)[0]??[];
$state=static function()use($rows):array{
    $result=[];foreach(['payroll_runs','payroll_line_items','payroll_earnings','payroll_taxes','payroll_deductions','workflow_instances','workflow_step_actions']as$table)$result[$table]=$rows('SELECT * FROM '.$table.' ORDER BY id');return $result;
};
$before=$state();
$financialBefore=[$rows('SELECT * FROM accounting_events ORDER BY id'),$rows('SELECT * FROM accounting_journal_entries ORDER BY id')];
$pdo->exec("CREATE TRIGGER ci_payroll_review_projection_reject BEFORE UPDATE ON payroll_line_items FOR EACH ROW BEGIN
    IF NEW.status='approved' AND EXISTS(SELECT 1 FROM payroll_runs WHERE tenant_id=NEW.tenant_id AND id=NEW.run_id AND notes='CI review projection failure')
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CI review projection failure'; END IF; END");
$pdo->beginTransaction();
try {
    $actors=[];
    foreach(['maker','reviewer','outside'] as $name){
        $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES (?,?,?,"!no-login-ci-fixture","!no-login-ci-fixture","tenant_admin",1,0)')->execute([$tenant,'CI payroll '.$name,'ci-payroll-review-'.$name.'@example.invalid']);
        $actors[$name]=(int)$pdo->lastInsertId();
        provisionMembership($actors[$name],$tenant,'tenant_admin',['is_primary'=>true,'status'=>'active']);
    }
    RBACResolver::resetCache();
    [$maker,$reviewer,$outside]=[$actors['maker'],$actors['reviewer'],$actors['outside']];
    payrollRunApprovalSettingsSave($tenant,[$reviewer],$maker);
    $check(payrollRunApprovalReadiness($tenant,$maker)['ready'],'synthetic calculation has a distinct eligible reviewer');
    $pdo->prepare('INSERT INTO people(tenant_id,first_name,last_name,email_primary,classification,status) VALUES (?,"CI Review","Worker","ci-payroll-review-worker@example.invalid","w2","active")')->execute([$tenant]);
    $person=(int)$pdo->lastInsertId();$employee=(int)peopleEmploymentForNewPerson($person,$maker)['employee_id'];
    $pdo->prepare('UPDATE people_employees SET status="active" WHERE id=?')->execute([$employee]);
    $pdo->prepare('INSERT INTO payroll_pay_schedules(tenant_id,name,frequency,period_start_anchor,active) VALUES (?,"CI exact payroll review","weekly","2026-10-05",1)')->execute([$tenant]);
    $schedule=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO payroll_pay_periods(tenant_id,schedule_id,period_number,period_start,period_end,pay_date,status) VALUES (?,?,1,"2026-10-05","2026-10-11","2026-10-16","open")')->execute([$tenant,$schedule]);
    $period=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO payroll_profiles(tenant_id,employee_id,schedule_id,work_state,payment_method,default_hours_per_period,enabled) VALUES (?,?,?,"NJ","check",40,1)')->execute([$tenant,$employee,$schedule]);
    $pdo->prepare('INSERT INTO people_compensation(tenant_id,employee_id,pay_type,pay_rate_cents,pay_frequency,effective_from,currency) VALUES (?,?,"hourly",3000,"weekly","2026-01-01","USD")')->execute([$tenant,$employee]);
    $pdo->prepare('INSERT INTO people_tax_federal(tenant_id,employee_id,filing_status,effective_date) VALUES (?,?,"single","2026-01-01")')->execute([$tenant,$employee]);
    $newRun=static function()use($pdo,$tenant,$period,$maker):int{
        $pdo->prepare('INSERT INTO payroll_runs(tenant_id,pay_period_id,run_type,created_by_user_id,status) VALUES (?,?,"off_cycle",?,"draft")')->execute([$tenant,$period,$maker]);
        $id=(int)$pdo->lastInsertId();payrollComputeRunForReview($id,[],$maker,0);return $id;
    };
    $runId=$newRun();$run=payrollCalculationRun($tenant,$runId);$workflow=(int)$run['workflow_instance_id'];
    $payload=json_decode($one('SELECT payload_json FROM workflow_instances WHERE id=?',[$workflow])['payload_json'],true);
    $check(strlen($run['computation_sha256'])===64 && $payload['computation_revision']===1 && $payload['computation_sha256']===$run['computation_sha256'],'review request binds the full saved calculation and its revision');
    $line=(int)$one('SELECT id FROM payroll_line_items WHERE run_id=?',[$runId])['id'];
    $snapshot=$state();
    $check($refuses(fn()=>workflowAct($tenant,$workflow,$maker,'approve'),'Separation of duties') && $state()===$snapshot,'generic workflow cannot approve the preparer own payroll');
    $check($refuses(fn()=>workflowAct($tenant,$workflow,$outside,'approve'),'not an approver') && $state()===$snapshot,'eligible but unrouted user cannot approve through the generic endpoint service');
    $check($refuses(fn()=>workflowAct($tenant,$workflow,$reviewer,'skip'),'approve or reject') && $state()===$snapshot,'skip cannot bypass independent payroll approval');
    $check($refuses(fn()=>workflowAct($tenant,$workflow,null,'approve',null,'email'),'signed-in reviewer') && $state()===$snapshot,'unsigned or email workflow decisions cannot approve payroll');
    $check($refuses(fn()=>workflowAct($tenant+999999,$workflow,$reviewer,'approve'),'Instance not found') && $state()===$snapshot,'another workspace cannot decide the review');
    $check($refuses(fn()=>payrollRunWorkflowAct($tenant,$run,$reviewer,'approve'),'changed since') && $state()===$snapshot,'native review requires displayed revision and fingerprint');
    $check($refuses(fn()=>payrollRunWorkflowAct($tenant,$run,$reviewer,'approve',null,1,str_repeat('f',64)),'changed since') && $state()===$snapshot,'native review cannot substitute a different calculation fingerprint');
    $changes=[
        ['UPDATE payroll_earnings SET amount_cents=amount_cents+1 WHERE line_item_id=?',[$line],'earnings'],
        ['UPDATE payroll_taxes SET amount_cents=amount_cents+1 WHERE line_item_id=?',[$line],'taxes'],
        ['INSERT INTO payroll_deductions(tenant_id,line_item_id,code,is_pretax,amount_cents) VALUES (?, ?,"other_posttax",0,1)',[$tenant,$line],'deductions'],
        ['UPDATE payroll_line_items SET source_time_json="changed" WHERE id=?',[$line],'source receipt'],
        ['UPDATE payroll_line_items SET payment_method="direct_deposit" WHERE id=?',[$line],'payment method'],
        ['UPDATE payroll_pay_periods SET pay_date="2026-10-17" WHERE id=?',[$period],'pay date'],
        ['UPDATE payroll_runs SET net_total_cents=net_total_cents+1 WHERE id=?',[$runId],'run total'],
    ];
    foreach($changes as [$sql,$args,$label]){
        $pdo->exec('SAVEPOINT ci_review_change');$pdo->prepare($sql)->execute($args);$changed=$state();
        $check($refuses(fn()=>workflowAct($tenant,$workflow,$reviewer,'approve'),'calculation changed') && $state()===$changed,'changed '.$label.' refuses before recording any decision');
        $pdo->exec('ROLLBACK TO SAVEPOINT ci_review_change');$pdo->exec('RELEASE SAVEPOINT ci_review_change');
    }
    $pdo->prepare('UPDATE users SET is_active=0 WHERE id=?')->execute([$reviewer]);$snapshot=$state();
    $check($refuses(fn()=>workflowAct($tenant,$workflow,$reviewer,'approve'),'approval access is required') && $state()===$snapshot,'revoked reviewer access is checked at decision time');
    $pdo->prepare('UPDATE users SET is_active=1 WHERE id=?')->execute([$reviewer]);
    $pdo->prepare('UPDATE payroll_runs SET notes="CI review projection failure" WHERE id=?')->execute([$runId]);$snapshot=$state();
    $check($refuses(fn()=>workflowAct($tenant,$workflow,$reviewer,'approve'),'CI review projection failure') && $state()===$snapshot && $pdo->inTransaction(),'late subject failure rolls back workflow status, decision receipt and payroll approval inside the caller transaction');
    $pdo->prepare('UPDATE payroll_runs SET notes=NULL WHERE id=?')->execute([$runId]);
    $newWorkflow=payrollComputeRunForReview($runId,[],$maker,1);$current=payrollCalculationRun($tenant,$runId);
    $check($newWorkflow!==$workflow && $one('SELECT status FROM workflow_instances WHERE id=?',[$workflow])['status']==='cancelled' && (int)$current['computation_revision']===2,'recompute retires the old review and creates a distinct review even for equal amounts');
    $snapshot=$state();
    $check($refuses(fn()=>workflowAct($tenant,$workflow,$reviewer,'approve'),'already cancelled') && $state()===$snapshot,'generic old review cannot approve a recomputation');
    $check($refuses(fn()=>payrollRunWorkflowAct($tenant,$run,$reviewer,'approve',null,1,$run['computation_sha256']),'changed since') && $state()===$snapshot,'native stale screen cannot fall forward to a newer review');
    $main=$pdo;$second=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $second->exec('SET SESSION innodb_lock_wait_timeout=1');$pdo=$second;
    try{$check($refuses(fn()=>payrollRunWorkflowAct($tenant,$current,$reviewer,'approve',null,2,$current['computation_sha256']),'Lock wait timeout') && !$second->inTransaction(),'a competing native approval waits for the payroll calculation lock');}
    finally{$pdo=$main;$second=null;}
    $check($state()===$snapshot,'contention leaves both the saved calculation and review untouched');
    $approved=payrollRunWorkflowAct($tenant,$current,$reviewer,'approve',null,2,$current['computation_sha256']);
    $saved=payrollCalculationRun($tenant,$runId);
    $check($approved['approved'] && (int)$saved['approved_by']===$reviewer && (int)$saved['approved_computation_revision']===2 && $saved['approved_computation_sha256']===$current['computation_sha256'],'independent native approval records the exact reviewed computation');
    $check($one('SELECT status FROM payroll_line_items WHERE run_id=?',[$runId])['status']==='approved' && $one('SELECT status FROM workflow_instances WHERE id=?',[$newWorkflow])['status']==='approved','native approval and workflow/line projections commit together');
    $snapshot=$state();$check($refuses(fn()=>workflowAct($tenant,$newWorkflow,$reviewer,'approve'),'current unapproved') && $state()===$snapshot,'repeated approval cannot create a second decision');
    payrollAccountingLoadRun($tenant,$runId);$check(true,'unchanged approved calculation passes the accounting reader without posting');
    $pdo->exec('SAVEPOINT ci_approved_change');$pdo->prepare('UPDATE payroll_line_items SET net_cents=net_cents+1 WHERE run_id=?')->execute([$runId]);
    $check($refuses(fn()=>payrollAccountingLoadRun($tenant,$runId),'calculation changed'),'changed approved earnings cannot pass into accounting');
    $pdo->exec('ROLLBACK TO SAVEPOINT ci_approved_change');$pdo->exec('RELEASE SAVEPOINT ci_approved_change');
    $secondRun=$newRun();$secondRunRow=payrollCalculationRun($tenant,$secondRun);$secondWorkflow=(int)$secondRunRow['workflow_instance_id'];
    workflowAct($tenant,$secondWorkflow,$reviewer,'reject','Review source time');
    $check(payrollCalculationRun($tenant,$secondRun)['status']==='computed' && $one('SELECT status FROM workflow_instances WHERE id=?',[$secondWorkflow])['status']==='rejected','rejection retains the calculation without granting payroll approval');
    $reopened=payrollComputeRunForReview($secondRun,[],$maker,1);
    workflowAct($tenant,$reopened,$reviewer,'approve');
    $check(payrollCalculationRun($tenant,$secondRun)['status']==='approved','a fresh computation after rejection can receive independent generic-workflow approval');
    $check([$rows('SELECT * FROM accounting_events ORDER BY id'),$rows('SELECT * FROM accounting_journal_entries ORDER BY id')]===$financialBefore,'review tests create no financial postings');
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('DROP TRIGGER IF EXISTS ci_payroll_review_projection_reject');
    RBACResolver::resetCache();
}
$check($state()===$before,'all synthetic calculation and review records are removed');
echo "{$checks} exact payroll review database checks passed\n";
