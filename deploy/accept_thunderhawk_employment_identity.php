<?php
/** Confirm reviewed pilot identity pairs using native login, RBAC, API and exact replay. */
declare(strict_types=1);
if(($argv[1]??'')!=='--review-existing-isolated-employment')exit(2);
require __DIR__.'/thunderhawk_employment_native.php';
require_once ThunderhawkEmploymentNative::ROOT.'/modules/people/lib/employment_links.php';
require_once ThunderhawkEmploymentNative::ROOT.'/modules/placements/lib/economics.php';
$h=new ThunderhawkEmploymentNative('employment_link');$error=null;
try{
    $h->expect((int)$h->pdo->query('SELECT COUNT(*) FROM people_employment_links')->fetchColumn()===0,'No existing link will be replaced');
    $evidence=json_decode(file_get_contents(__DIR__.'/employment-reviewed-pairs.json'),true,64,JSON_THROW_ON_ERROR);
    $h->expect($evidence['tenant_id']===2002&&$evidence['pairs']===[[358,4],[368,5],[387,6]],'Reviewed existing pilot pairs are explicit');
    $saved=json_decode(file_get_contents(ThunderhawkEmploymentNative::PRIVATE.'/synthetic-lifecycle-employee_setup-20261112-correction.json'),true,64,JSON_THROW_ON_ERROR);
    $h->expect($saved['passed']&&$saved['employee_id']===6&&$saved['person_id']===387,'Correction case proves separate employment creation for P-387');
    $actor=$h->actor(['people'=>'write']);$h->report['links']=[];
    foreach($evidence['pairs']as[$person,$employee]){
        $h->checkpoint('reviewing_person_'.$person);
        [$status,$context]=$h->request('/modules/people/api/employment_link.php?person_id='.$person);
        $h->expect($status===200&&$context['available']&&$context['can_manage']&&$context['link']===null,'Native directory permits explicit review for P-'.$person);
        $choice=array_values(array_filter($context['choices'],static fn($e)=>(int)$e['id']===$employee));
        $h->expect(count($choice)===1,'Reviewed employment is available in this directory');
        $q=$h->pdo->prepare('SELECT SHA2(LOWER(TRIM(email_primary)),256) FROM people WHERE tenant_id=2002 AND id=?');$q->execute([$person]);
        $h->expect($q->fetchColumn()===$evidence['contact_hashes'][(string)$person],'Reviewed person contact evidence is unchanged');
        $q=$h->pdo->prepare('SELECT SHA2(LOWER(TRIM(work_email)),256) FROM people_employees WHERE tenant_id=2002 AND id=?');$q->execute([$employee]);
        $h->expect($q->fetchColumn()===$evidence['contact_hashes'][(string)$person],'Reviewed employment contact evidence is unchanged');
        [$status,$review]=$h->request('/modules/people/api/employment_link.php?action=preview',[
            'person_id'=>$person,'employee_id'=>$employee,'reason'=>$evidence['reasons'][(string)$person],
        ],'POST');
        $h->expect($status===200&&$review['person']['person_number']==='P-'.$person&&(int)$review['employee']['id']===$employee&&!$review['creates_employee'],'Review retains original person and employment numbers');
        $body=['review_token'=>$review['review_token'],'confirm_link'=>true];
        $old=umask(0077);try{file_put_contents(ThunderhawkEmploymentNative::PRIVATE.'/employment-person-'.$person.'-request.private.json',json_encode($body,JSON_THROW_ON_ERROR));}finally{umask($old);}
        $h->checkpoint('applying_person_'.$person);
        [$status,$result]=$h->request('/modules/people/api/employment_link.php?action=apply',$body,'POST');
        $h->expect($status===200&&!$result['replayed']&&(int)$result['link']['person_id']===$person&&(int)$result['link']['employee_id']===$employee,'Reviewed permanent link saved');
        [$status,$replay]=$h->request('/modules/people/api/employment_link.php?action=apply',$body,'POST');
        $h->expect($status===200&&$replay['replayed']&&(int)$replay['link']['id']===(int)$result['link']['id'],'Exact retry recovers the original receipt');
        $h->report['links'][]=['person_id'=>$person,'employee_id'=>$employee,'link_id'=>(int)$result['link']['id'],'replay_verified'=>true];
        $h->checkpoint('person_'.$person.'_linked');
    }
    $h->expect((int)$h->pdo->query('SELECT COUNT(*) FROM people_employment_links WHERE tenant_id=2002')->fetchColumn()===3,'Exactly three reviewed pilot links exist');
    foreach([[368,5],[387,6]]as[$person,$employee]){
        $resolved=placementEconomicsPayrollEmployee(2002,['person_id'=>$person]);
        $h->expect((int)($resolved['employee_id']??0)===$employee,'Same-name synthetic person resolves its own existing payroll profile');
    }
    $h->report['provider_calls']=0;$h->report['employment_records_created']=0;
}catch(Throwable $e){$error=$e;}
$h->finish($error,['people_employment_links']);
