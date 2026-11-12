<?php
/** Return the original hour's independent Core review; do not change source hours. */
declare(strict_types=1);
if(($argv[1]??'')!=='--execute-isolated-correction-phase')exit(2);
require __DIR__.'/thunderhawk_correction_native.php';
$h=new ThunderhawkCorrectionNative('revision_return');$error=null;
$source='3a18a0f9-6761-4a6e-be8f-0a5bcaa552c2';
try{
    $prior=json_decode(file_get_contents(ThunderhawkCorrectionNative::PRIVATE.'/synthetic-lifecycle-reapproval-20261112-correction.json'),true,64,JSON_THROW_ON_ERROR);
    $h->expect($prior['passed']&&$prior['operator_retired'],'Independent correction approval is verified');
    $row=$h->pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002 AND id=16')->fetch(PDO::FETCH_ASSOC);
    $h->expect($row['status']==='approved'&&(int)$row['person_id']===387&&(int)$row['placement_id']===902
        &&(int)$row['approved_by_user_id']===(int)$prior['operator_id']&&(float)$row['hours']===1.5,'Original independent Core approval is unchanged');
    $h->actor(['people'=>'read','placements'=>'read','time'=>'read','integrations'=>'admin','accounting'=>'admin']);
    [$status,$review]=$h->request('/api/quanta.php?action=time_review_preview',['source_entry_id'=>$source],'POST');
    $h->expect($status===200&&($review['state']??null)==='accepted'&&isset($review['delivery']),'Core offers accepted status for the original source hour');
    $h->report['review']=$review;$h->checkpoint('acceptance_sending');
    $send=['delivery_id'=>(int)$review['delivery']['id'],'review_hash'=>$review['delivery']['review_hash'],'confirm_status'=>true];
    [$status,$sent]=$h->request('/api/quanta.php?action=time_review_send',$send,'POST');
    $h->expect($status===200&&($sent['state']??null)==='delivered','Quanta accepted the Core approval return');
    $h->report['delivery']=$sent;$h->checkpoint('acceptance_delivered');
    [$status,$again]=$h->request('/api/quanta.php?action=time_review_send',$send,'POST');
    $h->expect($status===200&&$again===$sent,'Acceptance replay retains the original receipt');
    $h->report['source_entry_id']=$source;$h->report['core_time_status']='approved';$h->report['quanta_core_review']='accepted';
}catch(Throwable $e){$error=$e;}
$h->finish($error,['integration_deliveries','integration_delivery_subjects']);
