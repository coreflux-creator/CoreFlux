<?php
/** Real HTTP sessions exercise both submission paths without financial execution. */
(static function()use($pdo,$tenant,$actor,$request,$expect,$snapshot,$financialTables,$graphTables):void {
    $workspace=900020;$member=null;$api='/modules/time/api/entries.php';$weekly='/modules/staffing/api/timesheets.php?action=submit';
    $insert=static function(string $table,array $row)use($pdo):int {
        $pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return (int)$pdo->lastInsertId();
    };
    $read=static function(string $table,int $id)use($pdo):array {$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC);};
    $tables=array_values(array_unique([...$graphTables,...$financialTables,'time_entries','time_periods','staffing_timesheets','artifact_objects','artifact_events','artifact_relationships','audit_log','quanta_time_imports','core_record_numbers']));
    if($pdo->query('SELECT id FROM tenants WHERE id=900020')->fetchColumn())throw new RuntimeException('Submission fixture already exists');
    $insert('tenants',['id'=>$workspace,'name'=>'Synthetic time submission HTTP','parent_id'=>$tenant,'tenant_type'=>'sub','is_active'=>1,'is_simulation'=>1]);
    try {
        $member=(int)provisionMembership($actor,$workspace,'admin',['is_primary'=>false,'status'=>'active'])['membership_id'];
        foreach(['companies','people','placements','staffing','time']as$module)$insert('tenant_module_scope',['tenant_id'=>$workspace,'module_key'=>$module,'scope_mode'=>'shared']);
        $person=$insert('people',['tenant_id'=>$tenant,'first_name'=>'Synthetic submission','last_name'=>'Worker','email_primary'=>'submission-http@example.invalid','classification'=>'w2','status'=>'active']);
        $placement=$insert('placements',['tenant_id'=>$tenant,'person_id'=>$person,'title'=>'Submission assignment','status'=>'active','engagement_type'=>'w2','start_date'=>'2026-10-01','end_date'=>'2026-12-31','worksite_country'=>'US']);
        $rate=$insert('placement_rates',['tenant_id'=>$tenant,'placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>60,'approved_at'=>'2026-10-01 12:00:00']);
        RBACResolver::grantModule($member,'time','write',[$workspace],$actor);
        RBACResolver::grantModule($member,'staffing','admin',[$workspace],$actor);
        $body=['placement_id'=>$placement,'work_date'=>'2026-10-05','category'=>'regular_billable','hours'=>8];
        [$status,$created]=$request('POST',$api.'?action=bulk_create',['entries'=>[$body,array_replace($body,['work_date'=>'2026-10-06'])]],true,$workspace);
        $expect($status===201&&$created['created']===2,'Submission setup creates two native draft entries');
        $ids=$created['entry_ids'];$first=$read('time_entries',$ids[0]);$second=$read('time_entries',$ids[1]);$header=(int)$first['timesheet_id'];$period=(int)$first['period_id'];
        $week=['person_id'=>$person,'period_start'=>'2026-10-05','period_end'=>'2026-10-11'];
        $paths=[[$api.'?action=bulk_submit',['ids'=>$ids]],[$weekly,$week]];
        $before=$snapshot($tables);
        $expect($request('POST',$paths[0][0],$paths[0][1],false,$workspace)[0]===401&&$snapshot($tables)===$before,'Submission requires a native signed-in session');
        foreach([[$ids[0],$ids[1].'junk'],[$ids[0],0],[$ids[0],true]]as$invalid) {
            $expect($request('POST',$paths[0][0],['ids'=>$invalid],true,$workspace)[0]===422&&$snapshot($tables)===$before,'Malformed submission IDs cannot silently select or skip records');
        }
        // Every committed dependency change must refuse both routes without partial submission.
        foreach([
            ['people',$person,'deleted_at','2026-10-07 12:00:00',null],
            ['placements',$placement,'deleted_at','2026-10-07 12:00:00',null],
            ['placements',$placement,'actual_end_date','2026-10-05',null],
            ['time_periods',$period,'status','locked','open'],
            ['time_entries',$ids[1],'work_date','2026-10-12','2026-10-06'],
            ['time_entries',$ids[1],'hours',25,8],
            ['time_entries',$ids[1],'payroll_extracted_ref','synthetic-held-reference',null],
            ['staffing_timesheets',$header,'status','locked','draft'],
        ]as[$table,$id,$field,$changed,$original]) {
            $pdo->prepare('UPDATE '.$table.' SET '.$field.'=? WHERE id=?')->execute([$changed,$id]);$before=$snapshot($tables);
            try {foreach($paths as[$path,$payload]) {
                [$status,$result]=$request('POST',$path,$payload,true,$workspace);
                $expect(in_array($status,[404,409,422],true)&&$snapshot($tables)===$before,'Both submission routes retain all rows after '.$table.'.'.$field.' changes: '.json_encode([$status,$result]));
            }} finally {$pdo->prepare('UPDATE '.$table.' SET '.$field.'=? WHERE id=?')->execute([$original,$id]);}
        }
        foreach(['people','placements']as$module) {
            $pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key=?')->execute([$workspace,$module]);$before=$snapshot($tables);
            try {foreach($paths as[$path,$payload])$expect(in_array($request('POST',$path,$payload,true,$workspace)[0],[404,409,422],true)&&$snapshot($tables)===$before,'Submission uses current '.$module.' directory rather than a prior shared owner');}
            finally {$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="shared" WHERE tenant_id=? AND module_key=?')->execute([$workspace,$module]);}
        }
        foreach(['time.entry.submitted','time.timesheet.submitted']as$event) {
            $before=$snapshot($tables);
            $pdo->exec("CREATE TRIGGER cf_http_submission_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900020 AND NEW.event=".$pdo->quote($event)." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic private submission receipt failure'; END IF; END");
            try {foreach($paths as[$path,$payload]) {
                [$status,$result]=$request('POST',$path,$payload,true,$workspace);
                $expect($status===503&&!str_contains(json_encode($result),'SQLSTATE')&&$snapshot($tables)===$before,'Required '.$event.' failure rolls back entries, header, artifacts and earlier receipts');
            }} finally {$pdo->exec('DROP TRIGGER cf_http_submission_audit');}
        }
        $financialBefore=$snapshot($financialTables);
        [$status,$submitted]=$request('POST',$paths[0][0],$paths[0][1],true,$workspace);
        $expect($status===200&&$submitted['submitted']===2&&$submitted['workspace_id']===$workspace&&$read('staffing_timesheets',$header)['status']==='submitted','Generic bulk submission updates its original child header and reports the exact submitted set');
        foreach([$first,$second]as$original) {
            $current=$read('time_entries',(int)$original['id']);unset($current['status'],$original['status'],$current['updated_at'],$original['updated_at']);
            $expect($current===$original,'Submission preserves original hours, source, placement, person and approval evidence');
        }
        $before=$snapshot($tables);
        $expect($request('POST',$paths[0][0],$paths[0][1],true,$workspace)[0]===409&&$snapshot($tables)===$before,'Submitting the same reviewed set twice cannot duplicate receipts');
        // A rejected row may be resubmitted with an independently approved sibling.
        $pdo->prepare('UPDATE time_entries SET status="approved",approved_by_user_id=?,approved_at="2026-10-12 10:00:00",rate_snapshot_id=? WHERE id=?')->execute([$actor,$rate,$ids[0]]);
        $pdo->prepare('UPDATE time_entries SET status="rejected",rejected_reason="synthetic review",source="quanta",source_system="quanta" WHERE id=?')->execute([$ids[1]]);
        $pdo->prepare('UPDATE staffing_timesheets SET status="rejected",rejected_by_user_id=?,rejected_at="2026-10-12 10:00:00",rejection_reason="synthetic review" WHERE id=?')->execute([$actor,$header]);
        $approved=$read('time_entries',$ids[0]);$source=$read('time_entries',$ids[1]);
        [$status,$resubmitted]=$request('POST',$weekly,$week,true,$workspace);
        $changed=$read('time_entries',$ids[1]);$newHeader=$read('staffing_timesheets',$header);
        $expect($status===200&&$newHeader['status']==='submitted'&&$newHeader['rejection_reason']===null&&$read('time_entries',$ids[0])===$approved,'Weekly resubmission clears its rejection while retaining the approved sibling exactly');
        unset($source['status'],$source['rejected_reason'],$changed['status'],$changed['rejected_reason'],$source['updated_at'],$changed['updated_at']);
        $expect($changed===$source,'Resubmitting rejected Quanta time preserves its complete source facts');
        // The read grant permits self-submission only, using current persisted identity.
        $pdo->prepare('UPDATE time_entries SET status="draft",approved_at=NULL,approved_by_user_id=NULL,rate_snapshot_id=NULL WHERE id IN (?,?)')->execute($ids);
        $pdo->prepare('UPDATE staffing_timesheets SET status="draft" WHERE id=?')->execute([$header]);
        RBACResolver::grantModule($member,'time','read',[$workspace],$actor);
        RBACResolver::grantModule($member,'staffing','write',[$workspace],$actor);
        $before=$snapshot($tables);
        foreach($paths as[$path,$payload])$expect($request('POST',$path,$payload,true,$workspace)[0]===403&&$snapshot($tables)===$before,'Self-service permission cannot submit another worker’s time');
        $q=$pdo->prepare('SELECT email FROM users WHERE id=?');$q->execute([$actor]);$accountEmail=$q->fetchColumn();
        $pdo->prepare('UPDATE people SET email_primary=? WHERE id=?')->execute([$accountEmail,$person]);
        [$status,$own]=$request('POST',$paths[0][0],$paths[0][1],true,$workspace);
        $expect($status===200&&$own['submitted']===2,'Self-service submission resolves the current account in the shared people directory');
        $pdo->prepare('UPDATE time_entries SET status="draft" WHERE id IN (?,?)')->execute($ids);
        $pdo->prepare('UPDATE staffing_timesheets SET status="draft" WHERE id=?')->execute([$header]);
        [$status,$ownWeek]=$request('POST',$weekly,$week,true,$workspace);
        $expect($status===200&&$ownWeek['timesheet']['status']==='submitted','The same self-service identity can submit its week');
        $expect($snapshot($financialTables)===$financialBefore,'Submission and resubmission create no financial event, posting, payment or payroll run');
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS cf_http_submission_audit');
        if($member){$pdo->prepare('DELETE FROM membership_module_access WHERE membership_id=?')->execute([$member]);deactivateMembership($actor,$workspace);}
        $pdo->prepare('UPDATE tenants SET is_active=0 WHERE id=?')->execute([$workspace]);RBACResolver::resetCache();
    }
})();
