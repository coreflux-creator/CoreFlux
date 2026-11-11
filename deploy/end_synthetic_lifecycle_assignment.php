<?php
/** End only the existing synthetic placement and publish reviewed terminal metadata. */
declare(strict_types=1);
if(!in_array($argv[1]??'', ['--end-original-synthetic-placement','--resume-after-original-review-correction'],true))exit(2);
require __DIR__.'/thunderhawk_lifecycle_native.php';
require_once ThunderhawkLifecycleNative::ROOT.'/core/integrations/purepay_assignment_inputs.php';
$resume=$argv[1]==='--resume-after-original-review-correction';
if($resume){
    $prior=json_decode(file_get_contents(ThunderhawkLifecycleNative::PRIVATE.'/synthetic-lifecycle-offboarding-20261111.json'),true,64,JSON_THROW_ON_ERROR);
    if($prior['passed']!==false||$prior['step']!=='prepared'||$prior['requests']!==[]||isset($prior['operator_id'])||$prior['added_record_tables']!==[]||$prior['tables_verified']!==368)exit(2);
}
$h=new ThunderhawkLifecycleNative($resume?'offboarding_resume':'offboarding');$error=null;
function offboardSend(ThunderhawkLifecycleNative $h,string $label,string $path,array $preview,array $confirmation):array {
    $d=$preview['delivery']??null;
    $h->expect(is_array($d)&&$d['state']==='preview'&&(int)$d['entity_tenant_id']===2002&&(int)$d['entity_id']===874,'Scoped '.$label.' terminal review');
    $body=['delivery_id'=>(int)$d['id'],'review_hash'=>$d['review_hash']]+$confirmation;
    $h->report['reviews'][$label]=$preview;$h->checkpoint($label.'_reviewed');
    $wrong=$body;$wrong['review_hash']=str_repeat('0',64);
    [$status]=$h->request($path,$wrong,'POST');$h->expect(in_array($status,[400,409,422],true),$label.' stale review refused');
    $h->checkpoint($label.'_sending');[$status,$sent]=$h->request($path,$body,'POST');
    $h->expect($status===200&&($sent['state']??null)==='delivered'&&(int)$sent['id']===(int)$d['id'],$label.' terminal metadata received');
    $h->report['deliveries'][$label]=$sent;$h->checkpoint($label.'_delivered');
    [$status,$again]=$h->request($path,$body,'POST');$h->expect($status===200&&$again===$sent,$label.' replay reuses original delivery');
    return $sent;
}
try {
    $install=json_decode(file_get_contents(ThunderhawkLifecycleNative::PRIVATE.'/placement-end-install-result.json'),true,64,JSON_THROW_ON_ERROR);
    $cancel=json_decode(file_get_contents(ThunderhawkLifecycleNative::PRIVATE.'/synthetic-lifecycle-payroll_cancel-20261111.json'),true,64,JSON_THROW_ON_ERROR);
    $h->expect($install['passed']&&$cancel['passed']&&$cancel['permanent_cancellation_verified'],'Installed closure and original cancellation verified');
    $pdo=$h->pdo;
    $before=$pdo->query('SELECT * FROM placements WHERE tenant_id=2002 AND id=874')->fetch(PDO::FETCH_ASSOC);
    $h->expect($before['status']==='active'&&$before['actual_end_date']===null&&$before['start_date']==='2026-11-09','Original synthetic active placement selected');
    $h->expect((int)$pdo->query('SELECT COUNT(*) FROM payroll_source_claims WHERE time_tenant_id=2002 AND time_entry_id=14')->fetchColumn()===0,'Original synthetic payroll claim remains released');
    $published=json_decode(file_get_contents(ThunderhawkLifecycleNative::PRIVATE.'/synthetic-lifecycle-publication_resume-20261111.json'),true,64,JSON_THROW_ON_ERROR);
    $originalDelivery=$published['deliveries']['pure_assignment'];
    $h->expect($published['passed']&&$originalDelivery['operation']==='assignments.inputs'&&(int)$originalDelivery['entity_id']===874,'Original assignment delivery selected from its successful receipt');
    $old=purepayAssignmentInputStatus($pdo,2002,integrationInstanceId(),quantaTimeControlOwners(2002),(int)$originalDelivery['id'],$originalDelivery['review_hash']);
    $previous=$old['provider_review']['accepted_revision'];
    $h->expect($old['provider_review']['assignment_status']==='active'&&$previous==='6a0c515b5e086905ab7909c27288afa7f581422b103184b322be394fd811eda2','Original Pure active revision verified');
    $h->actor(['people'=>'read','placements'=>'admin','integrations'=>'admin','accounting'=>'admin','payroll'=>'read','ap'=>'read']);
    [$status]=$h->request('/api/placements/placements?action=end&id=874',['actual_end_date'=>'2026-11-08'],'POST');
    $h->expect($status===422&&$before===$pdo->query('SELECT * FROM placements WHERE tenant_id=2002 AND id=874')->fetch(PDO::FETCH_ASSOC),'Invalid end date leaves original placement unchanged');
    $h->checkpoint('ending');
    [$status,$ended]=$h->request('/api/placements/placements?action=end&id=874',['actual_end_date'=>'2026-11-10','reason'=>'Synthetic lifecycle closure; no real employee termination'],'POST');
    $h->expect($status===200&&($ended['ok']??false)&&!($ended['replayed']??true)&&($ended['placement']['status']??null)==='ended','Native placement end succeeds with omitted status');
    $after=$pdo->query('SELECT * FROM placements WHERE tenant_id=2002 AND id=874')->fetch(PDO::FETCH_ASSOC);
    $h->expect($after['actual_end_date']==='2026-11-10'&&$after['end_date']==='2026-11-30','Actual closure retains planned end date');
    $graph=$pdo->query('SELECT * FROM placement_economic_parties WHERE tenant_id=2002 AND placement_id=874 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $worker=array_values(array_filter($graph,static fn($r)=>$r['source_ref']==='worker:368'));
    $h->expect(count($worker)===1&&$worker[0]['effective_to']==='2026-11-10','Commercial worker period closes on actual end date');
    $audit=(int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    $h->report['actual_end_date']='2026-11-10';$h->report['core_status']='ended';$h->checkpoint('ended');
    [$status,$again]=$h->request('/api/placements/placements?action=end&id=874',[],'POST');
    $h->expect($status===200&&($again['replayed']??false)&&$after===$pdo->query('SELECT * FROM placements WHERE tenant_id=2002 AND id=874')->fetch(PDO::FETCH_ASSOC)
        &&$graph===$pdo->query('SELECT * FROM placement_economic_parties WHERE tenant_id=2002 AND placement_id=874 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
        &&$audit===(int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(),'End replay retains original date, graph and audit without new writes');
    [$status,$preview]=$h->request('/api/quanta.php?action=metadata_preview',['entity_type'=>'placement','entity_id'=>874,'worksite_id'=>'1bc9011d-12df-458e-b79d-c31f09b9ee09'],'POST');
    $h->expect($status===200&&($preview['workspace']['id']??null)==='4f93d4e7-af74-4765-b812-3464bb6d974b'&&($preview['reference']['number']??null)==='PL-874','Quanta terminal review belongs to original isolated assignment');
    offboardSend($h,'quanta_assignment','/api/quanta.php?action=metadata_send',$preview,['confirm_metadata'=>true]);
    [$status,$preview]=$h->request('/api/purepay_core.php?action=assignment_input_preview',['placement_number'=>'PL-874','previous_revision'=>$previous],'POST');
    $h->expect($status===200&&($preview['packet']['source_status']??null)==='ended'&&($preview['packet']['end_date']??null)==='2026-11-10'
        &&($preview['organization']['id']??null)==='org_0369a63ff2cb','Pure terminal revision follows original accepted placement');
    $sent=offboardSend($h,'pure_assignment','/api/purepay_core.php?action=assignment_input_send',$preview,['confirmed'=>true]);
    [$status,$review]=$h->request('/api/purepay_core.php?action=assignment_input_status',['delivery_id'=>(int)$sent['id'],'review_hash'=>$sent['review_hash']],'POST');
    $h->expect($status===200&&($review['provider_review']['state']??null)==='revision_ready'&&$review['provider_review']['assignment_status']==='active','Pure requires native review before applying the ended revision');
    $h->report['pure_provider_review']=$review['provider_review'];$h->report['pure_native_review_pending']=true;
}catch(Throwable $e){$error=$e;}
$h->finish($error,['integration_deliveries','integration_delivery_subjects','rbac_bridge_audit']);
