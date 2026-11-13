<?php
/** Native cookie, RBAC and reviewed placement consolidation; invented records only. */
(static function()use($pdo,$tenant,$actor,$foreign,$request,$expect,$snapshot,$graphTables,$financialTables,$documentDriver):void{
    $workspace=900017;$member=null;$endpoint='/modules/placements/api/merge.php';$placementEndpoint='/modules/placements/api/placements.php';
    if($pdo->query('SELECT id FROM tenants WHERE id=900017')->fetchColumn())throw new RuntimeException('Merge HTTP workspace exists');
    $pdo->prepare('INSERT INTO tenants(id,name,parent_id,tenant_type,is_active,is_simulation) VALUES(?,?,?,"sub",1,1)')->execute([$workspace,'Synthetic reviewed merge HTTP',$tenant]);
    $member=(int)provisionMembership($actor,$workspace,'admin',['is_primary'=>false,'status'=>'active'])['membership_id'];
    $insert=static function(string $table,array $row)use($pdo):int{$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return(int)$pdo->lastInsertId();};
    try{
        foreach(['companies','people','placements','staffing','accounting']as$module)$insert('tenant_module_scope',['tenant_id'=>$workspace,'module_key'=>$module,'scope_mode'=>'shared']);
        $person=$insert('people',['tenant_id'=>$tenant,'first_name'=>'Synthetic HTTP','last_name'=>'Merged worker','email_primary'=>'merge-http@example.invalid','classification'=>'w2','status'=>'active']);
        $make=static fn(string $title):int=>$insert('placements',['tenant_id'=>$tenant,'person_id'=>$person,'title'=>$title,'status'=>'on_hold','engagement_type'=>'w2','start_date'=>'2026-10-01','worksite_country'=>'US']);
        $a=$make('Current HTTP placement');$b=$make('Original HTTP placement');$archived=$make('Ordinary archived HTTP placement');
        $pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$archived]);
        foreach([[$a,110,60],[$b,100,50]]as[$id,$bill,$pay])$insert('placement_rates',['tenant_id'=>$tenant,'placement_id'=>$id,'effective_from'=>'2026-10-01','bill_rate'=>$bill,'pay_rate'=>$pay,'approved_at'=>'2026-10-01 12:00:00']);
        $period=$insert('time_periods',['tenant_id'=>$workspace,'start_date'=>'2026-10-01','end_date'=>'2026-10-07','label'=>'Synthetic original time history']);
        $foreignPeriod=$insert('time_periods',['tenant_id'=>$tenant,'start_date'=>'2026-10-01','end_date'=>'2026-10-07','label'=>'Synthetic other workspace time']);
        $sheet=$insert('staffing_timesheets',['tenant_id'=>$workspace,'person_id'=>$person,'period_start'=>'2026-10-01','period_end'=>'2026-10-07','status'=>'approved','total_hours'=>12,'approved_at'=>'2026-10-08 12:00:00']);
        $foreignSheet=$insert('staffing_timesheets',['tenant_id'=>$tenant,'person_id'=>$person,'period_start'=>'2026-10-01','period_end'=>'2026-10-07','status'=>'approved','total_hours'=>99]);
        $unrelatedSheet=$insert('staffing_timesheets',['tenant_id'=>$workspace,'person_id'=>$person,'period_start'=>'2026-10-08','period_end'=>'2026-10-14','status'=>'draft','total_hours'=>0]);
        $timeIds=[];
        foreach([[$workspace,$b,8,'approved',$period],[$workspace,$b,6,'superseded',$period],[$workspace,$a,4,'approved',$period],[$tenant,$b,99,'approved',$foreignPeriod]]as[$timeOwner,$id,$hours,$state,$periodId]){
            $timeIds[]=$insert('time_entries',['tenant_id'=>$timeOwner,'placement_id'=>$id,'person_id'=>$person,'period_id'=>$periodId,'work_date'=>'2026-10-05',
                'category'=>'regular_billable','hours'=>$hours,'status'=>$state,'approved_at'=>'2026-10-08 12:00:00','approved_by_user_id'=>$actor,'timesheet_id'=>$timeOwner===$workspace?$sheet:$foreignSheet]);
        }
        $payload=['startId'=>'99430001','candidateId'=>'99430002','jobId'=>'99430003','startDate'=>'2026-10-01','status'=>'on_hold','__cf_jobdiva_source_object'=>'assignment'];
        mappingUpsert($workspace,'jobdiva','placement','99430001',$a,$payload);$primary=mappingUpsert($workspace,'jobdiva','placement','jd:99430001',$b,$payload);
        entitySyncHistoryRecord($workspace,'jobdiva','placement',$b,'jd:99430001','pull',null,json_encode(['marker'=>'original-workspace-history']),null,str_repeat('a',64),$actor,true);
        entitySyncHistoryRecord($tenant,'jobdiva','placement',$b,'different-original-workspace','pull',null,json_encode(['marker'=>'other-workspace-history']),null,str_repeat('b',64),$actor,true);
        $tables=array_values(array_unique([...$graphTables,'core_record_numbers','entity_sync_history','placement_documents','storage_objects','staffing_timesheets',...$financialTables]));$before=$snapshot($tables);
        $pair=['survivor_id'=>$a,'retired_id'=>$b];
        $expect($request('GET',$endpoint.'?action=candidates',null,false,$workspace)[0]===401,'Placement merge candidates require native sign-in');
        RBACResolver::grantModule($member,'placements','read',[$workspace],$actor);
        $expect($request('GET',$endpoint.'?action=candidates',null,true,$workspace)[0]===403
            &&$request('POST',$endpoint.'?action=preview',$pair,true,$workspace)[0]===403,'Read-only module access cannot review or merge placements');
        RBACResolver::grantModule($member,'placements','write',[$workspace],$actor);
        $expect($request('POST',$endpoint.'?action=preview',$pair,true,$workspace)[0]===403&&$snapshot($tables)===$before,
            'General placement editing does not grant financial consolidation access');
        RBACResolver::grantModule($member,'placements','admin',[$workspace],$actor);
        $mappings='/api/integrations/mappings.php?entity_type=placement';$links=$mappings.'&action=list_for_internal&internal_id='.$b;
        $historyPath='/api/integrations/sync_history.php?entity_type=placement&internal_id='.$b;
        $expect($request('GET',$links,null,true,$workspace)[0]===403&&$request('GET',$historyPath,null,true,$workspace)[0]===403,
            'Placement management alone does not grant provider link or source history access');
        RBACResolver::grantModule($member,'integrations','read',[$workspace],$actor);
        $timeHistory='/modules/placements/api/time_history.php?placement_id='.$b;
        $sheetList='/modules/staffing/api/timesheets.php?action=list_for_placement&placement_id='.$b;
        $sheetDetail='/modules/staffing/api/timesheets.php?action=detail_for_placement&placement_id='.$b.'&id='.$sheet;
        $expect($request('GET',$timeHistory,null,true,$workspace)[0]===403,'Placement management alone does not grant original time-history access');
        $expect($request('GET',$sheetList,null,true,$workspace)[0]===403&&$request('GET',$sheetDetail,null,true,$workspace)[0]===403,
            'Placement management alone cannot list or read placement timesheets');
        RBACResolver::grantModule($member,'staffing','admin',[$workspace],$actor);
        $documents='/modules/placements/api/documents.php';$documentPath=$documents.'?placement_id='.$b;
        $key='placements/'.$tenant.'/document/'.$b.'/synthetic-original-placement.txt';
        $documentDriver->put($key,'Original placement agreement bytes',['mime'=>'text/plain','source_is_bytes'=>true]);
        $originalFile=$documentDriver->head($key);$documentBody=['doc_type'=>'sow','filename'=>'synthetic-original-placement.txt','storage_key'=>$key];
        [$status,$storedDocument]=$request('POST',$documentPath,$documentBody,true,$workspace);
        $expect($status===201,'Native merge fixture registers its original document before consolidation');
        $documentId=(int)$storedDocument['id'];$before=$snapshot($tables);
        [$status,$candidates]=$request('GET',$endpoint.'?action=candidates',null,true,$workspace);
        $expect($status===200&&$candidates['placements_archived']===0&&count(array_filter($candidates['candidates'],static fn($g)=>in_array($a,array_column($g['rows'],'id'),true)&&in_array($b,array_column($g['rows'],'id'),true)))===1,
            'Native candidate queue exposes exact duplicate records without selecting a survivor: '.json_encode([$status,$candidates]));
        [$status,$review]=$request('POST',$endpoint.'?action=preview',$pair,true,$workspace);
        $expect($status===200&&$review['placement_owner_id']===$tenant&&count($review['source_options'])===2&&$snapshot($tables)===$before,'Native reviewed merge resolves shared ownership and changes no business records');
        $body=[...$pair,'review_token'=>$review['review_token'],'request_id'=>bin2hex(random_bytes(4)).'-0000-4000-8000-'.bin2hex(random_bytes(6)),
            'reason'=>'Synthetic reviewed duplicate assignment','fields'=>array_fill_keys(array_keys($review['fields']),'survivor'),
            'primary_jobdiva_mapping_id'=>(int)$primary['id'],'configuration_placement_id'=>$a,'history_policy'=>'retain_original_rows','confirm'=>'MERGE_REVIEWED_PLACEMENTS'];
        $body['fields']['title']='retired';
        $bad=$body;unset($bad['fields']['title']);
        $expect($request('POST',$endpoint.'?action=apply',$bad,true,$workspace)[0]===422&&$snapshot($tables)===$before,'Native merge requires an explicit choice for every field');
        $pdo->prepare('UPDATE placements SET notes="Changed while review was open" WHERE id=?')->execute([$a]);$changed=$snapshot($tables);
        $expect($request('POST',$endpoint.'?action=apply',$body,true,$workspace)[0]===409&&$snapshot($tables)===$changed,'Native stale merge refuses all changes after an intervening edit');
        [$status,$review]=$request('POST',$endpoint.'?action=preview',$pair,true,$workspace);$body['review_token']=$review['review_token'];
        $expect($status===200,'Native merge can refresh its review after an intervening edit');
        $pdo->exec("CREATE TRIGGER cf_http_placement_merge_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.tenant_id=900017 AND BINARY NEW.event=BINARY 'placement.identity_merged' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic merge audit failure'; END IF; END");
        try{$expect($request('POST',$endpoint.'?action=apply',$body,true,$workspace)[0]===503&&$snapshot($tables)===$changed,'Native required receipt failure rolls back the entire merge');}
        finally{$pdo->exec('DROP TRIGGER cf_http_placement_merge_audit');}
        $protected=$snapshot(['placement_rates','external_entity_mappings','time_entries','placement_documents','storage_objects',...$financialTables]);
        [$status,$saved]=$request('POST',$endpoint.'?action=apply',$body,true,$workspace);
        $expect($status===200&&!$saved['replayed']&&$saved['survivor_id']===$a&&$saved['retired_id']===$b,'Native reviewed merge succeeds with the authenticated reviewer: '.json_encode([$status,$saved]));
        $expect($snapshot(['placement_rates','external_entity_mappings','time_entries','placement_documents','storage_objects',...$financialTables])===$protected,'Native merge preserves every provider pair, approved rate, time record, document and financial result');
        $after=$snapshot($tables);[$status,$replayed]=$request('POST',$endpoint.'?action=apply',$body,true,$workspace);
        $expect($status===200&&$replayed['replayed']&&$replayed['retained_number']===$saved['retained_number']&&$snapshot($tables)===$after,'Native lost-response retry returns the original receipt with no repeated writes');
        foreach(['id='.(string)$b,'number='.$review['retired_number']]as$query){
            [$status,$history]=$request('GET',$placementEndpoint.'?'.$query,null,true,$workspace);
            $expect($status===200&&$history['placement']===null&&$history['identity']['merged']&&$history['identity']['source']['id']===$b
                &&$history['identity']['current']['id']===$a&&(float)$history['identity']['source_rates'][0]['bill_rate']===100.0,
                'Historical link returns original placement and rates separately from the editable current record: '.$query);
        }
        [$status,$current]=$request('GET',$placementEndpoint.'?id='.$a,null,true,$workspace);
        $expect($status===200&&(int)$current['placement']['id']===$a&&count($current['identity']['family'])===2,'Current placement exposes related retained placement numbers');
        [$status,$originalLinks]=$request('GET',$links,null,true,$workspace);
        $expect($status===200&&count($originalLinks['mappings'])===1&&$originalLinks['mappings'][0]['id']===(int)$primary['id']
            &&$originalLinks['mappings'][0]['internal_entity_id']===$b&&$originalLinks['canonical_internal_id']===$a,
            'Historical source view reads the original workspace and physical provider pair');
        [$status,$currentLinks]=$request('GET',$mappings.'&action=list_for_internal&internal_id='.$a,null,true,$workspace);
        $expect($status===200&&count($currentLinks['mappings'])===2&&array_column($currentLinks['mappings'],'internal_entity_id')===[$a,$b],
            'Current source view includes retained family links without rewriting their original identities');
        [$status,$externalView]=$request('GET',$mappings.'&action=find_external&source_system=jobdiva&internal_id='.$a,null,true,$workspace);
        $expect($status===200&&$externalView['mapping']===null&&$externalView['requires_source_selection'],
            'Multiple retained provider IDs require source selection instead of an arbitrary reverse link');
        [$status,$reverse]=$request('GET',$mappings.'&action=find_internal&source_system=jobdiva&external_id=jd%3A99430001',null,true,$workspace);
        $expect($status===200&&$reverse['mapping']['internal_entity_id']===$b&&$reverse['canonical_internal_id']===$a,
            'Exact provider reverse lookup preserves physical identity alongside the current placement');
        [$status,$observations]=$request('GET',$historyPath,null,true,$workspace);
        $expect($status===200&&count($observations['rows'])===1&&$observations['rows'][0]['internal_entity_id']===$b
            &&$observations['rows'][0]['payload_after']['marker']==='original-workspace-history'&&!str_contains(json_encode($observations),'other-workspace-history'),
            'Historical source timeline keeps original observations and excludes another workspace');
        $expect($request('GET',$mappings.'&internal_id='.$foreign['placement'],null,true,$workspace)[0]===404
            &&$request('GET','/api/integrations/sync_history.php?entity_type=placement&internal_id='.$foreign['placement'],null,true,$workspace)[0]===404,
            'Provider link and source-history readers both refuse a foreign placement directory');
        $expect($request('GET',$links.'junk',null,true,$workspace)[0]===422&&$request('GET',$historyPath.'junk',null,true,$workspace)[0]===422,
            'Provider source readers reject partially parsed placement IDs');
        [$status,$time]=$request('GET',$timeHistory,null,true,$workspace);
        $expect($status===200&&$time['placement_id']===$b&&$time['current_placement_id']===$a&&$time['merged']
            &&array_column($time['entries'],'id')===[$timeIds[1],$timeIds[0]]&&array_column($time['entries'],'status')===['superseded','approved'],
            'Historical time preserves original and superseded rows while excluding other placements and workspaces');
        $expect($time['entries'][1]['approved_at']==='2026-10-08 12:00:00'&&!isset($time['entries'][1]['pay_rate'])&&!isset($time['entries'][1]['bill_rate']),
            'Original time history exposes recorded approval evidence without inventing rates');
        [$status,$page]=$request('GET',$timeHistory.'&limit=1',null,true,$workspace);
        [$nextStatus,$nextPage]=$request('GET',$timeHistory.'&limit=1&before_id='.$page['next_before_id'],null,true,$workspace);
        $expect($status===200&&$nextStatus===200&&array_column($page['entries'],'id')===[$timeIds[1]]&&array_column($nextPage['entries'],'id')===[$timeIds[0]]&&$nextPage['next_before_id']===null,
            'Original time history paginates without repeating or silently dropping earlier entries');
        $expect($request('POST',$timeHistory,[],true,$workspace)[0]===405&&$request('GET',$timeHistory.'&before_id=1junk',null,true,$workspace)[0]===422,
            'Time history refuses mutations and malformed cursors');
        $expect($request('GET','/modules/placements/api/time_history.php?placement_id='.$foreign['placement'],null,true,$workspace)[0]===404
            &&$request('GET','/modules/placements/api/time_history.php?placement_id='.$archived,null,true,$workspace)[0]===404,
            'Original time history refuses foreign placements and ordinary archives');
        [$status,$sheets]=$request('GET',$sheetList,null,true,$workspace);
        $expect($status===200&&$sheets['workspace_id']===$workspace&&$sheets['placement_id']===$b&&$sheets['current_placement_id']===$a&&$sheets['read_only']
            &&count($sheets['rows'])===1&&(int)$sheets['rows'][0]['id']===$sheet&&(float)$sheets['rows'][0]['placement_hours']===8.0,
            'Placement timesheet list retains the original workspace and excludes superseded hours from operational totals');
        [$status,$detail]=$request('GET',$sheetDetail,null,true,$workspace);
        $expect($status===200&&$detail['read_only']&&(int)$detail['timesheet']['id']===$sheet&&(float)$detail['placement_hours']===8.0
            &&array_map('intval',array_column($detail['entries'],'id'))===[$timeIds[0]]&&(int)$detail['entries'][0]['placement_id']===$b,
            'Placement timesheet detail retains original entries without substituting current or foreign-workspace time');
        [$status,$currentSheets]=$request('GET','/modules/staffing/api/timesheets.php?action=list_for_placement&placement_id='.$a,null,true,$workspace);
        $expect($status===200&&!$currentSheets['read_only']&&count($currentSheets['rows'])===1&&(float)$currentSheets['rows'][0]['placement_hours']===4.0,
            'Current placement timesheet totals exclude time retained on its historical placement');
        foreach ([$unrelatedSheet,$foreignSheet] as $otherSheet) $expect($request('GET','/modules/staffing/api/timesheets.php?action=detail_for_placement&placement_id='.$b.'&id='.$otherSheet,null,true,$workspace)[0]===404,
            'Placement timesheet detail refuses a header without original-workspace placement entries: '.$otherSheet);
        [$status,$filtered]=$request('GET',$sheetList.'&period_start=2026-10-08',null,true,$workspace);
        $expect($status===200&&$filtered['rows']===[],'Placement timesheet period filters preserve an empty matching result');
        $expect($request('GET',$sheetList.'&period_start=2026-02-30',null,true,$workspace)[0]===422&&$request('GET',$sheetDetail.'junk',null,true,$workspace)[0]===422,
            'Placement timesheets reject invalid periods and partially parsed header IDs');
        foreach ([$foreign['placement'],$archived] as $unavailable) $expect($request('GET','/modules/staffing/api/timesheets.php?action=list_for_placement&placement_id='.$unavailable,null,true,$workspace)[0]===404,
            'Operational timesheet list refuses a foreign or ordinary archived placement: '.$unavailable);
        [$status,$historyDocuments]=$request('GET',$documentPath,null,true,$workspace);
        $expect($status===200&&count($historyDocuments['documents'])===1&&(int)$historyDocuments['documents'][0]['id']===$documentId
            &&(int)$historyDocuments['documents'][0]['placement_id']===$b&&!$historyDocuments['context']['permissions']['manage'],
            'Historical placement lists its original document with management disabled');
        [$status,$download]=$request('GET',$documentPath.'&id='.$documentId,null,true,$workspace);
        $expect($status===200&&(int)$download['document']['placement_id']===$b&&is_string($download['signed_url']??null)
            &&!isset($download['document']['s3_key'])&&$documentDriver->head($key)===$originalFile,
            'Historical download verifies original physical evidence without transferring storage ownership');
        [$status,$currentDocuments]=$request('GET',$documents.'?placement_id='.$a,null,true,$workspace);
        $expect($status===200&&$currentDocuments['documents']===[],'Current placement does not relabel historical documents as its own');
        $expect($request('GET',$documents.'?placement_id='.$a.'&id='.$documentId,null,true,$workspace)[0]===409,
            'Historical document download cannot substitute the current placement identity');
        $expect($request('GET',$documentPath.'&action=upload_url&file_name=another.txt',null,true,$workspace)[0]===409
            &&$request('POST',$documentPath,$documentBody,true,$workspace)[0]===409
            &&$request('DELETE',$documentPath.'&id='.$documentId,null,true,$workspace)[0]===409,
            'Historical documents cannot be uploaded, rebound or retired through the original placement');
        $expect($request('GET',$documents.'?placement_id='.$archived,null,true,$workspace)[0]===409,
            'An ordinary archive does not acquire historical document access');
        $pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$workspace]);
        $expect($request('GET',$documentPath,null,true,$workspace)[0]===409&&$request('GET',$documents.'?id='.$documentId,null,true,$workspace)[0]===409,
            'Historical document list and download both recheck the current directory policy');
        $expect($request('GET',$timeHistory,null,true,$workspace)[0]===404,'Original time history rechecks the current placement directory');
        $expect($request('GET',$sheetList,null,true,$workspace)[0]===404&&$request('GET',$sheetDetail,null,true,$workspace)[0]===404,
            'Both placement timesheet readers recheck current directory ownership');
        $expect($request('GET',$links,null,true,$workspace)[0]===404&&$request('GET',$historyPath,null,true,$workspace)[0]===404,
            'Historical source links and observations follow current placement directory policy');
        $pdo->prepare('UPDATE tenant_module_scope SET scope_mode="shared" WHERE tenant_id=? AND module_key="placements"')->execute([$workspace]);
        $expect($request('GET',$placementEndpoint.'?id='.$foreign['placement'],null,true,$workspace)[0]===404,'Historical navigation cannot disclose a foreign directory');
        $expect($request('GET',$placementEndpoint.'?id='.$archived,null,true,$workspace)[0]===404,'Ordinary archives are not restored through history navigation');
        $expect($request('GET',$placementEndpoint.'?number=PL-1junk',null,true,$workspace)[0]===422,'Navigation refuses malformed permanent numbers');
        $expect($snapshot($tables)===$after,'Placement history requests are fully read-only');
        RBACResolver::revokeModule($member,'integrations',$actor);
        $expect($request('GET',$links,null,true,$workspace)[0]===403&&$request('GET',$historyPath,null,true,$workspace)[0]===403,
            'Same native session loses original provider links and source history after integration access is revoked');
        RBACResolver::revokeModule($member,'staffing',$actor);
        $expect($request('GET',$timeHistory,null,true,$workspace)[0]===403,'Same native session loses original time history after staffing access is revoked');
        $expect($request('GET',$sheetList,null,true,$workspace)[0]===403&&$request('GET',$sheetDetail,null,true,$workspace)[0]===403,
            'Both placement timesheet readers deny the same session after staffing access is revoked');
        RBACResolver::grantModule($member,'placements','read',[$workspace],$actor);
        $expect($request('POST',$endpoint.'?action=apply',$body,true,$workspace)[0]===403&&$snapshot($tables)===$after,'Same native session cannot recover a merge receipt after write permission is removed');
        RBACResolver::revokeModule($member,'placements',$actor);
        $expect($request('GET',$placementEndpoint.'?id='.$b,null,true,$workspace)[0]===403,'Same native session loses original-history access immediately after revocation');
    }finally{
        $pdo->exec('DROP TRIGGER IF EXISTS cf_http_placement_merge_audit');
        if($member){
            $pdo->prepare('DELETE FROM membership_module_access WHERE membership_id=?')->execute([$member]);
            deactivateMembership($actor,$workspace);
        }
        $pdo->prepare('UPDATE tenants SET is_active=0 WHERE id=?')->execute([$workspace]);
        RBACResolver::resetCache();
    }
})();
