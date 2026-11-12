<?php
/** Real rules, graph, approved snapshots and schedule claims in a disposable DB. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    ||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../modules/placements/lib/source_rule_edit.php';
require_once __DIR__.'/../core/jobdiva/sync.php';
require_once __DIR__.'/../core/migrate.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if(!$tenant)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$n=0;$labels=[];
$check=static function(bool $ok,string $label)use(&$n,&$labels):void{if(!$ok)throw new RuntimeException($label);$n++;$labels[]=$label;echo "PASS $label\n";};
$refused=static function(callable $f,int $code):bool{try{$f();return false;}catch(InvalidArgumentException|RuntimeException $e){if($e->getCode()!==$code)throw $e;return true;}};
$read=static fn(string $table):array=>getDB()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$old=[$read('placement_commissions'),$read('placement_economic_parties')];
foreach([1,2] as $pass)foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/196_commission_terms.sql')) as $sql)$pdo->exec($sql);
$check([$read('placement_commissions'),$read('placement_economic_parties')]===$old,'Migration replay preserves all existing commission and graph values');
$protected=[$read('payroll_runs'),$read('accounting_events'),$read('accounting_journal_entries'),$read('time_entries')];
$pdo->beginTransaction();
try {
    $uid=(int)$pdo->query('SELECT MIN(id) FROM users WHERE is_active=1')->fetchColumn();$user=['id'=>$uid];
    require_once __DIR__.'/payroll_recipient_fixture.php';
    payrollRecipientFixture($pdo,$tenant,$uid,[992001,992002,992003,992004,992005]);
    $protected=[$read('payroll_runs'),$read('accounting_events'),$read('accounting_journal_entries'),$read('time_entries')];
    $pdo->prepare('INSERT INTO tenant_memberships(tenant_id,user_id,persona_label,persona_type,is_primary,status) VALUES(?,?,"CI terms","tenant_admin",1,"active") ON DUPLICATE KEY UPDATE status="active"')->execute([$tenant,$uid]);
    $person=scopedInsert('people',['first_name'=>'CI','last_name'=>'Commission terms','classification'=>'w2','email_primary'=>'commission-terms@example.invalid']);
    $pid=scopedInsert('placements',['person_id'=>$person,'title'=>'CI explicit commission','status'=>'on_hold','engagement_type'=>'w2','start_date'=>'2026-05-18','external_id'=>'jd:99200001']);
    $rateId=scopedInsert('placement_rates',['placement_id'=>$pid,'effective_from'=>'2026-05-18','currency'=>'USD','bill_rate'=>98,'pay_rate'=>65,'approved_at'=>'2026-05-18 12:00:00']);
    $rates=$read('placement_rates');
    $edit=static fn(string $op,int $id,array $body):array=>placementSourceRuleEdit($tenant,$pid,'commission',$op,$id,$body,$user);
    $input=['request_id'=>'99200001-0000-4000-8000-000000000001','role'=>'recruiter','user_id'=>$uid,'basis'=>'flat','flat_amount'=>'7500.00','currency'=>'inr','payment_frequency'=>'one_time','due_month'=>'2026-08','effective_from'=>'2026-05-18'];
    $saved=$edit('create',0,$input);$id=$saved['id'];
    $q=$pdo->prepare('SELECT * FROM placement_commissions WHERE tenant_id=? AND id=?');$q->execute([$tenant,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $check($row['currency']==='INR'&&$row['payment_frequency']==='one_time'&&$row['due_month']==='2026-08'&&$row['flat_amount']==='7500.00','Native rule retains INR 7500, one-time frequency and month-only due date');
    $repeat=$edit('create',0,$input);$check($repeat['id']===$id&&!$repeat['changed'],'Exact native retry retains one rule and identity');
    $check($refused(fn()=>$edit('update',$id,['payment_frequency'=>'per_pay_period']),422),'Recurring frequency cannot retain a one-time due month');
    $check($refused(fn()=>$edit('update',$id,['currency'=>null]),422),'Clearing denomination cannot create an unqualified payable');
    $parties=placementEconomicsParties($tenant,$pid);$party=current(array_filter($parties,static fn($p)=>$p['source_ref']==='commission:'.$id));
    $check($party['currency']==='INR'&&$party['due_month']==='2026-08','Graph preserves independent commission terms');
    $rate=['id'=>$rateId,'placement_id'=>$pid,'currency'=>'USD','bill_rate'=>98,'pay_rate'=>65,'effective_from'=>'2026-05-18'];
    $model=placementEconomicsModelForRate($tenant,$pid,$rate,$parties,null,'w2');
    $check($model['fixed_obligations']===0.0&&$model['fixed_obligations_by_currency']===['INR'=>7500.0],'INR commission is shown separately from USD fixed totals');
    $snapshot=placementEconomicsApprovalSnapshot($tenant,$rate);
    $snapParty=current(array_filter($snapshot['economics_snapshot']['contract_parties'],static fn($p)=>$p['source_ref']==='commission:'.$id));
    $check($snapParty['currency']==='INR'&&$snapParty['payment_frequency']==='one_time'&&$snapParty['due_month']==='2026-08','Future approved contract snapshots preserve all terms');
    $check($read('placement_rates')===$rates,'Review does not rewrite any existing approved rate');
    $check(placementEconomicsPayrollCharges($tenant,$pid,1,98,65,'2026-07-31')===[],'One-time commission is not due before its recorded month');
    $charges=placementEconomicsPayrollCharges($tenant,$pid,1,98,65,'2026-08-01');
    $check(count($charges)===1&&$charges[0]['currency']==='INR'&&$charges[0]['calculated_amount']===7500.0,'Due calculation preserves denomination without conversion');
    $claim=static function(array $terms,int $source,int $run,string $date='2026-08-20')use($tenant,$pid):int{
        return placementEconomicsRecordObligation($tenant,$pid,(int)$terms['id'],'time_bundle',$source,[
            'commission_terms'=>$terms,'period_start'=>$date,'period_end'=>$date,'quantity'=>1,'basis_amount'=>0,
            'amount'=>$terms['fee_flat'],'currency'=>$terms['currency'],'status'=>'payroll','payroll_ref_id'=>$run]);
    };
    $check($refused(fn()=>$claim($party,99200001,992001),409),'Native payroll claim refuses foreign currency without writing an obligation');
    $edit('update',$id,['currency'=>'USD','flat_amount'=>'75.00']);
    $parties=placementEconomicsParties($tenant,$pid);$party=current(array_filter($parties,static fn($p)=>$p['source_ref']==='commission:'.$id));
    $check($refused(fn()=>$claim($party,99200001,992001,'2026-07-31'),409),'Final writer independently refuses a claim before the due month');
    $obligation=$claim($party,99200001,992001);
    $check($obligation>0&&$claim($party,99200002,992002)===0,'One-time amount is claimed once across batches and runs');
    $check(placementEconomicsPayrollCharges($tenant,$pid,1,98,65,'2026-09-01')===[],'Later time calculation does not repeat a consumed one-time amount');
    $check($refused(fn()=>$edit('update',$id,['flat_amount'=>'100.00']),409),'Consumed commission amount cannot be retrospectively changed');
    $check($refused(fn()=>$edit('delete',$id,[]),409),'Consumed commission cannot be deleted out of its historical graph');
    $check($refused(fn()=>$edit('update',$id,['payment_frequency'=>'per_pay_period','due_month'=>null]),409),'Consumed one-time commission cannot become recurring');
    $check($refused(fn()=>placementCommissionProtectConsumed($pdo,$tenant,$pid,$id,['user_id'=>$uid+9999]),409),'Consumed recipient changes require a new future rule');
    $edit('update',$id,['notes'=>'Retained historical contract','effective_to'=>'2026-09-01']);
    $check(true,'Historical terms can receive notes and an end date');
    $input['request_id']='99200001-0000-4000-8000-000000000002';$input['currency']='USD';$input['payment_frequency']='per_pay_period';$input['due_month']=null;$input['flat_amount']='20.00';
    $recurring=$edit('create',0,$input)['id'];$parties=placementEconomicsParties($tenant,$pid);$rec=current(array_filter($parties,static fn($p)=>$p['source_ref']==='commission:'.$recurring));
    $check($claim($rec,99200003,992003)>0&&$claim($rec,99200004,992003)===0&&$claim($rec,99200005,992004)>0,'Recurring fixed commission is claimed once per recipient run across multiple rate batches');
    $changed=$rec;$changed['fee_flat']='30.00';$check($refused(fn()=>$claim($changed,99200006,992003),409),'Conflicting same-period amount is refused instead of duplicated');
    $stale=$rec;$stale['user_id']=$uid+9999;$check($refused(fn()=>$claim($stale,99200007,992005),409),'Changed recipient cannot consume a previous approved contract');
    $check($refused(fn()=>placementCommissionClaim($tenant+999999,$pid,(int)$rec['id'],'time_bundle',99200008,['commission_terms'=>$rec]),404),'Foreign tenant cannot claim a placement commission');
    $check(jobdivaSyncUpsertPlacementCommissionRow($tenant,$pid,'recruiter',$uid,null,99,'flat','2026-05-18',null,'recruiter',['currency'=>'USD','payment_frequency'=>'per_pay_period'])===0,'JobDiva refresh respects Core ownership of the complete term set');
    $check([$read('payroll_runs'),$read('accounting_events'),$read('accounting_journal_entries'),$read('time_entries')]===$protected,'No payroll run, accounting event, posting or time entry is changed');
}finally{if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);}
echo "$n commission terms database checks passed. No provider requests or real financial execution.\n";
