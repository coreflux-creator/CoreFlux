<?php
/** Original-workspace weekly links exercised through real native sessions and public HTTP. */
require_once __DIR__.'/../../core/staffing_email_approval.php';
$weeklyTables=array_values(array_unique([...$tables,'approval_tokens']));
[$weeklyPeriod,$weeklySheet,$weeklyIds]=$newTokenSet('2026-11-02','2026-11-08');
$weeklyPath='/modules/staffing/api/timesheet_email_approver.php';
$publicWeek='/api/staffing/approve_timesheet_by_email.php';
$weeklyBody=['timesheet_id'=>$weeklySheet,'approver_email'=>$clientEmail];
$before=$snapshot($weeklyTables);
$expect($nativeRequest('POST',$weeklyPath,$weeklyBody,false,$workspace)[0]===401&&$snapshot($weeklyTables)===$before,'Weekly email issuance requires native sign-in');
RBACResolver::grantModule($member,'staffing','read',[$workspace],$actor);
$expect($nativeRequest('POST',$weeklyPath,$weeklyBody,true,$workspace)[0]===403&&$snapshot($weeklyTables)===$before,'Staffing readers cannot delegate weekly approval');
RBACResolver::grantModule($member,'staffing','admin',[$workspace],$actor);
foreach(['approval-worker@example.invalid','approval-preparer@example.invalid']as$self) {
    $before=$snapshot($weeklyTables);
    $expect($nativeRequest('POST',$weeklyPath,['approver_email'=>$self]+$weeklyBody,true,$workspace)[0]===403&&$snapshot($weeklyTables)===$before,'Weekly link cannot authorize its own worker or preparer');
}
$before=$snapshot($weeklyTables);
$expect($nativeRequest('POST',$weeklyPath,['timesheet_id'=>2147483000]+$weeklyBody,true,$workspace)[0]===404&&$snapshot($weeklyTables)===$before,'Weekly dispatch refuses unavailable original-workspace records');
$mute=$insert('tenant_mail_senders',['tenant_id'=>$workspace,'purpose'=>'timesheets','enabled'=>0]);
$outboxBefore=$snapshot(['mail_outbox']);
try {[$status,$muted]=$nativeRequest('POST',$weeklyPath,$weeklyBody,true,$workspace);$expect($status===202&&$muted['sent']===false&&$snapshot(['mail_outbox'])===$outboxBefore,'Weekly email honors the original workspace Timesheets delivery mute');}
finally {$pdo->prepare('DELETE FROM tenant_mail_senders WHERE id=?')->execute([$mute]);}
[$status,$receipt]=$nativeRequest('POST',$weeklyPath,$weeklyBody,true,$workspace);
$expect($status===202&&$receipt['sent']===false&&!isset($receipt['approve_url'],$receipt['reject_url'],$receipt['token'])&&isset($receipt['token_id']),'Weekly log-only delivery is not reported sent and exposes no reviewer secret');
$mailQuery=$pdo->prepare('SELECT body_text,body_html FROM mail_outbox WHERE tenant_id=? AND module="staffing" AND purpose="timesheets" ORDER BY id DESC LIMIT 1');$mailQuery->execute([$workspace]);$storedMail=$mailQuery->fetch(PDO::FETCH_ASSOC);
$expect($storedMail&&!preg_match('/[?&]t=[a-f0-9]{64}/',implode(' ',$storedMail))&&str_contains($storedMail['body_html'],'[redacted]'),'Weekly delivery history redacts both plain and HTML-escaped reviewer credentials');
$issueWeek=static fn()=>staffingEmailReviewIssue($workspace,$actor,$weeklySheet,$clientEmail,'Synthetic manager');
$issued=$issueWeek();$old=$read('approval_tokens',$issued['token_id']);$before=$snapshot($weeklyTables);
$pdo->exec("CREATE TRIGGER cf_http_weekly_receipt BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900021 AND NEW.event='time.timesheet.email_token_issued' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic weekly issue receipt failure'; END IF; END");
try {$expect($nativeRequest('POST',$weeklyPath,$weeklyBody,true,$workspace)[0]===503&&$snapshot($weeklyTables)===$before,'Weekly issue audit failure preserves the previous credential and complete time state');}
finally {$pdo->exec('DROP TRIGGER cf_http_weekly_receipt');}
$before=$snapshot($weeklyTables);$view=staffingEmailApprovalReview($issued['token'],'approve');
[$status,,,$html]=$nativeRequest('GET',$publicWeek.'?t='.$issued['token'].'&a=approve&confirm=1&note=legacy',null,false,$tenant);
$expect($status===200&&str_contains($html,'method="post"')&&str_contains($html,$view['revision'])&&str_contains($html,$view['person_number'])&&str_contains($html,$view['entries'][0]['placement_number'])&&$snapshot($weeklyTables)===$before,'Weekly GET including legacy confirmation displays exact identities and hours without writes');
$expect($view['workspace_id']===$workspace&&$view['total_hours']==4&&count($view['entries'])===2&&!str_contains(json_encode($view),'pay_rate')&&!str_contains(json_encode($view),'bill_rate'),'Weekly public review shows its original workspace and complete week without internal rates');
$postWeek=static fn(string $raw,string $action,?string $revision,string $note='Synthetic weekly correction')=>$nativeRequest('POST',$publicWeek,['t'=>$raw,'a'=>$action,'review_revision'=>$revision,'note'=>$note],false,$tenant,true);
$expect($postWeek($issued['token'],'approve',null)[0]===409&&$snapshot($weeklyTables)===$before,'Weekly decision requires the displayed review revision');
$expect($postWeek($issued['token'],'reject',$view['revision'])[0]===409&&$snapshot($weeklyTables)===$before,'Weekly approval review cannot authorize rejection');
$expect($nativeRequest('GET',$publicWeek.'?t='.str_repeat('0',64).'&a=approve',null,false,$workspace)[0]===404&&$snapshot($weeklyTables)===$before,'Unknown weekly link reveals no time');
$pdo->prepare('UPDATE approval_tokens SET issued_review_json=NULL WHERE id=?')->execute([$issued['token_id']]);$before=$snapshot($weeklyTables);
$expect($postWeek($issued['token'],'approve',$view['revision'])[0]===409&&$snapshot($weeklyTables)===$before,'Weekly legacy links need a newly issued complete week');
$pdo->prepare('UPDATE approval_tokens SET issued_review_json=? WHERE id=?')->execute([$old['issued_review_json'],$issued['token_id']]);
foreach([['time_entries',$weeklyIds[0],'hours',3],['time_periods',$weeklyPeriod,'status','locked'],['people',$person,'email_primary','changed-worker@example.invalid'],['placements',$placement,'title','Changed assignment'],['approval_tokens',$issued['token_id'],'actor_email','changed-manager@example.invalid'],['placement_rates',$rate,'pay_rate',61]]as[$table,$id,$field,$value]) {
    $original=$read($table,$id);$trialView=staffingEmailApprovalReview($issued['token'],'approve');
    $pdo->prepare('UPDATE '.$table.' SET '.$field.'=? WHERE id=?')->execute([$value,$id]);$before=$snapshot($weeklyTables);
    try {$expect($postWeek($issued['token'],'approve',$trialView['revision'])[0]===409&&$snapshot($weeklyTables)===$before,'Weekly approval refuses changed '.$table.'.'.$field.' without consuming its link');}
    finally {$columns=array_keys($original);$pdo->prepare('UPDATE '.$table.' SET '.implode(',',array_map(static fn($c)=>'`'.$c.'`=?',$columns)).' WHERE id=?')->execute([...array_values($original),$id]);}
}
$extra=$insert('time_entries',['tenant_id'=>$workspace,'person_id'=>$person,'placement_id'=>$placement,'period_id'=>$weeklyPeriod,'timesheet_id'=>$weeklySheet,'work_date'=>'2026-11-04','hours'=>1,'hour_type'=>'regular','category'=>'regular_billable','status'=>'pending_review','created_by_user_id'=>$creator]);$before=$snapshot($weeklyTables);
$expect($postWeek($issued['token'],'approve',$view['revision'])[0]===409&&$snapshot($weeklyTables)===$before,'Weekly approval refuses a newly added unreviewed entry');
$pdo->prepare('DELETE FROM time_entries WHERE id=?')->execute([$extra]);
$replacement=$issueWeek();$before=$snapshot($weeklyTables);
$expect(staffingEmailApprovalReview($issued['token'],'approve')['state']==='already_acted'&&$postWeek($issued['token'],'approve',$view['revision'])[0]===409&&$snapshot($weeklyTables)===$before,'Superseded weekly credential cannot decide or expose its entries');
$pdo->prepare('UPDATE approval_tokens SET expires_at="2000-01-01" WHERE id=?')->execute([$replacement['token_id']]);$before=$snapshot($weeklyTables);
$expect(staffingEmailApprovalReview($replacement['token'],'approve')['state']==='expired'&&$snapshot($weeklyTables)===$before,'Expired weekly review is read-only and discloses no entries');
// Retain an independently approved sibling while reviewing the rest of its week.
StaffingNativeTimeEdit::runForWorkspace($workspace,static fn()=>timeApproveEntry($weeklyIds[0],['id'=>$actor]));
$frozen=$read('time_entries',$weeklyIds[0]);$issued=$issueWeek();$view=staffingEmailApprovalReview($issued['token'],'approve');$before=$snapshot($weeklyTables);
$expect($view['total_hours']==4&&$view['pending_hours']==2&&$view['entries'][0]['will_change']===false,'Weekly review distinguishes retained approved hours from the pending decision');
$pdo->exec("CREATE TRIGGER cf_http_weekly_receipt BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900021 AND NEW.event='time.timesheet.email_token_consumed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic weekly consume receipt failure'; END IF; END");
try {$expect($postWeek($issued['token'],'approve',$view['revision'])[0]===503&&$snapshot($weeklyTables)===$before,'Weekly final audit failure restores token, entries, header and artifact together');}
finally {$pdo->exec('DROP TRIGGER cf_http_weekly_receipt');}
$pdo->beginTransaction();
try {$result=staffingEmailApprovalConsume($issued['token'],'approve',null,null,$view['revision']);$expect(!$result['ok']&&$result['status_code']===409&&$pdo->inTransaction()&&$snapshot($weeklyTables)===$before,'Weekly accounting wrapper refuses an uncommitted outer transaction');}
finally {$pdo->rollBack();}
$saved=staffingEmailApprovalDecide($issued['token'],'approve',$view['revision'],'Approved full displayed week');
$done=$read('time_entries',$weeklyIds[1]);
$expect($saved['ok']&&$done['status']==='approved'&&$done['approved_by_user_id']===null&&$done['client_approver_email']===$clientEmail&&$frozen===$read('time_entries',$weeklyIds[0])&&$read('staffing_timesheets',$weeklySheet)['status']==='approved','Weekly pure decision retains the actual external actor, frozen dimensions and prior approved sibling');
$before=$snapshot($weeklyTables);$expect($postWeek($issued['token'],'approve',$view['revision'])[0]===409&&$snapshot($weeklyTables)===$before,'Weekly repeated approval does not duplicate a decision or receipt');
[$rejectWeeklyPeriod,$rejectWeeklySheet,$rejectWeeklyIds]=$newTokenSet('2026-11-09','2026-11-15');
$rejection=staffingEmailReviewIssue($workspace,$actor,$rejectWeeklySheet,$clientEmail);
$rv=staffingEmailApprovalReview($rejection['token'],'reject');$before=$snapshot($weeklyTables);
$expect($postWeek($rejection['token'],'reject',$rv['revision'],'')[0]===422&&$snapshot($weeklyTables)===$before,'Weekly rejection requires an explicit reason');
$pdo->exec("CREATE TRIGGER cf_http_weekly_receipt BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900021 AND NEW.event='time.timesheet.rejected' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic weekly rejection receipt failure'; END IF; END");
try {$expect($postWeek($rejection['token'],'reject',$rv['revision'])[0]===503&&$snapshot($weeklyTables)===$before,'Weekly rejection audit failure restores its entire issued set');}
finally {$pdo->exec('DROP TRIGGER cf_http_weekly_receipt');}
$pdo->prepare('UPDATE placement_rates SET approved_at=NULL WHERE id=?')->execute([$rate]);
[$status,,,$html]=$postWeek($rejection['token'],'reject',$rv['revision']);$rejectHeader=$read('staffing_timesheets',$rejectWeeklySheet);
$expect($status===200&&str_contains($html,'Rejection recorded')&&$rejectHeader['status']==='rejected'&&$rejectHeader['rejected_by_user_id']===null&&$rejectHeader['rejection_reason']==='Synthetic weekly correction','Weekly rejection records its reason without requiring an approved pay rate or pretending to notify the worker');
$pdo->prepare('UPDATE placement_rates SET approved_at="2026-10-01 12:00:00" WHERE id=?')->execute([$rate]);
$expect($snapshot($financialTables)===$financialBefore,'Weekly review and decision service tests create no financial execution');
