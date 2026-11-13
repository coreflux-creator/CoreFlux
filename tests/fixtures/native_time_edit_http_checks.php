<?php
/** Real session edits with shared catalogs and separate original time workspaces. */
(static function()use($pdo,$tenant,$actor,$foreign,$request,$expect,$snapshot,$graphTables,$financialTables):void {
    $workspace=900018;$member=null;$api='/modules/staffing/api/timesheets.php?action=';
    if($pdo->query('SELECT id FROM tenants WHERE id=900018')->fetchColumn())throw new RuntimeException('Native time fixture workspace exists');
    $insert=static function(string $table,array $row)use($pdo):int {
        $pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
        return(int)$pdo->lastInsertId();
    };
    $read=static function(string $table,int $id)use($pdo):?array {
        $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    };
    $tables=array_values(array_unique([...$graphTables,...$financialTables,'core_record_numbers','time_entries','time_periods','staffing_timesheets','artifact_objects','artifact_events','artifact_relationships','audit_log','quanta_time_imports']));
    $insert('tenants',['id'=>$workspace,'name'=>'Synthetic native time HTTP','parent_id'=>$tenant,'tenant_type'=>'sub','is_active'=>1,'is_simulation'=>1]);
    try {
        $member=(int)provisionMembership($actor,$workspace,'admin',['is_primary'=>false,'status'=>'active'])['membership_id'];
        foreach(['companies','people','placements','staffing']as$module)$insert('tenant_module_scope',['tenant_id'=>$workspace,'module_key'=>$module,'scope_mode'=>'shared']);
        $person=$insert('people',['tenant_id'=>$tenant,'first_name'=>'Synthetic time','last_name'=>'Original workspace','email_primary'=>'native-time@example.invalid','classification'=>'w2','status'=>'active']);
        $placement=$insert('placements',['tenant_id'=>$tenant,'person_id'=>$person,'title'=>'Synthetic editable time','status'=>'active','engagement_type'=>'w2','start_date'=>'2026-10-01','end_date'=>'2026-12-31','worksite_country'=>'US']);
        $ended=$insert('placements',['tenant_id'=>$tenant,'person_id'=>$person,'title'=>'Synthetic actually ended','status'=>'ended','engagement_type'=>'w2','start_date'=>'2026-10-01','end_date'=>'2026-12-31','actual_end_date'=>'2026-10-04','worksite_country'=>'US']);
        $period=$insert('time_periods',['tenant_id'=>$workspace,'start_date'=>'2026-10-05','end_date'=>'2026-10-11','label'=>'Original native time week']);
        $q=$pdo->prepare('SELECT id FROM time_periods WHERE tenant_id=? AND start_date="2026-10-05" AND end_date="2026-10-11"');$q->execute([$tenant]);
        $parentPeriod=(int)$q->fetchColumn()?:$insert('time_periods',['tenant_id'=>$tenant,'start_date'=>'2026-10-05','end_date'=>'2026-10-11','label'=>'Parent catalog native time week']);
        $sheet=$insert('staffing_timesheets',['tenant_id'=>$workspace,'person_id'=>$person,'period_start'=>'2026-10-05','period_end'=>'2026-10-11','status'=>'submitted','submitted_at'=>'2026-10-12 12:00:00','total_hours'=>10]);
        $parentSheet=$insert('staffing_timesheets',['tenant_id'=>$tenant,'person_id'=>$person,'period_start'=>'2026-10-05','period_end'=>'2026-10-11','status'=>'submitted','total_hours'=>19]);
        $entry=static function(int $owner,int $header,int $timePeriod,float $hours,string $status,string $day='2026-10-05')use($insert,$placement,$person,$actor):int {
            return $insert('time_entries',['tenant_id'=>$owner,'timesheet_id'=>$header,'period_id'=>$timePeriod,'placement_id'=>$placement,'person_id'=>$person,'work_date'=>$day,'category'=>'regular_billable','hour_type'=>'regular','hours'=>$hours,'status'=>$status,
                'approved_at'=>$status==='approved'?'2026-10-12 11:00:00':null,'approved_by_user_id'=>$status==='approved'?$actor:null]);
        };
        $pending=$entry($workspace,$sheet,$period,8,'pending_review');$approved=$entry($workspace,$sheet,$period,2,'approved');
        $parentEntry=$entry($tenant,$parentSheet,$parentPeriod,19,'pending_review');
        $parentRows=[$read('staffing_timesheets',$parentSheet),$read('time_entries',$parentEntry),$read('time_periods',$parentPeriod)];
        $approvedRow=$read('time_entries',$approved);$protected=$snapshot([...$graphTables,...$financialTables]);
        $week=$api.'week&person_id='.$person.'&period_start=2026-10-05&period_end=2026-10-11';
        $before=$snapshot($tables);
        $expect($request('POST',$api.'entry_save',['id'=>$pending,'hours'=>7],false,$workspace)[0]===401,'Native time editing requires sign-in');
        $expect($request('POST',$api.'entry_save',['id'=>$pending,'hours'=>7],true,$workspace)[0]===403,'Native time editing requires a staffing grant');
        RBACResolver::grantModule($member,'staffing','write',[$workspace],$actor);
        $expect($request('POST',$api.'entry_save',['id'=>$pending,'hours'=>7],true,$workspace)[0]===403,'General time writing cannot edit another worker without review access');
        $expect($snapshot($tables)===$before,'Native time permission failures preserve original records and receipts');
        RBACResolver::grantModule($member,'staffing','admin',[$workspace],$actor);
        [$status,$weekData]=$request('GET',$week,null,true,$workspace);
        $expect($status===200&&(int)$weekData['timesheet']['id']===$sheet&&count($weekData['entries'])===2,'Native week reads original workspace time despite shared staffing catalog');
        foreach(['locked','closed']as$periodStatus) {
            $pdo->prepare('UPDATE time_periods SET status=? WHERE id=?')->execute([$periodStatus,$period]);$before=$snapshot($tables);
            foreach([['entry_save',['id'=>$pending,'hours'=>7]],['entry_delete',['id'=>$pending]],['reopen',['id'=>$sheet]],['entry_save',['timesheet_id'=>$sheet,'placement_id'=>$placement,'work_date'=>'2026-10-06','hours'=>1]]]as[$action,$body])
                $expect($request('POST',$api.$action,$body,true,$workspace)[0]===409&&$snapshot($tables)===$before,'Native time respects the '.$periodStatus.' original period: '.$action);
            $pdo->prepare('UPDATE time_periods SET status="open" WHERE id=?')->execute([$period]);
        }
        foreach([
            ['placement_id'=>$foreign['placement']],['placement_id'=>$ended],['work_date'=>'2026-10-12'],['hours'=>23],['hours'=>'invalid'],['timesheet_id'=>$parentSheet],
        ]as$invalid) {
            $before=$snapshot($tables);[$status]=$request('POST',$api.'entry_save',['id'=>$pending,...$invalid],true,$workspace);
            $expect(in_array($status,[400,404,409,422],true)&&$snapshot($tables)===$before,'Invalid inline edit leaves submitted header, entries and artifacts unchanged: '.json_encode($invalid));
        }
        $before=$snapshot($tables);
        $expect($request('POST',$api.'entry_delete',['id'=>$parentEntry],true,$workspace)[0]===404&&$snapshot($tables)===$before,'Original workspace cannot delete a parent catalog time entry');
        // Failure after all time writes must restore both the reopened header and its artifact lifecycle.
        $pdo->exec("CREATE TRIGGER cf_http_native_time_receipt BEFORE INSERT ON artifact_events FOR EACH ROW BEGIN IF NEW.tenant_id=900018 AND NEW.event_type='timesheet.entry_saved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic private receipt error'; END IF; END");
        try {
            [$status,$failure]=$request('POST',$api.'entry_save',['id'=>$pending,'hours'=>7],true,$workspace);
            $expect($status===503&&!str_contains(json_encode($failure),'SQLSTATE')&&!str_contains(json_encode($failure),'Synthetic private')&&$snapshot($tables)===$before,'Late native time receipt failure rolls back every change and hides database details');
        } finally {$pdo->exec('DROP TRIGGER cf_http_native_time_receipt');}
        [$status,$saved]=$request('POST',$api.'entry_save',['id'=>$pending,'hours'=>7],true,$workspace);
        $expect($status===200&&(float)$saved['entry']['hours']===7.0&&$saved['timesheet']['status']==='draft'&&(float)$saved['timesheet']['total_hours']===9.0,'Valid native edit reopens only the original submitted timesheet');
        $expect($read('time_entries',$approved)===$approvedRow&&[$read('staffing_timesheets',$parentSheet),$read('time_entries',$parentEntry),$read('time_periods',$parentPeriod)]===$parentRows,'Approved sibling and parent workspace records remain byte-for-byte unchanged');
        $q=$pdo->prepare("SELECT payload FROM artifact_events WHERE tenant_id=? AND event_type='timesheet.entry_saved' ORDER BY id DESC LIMIT 1");$q->execute([$workspace]);$receipt=json_decode((string)$q->fetchColumn(),true);
        $expect((int)$receipt['before']['id']===$pending&&(float)$receipt['before']['hours']===8.0&&(float)$receipt['after']['hours']===7.0,'Successful native edit retains full before and after facts in its required receipt');
        $superseded=$entry($workspace,$sheet,$period,3,'superseded','2026-10-06');
        foreach([$approved,$superseded]as$id)foreach(['entry_save','entry_delete']as$action) {
            $before=$snapshot($tables);
            $expect($request('POST',$api.$action,['id'=>$id,'hours'=>1],true,$workspace)[0]===409&&$snapshot($tables)===$before,'Draft header cannot authorize editing or deleting approved/superseded time: '.$action.' '.$id);
        }
        $imported=$entry($workspace,$sheet,$period,4,'pending_review','2026-10-06');
        $importId=$insert('quanta_time_imports',['tenant_id'=>$workspace,'quanta_entry_id'=>'synthetic-native-time','component'=>'regular','time_entry_id'=>$imported,'timesheet_id'=>$sheet,'source_hash'=>str_repeat('a',64),'source_timesheet_status'=>'approved']);
        foreach(['entry_save','entry_delete']as$action) {
            $before=$snapshot($tables);
            $expect($request('POST',$api.$action,['id'=>$imported,'hours'=>3],true,$workspace)[0]===409&&$snapshot($tables)===$before,'Imported Quanta time keeps its source correction workflow: '.$action);
        }
        $newWeek=['person_id'=>$person,'period_start'=>'2026-10-12','period_end'=>'2026-10-18','rows'=>[['placement_id'=>$foreign['placement'],'work_date'=>'2026-10-12','hours'=>1]]];
        $before=$snapshot($tables);
        $expect($request('POST',$api.'bulk_save',$newWeek,true,$workspace)[0]===404&&$snapshot($tables)===$before,'Failed new-week save leaves no header, period or orphan artifact');
        $batch=['person_id'=>$person,'period_start'=>'2026-10-05','period_end'=>'2026-10-11','rows'=>[['id'=>$pending,'placement_id'=>$placement,'work_date'=>'2026-10-05','hours'=>6],['id'=>$approved,'placement_id'=>$placement,'work_date'=>'2026-10-05','hours'=>1]]];
        $expect($request('POST',$api.'bulk_save',$batch,true,$workspace)[0]===409&&$snapshot($tables)===$before,'Invalid second weekly row rolls back the earlier row and its total');
        [$status,$partial]=$request('POST',$api.'entries_bulk_save',['rows'=>[['id'=>$pending,'hours'=>6],['id'=>$approved,'hours'=>1]]],true,$workspace);
        $expect($status===200&&$partial['saved']===1&&count($partial['errors'])===1&&(float)$read('time_entries',$pending)['hours']===6.0&&$read('time_entries',$approved)===$approvedRow,'Per-entry batch reports the saved row and refused approved row separately');
        foreach(['people','placements']as$module) {
            $pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key=?')->execute([$workspace,$module]);$before=$snapshot($tables);
            $expect(in_array($request('POST',$api.'entry_save',['id'=>$pending,'hours'=>5],true,$workspace)[0],[400,404,409],true)&&$snapshot($tables)===$before,'Same cookie edit uses fresh '.$module.' ownership');
            $pdo->prepare('UPDATE tenant_module_scope SET scope_mode="shared" WHERE tenant_id=? AND module_key=?')->execute([$workspace,$module]);
        }
        $placementBefore=$read('placements',$placement);
        $pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$placement]);$before=$snapshot($tables);
        $expect($request('POST',$api.'entry_delete',['id'=>$pending],true,$workspace)[0]===404&&$snapshot($tables)===$before,'Archived placement time cannot be silently removed');
        $pdo->prepare('UPDATE placements SET deleted_at=NULL,updated_at=? WHERE id=?')->execute([$placementBefore['updated_at'],$placement]);
        $before=$snapshot($tables);
        $pdo->exec("CREATE TRIGGER cf_http_native_time_receipt BEFORE INSERT ON artifact_events FOR EACH ROW BEGIN IF NEW.tenant_id=900018 AND NEW.event_type='timesheet.entry_deleted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic private receipt error'; END IF; END");
        try {$expect($request('POST',$api.'entry_delete',['id'=>$pending],true,$workspace)[0]===503&&$snapshot($tables)===$before,'Late delete receipt failure restores the entry and header total');}
        finally {$pdo->exec('DROP TRIGGER cf_http_native_time_receipt');}
        [$status,$deleted]=$request('POST',$api.'entry_delete',['id'=>$pending],true,$workspace);
        $expect($status===200&&$read('time_entries',$pending)===null&&(float)$deleted['timesheet']['total_hours']===6.0&&$read('time_entries',$approved)===$approvedRow,'Native deletion changes only editable time and recomputes non-superseded totals');
        $q=$pdo->prepare("SELECT payload FROM artifact_events WHERE tenant_id=? AND event_type='timesheet.entry_deleted' ORDER BY id DESC LIMIT 1");$q->execute([$workspace]);$receipt=json_decode((string)$q->fetchColumn(),true);
        $expect((int)$receipt['before']['id']===$pending&&(float)$receipt['before']['hours']===6.0,'Deleted editable time retains its complete prior row in the audit receipt');
        $expect($snapshot([...$graphTables,...$financialTables])===$protected,'Native time edits preserve all identities, rates, provider mappings and financial records');
        $retainedEntry=$entry($workspace,$sheet,$period,1,'draft','2026-10-07');
        $current=$insert('placements',['tenant_id'=>$tenant,'person_id'=>$person,'title'=>'Synthetic surviving time placement','status'=>'active','engagement_type'=>'w2','start_date'=>'2026-10-01','worksite_country'=>'US']);
        $pdo->beginTransaction();coreRecordNumbersMerge($pdo,$tenant,'placement',$current,$placement,$actor);
        $pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$placement]);$pdo->commit();
        $before=$snapshot($tables);
        foreach([['entry_save',['id'=>$retainedEntry,'placement_id'=>$current,'hours'=>1]],['entry_delete',['id'=>$retainedEntry]],['reopen',['id'=>$sheet]]]as[$action,$body])
            $expect($request('POST',$api.$action,$body,true,$workspace)[0]===409&&$snapshot($tables)===$before,'Retained-placement time cannot be silently retargeted, removed or reopened: '.$action);
        RBACResolver::revokeModule($member,'staffing',$actor);$before=$snapshot($tables);
        $expect($request('POST',$api.'reopen',['id'=>$sheet],true,$workspace)[0]===403&&$snapshot($tables)===$before,'Same session immediately loses native time write access after revocation');
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS cf_http_native_time_receipt');
        if($member){$pdo->prepare('DELETE FROM membership_module_access WHERE membership_id=?')->execute([$member]);deactivateMembership($actor,$workspace);}
        $pdo->prepare('UPDATE tenants SET is_active=0 WHERE id=?')->execute([$workspace]);RBACResolver::resetCache();
    }
})();
