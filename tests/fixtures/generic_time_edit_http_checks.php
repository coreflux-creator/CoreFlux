<?php
/** Original-workspace time API, real session and permissions, invented data only. */
(static function()use($pdo,$tenant,$actor,$foreign,$request,$expect,$snapshot,$financialTables,$graphTables):void {
    $workspace=900019;$member=null;$api='/modules/time/api/entries.php';
    $insert=static function(string $table,array $row)use($pdo):int {$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return(int)$pdo->lastInsertId();};
    $read=static function(string $table,int $id)use($pdo):?array {$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;};
    $tables=array_values(array_unique([...$graphTables,...$financialTables,'time_entries','time_periods','staffing_timesheets','time_uploaded_documents','artifact_objects','artifact_events','artifact_relationships','audit_log','quanta_time_imports','core_record_numbers']));
    if($pdo->query('SELECT id FROM tenants WHERE id=900019')->fetchColumn())throw new RuntimeException('Generic time fixture exists');
    $insert('tenants',['id'=>$workspace,'name'=>'Synthetic generic time HTTP','parent_id'=>$tenant,'tenant_type'=>'sub','is_active'=>1,'is_simulation'=>1]);
    try {
        $member=(int)provisionMembership($actor,$workspace,'admin',['is_primary'=>false,'status'=>'active'])['membership_id'];
        foreach(['companies','people','placements','staffing','time']as$module)$insert('tenant_module_scope',['tenant_id'=>$workspace,'module_key'=>$module,'scope_mode'=>'shared']);
        $person=$insert('people',['tenant_id'=>$tenant,'first_name'=>'Synthetic generic time','last_name'=>'Worker','email_primary'=>'generic-time@example.invalid','classification'=>'w2','status'=>'active']);
        $placement=$insert('placements',['tenant_id'=>$tenant,'person_id'=>$person,'title'=>'Generic time assignment','status'=>'active','engagement_type'=>'w2','start_date'=>'2026-10-01','end_date'=>'2026-12-31','worksite_country'=>'US']);
        $rates=$insert('placement_rates',['tenant_id'=>$tenant,'placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>60,'approved_at'=>'2026-10-01 12:00:00']);
        $body=['placement_id'=>$placement,'work_date'=>'2026-10-05','category'=>'regular_billable','hours'=>8];
        $foreignDocument=$insert('time_uploaded_documents',['tenant_id'=>$tenant,'uploaded_by_user_id'=>$actor,'file_name'=>'parent-synthetic.txt','extraction_status'=>'extracted']);
        $parentCategory=$insert('tenant_time_categories',['tenant_id'=>$tenant,'code'=>'synthetic_generic','label'=>'Other workspace','parent_bucket'=>'nonbillable']);
        $protected=$snapshot([...$financialTables,...$graphTables]);$before=$snapshot($tables);
        $expect($request('POST',$api,$body,false,$workspace)[0]===401,'Generic time API requires native sign-in');
        $expect($request('POST',$api,$body,true,$workspace)[0]===403,'Generic time create requires its module grant');
        RBACResolver::grantModule($member,'time','read',[$workspace],$actor);
        $expect($request('POST',$api,$body,true,$workspace)[0]===403&&$snapshot($tables)===$before,'Self-time permission cannot write another worker or create orphan records');
        RBACResolver::grantModule($member,'time','write',[$workspace],$actor);
        [$status,$created]=$request('POST',$api,$body,true,$workspace);
        $expect($status===201&&(int)$created['entry']['tenant_id']===$workspace&&(int)$created['entry']['person_id']===$person,'Generic time create uses the original workspace despite shared time and staffing modules: '.json_encode([$status,$created]));
        $id=(int)$created['entry']['id'];$oldHeader=(int)$created['entry']['timesheet_id'];$oldPeriod=(int)$created['entry']['period_id'];
        $expect($oldHeader>0&&$read('staffing_timesheets',$oldHeader)['tenant_id']==$workspace&&(float)$read('staffing_timesheets',$oldHeader)['total_hours']===8.0,'Generic new time creates and reconciles its original weekly header');
        [$status,$listed]=$request('GET',$api.'?person_id='.$person,null,true,$workspace);
        $expect($status===200&&count($listed['rows'])===1&&(int)$listed['rows'][0]['id']===$id,'Generic entry reader and count use original-workspace time');
        $before=$snapshot($tables);
        foreach([
            ['PATCH',$api.'?id='.$id,['hours'=>25]],
            ['PATCH',$api.'?id='.$id,['hours'=>0.001]],
            ['PATCH',$api.'?id='.$id,['custom_category_id'=>$parentCategory]],
            ['POST',$api,array_replace($body,['placement_id'=>$foreign['placement']])],
            ['POST',$api,array_replace($body,['source'=>'ai_inbox','source_ref_id'=>$foreignDocument])],
        ]as[$method,$path,$invalid])$expect(in_array($request($method,$path,$invalid,true,$workspace)[0],[404,422],true)&&$snapshot($tables)===$before,'Generic native validation preserves every row and receipt: '.$method.' '.json_encode($invalid));
        $pdo->exec("CREATE TRIGGER cf_http_generic_time_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900019 AND NEW.event='time.entry.updated' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic private generic audit failure'; END IF; END");
        try {
            [$status,$failure]=$request('PATCH',$api.'?id='.$id,['hours'=>7,'work_date'=>'2026-10-12'],true,$workspace);
            $expect($status===503&&!str_contains(json_encode($failure),'SQLSTATE')&&$snapshot($tables)===$before,'Late generic edit audit failure restores both weeks, periods, artifacts and entry');
        } finally {$pdo->exec('DROP TRIGGER cf_http_generic_time_audit');}
        [$status,$moved]=$request('PATCH',$api.'?id='.$id,['hours'=>7,'work_date'=>'2026-10-12','category'=>'OT_nonbillable'],true,$workspace);
        $newHeader=(int)($moved['entry']['timesheet_id']??0);
        $expect($status===200&&$newHeader!==$oldHeader&&(float)$read('staffing_timesheets',$oldHeader)['total_hours']===0.0&&(float)$read('staffing_timesheets',$newHeader)['total_hours']===7.0,'Moving editable time across weeks updates both original headers');
        $expect($moved['entry']['hour_type']==='overtime'&&(int)$moved['entry']['billable']===0&&$moved['entry']['source']===$created['entry']['source'],'Generic category editing keeps weekly hour metadata and original source aligned');
        $custom=$insert('tenant_time_categories',['tenant_id'=>$workspace,'code'=>'synthetic_own','label'=>'Own workspace','parent_bucket'=>'nonbillable']);
        [$status,$categorized]=$request('PATCH',$api.'?id='.$id,['custom_category_id'=>$custom],true,$workspace);
        $expect($status===200&&(int)$categorized['entry']['custom_category_id']===$custom,'Generic editing can select its original-workspace custom category');
        [$status,$cleared]=$request('PATCH',$api.'?id='.$id,['custom_category_id'=>null],true,$workspace);
        $expect($status===200&&$cleared['entry']['custom_category_id']===null,'An explicit null clears the custom category');
        $before=$snapshot($tables);
        $expect($request('POST',$api.'?action=bulk_create',['entries'=>[array_replace($body,['work_date'=>'2026-10-19','hours'=>2]),array_replace($body,['work_date'=>'2026-10-20','placement_id'=>$foreign['placement']])]],true,$workspace)[0]===404&&$snapshot($tables)===$before,'Invalid later batch row rolls back earlier new time, header, period and required receipts');
        $document=$insert('time_uploaded_documents',['tenant_id'=>$workspace,'uploaded_by_user_id'=>$actor,'file_name'=>'synthetic-two-days.txt','extraction_status'=>'extracted']);
        $batch=['document_id'=>$document,'entries'=>[array_replace($body,['work_date'=>'2026-10-19','hours'=>2,'source'=>'ai_inbox','source_ref_id'=>$document]),array_replace($body,['work_date'=>'2026-10-20','hours'=>3,'source'=>'ai_inbox','source_ref_id'=>$document])]];
        $before=$snapshot($tables);
        $pdo->exec("CREATE TRIGGER cf_http_generic_time_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900019 AND NEW.event='time.upload.consumed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic private document receipt failure'; END IF; END");
        try {$expect($request('POST',$api.'?action=bulk_create',$batch,true,$workspace)[0]===503&&$snapshot($tables)===$before,'Document consumption receipt failure rolls back every extracted row and its consumed status');}
        finally {$pdo->exec('DROP TRIGGER cf_http_generic_time_audit');}
        [$status,$batchSaved]=$request('POST',$api.'?action=bulk_create',$batch,true,$workspace);
        $expect($status===201&&$batchSaved['created']===2&&$batchSaved['document_consumed']&&$read('time_uploaded_documents',$document)['consumed_entry_count']==2,'Valid document batch commits both weekly-linked rows and one consumption receipt');
        $before=$snapshot($tables);
        $expect($request('POST',$api.'?action=bulk_create',$batch,true,$workspace)[0]===409&&$snapshot($tables)===$before,'Repeated document batch cannot duplicate imported hours');
        $singleDocument=$insert('time_uploaded_documents',['tenant_id'=>$workspace,'uploaded_by_user_id'=>$actor,'file_name'=>'synthetic-single.txt','extraction_status'=>'extracted']);
        $singleBody=array_replace($body,['work_date'=>'2026-10-28','hours'=>1,'source'=>'ai_inbox','source_ref_id'=>$singleDocument]);
        [$status,$singleSaved]=$request('POST',$api,$singleBody,true,$workspace);
        $expect($status===201&&(int)$singleSaved['entry']['source_ref_id']===$singleDocument&&$read('time_uploaded_documents',$singleDocument)['extraction_status']==='consumed','Single source-ref creation atomically consumes its source document too');
        $before=$snapshot($tables);
        $expect($request('POST',$api,$singleBody,true,$workspace)[0]===409&&$snapshot($tables)===$before,'Single-row source-ref replay cannot bypass document consumption');
        $approved=(int)$batchSaved['entry_ids'][0];$sibling=(int)$batchSaved['entry_ids'][1];$approvedHeader=(int)$batchSaved['timesheet_ids'][0];
        $pdo->prepare('UPDATE time_entries SET status="approved",approved_by_user_id=?,approved_at="2026-10-26 12:00:00",approved_via="manual",rate_snapshot_id=? WHERE id IN (?,?)')->execute([$actor,$rates,$approved,$sibling]);
        $pdo->prepare('UPDATE staffing_timesheets SET status="approved",approved_by_user_id=?,approved_at="2026-10-26 12:00:00" WHERE id=?')->execute([$actor,$approvedHeader]);
        $original=$read('time_entries',$approved);$siblingBefore=$read('time_entries',$sibling);$before=$snapshot($tables);
        $expect($request('PATCH',$api.'?id='.$approved,['hours'=>1],true,$workspace)[0]===409&&$snapshot($tables)===$before,'Generic PATCH cannot edit an approved entry directly');
        $correction=['hours'=>1.5,'correction_reason'=>'Synthetic corrected paper hours'];
        $pdo->exec("CREATE TRIGGER cf_http_generic_time_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900019 AND NEW.event='time.entry.superseded' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic private correction receipt failure'; END IF; END");
        try {$expect($request('POST',$api.'?action=correct&id='.$approved,$correction,true,$workspace)[0]===503&&$snapshot($tables)===$before,'Correction receipt failure restores the original approval, replacement, weekly totals and artifacts');}
        finally {$pdo->exec('DROP TRIGGER cf_http_generic_time_audit');}
        [$status,$corrected]=$request('POST',$api.'?action=correct&id='.$approved,$correction,true,$workspace);
        $replacement=$read('time_entries',(int)($corrected['new_entry_id']??0));$retained=$read('time_entries',$approved);
        $expect($status===200&&$replacement['status']==='draft'&&(int)$replacement['timesheet_id']===$approvedHeader&&$retained['status']==='superseded'&&(int)$retained['superseded_by_id']===(int)$replacement['id'],'Manual correction retains its original row and links the replacement to the weekly header');
        foreach(['hours','work_date','approved_by_user_id','approved_at','approved_via','rate_snapshot_id','source','source_ref_id']as$field)$expect($retained[$field]===$original[$field],'Manual correction retains original '.$field);
        $expect($siblingBefore===$read('time_entries',$sibling)&&$read('staffing_timesheets',$approvedHeader)['status']==='draft'&&(float)$read('staffing_timesheets',$approvedHeader)['total_hours']===4.5,'Correction preserves approved siblings and recomputes the week without superseded hours');
        $before=$snapshot($tables);
        $expect($request('POST',$api.'?action=correct&id='.$approved,$correction,true,$workspace)[0]===409&&$snapshot($tables)===$before,'Repeated manual correction cannot create another replacement');
        $pdo->prepare('UPDATE time_entries SET source="quanta",source_system="quanta" WHERE id=?')->execute([$id]);$before=$snapshot($tables);
        $expect($request('PATCH',$api.'?id='.$id,['hours'=>6],true,$workspace)[0]===409&&$snapshot($tables)===$before,'Quanta source flags still protect time when its import mapping is unavailable');
        $pdo->prepare('UPDATE time_entries SET status="approved" WHERE id=?')->execute([$id]);$before=$snapshot($tables);
        $expect($request('POST',$api.'?action=correct&id='.$id,$correction,true,$workspace)[0]===409&&$snapshot($tables)===$before,'Manual correction cannot erase unmapped Quanta approval provenance');
        foreach(['people','placements']as$module) {
            $pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key=?')->execute([$workspace,$module]);$before=$snapshot($tables);
            $expect(in_array($request('POST',$api,array_replace($body,['work_date'=>'2026-10-27']),true,$workspace)[0],[400,404,422],true)&&$snapshot($tables)===$before,'Generic time create checks current '.$module.' directory ownership');
            $pdo->prepare('UPDATE tenant_module_scope SET scope_mode="shared" WHERE tenant_id=? AND module_key=?')->execute([$workspace,$module]);
        }
        $expect($snapshot([...$financialTables,...$graphTables])===$protected,'Generic time writes preserve all provider pairs, business identities, rates and financial records');
        RBACResolver::revokeModule($member,'time',$actor);$before=$snapshot($tables);
        $expect($request('POST',$api,$body,true,$workspace)[0]===403&&$snapshot($tables)===$before,'Generic time write access is removed immediately in the same session');
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS cf_http_generic_time_audit');
        if($member){$pdo->prepare('DELETE FROM membership_module_access WHERE membership_id=?')->execute([$member]);deactivateMembership($actor,$workspace);}
        $pdo->prepare('UPDATE tenants SET is_active=0 WHERE id=?')->execute([$workspace]);RBACResolver::resetCache();
    }
})();
