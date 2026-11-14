<?php
/** Continues inside the disposable native approval fixture; all writes stay synthetic. */
require_once __DIR__.'/../../modules/time/lib/native_decisions.php';
RBACResolver::grantModule($member,'staffing','admin',[$workspace],$actor);
$decisionPeriod=$insert('time_periods',['tenant_id'=>$workspace,'start_date'=>'2026-10-12','end_date'=>'2026-10-18','label'=>'Synthetic reviewed decisions']);
$decisionSheet=$insert('staffing_timesheets',['tenant_id'=>$workspace,'person_id'=>$person,'period_start'=>'2026-10-12','period_end'=>'2026-10-18','status'=>'submitted','total_hours'=>6]);
$decisionIds=[];
foreach(['2026-10-12','2026-10-13','2026-10-14']as$date)$decisionIds[]=$insert('time_entries',['tenant_id'=>$workspace,'person_id'=>$person,'placement_id'=>$placement,'period_id'=>$decisionPeriod,'timesheet_id'=>$decisionSheet,'work_date'=>$date,'hours'=>2,'hour_type'=>'regular','category'=>'regular_billable','status'=>'pending_review','created_by_user_id'=>$creator]);
$endpoint=static fn(string $kind,string $action)=>($kind==='weeks'?'/modules/staffing/api/timesheets.php':'/modules/time/api/entries.php').'?action='.$action;
$reviewRequest=static fn(string $kind,array $ids,string $decision)=>$nativeRequest('POST',$endpoint($kind,'decision_review'),['ids'=>$ids,'decision'=>$decision],true,$workspace);
$commit=static fn(string $kind,array $ids,string $decision,$revision,string $reason='Reviewed source correction')=>$nativeRequest('POST',$endpoint($kind,'decision_commit'),['ids'=>$ids,'decision'=>$decision,'review_revision'=>$revision,'reason'=>$reason],true,$workspace);
$before=$snapshot($tables);
$expect($nativeRequest('POST',$endpoint('entries','decision_review'),['ids'=>$decisionIds,'decision'=>'approve'],false,$workspace)[0]===401&&$snapshot($tables)===$before,'Decision review requires native authentication');
RBACResolver::grantModule($member,'time','read',[$workspace],$actor);
$expect($reviewRequest('entries',$decisionIds,'approve')[0]===403&&$snapshot($tables)===$before,'Decision review requires decision permission rather than read-only access');
RBACResolver::grantModule($member,'time','admin',[$workspace],$actor);
[$status,$review]=$reviewRequest('entries',$decisionIds,'approve');
$expect($status===200&&count($review['entries'])===3&&$review['entry_count']===3&&$review['total_hours']===6&&strlen($review['revision'])===64&&$snapshot($tables)===$before,'Read-only decision review returns the entire selected set without writes');
$expect(!str_contains(json_encode($review),'pay_rate')&&!str_contains(json_encode($review),'bill_rate')&&str_starts_with($review['entries'][0]['person_number'],'P-')&&str_starts_with($review['entries'][0]['placement_number'],'PL-'),'Review identifies permanent worker and placement numbers without disclosing pay or bill rates');
$expect($commit('entries',$decisionIds,'approve',null)[0]===409&&$snapshot($tables)===$before,'Approval cannot bypass the browser review revision');
$expect($nativeRequest('POST',$api.$decisionIds[0],[],true,$workspace)[0]===409&&$snapshot($tables)===$before,'Legacy approve action also requires the reviewed revision');
$expect($commit('entries',[$decisionIds[0]],'approve',$review['revision'])[0]===409&&$snapshot($tables)===$before,'An approval review cannot be reused for only a subset');
$expect($commit('entries',$decisionIds,'reject',$review['revision'])[0]===409&&$snapshot($tables)===$before,'A review cannot authorize the opposite decision');
$expect($reviewRequest('entries',[$decisionIds[0],'bad-id'],'approve')[0]===422&&$snapshot($tables)===$before,'Invalid selection members cannot be silently discarded');
$expect($reviewRequest('entries',[$decisionIds[0],2147483647],'approve')[0]===404&&$snapshot($tables)===$before,'Missing or foreign selection members refuse the entire review');
foreach([['time_entries',$decisionIds[1],'hours',3,2],['placement_rates',$rate,'pay_rate',61,60],['people',$person,'first_name','Changed worker','Synthetic approval']]as[$table,$id,$field,$value,$original]) {
    [$status,$review]=$reviewRequest('entries',$decisionIds,'approve');if($status!==200)throw new RuntimeException('Decision review setup failed: '.json_encode($review));
    $pdo->prepare('UPDATE '.$table.' SET '.$field.'=? WHERE id=?')->execute([$value,$id]);$before=$snapshot($tables);
    try {$expect($commit('entries',$decisionIds,'approve',$review['revision'])[0]===409&&$snapshot($tables)===$before,'Reviewed approval refuses changed '.$table.'.'.$field.' atomically');}
    finally {$pdo->prepare('UPDATE '.$table.' SET '.$field.'=? WHERE id=?')->execute([$original,$id]);}
}
[$status,$review]=$reviewRequest('entries',$decisionIds,'approve');$before=$snapshot($tables);
$pdo->exec("CREATE TRIGGER cf_http_decision_receipt BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900021 AND NEW.event='time.review.decided' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic final decision failure'; END IF; END");
try {$expect($commit('entries',$decisionIds,'approve',$review['revision'])[0]===503&&$snapshot($tables)===$before,'Final decision receipt failure rolls back every entry approval and header projection');}
finally {$pdo->exec('DROP TRIGGER cf_http_decision_receipt');}
[$status,$review]=$reviewRequest('entries',[$decisionIds[0]],'approve');
[$status,$saved]=$commit('entries',[$decisionIds[0]],'approve',$review['revision']);
$expect($status===200&&$saved['succeeded']===1&&$saved['failed']===0&&$saved['entries'][0]['status']==='approved','Reviewed entry approval persists exactly the confirmed decision');
$retained=$read('time_entries',$decisionIds[0]);
[$status,$review]=$reviewRequest('weeks',[$decisionSheet],'reject');$before=$snapshot($tables);
$expect($status===200&&$review['entry_count']===2&&count($review['entries'])===3&&!$review['entries'][0]['will_change'],'Weekly rejection review displays the approved sibling without selecting it for rejection');
$expect($commit('weeks',[$decisionSheet],'reject',$review['revision'],'')[0]===422&&$snapshot($tables)===$before,'Rejection requires a reason before any decision write');
$expect($commit('weeks',[$decisionSheet],'reject',$review['revision'],str_repeat('x',501))[0]===422&&$snapshot($tables)===$before,'Overlong rejection reasons cannot partially change the week');
$extra=$insert('time_entries',['tenant_id'=>$workspace,'person_id'=>$person,'placement_id'=>$placement,'period_id'=>$decisionPeriod,'timesheet_id'=>$decisionSheet,'work_date'=>'2026-10-15','hours'=>1,'status'=>'pending_review','created_by_user_id'=>$creator]);$before=$snapshot($tables);
$expect($commit('weeks',[$decisionSheet],'reject',$review['revision'])[0]===409&&$snapshot($tables)===$before,'A newly added weekly entry invalidates the previously displayed set');
$pdo->prepare('DELETE FROM time_entries WHERE id=?')->execute([$extra]);
[$status,$review]=$reviewRequest('weeks',[$decisionSheet],'reject');$before=$snapshot($tables);
$pdo->exec("CREATE TRIGGER cf_http_decision_receipt BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900021 AND NEW.event='time.timesheet.rejected' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic rejection receipt failure'; END IF; END");
try {$expect($commit('weeks',[$decisionSheet],'reject',$review['revision'])[0]===503&&$snapshot($tables)===$before,'Late rejection receipt failure restores all rows, prior approvals and artifacts');}
finally {$pdo->exec('DROP TRIGGER cf_http_decision_receipt');}
[$status,$saved]=$commit('weeks',[$decisionSheet],'reject',$review['revision']);$header=$read('staffing_timesheets',$decisionSheet);
$expect($status===200&&$saved['rejected']===1&&$header['status']==='rejected'&&(int)$header['rejected_by_user_id']===$actor&&$header['rejection_reason']==='Reviewed source correction','Reviewed weekly rejection records the actual reviewer and reason');
$expect($read('time_entries',$decisionIds[0])===$retained&&$read('time_entries',$decisionIds[1])['status']==='rejected'&&$read('time_entries',$decisionIds[2])['status']==='rejected','Rejection changes the complete pending set and preserves the approved sibling exactly');
$before=$snapshot($tables);$expect($commit('weeks',[$decisionSheet],'reject',$review['revision'])[0]===409&&$snapshot($tables)===$before,'Repeated rejection does not create another decision or receipt');
$expect($snapshot($financialTables)===$financialBefore,'Reviewed entry decisions and weekly rejection create no financial execution');
