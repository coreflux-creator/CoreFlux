<?php
/** Continue from the saved cohort after repairing its response; never recreate it. */
declare(strict_types=1);
if(($argv[1]??'')!=='--resume-saved-synthetic-cohort')exit(2);
require __DIR__.'/thunderhawk_lifecycle_native.php';
$h=new ThunderhawkLifecycleNative('activation_resume');$error=null;
try{
    $prior=json_decode(file_get_contents(ThunderhawkLifecycleNative::PRIVATE.'/synthetic-lifecycle-activation-20261111.json'),true,64,JSON_THROW_ON_ERROR);
    $h->expect($prior['passed']===false&&$prior['step']==='cycle_requested'&&$prior['operator_retired']===true&&$prior['private_session_removed']===true,'Previous failed request is resolved and its operator retired');
    $h->expect($h->pdo->query('SELECT status FROM placements WHERE tenant_id=2002 AND id=874')->fetchColumn()==='draft','Existing synthetic placement is still draft');
    $s=$h->pdo->query('SELECT * FROM payroll_pay_schedules WHERE tenant_id=2002')->fetchAll(PDO::FETCH_ASSOC);
    $c=$h->pdo->query('SELECT * FROM payroll_pay_cycles WHERE tenant_id=2002')->fetchAll(PDO::FETCH_ASSOC);
    $h->expect(count($s)===1&&(int)$s[0]['id']===3&&$s[0]['name']==='Synthetic lifecycle — PurePayroll test'&&$s[0]['period_start_anchor']==='2026-11-09','Saved synthetic schedule reused');
    $h->expect(count($c)===1&&(int)$c[0]['id']===1&&(int)$c[0]['schedule_id']===3&&$c[0]['name']==='Synthetic P-368 lifecycle cohort'&&(int)$c[0]['active']===1,'Saved synthetic cohort reused');
    $h->expect((int)$h->pdo->query('SELECT COUNT(*) FROM payroll_profiles WHERE tenant_id=2002')->fetchColumn()===0,'No prior profile request committed');
    $h->expect((int)$h->pdo->query("SELECT COUNT(*) FROM people_employees WHERE tenant_id=2002 AND id=5 AND work_email='thunderhawk-lifecycle-20261111@example.invalid'")->fetchColumn()===1,'Synthetic employee identity verified');
    $h->report['schedule_id']=3;$h->report['cycle_id']=1;
    $actor=$h->actor(['people'=>'read','placements'=>'admin','payroll'=>'write']);
    $h->checkpoint('cohort_response_verification');
    // Empty edit exercises the repaired native endpoint without changing the saved cohort.
    [$status,$receipt]=$h->request('/api/payroll/cycles?id=1',[],'PATCH');
    $h->expect($status===200&&($receipt['ok']??false)===true,'Repaired cohort endpoint responds successfully');
    $audits=$h->pdo->query('SELECT * FROM audit_log WHERE tenant_id=2002 ORDER BY id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
    $matched=array_values(array_filter($audits,static fn($r)=>($r['event']??$r['action']??'')==='payroll.cycle.updated'&&(int)($r['actor_user_id']??$r['user_id']??0)===$actor));
    $meta=count($matched)===1?json_decode($matched[0]['meta_json'],true):null;
    $h->expect($meta!==null&&$meta['before']===$c[0]&&$meta['after']===$c[0],'Native cohort receipt retains exact before and after snapshots');
    $h->checkpoint('profile_requested');
    [$status,$profile]=$h->request('/api/payroll/profiles',['employee_id'=>5,'schedule_id'=>3,'cycle_id'=>1,'enabled'=>true,'work_state'=>'NJ','payment_method'=>'direct_deposit','default_hours_per_period'=>null,'notes'=>'Synthetic profile for Core payee resolution. No bank, tax or payment setup; operational test uses held PurePayroll inputs.'],'POST');
    $h->expect($status===201&&(int)($profile['id']??0)>0,'Synthetic payroll metadata created');$h->report['profile_id']=(int)$profile['id'];$h->checkpoint('placement_setup_requested');
    [$status]=$h->request('/api/placements/placements?id=874',['workers_comp_class'=>'SYNTHETIC TEST ONLY','client_bill_cycle'=>'weekly','client_payment_terms_override'=>'Net 30','payroll_cycle_id'=>1,'recruiter_name'=>'Synthetic lifecycle recruiter','account_manager_name'=>'Synthetic lifecycle manager'],'PATCH');
    $h->expect($status===200,'Synthetic setup completed through native placement edit');$h->checkpoint('rate_review_requested');
    [$status]=$h->request('/api/placements/rates?action=approve&id=3',[],'POST');
    $h->expect($status===200,'Test rate approved by a different operator from its creator');$h->checkpoint('activation_requested');
    [$status,$activated]=$h->request('/api/placements/placements?action=activate&id=874',[],'POST');
    $h->expect($status===200&&($activated['placement']['status']??null)==='active','Synthetic placement activated');
    [$status,$detail]=$h->request('/api/placements/placements?id=874');
    $h->expect($status===200&&($detail['dimension_readiness']['time_blockers']??null)===[],'Synthetic time entry has no setup blockers');
    $h->report['dimension_readiness']=$detail['dimension_readiness'];
    [$status,$employee]=$h->request('/api/payroll/profiles?employee_id=5');
    $h->expect($status===200&&($employee['ready']??null)===false,'Synthetic employee remains incomplete for payroll execution');
    $h->report['payroll_readiness_gaps']=$employee['gaps'];
    $row=$h->pdo->query('SELECT created_by_user_id,approved_by_user_id,approved_at FROM placement_rates WHERE tenant_id=2002 AND placement_id=874 AND id=3')->fetch(PDO::FETCH_ASSOC);
    $h->expect((int)$row['created_by_user_id']===64&&(int)$row['approved_by_user_id']===$actor&&$row['approved_at']!==null,'Rate approval retains distinct creator and reviewer');
    $h->report['placement_status']='active';$h->report['rate_id']=3;
}catch(Throwable $e){$error=$e;}
$h->finish($error,['payroll_profiles']);
