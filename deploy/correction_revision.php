<?php
/** Review the fresh approved source correction via the installed Core APIs. */
declare(strict_types=1);
if(($argv[1]??'')!=='--execute-isolated-correction-phase')exit(2);
require __DIR__.'/thunderhawk_correction_native.php';
require_once ThunderhawkCorrectionNative::ROOT.'/modules/time/lib/time.php';
$h=new ThunderhawkCorrectionNative('revision');$error=null;
$source='3a18a0f9-6761-4a6e-be8f-0a5bcaa552c2';
try{
    $original=timeEntryGet(15);
    $h->expect($original['status']==='approved'&&(int)$original['person_id']===387&&(int)$original['placement_id']===902
        &&(float)$original['hours']===1.0&&(int)$original['approved_by_user_id']===86,'Original independent one-hour approval is intact');
    $q=$h->pdo->prepare('SELECT * FROM quanta_time_imports WHERE tenant_id=2002 AND quanta_entry_id=?');$q->execute([$source]);$oldImport=$q->fetch(PDO::FETCH_ASSOC);
    $h->expect((int)$oldImport['time_entry_id']===15&&(int)$oldImport['is_current']===1,'Original current source component identified');
    $h->expect((int)$h->pdo->query('SELECT COUNT(*) FROM quanta_time_revisions WHERE tenant_id=2002')->fetchColumn()===0,'No prior pilot correction exists');
    $actor=$h->actor(['people'=>'read','placements'=>'read','time'=>'admin','integrations'=>'admin','accounting'=>'admin']);
    [$status]=$h->request('/api/time/entries?action=correct&id=15',['reason'=>'Synthetic manual bypass must fail','hours'=>1.5],'POST');
    $h->expect($status===409&&timeEntryGet(15)===$original,'Manual correction cannot remove Quanta provenance');
    [$status]=$h->request('/api/quanta.php?action=time_revision_preview',['source_entry_id'=>$source],'POST',2);
    $h->expect($status===403,'Actual foreign-tenant correction request is refused');
    [$status,$preview]=$h->request('/api/quanta.php?action=time_revision_preview',['source_entry_id'=>$source],'POST');
    $h->expect($status===200&&$preview['person_number']==='P-387'&&$preview['placement_number']==='PL-902'
        &&count($preview['before'])===1&&(int)$preview['before'][0]['id']===15
        &&(float)$preview['before'][0]['hours']===1.0&&(float)array_sum($preview['after']['components'])===1.5
        &&$preview['after']['status']==='pending_review','Native review shows original one hour and replacement 1.5 hours');
    $h->report['review']=$preview;$h->checkpoint('correction_reviewed');
    $body=['source_entry_id'=>$source,'review_hash'=>$preview['review_hash'],'reason'=>'Synthetic acceptance: approved source corrected from 60 to 90 minutes; retain original approval.','confirm_revision'=>true];
    $wrong=$body;$wrong['review_hash']=str_repeat('0',64);
    [$status]=$h->request('/api/quanta.php?action=time_revision_apply',$wrong,'POST');
    $h->expect(in_array($status,[409,422],true)&&timeEntryGet(15)===$original,'Changed review cannot alter the approved entry');
    $unconfirmed=$body;unset($unconfirmed['confirm_revision']);
    [$status]=$h->request('/api/quanta.php?action=time_revision_apply',$unconfirmed,'POST');
    $h->expect($status===422,'Correction requires explicit reviewed confirmation');
    $h->checkpoint('correction_apply_requested');
    [$status,$receipt]=$h->request('/api/quanta.php?action=time_revision_apply',$body,'POST');
    $h->expect($status===200&&$receipt['result']['status']==='pending_review'&&count($receipt['result']['new_entry_ids'])===1,'Correction committed once, pending independent approval');
    $h->report['receipt']=$receipt;$h->checkpoint('correction_applied');
    $newId=(int)array_values($receipt['result']['new_entry_ids'])[0];$new=timeEntryGet($newId);$old=timeEntryGet(15);
    $h->expect($old['status']==='superseded'&&(int)$old['superseded_by_id']===$newId,'Original points to its replacement');
    foreach(['status','superseded_by_id','updated_at'] as $field){unset($old[$field],$original[$field]);}
    $h->expect($old===$original,'Original hours, rate, source and independent approval remain unchanged');
    $h->expect($new['status']==='pending_review'&&(float)$new['hours']===1.5&&(int)$new['person_id']===387
        &&(int)$new['placement_id']===902&&(int)$new['timesheet_id']===8&&$new['source']==='quanta'
        &&$new['source_system']==='quanta'&&(int)$new['created_by_user_id']===$actor&&$new['approved_at']===null,'Replacement retains native time links and requires fresh approval');
    [$status,$replay]=$h->request('/api/quanta.php?action=time_revision_apply',$body,'POST');
    $h->expect($status===200&&$replay===$receipt,'Exact correction retry returns the original receipt');
    $wrong=$body;$wrong['reason'].=' changed';
    [$status]=$h->request('/api/quanta.php?action=time_revision_apply',$wrong,'POST');
    $h->expect(in_array($status,[409,422],true),'Changed retry is refused');
    [$status,$history]=$h->request('/api/quanta.php?action=time_revision_history',['source_entry_id'=>$source],'POST');
    $h->expect($status===200&&count($history['items'])===1&&$history['items'][0]===$receipt,'Saved history retains exactly the original correction');
    $denied=false;try{timeApproveEntry($newId,['id'=>$actor]);}catch(RuntimeException $e){$denied=$e->getCode()===403;}
    $h->expect($denied&&timeEntryGet($newId)===$new,'Corrector cannot approve the replacement');
    $q=$h->pdo->prepare('SELECT * FROM quanta_time_revisions WHERE tenant_id=2002 AND id=?');$q->execute([$receipt['id']]);$saved=$q->fetch(PDO::FETCH_ASSOC);
    $before=json_decode(decryptField($saved['before_ct']),true,64,JSON_THROW_ON_ERROR);
    $savedImports=array_values($before['prior']['imports']);
    $h->expect(count($savedImports)===1&&integrationDeliveryHash($savedImports[0])===integrationDeliveryHash($oldImport),'Encrypted history retains the complete original import evidence');
    $q=$h->pdo->prepare('SELECT COUNT(*) FROM quanta_time_approval_evidence WHERE tenant_id=2002 AND quanta_time_import_id=?');$q->execute([$oldImport['id']]);
    $h->expect((int)$q->fetchColumn()===2,'Both original and corrected source approvals are retained');
    [$status,$review]=$h->request('/api/quanta.php?action=time_review_preview',['source_entry_id'=>$source],'POST');
    $h->expect($status===200&&$review['state']==='pending_review','Corrected source has a pending Core review return');
    $send=['delivery_id'=>(int)$review['delivery']['id'],'review_hash'=>$review['delivery']['review_hash'],'confirm_status'=>true];
    $h->checkpoint('pending_status_return_requested');
    [$status,$sent]=$h->request('/api/quanta.php?action=time_review_send',$send,'POST');
    $h->expect($status===200&&$sent['state']==='delivered','Quanta received the replacement pending-review status');
    $h->report['pending_return_delivery']=$sent;
    $h->report['new_entry_id']=$newId;$h->report['original_entry_id']=15;$h->report['timesheet_id']=8;
    $h->report['original_approval_preserved']=true;$h->report['replay_verified']=true;$h->report['self_approval_refused']=true;
}catch(Throwable $e){$error=$e;}
$h->finish($error,['time_entries','quanta_time_approval_evidence','quanta_time_revisions','artifact_events','integration_deliveries','integration_delivery_subjects']);
