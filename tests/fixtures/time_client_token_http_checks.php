<?php
/** Public bearer links use real native HTTP and the same disposable original workspace. */
require_once __DIR__.'/../../modules/time/lib/client_token_review.php';
$tokenTables=array_values(array_unique([...$tables,'time_approval_tokens']));
$clientEmail='independent-client@example.invalid';
$pdo->prepare('UPDATE placements SET tokenized_email_approval_enabled=1,client_approver_email=? WHERE id=?')->execute([$clientEmail,$placement]);
$newTokenSet=static function(string $start,string $end)use($insert,$workspace,$person,$placement,$creator):array {
    $p=$insert('time_periods',['tenant_id'=>$workspace,'start_date'=>$start,'end_date'=>$end,'label'=>'Synthetic client link']);
    $s=$insert('staffing_timesheets',['tenant_id'=>$workspace,'person_id'=>$person,'period_start'=>$start,'period_end'=>$end,'status'=>'submitted','total_hours'=>4]);
    $ids=[];
    foreach([$start,date('Y-m-d',strtotime($start.' +1 day'))]as$date)$ids[]=$insert('time_entries',['tenant_id'=>$workspace,'person_id'=>$person,'placement_id'=>$placement,'period_id'=>$p,'timesheet_id'=>$s,'work_date'=>$date,'hours'=>2,'hour_type'=>'regular','category'=>'regular_billable','status'=>'pending_review','created_by_user_id'=>$creator]);
    return [$p,$s,$ids];
};
[$clientPeriod,$clientSheet,$clientIds]=$newTokenSet('2026-10-19','2026-10-25');
$tokenPath='/modules/time/api/approval_tokens.php';
$issueBody=['placement_id'=>$placement,'period_id'=>$clientPeriod,'entry_ids'=>$clientIds];
$before=$snapshot($tokenTables);
$expect($nativeRequest('POST',$tokenPath.'?action=issue',$issueBody,false,$workspace)[0]===401&&$snapshot($tokenTables)===$before,'Client token issuance requires native authentication');
RBACResolver::grantModule($member,'time','read',[$workspace],$actor);
$expect($nativeRequest('POST',$tokenPath.'?action=issue',$issueBody,true,$workspace)[0]===403&&$snapshot($tokenTables)===$before,'Time readers cannot issue client approval credentials');
RBACResolver::grantModule($member,'time','admin',[$workspace],$actor);
$expect($nativeRequest('POST',$tokenPath.'?action=issue',['entry_ids'=>[$clientIds[0],'bad']]+$issueBody,true,$workspace)[0]===422&&$snapshot($tokenTables)===$before,'Client issuance refuses malformed selection members without writes');
foreach(['approval-worker@example.invalid','approval-preparer@example.invalid']as$selfEmail) {
    $pdo->prepare('UPDATE placements SET client_approver_email=? WHERE id=?')->execute([$selfEmail,$placement]);$before=$snapshot($tokenTables);
    $expect($nativeRequest('POST',$tokenPath.'?action=issue',$issueBody,true,$workspace)[0]===403&&$snapshot($tokenTables)===$before,'Client credential cannot be issued to its own '.($selfEmail==='approval-worker@example.invalid'?'worker':'preparer'));
}
$pdo->prepare('UPDATE placements SET client_approver_email=? WHERE id=?')->execute([$clientEmail,$placement]);
$before=$snapshot($tokenTables);
$pdo->exec("CREATE TRIGGER cf_http_client_receipt BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900021 AND NEW.event='time.tokenized_email.issued' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic client issue receipt failure'; END IF; END");
try {$expect($nativeRequest('POST',$tokenPath.'?action=issue',$issueBody,true,$workspace)[0]===503&&$snapshot($tokenTables)===$before,'Client issue receipt failure leaves no usable credential or time change');}
finally {$pdo->exec('DROP TRIGGER cf_http_client_receipt');}
[$status,$apiIssued]=$nativeRequest('POST',$tokenPath.'?action=issue',$issueBody,true,$workspace);
$expect($status===202&&$apiIssued['email_status']==='failed'&&$read('time_approval_tokens',$apiIssued['token_id'])['email_status']==='failed','Native client issuance persists its complete set and does not call log-only mail delivered');
$issued=timeClientTokenIssue($workspace,$actor,$placement,$clientPeriod,$clientIds);
$tokenRow=$read('time_approval_tokens',$issued['token_id']);
$expect($tokenRow['token']!==$issued['raw']&&str_starts_with($tokenRow['token'],'sha256:')&&$tokenRow['token_hash']===timeTokenHash($issued['raw']),'Issued client secret is hashed in both token storage columns');
$clientReview=static fn(array $issued,string $choice='approve')=>$nativeRequest('GET',$tokenPath.'?action=verify&t='.$issued['raw'].'&a='.$choice,null,false,$workspace);
$clientRespond=static fn(array $issued,string $choice,?string $revision,string $note='Client requested correction',bool $signed=false)=>$nativeRequest('POST',$tokenPath.'?action=respond',['t'=>$issued['raw'],'action'=>$choice,'review_revision'=>$revision,'note'=>$note],$signed,$tenant);
$incompleteSibling=$insert('time_entries',['tenant_id'=>$workspace,'person_id'=>$person,'placement_id'=>$placement,'period_id'=>$clientPeriod,'timesheet_id'=>$clientSheet,'work_date'=>'2026-10-21','hours'=>1,'hour_type'=>'regular','category'=>'regular_billable','status'=>'approved','created_by_user_id'=>$creator]);$before=$snapshot($tokenTables);
$expect($clientReview($issued)[0]===409&&$snapshot($tokenTables)===$before,'Client completion refuses incomplete approval evidence on an unselected sibling');
$expect($nativeRequest('POST','/modules/time/api/entries.php?action=decision_review',['ids'=>$clientIds,'decision'=>'approve'],true,$workspace)[0]===409&&$snapshot($tokenTables)===$before,'Native complete-set review also refuses incomplete prior sibling evidence');
$pdo->prepare('DELETE FROM time_entries WHERE id=?')->execute([$incompleteSibling]);
$before=$snapshot($tokenTables);[$status,$view]=$clientReview($issued);
$expect($status===200&&$view['workspace_id']===$workspace&&count($view['entries'])===2&&strlen($view['revision'])===64&&$snapshot($tokenTables)===$before,'Client preview reads the complete original-workspace selection without writes');
$expect(str_starts_with($view['placement']['person_number'],'P-')&&str_starts_with($view['placement']['placement_number'],'PL-')&&!str_contains(json_encode($view),'pay_rate')&&!str_contains(json_encode($view),'bill_rate'),'Client review exposes stable identities and hours without internal rates');
$pageCurl=curl_init('http://127.0.0.1:'.getenv('COREFLUX_CONTRACT_HTTP_PORT').'/time_approve.php?t='.$issued['raw'].'&a=approve');
curl_setopt_array($pageCurl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);$pageHtml=(string)curl_exec($pageCurl);$pageStatus=curl_getinfo($pageCurl,CURLINFO_HTTP_CODE);curl_close($pageCurl);
$expect($pageStatus===200&&str_contains($pageHtml,$view['revision'])&&str_contains($pageHtml,$view['placement']['person_number'])&&str_contains($pageHtml,$view['placement']['placement_number'])&&str_contains($pageHtml,'Confirm approval')&&$snapshot($tokenTables)===$before,'Public client page renders the actual scoped identities and exact review without GET writes');
$expect($clientRespond($issued,'approve',null)[0]===409&&$snapshot($tokenTables)===$before,'Client response requires its displayed revision');
$expect($clientRespond($issued,'reject',$view['revision'])[0]===409&&$snapshot($tokenTables)===$before,'Client review cannot authorize an opposite decision');
$expect($nativeRequest('GET',$tokenPath.'?action=verify&t='.str_repeat('0',64),null,false,$workspace)[0]===404&&$snapshot($tokenTables)===$before,'Unknown client bearer cannot read any time');
$pdo->prepare('UPDATE placement_rates SET pay_rate=61 WHERE id=?')->execute([$rate]);$before=$snapshot($tokenTables);
$expect($clientRespond($issued,'approve',$view['revision'])[0]===409&&$snapshot($tokenTables)===$before,'Client approval refuses rates changed since its displayed review');
$pdo->prepare('UPDATE placement_rates SET pay_rate=60 WHERE id=?')->execute([$rate]);
foreach([['time_entries',$clientIds[0],'hours',3],['time_entries',$clientIds[1],'status','approved'],['time_periods',$clientPeriod,'status','locked'],['placements',$placement,'client_approver_email','other-client@example.invalid']]as[$table,$id,$field,$changed]) {
    $original=$read($table,$id);$trial=timeClientTokenIssue($workspace,$actor,$placement,$clientPeriod,$clientIds);[$status,$trialView]=$clientReview($trial);
    $pdo->prepare('UPDATE '.$table.' SET '.$field.'=? WHERE id=?')->execute([$changed,$id]);$before=$snapshot($tokenTables);
    try {$expect($clientRespond($trial,'approve',$trialView['revision'])[0]===409&&$snapshot($tokenTables)===$before,'Client issued set refuses changed '.$table.'.'.$field.' without consuming its credential');}
    finally {$columns=array_keys($original);$pdo->prepare('UPDATE '.$table.' SET '.implode(',',array_map(static fn($c)=>'`'.$c.'`=?',$columns)).' WHERE id=?')->execute([...array_values($original),$id]);}
}
$oldBasis=$tokenRow['entries_json'];$pdo->prepare('UPDATE time_approval_tokens SET entries_json=? WHERE id=?')->execute([json_encode(['entry_ids'=>$clientIds]),$issued['token_id']]);$before=$snapshot($tokenTables);
$expect($clientReview($issued)[0]===409&&$snapshot($tokenTables)===$before,'Legacy client links without issued facts require a fresh request');
$pdo->prepare('UPDATE time_approval_tokens SET entries_json=? WHERE id=?')->execute([$oldBasis,$issued['token_id']]);
$expiring=timeClientTokenIssue($workspace,$actor,$placement,$clientPeriod,$clientIds);$pdo->prepare('UPDATE time_approval_tokens SET expires_at="2000-01-01" WHERE id=?')->execute([$expiring['token_id']]);$before=$snapshot($tokenTables);
[$status,$expired]=$clientReview($expiring);$expect($status===200&&$expired['status']==='expired'&&!isset($expired['entries'])&&$snapshot($tokenTables)===$before,'Expired client preview reveals no entries and never mutates token state');
$revoking=timeClientTokenIssue($workspace,$actor,$placement,$clientPeriod,$clientIds);[$status,$revokeView]=$clientReview($revoking);
$before=$snapshot($tokenTables);$expect($nativeRequest('POST',$tokenPath.'?action=revoke&id='.$revoking['token_id'],[],true,$tenant)[0]===403&&$snapshot($tokenTables)===$before,'A parent-workspace user without time administration cannot revoke client links');
$parentMemberQuery=$pdo->prepare('SELECT id FROM tenant_memberships WHERE user_id=? AND tenant_id=?');$parentMemberQuery->execute([$actor,$tenant]);$parentMember=(int)$parentMemberQuery->fetchColumn();
$parentAccessQuery=$pdo->prepare('SELECT * FROM membership_module_access WHERE membership_id=? AND module_key="time"');$parentAccessQuery->execute([$parentMember]);$parentAccess=$parentAccessQuery->fetch(PDO::FETCH_ASSOC);
try {
    RBACResolver::grantModule($parentMember,'time','admin',[$tenant],$actor);$before=$snapshot($tokenTables);
    $expect($nativeRequest('POST',$tokenPath.'?action=revoke&id='.$revoking['token_id'],[],true,$tenant)[0]===404&&$snapshot($tokenTables)===$before,'Even an authorized parent-workspace time administrator cannot revoke a child-workspace link');
} finally {
    $pdo->prepare('DELETE FROM membership_module_access WHERE membership_id=? AND module_key="time"')->execute([$parentMember]);
    if($parentAccess)$pdo->prepare('INSERT INTO membership_module_access (`'.implode('`,`',array_keys($parentAccess)).'`) VALUES('.implode(',',array_fill(0,count($parentAccess),'?')).')')->execute(array_values($parentAccess));
    RBACResolver::resetCache();
}
$before=$snapshot($tokenTables);
$pdo->exec("CREATE TRIGGER cf_http_client_receipt BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900021 AND NEW.event='time.tokenized_email.revoked' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic revoke receipt failure'; END IF; END");
try {$expect($nativeRequest('POST',$tokenPath.'?action=revoke&id='.$revoking['token_id'],[],true,$workspace)[0]===503&&$snapshot($tokenTables)===$before,'Revocation receipt failure preserves the pending credential');}
finally {$pdo->exec('DROP TRIGGER cf_http_client_receipt');}
$expect($nativeRequest('POST',$tokenPath.'?action=revoke&id='.$revoking['token_id'],[],true,$workspace)[0]===200,'Original workspace can revoke its pending client link');
$before=$snapshot($tokenTables);$expect($clientRespond($revoking,'approve',$revokeView['revision'])[0]===409&&$snapshot($tokenTables)===$before,'A revoked credential cannot use its earlier review');
$issued=timeClientTokenIssue($workspace,$actor,$placement,$clientPeriod,$clientIds);[$status,$view]=$clientReview($issued);$before=$snapshot($tokenTables);
$pdo->exec("CREATE TRIGGER cf_http_client_receipt BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900021 AND NEW.event='time.tokenized_email.responded' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic final client receipt failure'; END IF; END");
try {$expect($clientRespond($issued,'approve',$view['revision'])[0]===503&&$snapshot($tokenTables)===$before,'Client receipt failure restores token, complete entries, weekly header and audit');}
finally {$pdo->exec('DROP TRIGGER cf_http_client_receipt');}
[$status,$saved]=$clientRespond($issued,'approve',$view['revision'],'Client reviewed complete set',true);
$first=$read('time_entries',$clientIds[0]);$second=$read('time_entries',$clientIds[1]);
$expect($status===200&&$saved['entries_decided']===2&&$first['status']==='approved'&&$second['status']==='approved'&&$first['approved_by_user_id']===null&&$first['client_approver_email']===$clientEmail&&$first['dimension_snapshot_hash']!==null&&$read('staffing_timesheets',$clientSheet)['status']==='approved','Client approval records actual external actor and frozen dimensions despite another signed-in workspace');
$before=$snapshot($tokenTables);$expect($clientRespond($issued,'approve',$view['revision'])[0]===409&&$snapshot($tokenTables)===$before,'Repeated client approval never duplicates receipts');
[$status,$terminal]=$clientReview($issued);$expect($terminal['status']==='approved'&&!isset($terminal['entries'])&&$snapshot($tokenTables)===$before,'Used client link reports terminal status without exposing current time');
[$rejectPeriod,$rejectSheet,$rejectIds]=$newTokenSet('2026-10-26','2026-11-01');
$rejecting=timeClientTokenIssue($workspace,$actor,$placement,$rejectPeriod,$rejectIds);[$status,$rejectView]=$clientReview($rejecting,'reject');$before=$snapshot($tokenTables);
$expect($clientRespond($rejecting,'reject',$rejectView['revision'],'')[0]===422&&$snapshot($tokenTables)===$before,'Client rejection needs an explicit reason');
$pdo->exec("CREATE TRIGGER cf_http_client_receipt BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900021 AND NEW.event='time.timesheet.rejected' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic client rejection receipt failure'; END IF; END");
try {$expect($clientRespond($rejecting,'reject',$rejectView['revision'])[0]===503&&$snapshot($tokenTables)===$before,'Client rejection receipt failure rolls back the complete decision');}
finally {$pdo->exec('DROP TRIGGER cf_http_client_receipt');}
[$status,$saved]=$clientRespond($rejecting,'reject',$rejectView['revision']);$header=$read('staffing_timesheets',$rejectSheet);
$expect($status===200&&$saved['entries_decided']===2&&$header['status']==='rejected'&&$header['rejected_by_user_id']===null&&$header['rejection_reason']==='Client requested correction','Client rejection updates the whole issued set without inventing a user actor');
$expect($snapshot($financialTables)===$financialBefore,'Client approval and rejection create no financial execution');
