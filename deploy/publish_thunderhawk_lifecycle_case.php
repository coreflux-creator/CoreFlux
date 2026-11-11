<?php
/** Reviewed person and assignment metadata only, in the existing isolated pairings. */
declare(strict_types=1);
if(!in_array(($argv[1]??''),['--publish-existing-synthetic-case','--resume-rejected-synthetic-contact'],true))exit(2);
$resume=$argv[1]==='--resume-rejected-synthetic-contact';
require __DIR__.'/thunderhawk_lifecycle_native.php';
$h=new ThunderhawkLifecycleNative($resume?'publication_resume':'publication');$error=null;
function lifecycleSend(ThunderhawkLifecycleNative $h,string $label,string $path,array $preview,array $confirmation):array
{
    $d=$preview['delivery']??null;
    $h->expect(is_array($d)&&($d['state']??null)==='preview'&&(int)$d['entity_tenant_id']===2002&&in_array((int)$d['entity_id'],[368,874],true),$label.' has a fresh scoped review');
    $body=['delivery_id'=>(int)$d['id'],'review_hash'=>$d['review_hash']]+$confirmation;
    $h->report['reviews'][$label]=$preview;$h->checkpoint($label.'_reviewed');
    $wrong=$body;$wrong['review_hash']=str_repeat('0',64);
    [$status]=$h->request($path,$wrong,'POST');
    $h->expect(in_array($status,[400,409,422],true),$label.' rejects a changed review');
    $h->checkpoint($label.'_sending');
    [$status,$sent]=$h->request($path,$body,'POST');
    $h->expect($status===200&&($sent['state']??null)==='delivered'&&(int)$sent['id']===(int)$d['id'],$label.' confirmed by provider receipt');
    $h->report['deliveries'][$label]=$sent;$h->checkpoint($label.'_delivered');
    [$status,$replay]=$h->request($path,$body,'POST');
    $h->expect($status===200&&$replay===$sent,$label.' replay returns the original receipt');
    return $sent;
}
try{
    $activation=json_decode(file_get_contents(ThunderhawkLifecycleNative::PRIVATE.'/synthetic-lifecycle-activation_resume-20261111.json'),true,64,JSON_THROW_ON_ERROR);
    $h->expect($activation['passed']===true&&$activation['placement_status']==='active','Completed native activation verified');
    $h->expect($h->pdo->query('SELECT status FROM placements WHERE tenant_id=2002 AND id=874')->fetchColumn()==='active','Synthetic placement is active');
    $h->expect((int)$h->pdo->query('SELECT COUNT(*) FROM integration_deliveries WHERE tenant_id=2002 AND ((entity_type="person" AND entity_id=368) OR (entity_type="placement" AND entity_id=874))')->fetchColumn()===($resume?1:0),'Only the known synthetic publication history exists');
    if($resume){
        $d=$h->pdo->query('SELECT state,remote_id,remote_receipt_id FROM integration_deliveries WHERE tenant_id=2002 AND id=83 AND entity_type="person" AND entity_id=368')->fetch(PDO::FETCH_ASSOC);
        $h->expect($d===['state'=>'failed','remote_id'=>null,'remote_receipt_id'=>null],'Rejected request is terminal with no remote identity');
        $h->expect((int)$h->pdo->query('SELECT COUNT(*) FROM quanta_worker_links WHERE tenant_id=2002 AND person_id=368')->fetchColumn()===0,'No earlier worker link exists');
    }
    $h->actor(['people'=>$resume?'write':'read','placements'=>'admin','integrations'=>'admin','accounting'=>'admin','payroll'=>'read','ap'=>'read']);
    if($resume){
        $h->checkpoint('synthetic_contact_correction');
        [$status,$person]=$h->request('/api/people/people?id=368',['email_primary'=>'thunderhawk-lifecycle-20261111@example.com'],'PATCH');
        $h->expect($status===200&&($person['person']['email_primary']??null)==='thunderhawk-lifecycle-20261111@example.com','Synthetic person contact uses an accepted example address');
        [$status,$employee]=$h->request('/api/people/employees?id=5',['work_email'=>'thunderhawk-lifecycle-20261111@example.com'],'PATCH');
        $h->expect($status===200&&($employee['employee']['work_email']??null)==='thunderhawk-lifecycle-20261111@example.com','Existing employee contact stays aligned');
        $h->report['rejected_delivery_preserved']=83;$h->report['synthetic_contact']='thunderhawk-lifecycle-20261111@example.com';
    }
    [$status,$preview]=$h->request('/api/quanta.php?action=publication_preview',['person_ids'=>[368]],'POST');
    $h->expect($status===200&&($preview['delivery_enabled']??false)===true&&($preview['workspace']['id']??null)==='4f93d4e7-af74-4765-b812-3464bb6d974b'&&count($preview['rows']??[])===1,'Quanta person review uses the isolated workspace');
    $h->expect(($preview['rows'][0]['person_number']??null)==='P-368'&&($preview['rows'][0]['status']??null)==='preview','Quanta person keeps its permanent number');
    lifecycleSend($h,'quanta_person','/api/quanta.php?action=publication_send',$preview['rows'][0],['confirm_profile'=>true]);
    [$status,$preview]=$h->request('/api/quanta.php?action=metadata_preview',['entity_type'=>'placement','entity_id'=>874,'worksite_id'=>'1bc9011d-12df-458e-b79d-c31f09b9ee09'],'POST');
    $h->expect($status===200&&($preview['delivery_enabled']??false)===true&&($preview['reference']['number']??null)==='PL-874'&&($preview['workspace']['id']??null)==='4f93d4e7-af74-4765-b812-3464bb6d974b','Quanta assignment review preserves placement and workspace');
    lifecycleSend($h,'quanta_assignment','/api/quanta.php?action=metadata_send',$preview,['confirm_metadata'=>true]);
    [$status,$preview]=$h->request('/api/purepay_core.php?action=people_input_preview',['person_number'=>'P-368','placement_number'=>'PL-874'],'POST');
    $h->expect($status===200&&($preview['delivery_enabled']??false)===true&&($preview['organization']['id']??null)==='org_0369a63ff2cb'&&($preview['packet']['person']['number']??null)==='P-368','PurePayroll person review preserves person and isolated company');
    lifecycleSend($h,'pure_person','/api/purepay_core.php?action=people_input_send',$preview,['confirmed'=>true]);
    [$status,$preview]=$h->request('/api/purepay_core.php?action=assignment_input_preview',['placement_number'=>'PL-874'],'POST');
    $h->expect($status===200&&($preview['delivery_enabled']??false)===true&&($preview['organization']['id']??null)==='org_0369a63ff2cb'&&($preview['packet']['placement']['number']??null)==='PL-874','PurePayroll assignment review preserves placement and isolated company');
    lifecycleSend($h,'pure_assignment','/api/purepay_core.php?action=assignment_input_send',$preview,['confirmed'=>true]);
    foreach(['pure_person'=>'people_input_status','pure_assignment'=>'assignment_input_status'] as $label=>$action){
        $d=$h->report['deliveries'][$label];[$status,$read]=$h->request('/api/purepay_core.php?action='.$action,['delivery_id'=>(int)$d['id'],'review_hash'=>$d['review_hash']],'POST');
        $h->expect($status===200&&($read['delivery']['state']??null)==='delivered',$label.' incoming native review is discoverable');
        $h->report['provider_reviews'][$label]=$read['provider_review'];
    }
    $h->report['native_provider_acceptance_complete']=false;
}catch(Throwable $e){$error=$e;}
$h->finish($error,['integration_deliveries','integration_delivery_subjects','quanta_worker_links','people_change_log']);
