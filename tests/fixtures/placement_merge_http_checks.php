<?php
/** Native cookie, RBAC and reviewed placement consolidation; invented records only. */
(static function()use($pdo,$tenant,$actor,$foreign,$request,$expect,$snapshot,$graphTables,$financialTables):void{
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
        $payload=['startId'=>'99430001','candidateId'=>'99430002','jobId'=>'99430003','startDate'=>'2026-10-01','status'=>'on_hold','__cf_jobdiva_source_object'=>'assignment'];
        mappingUpsert($workspace,'jobdiva','placement','99430001',$a,$payload);$primary=mappingUpsert($workspace,'jobdiva','placement','jd:99430001',$b,$payload);
        $tables=array_values(array_unique([...$graphTables,'core_record_numbers','entity_sync_history',...$financialTables]));$before=$snapshot($tables);
        $pair=['survivor_id'=>$a,'retired_id'=>$b];
        $expect($request('GET',$endpoint.'?action=candidates',null,false,$workspace)[0]===401,'Placement merge candidates require native sign-in');
        RBACResolver::grantModule($member,'placements','read',[$workspace],$actor);
        $expect($request('GET',$endpoint.'?action=candidates',null,true,$workspace)[0]===403
            &&$request('POST',$endpoint.'?action=preview',$pair,true,$workspace)[0]===403,'Read-only module access cannot review or merge placements');
        RBACResolver::grantModule($member,'placements','write',[$workspace],$actor);
        $expect($request('POST',$endpoint.'?action=preview',$pair,true,$workspace)[0]===403&&$snapshot($tables)===$before,
            'General placement editing does not grant financial consolidation access');
        RBACResolver::grantModule($member,'placements','admin',[$workspace],$actor);
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
        $protected=$snapshot(['placement_rates','external_entity_mappings','time_entries',...$financialTables]);
        [$status,$saved]=$request('POST',$endpoint.'?action=apply',$body,true,$workspace);
        $expect($status===200&&!$saved['replayed']&&$saved['survivor_id']===$a&&$saved['retired_id']===$b,'Native reviewed merge succeeds with the authenticated reviewer: '.json_encode([$status,$saved]));
        $expect($snapshot(['placement_rates','external_entity_mappings','time_entries',...$financialTables])===$protected,'Native merge preserves every provider pair, approved rate, time record and financial result');
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
        $expect($request('GET',$placementEndpoint.'?id='.$foreign['placement'],null,true,$workspace)[0]===404,'Historical navigation cannot disclose a foreign directory');
        $expect($request('GET',$placementEndpoint.'?id='.$archived,null,true,$workspace)[0]===404,'Ordinary archives are not restored through history navigation');
        $expect($request('GET',$placementEndpoint.'?number=PL-1junk',null,true,$workspace)[0]===422,'Navigation refuses malformed permanent numbers');
        $expect($snapshot($tables)===$after,'Placement history requests are fully read-only');
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
