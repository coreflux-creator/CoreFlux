<?php
/** Extend the actual held-authority fixture with a new approved entry in the same payroll period. */
declare(strict_types=1);
$heldClaims=$pdo->query('SELECT * FROM payroll_source_claims ORDER BY time_entry_id')->fetchAll(PDO::FETCH_ASSOC);
$newTime=$pdo->query('SELECT * FROM time_entries WHERE id=2')->fetch(PDO::FETCH_ASSOC);
$newTime['id']=4;$newTime['hours']='1.25';$newTime['work_date']='2026-10-09';$newTime['timesheet_id']=2;
$columns=array_keys($newTime);
$pdo->prepare('INSERT INTO time_entries (`'.implode('`,`',$columns).'`) VALUES('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($newTime));
payrollInputFixtureApprovalEvidence($pdo,[4]);
$candidates=purepayPayrollInputCandidates($pdo,1,$instance,$owners,'P-1','2026-10-01','2026-10-19');
$check(array_column($candidates['rows'],'id')===[1,2,4]&&array_column($candidates['rows'],'available')===[false,false,true]
    &&str_contains($candidates['rows'][0]['reason'],'reserved'),'existing reserved time stays visible but unavailable beside the new approved supplement');
$check($fails(fn()=>purepayPayrollInputPreview($pdo,1,7,$instance,$owners,'P-1','2026-10-01','2026-10-19',[1,4])),'mixed reserved and available selection cannot issue a partial packet');
$selected=purepayPayrollInputPreview($pdo,1,7,$instance,$owners,'P-1','2026-10-01','2026-10-19',[4]);
$check(count($selected['packet']['components'])===1&&$selected['packet']['components'][0]['source']['hours']==='1.25'
    &&$selected['packet']['period_start']===$p['packet']['period_start']&&$selected['packet']['period_end']===$p['packet']['period_end'],
    'new approved supplement retains the actual period already used by the earlier held packet');
$send($selected);$newRequest=str_repeat('e',64);$newAuthority=$reserve($selected,$newRequest);
$check($newAuthority['components']===1&&$newAuthority['state']==='held'&&(int)$pdo->query('SELECT COUNT(*) FROM payroll_source_claims')->fetchColumn()===3,
    'selected source rebuild reserves only the new entry while the original payroll reservation remains held');
$check($pdo->query('SELECT * FROM payroll_source_claims WHERE time_entry_id IN(1,2) ORDER BY time_entry_id')->fetchAll(PDO::FETCH_ASSOC)===$heldClaims,
    'new reservation preserves all original claim identities and rates');
$check($reserve($selected,$newRequest)===$newAuthority&&$fails(fn()=>$reserve($selected,str_repeat('f',64))),
    'same selected reservation replays exactly while a competing request cannot reuse its hours');
$check($denied($pdo,'UPDATE time_entries SET hours=2 WHERE id=4'),'selected source receives the existing database edit fence');
$check(!purepayPayrollInputCandidates($pdo,1,$instance,$owners,'P-1','2026-10-01','2026-10-19')['rows'][2]['available'],
    'discovery immediately reflects the new reservation');
// Remove only this invented case so the pre-existing authority's transport tests retain their exact two-claim fixture.
$pdo->prepare('DELETE FROM payroll_source_claims WHERE authority_id=?')->execute([$newAuthority['id']]);
$pdo->prepare('DELETE FROM payroll_source_authorities WHERE id=?')->execute([$newAuthority['id']]);
$pdo->exec('DELETE FROM time_entries WHERE id=4');$pdo->exec('DELETE FROM time_approval_rates WHERE tenant_id=1 AND time_entry_id=4');
