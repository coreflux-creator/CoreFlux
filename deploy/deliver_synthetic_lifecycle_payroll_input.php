<?php
/** Native review-only delivery and source reservation for the existing synthetic hour. */
declare(strict_types=1);
if(($argv[1]??'')!=='--hold-original-synthetic-hour')exit(2);
require __DIR__.'/thunderhawk_lifecycle_native.php';
require_once ThunderhawkLifecycleNative::ROOT.'/core/integrations/purepay_payroll_reservation_review.php';
$h=new ThunderhawkLifecycleNative('payroll_input');$error=null;
$employee='emp_core_6db3732563a74d57f1e5cc00e6b257008e47eee22fd0b83502b04e24eab51717';
$assignment='asg_core_aff68d7460216d12beedbe1e0e6a3ddee7921840a562577d275d992b1d131008';
try{
    $accepted=json_decode(file_get_contents(ThunderhawkLifecycleNative::PRIVATE.'/synthetic-lifecycle-time_accept_return-20261111.json'),true,64,JSON_THROW_ON_ERROR);
    $h->expect(($accepted['passed']??false)&&($accepted['quanta_core_review']??null)==='accepted','Original approval return is verified');
    $h->expect(purepayCoreApiBase()==='https://purepay.online:19443/api/v1','Only the isolated Pure provider is selected');
    foreach(['purepayCoreLinkEnabled','purepayPayrollInputsEnabled','purepayPayrollAuthorityEnabled'] as $gate){
        $h->expect($gate(2002),'Isolated delivery gate is enabled: '.$gate);
        foreach([2,999,2003] as $other)$h->expect(!$gate($other),'Other company delivery is denied: '.$gate.' / '.$other);
    }
    $rows=$h->pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002 AND person_id=368')->fetchAll(PDO::FETCH_ASSOC);
    $entry=$rows[0]??[];
    $h->expect(count($rows)===1&&(int)$entry['id']===14&&(int)$entry['placement_id']===874&&$entry['status']==='approved'
        &&(int)$entry['approved_by_user_id']===71&&(float)$entry['hours']===1.0&&$entry['payroll_extracted_at']===null,'Exactly the original approved synthetic hour is available');
    $h->expect((int)$h->pdo->query('SELECT COUNT(*) FROM payroll_source_claims WHERE time_tenant_id=2002 AND time_entry_id=14')->fetchColumn()===0,'Original hour has no prior source reservation');
    $h->actor(['people'=>'read','placements'=>'read','time'=>'read','payroll'=>'write','integrations'=>'admin','accounting'=>'admin']);
    foreach([['person','P-368',$employee],['placement','PL-874',$assignment]] as [$kind,$number,$provider]){
        [$status,$link]=$h->request('/api/purepay_core.php?action=link_preview',['kind'=>$kind,'number'=>$number,'provider_id'=>$provider],'POST');
        $h->expect($status===200&&($link['existing_provider_link']??false)===true&&($link['organization']['id']??null)==='org_0369a63ff2cb'
            &&($link['provider_record']['id']??null)===$provider,'Previously created native identity matches '.$number);
        $send=['delivery_id'=>(int)$link['delivery']['id'],'review_hash'=>$link['delivery']['review_hash'],'confirmed'=>true];
        $h->report['link_previews'][$kind]=$link;$h->checkpoint($kind.'_link_recording');
        [$status,$sent]=$h->request('/api/purepay_core.php?action=link_send',$send,'POST');
        $h->expect($status===200&&($sent['state']??null)==='delivered'&&$sent['remote_id']===$provider,'Existing provider link receipt recorded for '.$number);
        [$status,$replay]=$h->request('/api/purepay_core.php?action=link_send',$send,'POST');
        $h->expect($status===200&&$replay===$sent,'Identity link replay is stable for '.$number);
        $h->report['links'][$kind]=$sent;
    }
    [$status,$preview]=$h->request('/api/purepay_core.php?action=payroll_input_preview',
        ['person_number'=>'P-368','period_start'=>'2026-11-09','period_end'=>'2026-11-15'],'POST');
    $c=$preview['packet']['components'][0]??[];
    $h->expect($status===200&&($preview['organization']['id']??null)==='org_0369a63ff2cb'&&count($preview['packet']['components']??[])===1
        &&($c['source']['time_entry_id']??null)===14&&($c['source']['hours']??null)==='1.00'
        &&($c['source']['source_system']??null)==='quanta'&&($c['source']['source_entry_id']??null)==='dcfd06b9-6f30-4ed9-8426-789fc255f194'
        &&($c['source']['source_workspace_id']??null)==='4f93d4e7-af74-4765-b812-3464bb6d974b'
        &&($c['employee_id']??null)===$employee&&($c['assignment_id']??null)===$assignment
        &&($c['rate']['pay_rate']??null)==='50.0000'&&($c['rate']['currency']??null)==='USD',
        'Payroll input contains only the reviewed one-hour source and its original employee, assignment and rate');
    $h->report['input_preview']=$preview;$h->checkpoint('input_sending');
    $send=['delivery_id'=>(int)$preview['delivery']['id'],'review_hash'=>$preview['delivery']['review_hash'],'confirmed'=>true];
    [$status,$sent]=$h->request('/api/purepay_core.php?action=payroll_input_send',$send,'POST');
    $h->expect($status===200&&($sent['state']??null)==='delivered','Pure received the original input for review');
    $h->report['input_delivery']=$sent;$h->checkpoint('input_delivered');
    [$status,$replay]=$h->request('/api/purepay_core.php?action=payroll_input_send',$send,'POST');
    $h->expect($status===200&&$replay===$sent,'Input replay preserves the original receipt');
    $reserve=$send+['request_id'=>hash('sha256','synthetic-lifecycle-20261111-core-entry14-payroll-reservation')];
    $h->report['reservation_request_id']=$reserve['request_id'];$h->checkpoint('reservation_creating');
    [$status,$held]=$h->request('/api/purepay_core.php?action=payroll_reservation_reserve',$reserve,'POST');
    $h->expect($status===200&&($held['authority']['state']??null)==='held'&&$held['authority']['input_id']===$sent['remote_id'],'Core holds the source for the original input');
    $h->report['authority']=$held['authority'];$h->checkpoint('source_held');
    [$status,$replay]=$h->request('/api/purepay_core.php?action=payroll_reservation_reserve',$reserve,'POST');
    $h->expect($status===200&&$replay===$held,'Source reservation replay preserves the same authority');
    $competing=$reserve;$competing['request_id']=hash('sha256','synthetic-lifecycle-20261111-core-entry14-competing-reservation');
    [$status]=$h->request('/api/purepay_core.php?action=payroll_reservation_reserve',$competing,'POST');
    $h->expect($status===422,'A competing reservation cannot reuse the held hour');
    $authority=$send+['authority_id'=>$held['authority']['id']];$h->checkpoint('reservation_sending');
    [$status,$received]=$h->request('/api/purepay_core.php?action=payroll_reservation_send',$authority,'POST');
    $h->expect($status===200&&($received['authority']['state']??null)==='held'
        &&($received['provider_receipt']['state']??null)==='available'&&($received['provider_receipt']['payroll_created']??null)===false,
        'Pure acknowledges the source reservation without creating payroll');
    $h->report['reservation_delivery']=$received;$h->checkpoint('reservation_delivered');
    [$status,$reconciled]=$h->request('/api/purepay_core.php?action=payroll_reservation_reconcile',$authority,'POST');
    $h->expect($status===200&&$reconciled===$received,'Provider reservation reconciliation matches the original acknowledgement');
    $h->expect($rows===$h->pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002 AND person_id=368')->fetchAll(PDO::FETCH_ASSOC),
        'Payroll review and reservation leave source time unchanged and unextracted');
    $h->report['input_replay_verified']=true;$h->report['reservation_replay_verified']=true;$h->report['competing_reservation_refused']=true;
    $h->report['entry_id']=14;$h->report['input_id']=$sent['remote_id'];$h->report['authority_id']=$held['authority']['id'];
    $h->report['payroll_created']=false;$h->report['source_time_unchanged']=true;
}catch(Throwable $e){$error=$e;}
$h->finish($error,['integration_deliveries','integration_delivery_subjects','payroll_source_authorities','payroll_source_claims','rbac_bridge_audit']);
