<?php
/** Continue the saved synthetic draft through the native employment workflow. */
declare(strict_types=1);
if(($argv[1]??'')!=='--execute-isolated-correction-phase')exit(2);
require __DIR__.'/thunderhawk_correction_native.php';
$h=new ThunderhawkCorrectionNative('employee_setup');$error=null;
try{
    $prior=json_decode(file_get_contents(ThunderhawkCorrectionNative::PRIVATE.'/synthetic-lifecycle-20261112-correction.json'),true,64,JSON_THROW_ON_ERROR);
    $h->expect(!$prior['passed']&&$prior['person_id']===387&&$prior['placement_id']===902&&$prior['rate_id']===23
        &&$prior['operator_retired']&&$prior['private_session_removed'],'Original draft outcome is known and its operator retired');
    $h->expect($h->pdo->query('SELECT status FROM placements WHERE tenant_id=2002 AND id=902')->fetchColumn()==='draft','Original draft is retained');
    $email='thunderhawk-correction-20261112@example.com';
    $q=$h->pdo->prepare('SELECT COUNT(*) FROM people_employees WHERE tenant_id=2002 AND work_email=?');$q->execute([$email]);
    $h->expect((int)$q->fetchColumn()===0,'No employment record exists for the new synthetic contact');
    $same=$h->pdo->query("SELECT id,work_email FROM people_employees WHERE tenant_id=2002 AND legal_first_name='Synthetic' AND legal_last_name='Lifecycle'")->fetchAll(PDO::FETCH_ASSOC);
    $h->expect(count($same)===1&&(int)$same[0]['id']===5&&$same[0]['work_email']==='thunderhawk-lifecycle-20261111@example.com','Legacy name-only backfill collision is identified, not treated as the new employee');
    $h->actor(['people'=>'write']);$h->checkpoint('employee_create_requested');
    [$status,$response]=$h->request('/api/people/employees',[
        'legal_first_name'=>'Synthetic','legal_last_name'=>'Lifecycle','work_email'=>$email,
        'status'=>'active','employment_type'=>'full_time','flsa_class'=>'non_exempt',
    ],'POST');
    $employee=$response['employee']??[];
    $h->expect($status===201&&(int)($employee['id']??0)>5&&($employee['work_email']??null)===$email,'Separate synthetic employment record created through the native API');
    $h->report['employee_id']=(int)$employee['id'];
    $h->report['automatic_backfill_name_collision_open']=true;
    $h->report['original_draft_reused']=true;
    $h->report['financial_or_private_employee_details_added']=false;
}catch(Throwable $e){$error=$e;}
$h->finish($error,['people_employees','people_change_log']);
