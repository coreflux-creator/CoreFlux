<?php
/** Actual native creation/edit endpoints, scoped references and atomic rollback. */
function placementNativeReferenceHttpCases(PDO $pdo,int $parent,int $actor,callable $request,callable $expect): void
{
    require_once __DIR__.'/../../modules/people/lib/company_merge_review.php';
    require_once __DIR__.'/../../core/integrations/entity_mappings.php';
    require_once __DIR__.'/../../core/integrations/stable_mapping.php';
    $child=990697;$membership=null;$created=false;$trigger=false;
    $ids=['companies'=>[],'staffing_clients'=>[],'staffing_jobs'=>[],'people'=>[],'placements'=>[],'accounting_entities'=>[]];
    $url='/modules/placements/api/placements.php';
    $insert=static function(string $table,array $row)use($pdo,&$ids):int{
        $pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
        $id=(int)$pdo->lastInsertId();$ids[$table][]=$id;return$id;
    };
    $read=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC)?:[];};
    $all=static fn(string $table):array=>$pdo->query('SELECT * FROM '.$table.' ORDER BY '.($table==='core_record_numbers'?'tenant_id,entity_type,record_id':'id'))->fetchAll(PDO::FETCH_ASSOC);
    $financial=[$all('accounting_journal_entries'),$all('accounting_journal_entry_lines'),$all('payroll_runs'),$all('billing_payments'),$all('ap_payments')];
    $cycles=array_column($all('staffing_operating_cycles'),'id');
    $setScope=static function(string $module,string $mode)use($pdo,$child):void{$pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,?,?) ON DUPLICATE KEY UPDATE scope_mode=VALUES(scope_mode)')->execute([$child,$module,$mode]);};
    try{
        if($pdo->query('SELECT id FROM tenants WHERE id=990697')->fetchColumn())throw new RuntimeException('Native placement fixture already exists');
        $pdo->prepare('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type,parent_id) VALUES(?,"Synthetic native placement child",1,1,"sub",?)')->execute([$child,$parent]);$created=true;
        $membership=(int)provisionMembership($actor,$child,'admin',['status'=>'active'])['membership_id'];
        RBACResolver::grantModule($membership,'people','write',[$child],$actor);
        $entity=$insert('accounting_entities',['tenant_id'=>$parent,'code'=>'NATIVE-REF','legal_name'=>'Synthetic placement employer']);
        $privateEntity=$insert('accounting_entities',['tenant_id'=>$child,'code'=>'PRIVATE-REF','legal_name'=>'Private synthetic employer']);
        foreach(['shared/shared','shared/isolated','isolated/isolated']as$mode){
            [$companyMode,$placementMode]=explode('/',$mode);$companyOwner=$companyMode==='shared'?$parent:$child;$owner=$placementMode==='shared'?$parent:$child;
            foreach(['companies'=>$companyMode,'placements'=>$placementMode,'staffing'=>'shared','accounting'=>'shared']as$module=>$setting)$setScope($module,$setting);
            $company=$insert('companies',['tenant_id'=>$companyOwner,'name'=>'Synthetic placement bill-to '.$mode]);
            $recipient=$insert('companies',['tenant_id'=>$companyOwner,'name'=>'Synthetic work recipient '.$mode]);
            $client=$insert('staffing_clients',['tenant_id'=>$owner,'company_id'=>$company,'name'=>'Synthetic placement bill-to '.$mode]);
            $job=$insert('staffing_jobs',['tenant_id'=>$parent,'title'=>'Synthetic independent job '.$mode]);
            $person=$insert('people',['tenant_id'=>$owner,'first_name'=>'Synthetic','last_name'=>'Placement worker','email_primary'=>'native-'.str_replace('/','-',$mode).'@example.invalid','classification'=>'w2']);
            $body=['person_id'=>$person,'title'=>'Synthetic native placement '.$mode,'start_date'=>'2026-11-01','engagement_type'=>'w2','client_id'=>$client,'end_client_company_id'=>$recipient,'staffing_job_id'=>$job,'accounting_entity_id'=>$entity];
            $expect($request($url,$body,false,$child)[0]===401,$mode.': anonymous native placement create is denied');
            RBACResolver::grantModule($membership,'placements','read',[$child],$actor);
            $expect($request($url,$body,true,$child)[0]===403,$mode.': placement reader cannot create');
            RBACResolver::grantModule($membership,'placements','write',[$child],$actor);
            [$status,$response]=$request($url,$body,true,$child);$placement=$response['placement']??[];$id=(int)($placement['id']??0);if($id)$ids['placements'][]=$id;
            $expect($status===201&&$id>0&&(int)$placement['tenant_id']===$owner,$mode.': native create uses the configured placement owner');
            $expect((int)$placement['client_id']===$client&&(int)$placement['end_client_company_id']===$recipient&&(int)$placement['person_id']===$person&&(int)$placement['accounting_entity_id']===$entity,$mode.': billing, recipient, worker and employer retain separate exact identities');
            $expect($placement['staffing_job_title']==='Synthetic independent job '.$mode&&!empty($placement['placement_number']),$mode.': create returns the independent job title and permanent placement number');
            $owned=json_decode($read('placements',$id)['coreflux_overridden_fields'],true);
            $expect(count(array_intersect(['client_id','end_client_company_id','person_id','accounting_entity_id','title'],$owned))===5,$mode.': native creation owns its selected fields before a future source link');
            [$status,$detail]=$request($url.'?id='.$id,null,true,$child);
            $expect($status===200&&$detail['placement']['staffing_job_title']===$placement['staffing_job_title'],$mode.': native detail reads the independently owned job');
            [$status,$list]=$request($url.'?q='.rawurlencode('Synthetic independent job '.$mode),null,true,$child);
            $rows=array_values(array_filter($list['rows']??[],static fn($r)=>(int)$r['id']===$id));
            $expect($status===200&&count($rows)===1&&$rows[0]['staffing_job_title']===$placement['staffing_job_title'],$mode.': list search resolves the same independent job directory');
            $before=$read('placements',$id);$audit=$all('audit_log');
            foreach(['person_id'=>true,'client_id'=>$client.'.5','end_client_company_id'=>true,'staffing_job_id'=>$job.'.5','accounting_entity_id'=>true]as$field=>$value){
                $expect($request($url.'?id='.$id,[$field=>$value],true,$child,false,'PATCH')[0]===422,$mode.': native edit rejects malformed '.$field);
            }
            $expect($read('placements',$id)===$before&&$all('audit_log')===$audit,$mode.': malformed references make no business or audit changes');
            $placementRows=$all('placements');
            $pdo->prepare('UPDATE staffing_clients SET status="inactive" WHERE id=?')->execute([$client]);
            $expect($request($url,$body,true,$child)[0]===422,$mode.': new placement cannot choose an inactive billing client');
            $pdo->prepare('UPDATE staffing_clients SET status="active" WHERE id=?')->execute([$client]);
            $pdo->prepare('UPDATE people SET deleted_at=NOW() WHERE id=?')->execute([$person]);
            $expect($request($url,$body,true,$child)[0]===422,$mode.': new placement cannot choose a retired worker');
            $pdo->prepare('UPDATE people SET deleted_at=NULL WHERE id=?')->execute([$person]);
            $pdo->prepare('UPDATE accounting_entities SET active=0 WHERE id=?')->execute([$entity]);
            $expect($request($url,$body,true,$child)[0]===422,$mode.': new placement cannot choose an inactive legal entity');
            $pdo->prepare('UPDATE accounting_entities SET active=1 WHERE id=?')->execute([$entity]);
            $pdo->prepare('UPDATE companies SET deleted_at=NOW() WHERE id=?')->execute([$company]);
            $expect($request($url,$body,true,$child)[0]===422,$mode.': billing client cannot hide a retired company reference');
            $pdo->prepare('UPDATE companies SET deleted_at=NULL WHERE id=?')->execute([$company]);
            $expect($request($url.'?id='.$id,['person_id'=>$person+100000000],true,$child,false,'PATCH')[0]===422,$mode.': native person change requires an existing current worker');
            $expect($request($url.'?id='.$id,['staffing_job_id'=>$job+100000000],true,$child,false,'PATCH')[0]===422,$mode.': native job change requires a job in its configured directory');
            $expect($all('placements')===$placementRows&&$all('audit_log')===$audit,$mode.': unavailable references leave placements and audit unchanged');
            RBACResolver::grantModule($membership,'placements','read',[$child],$actor);
            $expect($request($url.'?id='.$id,['title'=>'Denied'],true,$child,false,'PATCH')[0]===403,$mode.': reader cannot edit an existing placement');
            RBACResolver::grantModule($membership,'placements','write',[$child],$actor);
            // A mapped placement can have a legacy external number and belong to
            // a directory shared by several source workspaces.
            $pdo->prepare('UPDATE placements SET external_id=? WHERE id=?')->execute(['legacy-native-'.$id,$id]);
            mappingBindStable($placementMode==='shared'?$parent:$child,'jobdiva','placement','native-reference-'.$id,$id,null,'pull',$actor);
            $mapped=$read('placements',$id);$mapping=$all('external_entity_mappings');
            foreach(['external_id'=>'replacement','person_id'=>$person+1000000,'jobdiva_job_id'=>'replacement']as$field=>$value)$expect($request($url.'?id='.$id,[$field=>$value],true,$child,false,'PATCH')[0]===409,$mode.': mapped legacy placement protects '.$field.' across shared workspaces');
            $expect($read('placements',$id)===$mapped&&$all('external_entity_mappings')===$mapping,$mode.': rejected rebinding preserves original provider pairs');
            [$status,$edited]=$request($url.'?id='.$id,['notes'=>'Reviewed native note'],true,$child,false,'PATCH');
            $expect($status===200&&in_array('notes',json_decode($edited['placement']['coreflux_overridden_fields'],true),true),$mode.': mapped legacy placement retains native ownership');
            $replay=$read('placements',$id);$audit=$all('audit_log');
            $expect($request($url.'?id='.$id,['notes'=>'Reviewed native note'],true,$child,false,'PATCH')[0]===200&&$read('placements',$id)===$replay&&$all('audit_log')===$audit,$mode.': exact native edit replay writes no duplicate audit');
            $snapshot=[$all('people'),$all('companies'),$all('placements'),$all('placement_economic_parties'),$all('core_record_numbers'),$all('audit_log'),$all('staffing_operating_cycles')];
            $pdo->exec("CREATE TRIGGER placement_native_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='placement.created' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic placement audit failure'; END IF; END");$trigger=true;
            $inline=array_replace($body,['person_id'=>null,'new_person'=>['first_name'=>'Synthetic','last_name'=>'Rollback worker','email_primary'=>'rollback-'.str_replace('/','-',$mode).'@example.invalid'],'engagement_type'=>'temp_to_perm','end_client_company_id'=>null,'end_client_name'=>'Rollback recipient '.$mode]);
            $expect($request($url,$inline,true,$child)[0]===503,$mode.': required creation audit failure is reported');
            $expect([$all('people'),$all('companies'),$all('placements'),$all('placement_economic_parties'),$all('core_record_numbers'),$all('audit_log'),$all('staffing_operating_cycles')]===$snapshot,$mode.': failed inline creation rolls back person, company, placement, graph, permanent numbers and audit together');
            $pdo->exec('DROP TRIGGER placement_native_audit_failure');$trigger=false;
            [$status,$combined]=$request($url,$inline,true,$child);$new=$combined['placement']??[];
            if(!empty($new['id']))$ids['placements'][]=(int)$new['id'];if(!empty($new['person_id']))$ids['people'][]=(int)$new['person_id'];if(!empty($new['end_client_company_id']))$ids['companies'][]=(int)$new['end_client_company_id'];
            $expect($status===201&&$combined['person_created']===true&&(int)$new['person_id']!==$person&&(int)$new['client_id']===$client,$mode.': valid combined creation commits its own worker without changing billing identity');
            $companyBefore=$all('companies');$placeBefore=$read('placements',$id);
            $pdo->exec("CREATE TRIGGER placement_native_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='placement.updated' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic placement edit audit failure'; END IF; END");$trigger=true;
            $expect($request($url.'?id='.$id,['end_client_company_id'=>null,'end_client_name'=>'Uncommitted edit company '.$mode],true,$child,false,'PATCH')[0]===503,$mode.': required edit audit failure is reported');
            $expect($all('companies')===$companyBefore&&$read('placements',$id)===$placeBefore,$mode.': failed edit removes its new company and preserves placement ownership');
            $pdo->exec('DROP TRIGGER placement_native_audit_failure');$trigger=false;
            $survivor=$insert('companies',['tenant_id'=>$companyOwner,'name'=>'Synthetic recipient survivor '.$mode]);
            $review=companyMergePreview($child,['id'=>$actor,'role'=>'admin'],$survivor,$recipient);
            companyMergeApply($child,['id'=>$actor,'role'=>'admin'],$review['review_token'],['fields'=>array_fill_keys($review['fields'],'survivor'),'custom_fields'=>[],'primary_contact_id'=>0,'primary_addresses'=>[]]);
            $expect((int)$read('placements',$id)['end_client_company_id']===$survivor,$mode.': reviewed merge retains the placement and redirects only its recipient reference');
            $afterMerge=[$all('companies'),$read('placements',$id)];
            foreach([['end_client_company_id'=>$recipient],['end_client_name'=>'Synthetic work recipient '.$mode]]as$stale)$expect($request($url.'?id='.$id,$stale,true,$child,false,'PATCH')[0]===409,$mode.': stale recipient number or name cannot recreate a merged company');
            $expect([$all('companies'),$read('placements',$id)]===$afterMerge,$mode.': stale company choices preserve the merge and placement');
            $setScope('placements',$placementMode==='shared'?'isolated':'shared');
            $expect($request($url.'?id='.$id,['notes'=>'Wrong directory'],true,$child,false,'PATCH')[0]===404,$mode.': changed placement scope cannot write the former directory');
        }
        $setScope('placements','shared');$setScope('companies','isolated');
        $expect($request($url,array_replace($body,['person_id'=>$ids['people'][0],'client_id'=>null]),true,$child)[0]===409,'Shared placement rejects private child company references');
        $setScope('companies','shared');$setScope('staffing','isolated');
        $privateJob=$insert('staffing_jobs',['tenant_id'=>$child,'title'=>'Private child job']);
        $base=array_replace($body,['person_id'=>$ids['people'][0],'client_id'=>null,'end_client_company_id'=>null,'staffing_job_id'=>$privateJob]);
        $expect($request($url,$base,true,$child)[0]===409,'Shared placement cannot expose a private child job');
        $setScope('staffing','shared');$setScope('accounting','isolated');
        $expect($request($url,array_replace($base,['staffing_job_id'=>null,'accounting_entity_id'=>$privateEntity]),true,$child)[0]===409,'Shared placement cannot expose a private child legal entity');
        $expect([$all('accounting_journal_entries'),$all('accounting_journal_entry_lines'),$all('payroll_runs'),$all('billing_payments'),$all('ap_payments')]===$financial,'Native placement tests create no payroll runs, payments or financial postings');
    }finally{
        if($trigger)$pdo->exec('DROP TRIGGER placement_native_audit_failure');
        foreach($ids['placements']as$id){
            $pdo->prepare('DELETE FROM external_entity_mappings WHERE tenant_id IN(?,?) AND source_system="jobdiva" AND internal_entity_type="placement" AND internal_entity_id=?')->execute([$parent,$child,$id]);
            $pdo->prepare('DELETE FROM entity_sync_history WHERE tenant_id IN(?,?) AND source_system="jobdiva" AND internal_entity_type="placement" AND internal_entity_id=?')->execute([$parent,$child,$id]);
            $pdo->prepare('DELETE FROM placement_economic_parties WHERE placement_id=?')->execute([$id]);
        }
        foreach(['placements'=>'placement','people'=>'person','staffing_clients'=>'client','staffing_jobs'=>'job','companies'=>'company','accounting_entities'=>null]as$table=>$type)foreach(array_unique($ids[$table])as$id){
            $pdo->prepare('DELETE FROM '.$table.' WHERE id=?')->execute([$id]);
            if($type)$pdo->prepare('DELETE FROM core_record_numbers WHERE entity_type=? AND record_id=?')->execute([$type,$id]);
        }
        foreach($all('staffing_operating_cycles')as$row)if(in_array((int)$row['tenant_id'],[$parent,$child],true)&&!in_array($row['id'],$cycles,true))$pdo->prepare('DELETE FROM staffing_operating_cycles WHERE id=?')->execute([$row['id']]);
        if($membership!==null){foreach(['placements','people']as$module)RBACResolver::revokeModule($membership,$module,$actor);deactivateMembership($actor,$child);}
        if($created){foreach(['audit_log','tenant_module_scope','tenant_end_clients']as$table)$pdo->prepare('DELETE FROM '.$table.' WHERE tenant_id=?')->execute([$child]);$pdo->prepare('UPDATE tenants SET is_active=0 WHERE id=?')->execute([$child]);}
    }
}
