<?php
/** Independently review only the existing synthetic source hour; no payroll or GL call. */
declare(strict_types=1);
if(($argv[1]??'')!=='--execute-isolated-correction-phase')exit(2);
require __DIR__.'/thunderhawk_correction_native.php';
require_once ThunderhawkCorrectionNative::ROOT.'/modules/time/lib/time.php';
require_once ThunderhawkCorrectionNative::ROOT.'/core/quanta/time_current.php';
$h=new ThunderhawkCorrectionNative('time_approval');$error=null;
try{
    $entry=timeEntryGet(15);
    $h->expect($entry&&(int)$entry['person_id']===387&&(int)$entry['placement_id']===902
        &&(int)$entry['timesheet_id']===8&&(int)$entry['created_by_user_id']===85
        &&$entry['status']==='pending_review'&&(float)$entry['hours']===1.0&&$entry['work_date']==='2026-11-09',
        'Original synthetic hour is still awaiting review');
    $rate=timeResolveRateSnapshot(902,'2026-11-09',2002);
    $h->expect((int)$rate['id']===23&&(float)$rate['pay_rate']===50.0&&(float)$rate['bill_rate']===100.0&&$rate['currency']==='USD','Approved synthetic rate is unchanged');
    $denied=false;try{timeApproveEntry(15,['id'=>85]);}catch(RuntimeException $e){$denied=$e->getCode()===403;}
    $h->expect($denied&&timeEntryGet(15)===$entry,'Original importer cannot approve its own entry');
    $actor=$h->actor(['time'=>'admin']);
    $h->expect($actor!==85,'Reviewer is separate from the original importer');
    $h->pdo->beginTransaction();try{$source=quantaTimeAssertCurrent($h->pdo,2002,[15]);}finally{$h->pdo->rollBack();}
    $h->expect(($source['source_entries']??null)===1,'Quanta source remains currently approved');
    $h->report['source_check']=$source;$h->checkpoint('approval_sending');
    [$status,$approved]=$h->request('/api/time/entries?action=approve&id=15',[],'POST');
    $row=$approved['entry']??[];
    $h->expect($status===200&&($row['status']??null)==='approved'&&(int)$row['approved_by_user_id']===$actor
        &&(int)$row['rate_snapshot_id']===23&&$row['approved_via']==='manual'&&!empty($row['approved_at']),
        'Native Core approval records the independent reviewer and approved rate');
    $h->report['approved_entry']=$row;$h->checkpoint('approved');
    [$status]=$h->request('/api/time/entries?action=approve&id=15',[],'POST');
    $h->expect($status===409&&timeEntryGet(15)===$row,'Repeated approval is refused without changing the original approval');
    $header=$h->pdo->query('SELECT * FROM staffing_timesheets WHERE tenant_id=2002 AND id=8')->fetch(PDO::FETCH_ASSOC);
    $h->expect($header['status']==='approved'&&(float)$header['total_hours']===1.0&&$header['approved_at']===$row['approved_at'],
        'Native approval reconciles the timesheet header');
    $q=$h->pdo->query("SELECT meta_json FROM audit_log WHERE tenant_id=2002 AND event='time.entry.approved' AND target_id=15");
    $audits=$q->fetchAll(PDO::FETCH_COLUMN);$audit=json_decode($audits[0]??'{}',true);
    $h->expect(count($audits)===1&&(int)$audit['approver_user_id']===$actor&&($audit['quanta_source_check']['source_entries']??null)===1,
        'Exactly one approval audit retains the source verification');
    $h->report['timesheet_header']=$header;
    $q=$h->pdo->prepare('SELECT id,status,version FROM artifact_objects WHERE tenant_id=2002 AND id=?');$q->execute([$header['artifact_id']]);
    $h->report['artifact_after_atomic_approval']=$q->fetch(PDO::FETCH_ASSOC);
    $h->report['independent_human_review']=false;$h->report['independent_test_approver']=true;
    $h->report['approval_replay_refused']=true;$h->report['self_approval_refused']=true;
    $h->report['entry_id']=15;$h->report['timesheet_id']=8;$h->report['core_time_status']='approved';
}catch(Throwable $e){$error=$e;}
$h->finish($error,[]);
