<?php
/** Observe the held synthetic draft and reconcile it to its original one-hour source. */
declare(strict_types=1);
if(($argv[1]??'')!=='--observe-original-synthetic-held-draft')exit(2);
require __DIR__.'/thunderhawk_lifecycle_native.php';
require_once ThunderhawkLifecycleNative::ROOT.'/core/integrations/purepay_payroll_results.php';
$h=new ThunderhawkLifecycleNative('payroll_result');$error=null;
$authority='cpa_92285a297913dd5feab0e0a3ed148ba14866619c2a01e790e0394a5c57931954';
$input='cpi_255117c910a9437a565ed773d63cc32857b901b132926b88790b458040d09bd9';
$run='run_core_6ee5f0d01ee1a7ffa5316b44e2ef3f2927e1b01e43971ced17bb2a5024df7a06';
try{
    $prior=json_decode(file_get_contents(ThunderhawkLifecycleNative::PRIVATE.'/synthetic-lifecycle-payroll-readback-20261111.json'),true,64,JSON_THROW_ON_ERROR);
    $h->expect(($prior['passed']??false)&&$prior['input_id']===$input&&$prior['authority_id']===$authority,'The original input and held reservation are verified');
    $delivery=integrationDeliveryGet($h->pdo,2002,93);
    $h->expect($delivery['state']==='delivered'&&$delivery['remote_id']===$input,'Only the original delivered input is observed');
    $h->actor(['people'=>'read','placements'=>'read','time'=>'read','payroll'=>'write','integrations'=>'admin','accounting'=>'admin']);
    $body=['delivery_id'=>93,'review_hash'=>$delivery['review_hash'],'authority_id'=>$authority];
    [$status,$reservation]=$h->request('/api/purepay_core.php?action=payroll_reservation_reconcile',$body,'POST');
    $h->expect($status===200&&($reservation['authority']['state']??null)==='held'&&($reservation['provider_receipt']['state']??null)==='allocated'
        &&($reservation['provider_receipt']['allocation']['run_id']??null)===$run&&($reservation['provider_receipt']['allocation']['run_state']??null)==='draft',
        'The original source remains held and points to the one draft');
    $h->report['reservation']=$reservation;$h->checkpoint('result_observing');
    [$status,$saved]=$h->request('/api/purepay_core.php?action=payroll_result_refresh',$body,'POST');$r=$saved['result']??[];
    $h->expect($status===200&&($r['run_id']??null)===$run&&$r['input_id']===$input&&$r['organization_id']==='org_0369a63ff2cb'
        &&$r['status']==='draft'&&$r['execution_hold']===true&&$r['calculation_mode']==='manual'&&$r['currency']==='USD'
        &&$r['totals']['source_gross']==='50.00'&&$r['totals']['gross']==='50.00'&&count($r['lines'])===1
        &&$r['lines'][0]['employee_id']==='emp_core_6db3732563a74d57f1e5cc00e6b257008e47eee22fd0b83502b04e24eab51717'
        &&$r['lines'][0]['components'][0]['input_component']['source']['time_entry_id']===14,
        'Core reconciles the held draft gross and identity back to the original hour');
    $h->report['observation']=$saved;$h->checkpoint('result_saved');
    [$status,$again]=$h->request('/api/purepay_core.php?action=payroll_result_refresh',$body,'POST');
    $h->expect($status===200&&$again['replayed']===true&&$again['id']===$saved['id']&&$again['result']['result_hash']===$r['result_hash'],
        'Observation replay reuses the same stored result');
    $st=$h->pdo->prepare('SELECT payload_ct FROM payroll_provider_results WHERE tenant_id=2002 AND id=?');$st->execute([$saved['id']]);$cipher=$st->fetchColumn();
    $h->expect(is_string($cipher)&&!str_contains($cipher,'"gross"')&&(json_decode((string)decryptField($cipher),true)['result_hash']??null)===$r['result_hash'],
        'Financial result is encrypted at rest and matches the original observation');
    $h->report['observation_id']=$saved['id'];$h->report['result_hash']=$r['result_hash'];$h->report['run_id']=$run;
    $h->report['gross']='50.00';$h->report['execution_hold']=true;$h->report['tax_calculation_validated']=false;
    $h->report['replay_reuses_observation']=true;$h->report['encrypted_result_verified']=true;$h->report['source_time_unchanged']=true;
}catch(Throwable $e){$error=$e;}
$h->finish($error,['payroll_provider_results','rbac_bridge_audit']);
