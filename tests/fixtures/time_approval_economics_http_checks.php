<?php
/** Real session privacy, original workspace, immutable values and durable intent. */
require_once __DIR__.'/../../modules/time/lib/accounting_handoff.php';
$economicsPath='/modules/time/api/accounting_handoffs.php?timesheet_id='.$sheet;
$q=$pdo->prepare('SELECT * FROM time_approval_rates WHERE tenant_id=? AND time_entry_id=?');
$q->execute([$workspace,$ids[0]]);$archived=$q->fetch(PDO::FETCH_ASSOC);
$expect($archived&&(int)$archived['rate_owner_tenant_id']===$tenant&&(int)$archived['rate_id']===$rate,'Rate archive retains original time workspace and shared placement owner');
$originalEvents=timeStaffingAccountingEvents($workspace,$sheet);
$rateBefore=$read('placement_rates',$rate);
$pdo->prepare('UPDATE placement_rates SET bill_rate=700,pay_rate=400,ot_multiplier=3,workers_comp_pct=0.5 WHERE id=?')->execute([$rate]);
try {
    $fixed=timeApprovedRate($read('time_entries',$ids[0]),$workspace);
    $expect((float)$fixed['bill_rate']===100.0&&(float)$fixed['pay_rate']===60.0&&timeStaffingAccountingEvents($workspace,$sheet)===$originalEvents,'A later master-rate change cannot restate approved time or its accounting request');
} finally {
    $cols=array_keys($rateBefore);$pdo->prepare('UPDATE placement_rates SET '.implode(',',array_map(static fn($c)=>'`'.$c.'`=?',$cols)).' WHERE id=?')->execute([...array_values($rateBefore),$rate]);
}
foreach(['missing','corrupt','time_changed'] as $case) {
    $pdo->beginTransaction();
    try {
        if ($case==='missing') $pdo->prepare('DELETE FROM time_approval_rates WHERE tenant_id=? AND time_entry_id=?')->execute([$workspace,$ids[0]]);
        elseif ($case==='corrupt') $pdo->prepare('UPDATE time_approval_rates SET rate_json="{}" WHERE tenant_id=? AND time_entry_id=?')->execute([$workspace,$ids[0]]);
        else $pdo->prepare('UPDATE time_entries SET hours=3 WHERE tenant_id=? AND id=?')->execute([$workspace,$ids[0]]);
        $refused=false;try{timeStaffingAccountingEvents($workspace,$sheet);}catch(RuntimeException $e){$refused=$e->getCode()===409;}
        $expect($refused,'Accounting refuses '.$case.' approved evidence instead of substituting a current rate');
    } finally {$pdo->rollBack();}
}
$stateBefore=$snapshot($tables);
$expect($nativeRequest('GET',$economicsPath,null,false,$workspace)[0]===401,'Accounting handoff requires a signed-in session');
$expect($nativeRequest('GET',$economicsPath,null,true,$tenant)[0]===404,'Shared directory access cannot read a child company accounting handoff');
[$status,$handoff]=$nativeRequest('GET',$economicsPath,null,true,$workspace);
$expect($status===200&&$handoff['status']==='pending'&&$handoff['workspace_id']===$workspace&&!isset($handoff['events_json'])&&!isset($handoff['rate_json']),'Time review shows pending accounting without exposing private pay-rate or journal payloads');
$expect($snapshot($tables)===$stateBefore,'Handoff inspection creates no financial or operational writes');
RBACResolver::grantModule($member,'accounting','read',[$workspace],$actor);
$expect($nativeRequest('POST',$economicsPath,['review_revision'=>$handoff['review_revision']],true,$workspace)[0]===403,'A time approver with read-only accounting access cannot retry posting');
RBACResolver::grantModule($member,'accounting','admin',[$workspace],$actor);
$expect($nativeRequest('POST',$economicsPath,[],true,$workspace)[0]===409,'Posting retry requires the displayed handoff revision');
$expect($nativeRequest('POST',$economicsPath,['review_revision'=>str_repeat('0',64)],true,$workspace)[0]===409&&$snapshot($tables)===$stateBefore,'A stale reviewed accounting handoff has no posting effects');
// Restore the fixture's original accounting grant; later tests review time only.
RBACResolver::grantModule($member,'accounting','read',[$workspace],$actor);
$pdo->beginTransaction();
try {
    $before=$snapshot($tables);
    $message=staffingEmitWorkerHoursApprovedEvent($workspace,$sheet);
    $expect(str_contains((string)$message,'until the approval transaction commits')&&$snapshot($tables)===$before,'Nested accounting delivery is deferred without early journals or state changes');
} finally {$pdo->rollBack();}
