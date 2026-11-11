<?php
/** Native reviewed time policy for the existing P-368 / PL-874 synthetic case. */
declare(strict_types=1);
if(($argv[1]??'')!=='--publish-synthetic-time-policy')exit(2);
require __DIR__.'/thunderhawk_lifecycle_native.php';
$h=new ThunderhawkLifecycleNative('time_policy');$error=null;
try{
    $h->expect(quantaTimeControlEnabled(2002)&&!quantaTimeControlEnabled(2),'Only the isolated company can publish time setup');
    $h->expect((int)$h->pdo->query('SELECT COUNT(*) FROM integration_deliveries WHERE tenant_id=2002 AND entity_type="person" AND entity_id=368 AND operation="time.access"')->fetchColumn()===0,'This is the first time policy for the synthetic person');
    $h->actor(['people'=>'read','placements'=>'read','time'=>'write','integrations'=>'admin','accounting'=>'admin']);
    $selection=['person_id'=>368,'worksite_id'=>'1bc9011d-12df-458e-b79d-c31f09b9ee09'];
    [$status,$context]=$h->request('/api/quanta.php?action=time_control_context',$selection,'POST');
    $h->expect($status===200&&($context['delivery_enabled']??false)===true&&($context['person_number']??null)==='P-368'
        &&($context['workspace']['id']??null)==='4f93d4e7-af74-4765-b812-3464bb6d974b'
        &&($context['current_version']??null)===0&&($context['unbound_time']['count']??null)===0,'Both systems agree on a fresh isolated worker/worksite policy');
    $choices=$context['choices'];
    $h->expect(count($choices)===1&&(int)$choices[0]['placement_id']===874&&$choices[0]['placement_number']==='PL-874'
        &&$choices[0]['status']==='active'&&$choices[0]['selected']===false,'Only the active reviewed assignment is eligible');
    $body=$selection+['placement_ids'=>[874],'context_hash'=>$context['context_hash']];
    $wrong=$body;$wrong['context_hash']=str_repeat('0',64);
    [$status]=$h->request('/api/quanta.php?action=time_control_preview',$wrong,'POST');
    $h->expect(in_array($status,[400,409,422],true),'Changed worksite context is refused');
    [$status,$preview]=$h->request('/api/quanta.php?action=time_control_preview',$body,'POST');
    $h->expect($status===200&&($preview['delivery']['state']??null)==='preview'
        &&($preview['selected_placements']??null)===['PL-874'],'Native API previews the exact selected placement');
    $h->report['review']=$preview;$h->checkpoint('reviewed');
    $delivery=$preview['delivery'];$send=['delivery_id'=>(int)$delivery['id'],'review_hash'=>$delivery['review_hash'],'confirm_time_control'=>true];
    $wrong=$send;$wrong['review_hash']=str_repeat('0',64);
    [$status]=$h->request('/api/quanta.php?action=time_control_send',$wrong,'POST');
    $h->expect(in_array($status,[400,409,422],true),'Changed delivery review is refused');
    $h->checkpoint('sending');
    [$status,$sent]=$h->request('/api/quanta.php?action=time_control_send',$send,'POST');
    $h->expect($status===200&&($sent['state']??null)==='delivered'&&(int)$sent['id']===(int)$delivery['id'],'Quanta committed the original time policy');
    $h->report['delivery']=$sent;$h->checkpoint('delivered');
    [$status,$replay]=$h->request('/api/quanta.php?action=time_control_send',$send,'POST');
    $h->expect($status===200&&$replay===$sent,'Delivery replay preserves the original receipt');
    [$status,$receipt]=$h->request('/api/quanta.php?action=time_control_receipt',$send,'POST');
    $h->expect($status===200&&($receipt['delivery']??null)===$sent&&($receipt['receipt_state']??null)==='delivered','Original delivery is discoverable through the receipt endpoint');
    [$status,$after]=$h->request('/api/quanta.php?action=time_control_context',$selection,'POST');
    $h->expect($status===200&&($after['current_version']??null)===1&&count($after['choices']??[])===1
        &&$after['choices'][0]['selected']===true&&$after['context_hash']!==$context['context_hash'],'Fresh provider readback confirms policy version one');
    [$status]=$h->request('/api/quanta.php?action=time_control_preview',$body,'POST');
    $h->expect(in_array($status,[400,409,422],true),'Prior context cannot create a second review after publication');
    $h->report['policy_version']=1;$h->report['assignment_id']=$after['choices'][0]['assignment_id'];
    $h->report['time_entries_created']=0;
}catch(Throwable $e){$error=$e;}
$h->finish($error,['integration_deliveries','integration_delivery_subjects']);
