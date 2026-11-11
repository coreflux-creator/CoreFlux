<?php
/** Complete native setup for PL-874 only; no payroll runs, invoices or payments. */
declare(strict_types=1);
if(($argv[1]??'')!=='--activate-existing-synthetic-placement')exit(2);
require __DIR__.'/thunderhawk_lifecycle_native.php';
$h=new ThunderhawkLifecycleNative('activation');$error=null;
try{
    $h->expect($h->pdo->query('SELECT status FROM placements WHERE tenant_id=2002 AND id=874')->fetchColumn()==='draft','Existing synthetic placement is draft');
    $h->expect((int)$h->pdo->query('SELECT COUNT(*) FROM payroll_pay_schedules WHERE tenant_id=2002')->fetchColumn()===0,'No existing pilot pay schedule will be changed');
    $h->expect((int)$h->pdo->query("SELECT COUNT(*) FROM people_employees WHERE tenant_id=2002 AND id=5 AND work_email='thunderhawk-lifecycle-20261111@example.invalid'")->fetchColumn()===1,'Synthetic employee identity verified');
    $actor=$h->actor(['people'=>'read','placements'=>'admin','payroll'=>'write']);
    $h->checkpoint('schedule_requested');
    [$status,$schedule]=$h->request('/api/payroll/pay_schedules',['name'=>'Synthetic lifecycle — PurePayroll test','frequency'=>'biweekly','period_start_anchor'=>'2026-11-09','pay_date_offset_days'=>5,'timezone'=>'America/New_York','notes'=>'Simulation metadata for P-368 / PL-874. No real payroll or bank instructions.'],'POST');
    $h->expect($status===201&&(int)($schedule['id']??0)>0,'Synthetic biweekly schedule created');$scheduleId=(int)$schedule['id'];$h->report['schedule_id']=$scheduleId;$h->checkpoint('cycle_requested');
    [$status,$cycle]=$h->request('/api/payroll/cycles',['name'=>'Synthetic P-368 lifecycle cohort','schedule_id'=>$scheduleId,'notes'=>'Only the synthetic lifecycle worker. Do not advance a real payroll run.'],'POST');
    $h->expect($status===201&&(int)($cycle['id']??0)>0,'Synthetic cohort created without advancing a run');$cycleId=(int)$cycle['id'];$h->report['cycle_id']=$cycleId;$h->checkpoint('profile_requested');
    [$status,$profile]=$h->request('/api/payroll/profiles',['employee_id'=>5,'schedule_id'=>$scheduleId,'cycle_id'=>$cycleId,'enabled'=>true,'work_state'=>'NJ','payment_method'=>'direct_deposit','default_hours_per_period'=>null,'notes'=>'Synthetic profile for Core payee resolution. No bank, tax or payment setup; operational test uses held PurePayroll inputs.'],'POST');
    $h->expect($status===201&&(int)($profile['id']??0)>0,'Synthetic payroll metadata created');$h->report['profile_id']=(int)$profile['id'];$h->checkpoint('placement_setup_requested');
    [$status]=$h->request('/api/placements/placements?id=874',['workers_comp_class'=>'SYNTHETIC TEST ONLY','client_bill_cycle'=>'weekly','client_payment_terms_override'=>'Net 30','payroll_cycle_id'=>$cycleId,'recruiter_name'=>'Synthetic lifecycle recruiter','account_manager_name'=>'Synthetic lifecycle manager'],'PATCH');
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
    $q=$h->pdo->query('SELECT created_by_user_id,approved_by_user_id,approved_at FROM placement_rates WHERE tenant_id=2002 AND placement_id=874 AND id=3');$row=$q->fetch(PDO::FETCH_ASSOC);
    $h->expect((int)$row['created_by_user_id']===64&&(int)$row['approved_by_user_id']===$actor&&$row['approved_at']!==null,'Rate approval retains distinct creator and reviewer');
    $h->report['placement_status']='active';$h->report['rate_id']=3;
}catch(Throwable $e){$error=$e;}
$h->finish($error,['payroll_pay_schedules','payroll_pay_cycles','payroll_pay_periods','payroll_profiles']);
