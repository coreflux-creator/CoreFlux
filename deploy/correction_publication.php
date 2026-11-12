<?php
/** Reviewed person and assignment metadata only, in the existing isolated pairings. */
declare(strict_types=1);
if(($argv[1]??'')!=='--execute-isolated-correction-phase')exit(2);
require __DIR__.'/thunderhawk_correction_native.php';
$h=new ThunderhawkCorrectionNative('publication');$error=null;
function lifecycleSend(ThunderhawkCorrectionNative $h,string $label,string $path,array $preview,array $confirmation):array
{
    $d=$preview['delivery']??null;
    $h->expect(is_array($d)&&($d['state']??null)==='preview'&&(int)$d['entity_tenant_id']===2002&&in_array((int)$d['entity_id'],[387,902],true),$label.' has a fresh scoped review');
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
    $activation=json_decode(file_get_contents(ThunderhawkCorrectionNative::PRIVATE.'/synthetic-lifecycle-activation-20261112-correction.json'),true,64,JSON_THROW_ON_ERROR);
    $h->expect($activation['passed']===true&&$activation['placement_status']==='active','Completed native activation verified');
    $h->expect($h->pdo->query('SELECT status FROM placements WHERE tenant_id=2002 AND id=902')->fetchColumn()==='active','Synthetic placement is active');
    $h->expect((int)$h->pdo->query('SELECT COUNT(*) FROM integration_deliveries WHERE tenant_id=2002 AND ((entity_type="person" AND entity_id=387) OR (entity_type="placement" AND entity_id=902))')->fetchColumn()===0,'Only the known synthetic publication history exists');

    $h->actor(['people'=>'read','placements'=>'admin','integrations'=>'admin','accounting'=>'admin','payroll'=>'read','ap'=>'read']);

    [$status,$preview]=$h->request('/api/quanta.php?action=publication_preview',['person_ids'=>[387]],'POST');
    $h->expect($status===200&&($preview['delivery_enabled']??false)===true&&($preview['workspace']['id']??null)==='4f93d4e7-af74-4765-b812-3464bb6d974b'&&count($preview['rows']??[])===1,'Quanta person review uses the isolated workspace');
    $h->expect(($preview['rows'][0]['person_number']??null)==='P-387'&&($preview['rows'][0]['status']??null)==='preview','Quanta person keeps its permanent number');
    lifecycleSend($h,'quanta_person','/api/quanta.php?action=publication_send',$preview['rows'][0],['confirm_profile'=>true]);
    [$status,$preview]=$h->request('/api/quanta.php?action=metadata_preview',['entity_type'=>'placement','entity_id'=>902,'worksite_id'=>'1bc9011d-12df-458e-b79d-c31f09b9ee09'],'POST');
    $h->expect($status===200&&($preview['delivery_enabled']??false)===true&&($preview['reference']['number']??null)==='PL-902'&&($preview['workspace']['id']??null)==='4f93d4e7-af74-4765-b812-3464bb6d974b','Quanta assignment review preserves placement and workspace');
    lifecycleSend($h,'quanta_assignment','/api/quanta.php?action=metadata_send',$preview,['confirm_metadata'=>true]);
    $h->report['quanta_publication_complete']=true;
    $h->report['purepay_not_exercised_in_this_correction_case']=true;
}catch(Throwable $e){$error=$e;}
$h->finish($error,['integration_deliveries','integration_delivery_subjects','quanta_worker_links','people_change_log']);
