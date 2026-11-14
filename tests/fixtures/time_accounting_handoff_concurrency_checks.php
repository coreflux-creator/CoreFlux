<?php
/** Independent retries serialize one retained intent even when no posting rule exists. */
$fixture=$nativeSeed('durable accounting retries');extract($fixture,EXTR_PREFIX_ALL,'ha');
$pdo->prepare('UPDATE placements SET client_id=?,accounting_entity_id=?,worksite_state="NJ",workers_comp_class="synthetic-class" WHERE id=?')->execute([$reviewClient,$reviewEntity,$ha_placement]);
$haRate=$nativeInsert('placement_rates',['tenant_id'=>$tenant,'placement_id'=>$ha_placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>60,'approved_at'=>'2026-10-01 12:00:00']);
setRequestTenantId($tenant);
timeApproveEntry($ha_entry,['id'=>$reviewer]);
$q=$pdo->prepare('SELECT * FROM time_accounting_handoffs WHERE tenant_id=? AND timesheet_id=?');$q->execute([$tenant,$ha_sheet]);$intent=$q->fetch(PDO::FETCH_ASSOC);
$check($intent&&$intent['status']==='pending','Approval commits a durable accounting intent before any processor starts');
$financialBefore=$approvalFinancialState();
$pdo->beginTransaction();
try {
    $pdo->prepare('SELECT id FROM time_accounting_handoffs WHERE tenant_id=? AND id=? FOR UPDATE')->execute([$tenant,$intent['id']]);
    $input=['mode'=>'native','entity'=>'generic_time','operation'=>'accounting_dispatch','workspace'=>$tenant,'actor'=>$reviewer,'timesheet_id'=>$ha_sheet];
    $first=$spawn($input);$second=$spawn($input);
    $check($waiting($first)&&$waiting($second),'Both independent accounting processors wait on the same retained handoff');
    $pdo->commit();
    $a=$finish($first);$b=$finish($second);
    $q->execute([$tenant,$ha_sheet]);$after=$q->fetch(PDO::FETCH_ASSOC);
    $check($a['ok']&&$b['ok']&&!empty($a['result']['warning'])&&!empty($b['result']['warning'])&&$after['status']==='failed'&&(int)$after['attempts']===2,'Concurrent failed deliveries retain both attempts without losing the request: '.json_encode([$a,$b]));
    $check($after['id']===$intent['id']&&$after['events_hash']===$intent['events_hash']&&$after['events_json']===$intent['events_json']&&$approvalFinancialState()===$financialBefore,'Concurrent retries preserve one immutable intent and leave no partial financial results');
} finally {if($pdo->inTransaction())$pdo->rollBack();}
