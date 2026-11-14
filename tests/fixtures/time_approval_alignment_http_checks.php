<?php
/** Native entry API and weekly approval service with real session/database ownership. */
(static function()use($pdo,$tenant,$actor,$request,$expect,$snapshot,$financialTables,$graphTables):void {
    require_once __DIR__.'/../../modules/time/lib/native_review.php';
    $workspace=900021;$member=null;$api='/modules/time/api/entries.php?action=approve&id=';
    $nativeRequest=$request;
    $request=static function($method,$path,$body=null,$authenticated=true,$scope=null)use($nativeRequest):array {
        if ($method==='POST' && preg_match('/\/modules\/time\/api\/entries\.php\?action=approve&id=(\d+)$/D',$path,$match)) {
            [$status,$review]=$nativeRequest('POST','/modules/time/api/entries.php?action=decision_review',['ids'=>[(int)$match[1]],'decision'=>'approve'],$authenticated,$scope);
            if ($status!==200) return [$status,$review];
            $body=['review_revision'=>$review['revision']]+($body??[]);
        }
        return $nativeRequest($method,$path,$body,$authenticated,$scope);
    };
    $insert=static function(string $table,array $row)use($pdo):int {$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return (int)$pdo->lastInsertId();};
    $read=static function(string $table,int $id)use($pdo):array {$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC);};
    $tables=array_values(array_unique([...$graphTables,...$financialTables,'time_entries','time_periods','staffing_timesheets','artifact_objects','artifact_events','artifact_relationships','audit_log','core_record_numbers']));
    if($pdo->query('SELECT id FROM tenants WHERE id=900021')->fetchColumn())throw new RuntimeException('Approval fixture already exists');
    $insert('tenants',['id'=>$workspace,'name'=>'Synthetic approval alignment','parent_id'=>$tenant,'tenant_type'=>'sub','is_active'=>1,'is_simulation'=>1]);
    try {
        $member=(int)provisionMembership($actor,$workspace,'admin',['is_primary'=>false,'status'=>'active'])['membership_id'];
        foreach(['companies','people','placements','accounting','time','staffing']as$module)$insert('tenant_module_scope',['tenant_id'=>$workspace,'module_key'=>$module,'scope_mode'=>'shared']);
        RBACResolver::grantModule($member,'time','admin',[$workspace],$actor);
        $creator=$insert('users',['name'=>'Synthetic approval preparer','email'=>'approval-preparer@example.invalid','password_hash'=>'!no-login-fixture','role'=>'employee']);
        $person=$insert('people',['tenant_id'=>$tenant,'first_name'=>'Synthetic approval','last_name'=>'Worker','email_primary'=>'approval-worker@example.invalid','classification'=>'w2','status'=>'active']);
        $company=$insert('companies',['tenant_id'=>$tenant,'name'=>'Synthetic approval client']);
        $client=$insert('staffing_clients',['tenant_id'=>$tenant,'company_id'=>$company,'name'=>'Synthetic approval client']);
        $entity=$insert('accounting_entities',['tenant_id'=>$tenant,'code'=>'TAPR','legal_name'=>'Synthetic approval entity','country'=>'US','base_currency'=>'USD','active'=>1]);
        $placement=$insert('placements',['tenant_id'=>$tenant,'person_id'=>$person,'title'=>'Synthetic approval assignment','status'=>'active','engagement_type'=>'w2','start_date'=>'2026-10-01','worksite_country'=>'US','worksite_state'=>'NJ','workers_comp_class'=>'synthetic-class','client_id'=>$client,'accounting_entity_id'=>$entity]);
        $rate=$insert('placement_rates',['tenant_id'=>$tenant,'placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>60,'approved_at'=>'2026-10-01 12:00:00']);
        $period=$insert('time_periods',['tenant_id'=>$workspace,'start_date'=>'2026-10-05','end_date'=>'2026-10-11','label'=>'Synthetic approval week']);
        $sheet=$insert('staffing_timesheets',['tenant_id'=>$workspace,'person_id'=>$person,'period_start'=>'2026-10-05','period_end'=>'2026-10-11','status'=>'submitted','total_hours'=>4]);
        $ids=[];
        foreach(['2026-10-05','2026-10-06']as$date)$ids[]=$insert('time_entries',['tenant_id'=>$workspace,'person_id'=>$person,'placement_id'=>$placement,'period_id'=>$period,'timesheet_id'=>$sheet,'work_date'=>$date,'hours'=>2,'hour_type'=>'regular','category'=>'regular_billable','status'=>'pending_review','created_by_user_id'=>$creator]);
        $financialBefore=$snapshot($financialTables);$before=$snapshot($tables);
        $expect($request('POST',$api.$ids[0],[],false,$workspace)[0]===401&&$snapshot($tables)===$before,'Approval alignment requires native sign-in');
        RBACResolver::grantModule($member,'time','read',[$workspace],$actor);
        $expect($request('POST',$api.$ids[0],[],true,$workspace)[0]===403&&$snapshot($tables)===$before,'Time read permission cannot approve entries');
        RBACResolver::grantModule($member,'time','admin',[$workspace],$actor);
        foreach([
            ['time_entries',$ids[0],'created_by_user_id',$actor,$creator],
            ['people',$person,'deleted_at','2026-10-08 12:00:00',null],
            ['placements',$placement,'actual_end_date','2026-10-04',null],
            ['time_periods',$period,'status','locked','open'],
            ['accounting_entities',$entity,'active',0,1],
            ['placement_rates',$rate,'approved_at',null,'2026-10-01 12:00:00'],
            ['companies',$company,'deleted_at','2026-10-08 12:00:00',null],
            ['staffing_timesheets',$sheet,'person_id',$person+999,$person],
        ]as[$table,$id,$field,$changed,$original]) {
            $pdo->prepare('UPDATE '.$table.' SET '.$field.'=? WHERE id=?')->execute([$changed,$id]);$before=$snapshot($tables);
            try {[$status,$result]=$request('POST',$api.$ids[0],[],true,$workspace);$expect(in_array($status,[403,404,409,422],true)&&$snapshot($tables)===$before,'Native approval refuses changed '.$table.'.'.$field.' without writes: '.json_encode([$status,$result]));}
            finally {$pdo->prepare('UPDATE '.$table.' SET '.$field.'=? WHERE id=?')->execute([$original,$id]);}
        }
        $pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="accounting"')->execute([$workspace]);$before=$snapshot($tables);
        try {$expect($request('POST',$api.$ids[0],[],true,$workspace)[0]===422&&$snapshot($tables)===$before,'Approval cannot use an accounting entity from the previous shared owner');}
        finally {$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="shared" WHERE tenant_id=? AND module_key="accounting"')->execute([$workspace]);}
        $pdo->exec("CREATE TRIGGER cf_http_approval_receipt BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900021 AND NEW.event='time.entry.approved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic private approval receipt failure'; END IF; END");
        $before=$snapshot($tables);
        try {[$status,$error]=$request('POST',$api.$ids[0],[],true,$workspace);$expect($status===503&&!str_contains(json_encode($error),'SQLSTATE')&&$snapshot($tables)===$before,'Missing approval receipt rolls back the native entry and all projections');}
        finally {$pdo->exec('DROP TRIGGER cf_http_approval_receipt');}
        [$status,$approved]=$request('POST',$api.$ids[0],[],true,$workspace);
        $expect($status===200&&$approved['entry']['status']==='approved'&&(int)$approved['entry']['rate_snapshot_id']===$rate&&$read('staffing_timesheets',$sheet)['status']==='submitted','Native approval freezes one entry while its remaining sibling stays in review');
        $frozen=$read('time_entries',$ids[0]);$dimensions=json_decode($frozen['dimension_snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        $expect($dimensions['event_entity_id']===$entity&&strlen($dimensions['source_rate_hash'])===64&&!isset($dimensions['source_rate'])&&hash_equals($frozen['dimension_snapshot_hash'],hash('sha256',$frozen['dimension_snapshot_json'])),'Approval stores canonical dimensions and a rate fingerprint without exposing raw pay rates');
        $before=$snapshot($tables);$expect($request('POST',$api.$ids[0],[],true,$workspace)[0]===409&&$snapshot($tables)===$before,'Repeated native approval creates no second decision receipt');
        $plan=static fn(?int $reviewer)=>staffingTimesheetApprovalPlan($reviewer,staffingTimesheetRow($workspace,$sheet),$workspace);
        $apply=static fn(?int $reviewer,array $review,array $options=[])=>staffingTimesheetApplyApproval($reviewer,$sheet,$review,['tenant_id'=>$workspace]+$options);
        $refuse=static function(callable $work,string $label)use($expect,$snapshot,$tables):void {
            $before=$snapshot($tables);$failed=false;try{$work();}catch(RuntimeException|InvalidArgumentException $e){$failed=true;}
            $expect($failed&&$snapshot($tables)===$before,$label);
        };
        $review=$plan($actor);$refuse(fn()=>$apply($actor,[]),'Weekly approval refuses an incomplete reviewed entry set');
        $altered=$review;$altered[$ids[1]]['dimensions']['event_entity_id']=$entity+999;
        $refuse(fn()=>$apply($actor,$altered),'Weekly approval refuses substituted accounting evidence');
        foreach([['time_entries',$ids[1],'hours',3,2],['placement_rates',$rate,'bill_rate',101,100],['placements',$placement,'worksite_state','NY','NJ']]as[$table,$id,$field,$changed,$original]) {
            $review=$plan($actor);$pdo->prepare('UPDATE '.$table.' SET '.$field.'=? WHERE id=?')->execute([$changed,$id]);
            try {$refuse(fn()=>$apply($actor,$review),'Weekly approval requires a new review after '.$table.'.'.$field.' changes');}
            finally {$pdo->prepare('UPDATE '.$table.' SET '.$field.'=? WHERE id=?')->execute([$original,$id]);}
        }
        $review=$plan($actor);
        $pdo->exec("CREATE TRIGGER cf_http_approval_receipt BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900021 AND NEW.event='time.timesheet.approved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic late weekly receipt failure'; END IF; END");
        try {$refuse(fn()=>$apply($actor,$review),'Late weekly receipt failure restores entries, header, artifacts and earlier approval receipts');}
        finally {$pdo->exec('DROP TRIGGER cf_http_approval_receipt');}
        $external=['header_approved_via'=>'external_email','entry_approved_via'=>'tokenized_client_email','external_approver_email'=>'approval-preparer@example.invalid'];
        $review=$plan(null);$refuse(fn()=>$apply(null,$review,$external),'An external link cannot let the preparer approve its own time');
        $external['external_approver_email']='independent-client@example.invalid';$scopeBefore=currentTenantId();
        $apply(null,$review,$external);
        $done=$read('time_entries',$ids[1]);$header=$read('staffing_timesheets',$sheet);
        $expect($done['status']==='approved'&&$done['approved_by_user_id']===null&&$done['client_approver_email']===$external['external_approver_email']&&$header['status']==='approved','External weekly approval retains its actual email actor and original workspace');
        $expect(currentTenantId()===$scopeBefore&&$read('time_entries',$ids[0])===$frozen,'Explicit weekly approval restores the caller scope and retains the independently approved sibling');
        $expect($snapshot($financialTables)===$financialBefore,'Entry and weekly approval services create no financial events, journals, payments or payroll runs');
        require __DIR__.'/time_decision_review_http_checks.php';
        require __DIR__.'/time_client_token_http_checks.php';
        require __DIR__.'/time_weekly_email_http_checks.php';
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS cf_http_approval_receipt');
        if($member){$pdo->prepare('DELETE FROM membership_module_access WHERE membership_id=?')->execute([$member]);deactivateMembership($actor,$workspace);}
        $pdo->prepare('UPDATE tenants SET is_active=0 WHERE id=?')->execute([$workspace]);RBACResolver::resetCache();
    }
})();
