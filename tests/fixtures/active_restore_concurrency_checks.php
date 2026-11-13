<?php
/** Current permissions, business state and receipts through actual independent connections. */
$pdo->prepare('INSERT INTO tenant_memberships(user_id,tenant_id,persona_type,status) VALUES(?,?,"admin","active")')->execute([$actor,$child]);$restoreMember=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO membership_module_access(membership_id,module_key,access_level) VALUES(?,"placements","write")')->execute([$restoreMember]);$restoreGrant=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO jobdiva_connections(tenant_id,client_id,username,password_enc,status,last_sync_at) VALUES(?,"synthetic","synthetic","unused","connected",NOW()) ON DUPLICATE KEY UPDATE status="connected",last_sync_at=NOW()')->execute([$child]);
$restoreFixture=static function(string $label)use($projectionFixture,$pdo,$tenant,$child,$actor,$read,$restoreGrant):array{
    [$a,$b,$id,$original]=$projectionFixture('active-restore-'.$label,$child);
    $pdo->prepare('UPDATE membership_module_access SET access_level="write" WHERE id=?')->execute([$restoreGrant]);
    $p=$read('placements',$id);$person=(int)$p['person_id'];$source=$original['payload'];
    $root=['startId'=>$source['startId'],'candidateId'=>$source['candidateId'],'jobId'=>$source['jobId'],'startDate'=>'2026-11-01','status'=>'Active'];
    $detail=[['Start ID'=>$root['startId'],'Candidate ID'=>$root['candidateId'],'Job ID'=>$root['jobId'],'Start Date'=>'2026-11-01','Employment Category'=>'Hourly Employee','Bill Rate'=>100,'Pay Rate'=>50,
        'BILLING'=>[['Approved'=>true,'Actual Start'=>true,'Actual End'=>false,'Closed'=>false]],'SALARY'=>[['Approved'=>true,'Closed'=>false]]]];
    $payload=$root+['_jd_assignment_detail'=>$detail,'_jd_contract'=>jobdivaAssignmentContractBuild($detail,$root,$root['startId'])];
    $mapping=mappingUpsert($child,'jobdiva','placement',$root['startId'],$id,$payload);
    $pdo->prepare('UPDATE placements SET status="pending_start",coreflux_overridden_fields=NULL,client_bill_cycle="monthly",vendor_pay_cycle="biweekly",client_payment_terms_override="NET30" WHERE id=?')->execute([$id]);
    $pdo->prepare('UPDATE people SET classification="w2",status="active" WHERE id=?')->execute([$person]);
    $pdo->prepare('UPDATE placement_rates SET approved_at=NOW(),approved_by_user_id=? WHERE placement_id=?')->execute([$actor,$id]);
    $pdo->prepare('INSERT INTO people_employees(tenant_id,employee_number,legal_first_name,legal_last_name,status) VALUES(?,?,"Synthetic","Restore race","active")')->execute([$tenant,'restore-race-'.$person]);$employee=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO people_employment_links(tenant_id,person_id,employee_id,review_id,actor_user_id,origin,reason,review_ct) VALUES(?,?,?,?,?,"review_existing","Synthetic race","unused")')->execute([$tenant,$person,$employee,hash('sha256','restore-'.$person),$actor]);
    $pdo->prepare('INSERT INTO payroll_profiles(tenant_id,employee_id,enabled) VALUES(?,?,1)')->execute([$tenant,$employee]);
    placementEconomicsReconcile($tenant,$id);
    $r=jobdivaActiveRestoreReview($child,$actor,[$id]);
    if(!$r['can_apply'])throw new RuntimeException('Concurrent restore fixture is not eligible: '.json_encode($r['rows']));
    $body=['confirm'=>'RESTORE_REVIEWED_ACTIVE_STATUS','placement_ids'=>[$id],'review_token'=>$r['review_token'],'request_id'=>bin2hex(random_bytes(4)).'-0000-4000-8000-'.bin2hex(random_bytes(6)),
        'expected_restore_count'=>1,'expected_active_total'=>$r['expected_active_total_after'],'reason'=>'Reviewed synthetic concurrent active restore'];
    return[$id,$person,['mode'=>'native','entity'=>'active_restore','workspace'=>$child,'actor'=>$actor,'placement_id'=>$id,'body'=>$body]];
};
foreach(['COMMIT','ROLLBACK']as$decision){
    foreach(['policy','ownership','rate','permission','worker']as$change){
        [$id,$person,$input]=$restoreFixture($change.'-'.$decision);$pdo->beginTransaction();
        if($change==='policy')$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="people"')->execute([$child]);
        if($change==='ownership')$pdo->prepare('UPDATE placements SET coreflux_overridden_fields=\'["status"]\' WHERE id=?')->execute([$id]);
        if($change==='rate')$pdo->prepare('UPDATE placement_rates SET approved_at=NULL WHERE placement_id=?')->execute([$id]);
        if($change==='permission')$pdo->prepare('UPDATE membership_module_access SET access_level="read" WHERE id=?')->execute([$restoreGrant]);
        if($change==='worker')$pdo->prepare('UPDATE people SET status="inactive" WHERE id=?')->execute([$person]);
        $worker=$spawn($input);$check($waiting($worker),'Active restoration waits for '.$change.' '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();$before=$projectionRows();$result=$finish($worker);
        $check($decision==='COMMIT'?(!$result['ok']&&$before===$projectionRows()):($result['ok']&&$result['result']['restored']===1&&$read('placements',$id)['status']==='active'),
            'Active restoration rechecks '.$change.' after '.$decision.': '.json_encode($result));
    }
    [$id,$person,$input]=$restoreFixture('receipt-'.$decision);
    $first=$spawn(array_replace($input,['mode'=>'active_restore_hold']));$saved=$line($workers[$first]['pipes'][1]);
    $check(!empty($saved['saved'])&&!empty($saved['in_transaction']),'Active restoration holds status and receipt before '.$decision);
    $retry=$spawn($input);$check($waiting($retry),'Duplicate restoration waits for first receipt '.$decision);
    fwrite($workers[$first]['pipes'][0],$decision.PHP_EOL);fflush($workers[$first]['pipes'][0]);$written=$finish($first);$again=$finish($retry);
    $q=$pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND event="jobdiva.active_status_restored" AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.request_id"))=?');$q->execute([$child,$input['body']['request_id']]);
    $check($written['ok']&&$again['ok']&&$again['result']['replayed']===($decision==='COMMIT')&&(int)$q->fetchColumn()===1,'Concurrent restore retry commits one receipt after '.$decision);
}
// Exercise the real operator command against committed fixtures, not a PHP include.
[$id,$person,$input]=$restoreFixture('cli');
$restoreCli=static function(array $args):array{
    $h=proc_open([PHP_BINARY,dirname(__DIR__,2).'/scripts/repair_jobdiva_active_status_regression.php',...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,2));
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($h);
    if($exit!==0)throw new RuntimeException('Restoration CLI failed: '.$error);return json_decode($out,true,512,JSON_THROW_ON_ERROR);
};
$before=$projectionRows();$preview=$restoreCli(['preview',(string)$child,(string)$actor,(string)$id]);
$check($preview['can_apply']&&$before===$projectionRows(),'Actual restoration CLI preview is read-only');
$args=['apply',(string)$child,(string)$actor,(string)$id,$preview['review_token'],$input['body']['request_id'],'1',(string)$preview['expected_active_total_after'],'Reviewed CLI restoration'];
$saved=$restoreCli($args);$check($saved['restored']===1&&$read('placements',$id)['status']==='active','Actual CLI applies the exact reviewed selection');
$before=$projectionRows();$again=$restoreCli($args);$check($again['replayed']&&$before===$projectionRows(),'Actual CLI retries recover the stored receipt without another write');
