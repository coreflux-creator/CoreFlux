<?php
/** Actual sessions and endpoints; only disposable synthetic directory rows. */
function staffingClientNativeHttpCases(PDO $pdo,int $parent,int $actor,callable $request,callable $expect): void
{
    require_once __DIR__.'/../../modules/staffing/lib/client_write.php';
    require_once __DIR__.'/../../core/jobdiva/sync.php';
    require_once __DIR__.'/../../modules/people/lib/company_merge_review.php';
    $child=990682;$membership=null;$created=false;$companies=[];$clients=[];$people=[];$placements=[];$trigger=false;
    $url='/modules/staffing/api/clients.php?action=';
    $read=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC)?:[];};
    try{
        if($pdo->query('SELECT id FROM tenants WHERE id=990682')->fetchColumn())throw new RuntimeException('Native client fixture already exists');
        $pdo->prepare('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type,parent_id) VALUES(?,"Synthetic native client child",1,1,"sub",?)')->execute([$child,$parent]);$created=true;
        $membership=(int)provisionMembership($actor,$child,'admin',['status'=>'active'])['membership_id'];
        foreach(['shared/shared','shared/isolated','isolated/isolated']as$mode){
            [$companyMode,$clientMode]=explode('/',$mode);$companyOwner=$companyMode==='shared'?$parent:$child;$owner=$clientMode==='shared'?$parent:$child;
            foreach(['companies'=>$companyMode,'placements'=>$clientMode]as$module=>$setting)$pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,?,?) ON DUPLICATE KEY UPDATE scope_mode=VALUES(scope_mode)')->execute([$child,$module,$setting]);
            $body=['name'=>'Synthetic native client '.$mode,'primary_contact_phone'=>'Original','payment_terms_days'=>30,'billing_country'=>''];
            $expect($request($url.'create',$body,false,$child)[0]===401,$mode.': anonymous client create is denied');
            RBACResolver::grantModule($membership,'placements','read',[$child],$actor);
            foreach(['create','update','delete','bulk_update']as$action)$expect($request($url.$action,$body,true,$child)[0]===403,$mode.': client reader cannot '.$action);
            RBACResolver::grantModule($membership,'placements','write',[$child],$actor);
            [$status,$response]=$request($url.'create',$body,true,$child);$client=$response['client']??[];$id=(int)($client['id']??0);$company=(int)($client['company_id']??0);
            if($id)$clients[]=$id;if($company)$companies[]=$company;
            $expect($status===200&&$id>0&&(int)$client['tenant_id']===$owner&&(int)$read('companies',$company)['tenant_id']===$companyOwner,$mode.': client and company are created in their independent owners');
            $expect($read('companies',$company)['country']==='US'&&$client['billing_country']==='US',$mode.': blank country preserves the default on both rows');
            [$status,$detail]=$request($url.'get&id='.$id,null,true,$child);$detail=$detail['client']??[];
            $expect($status===200&&!empty($detail['client_number'])&&!empty($detail['company_number'])&&!empty($detail['edit_revision']),$mode.': detail returns both permanent numbers and a scoped edit revision');
            [$status,$list]=$request($url.'list',null,true,$child);$listed=array_values(array_filter($list['rows']??[],static fn($r)=>(int)$r['id']===$id));
            $expect($status===200&&count($listed)===1&&$listed[0]['edit_revision']===$detail['edit_revision']&&$listed[0]['company_number']===$detail['company_number'],$mode.': list supplies the same full-row revision and independently scoped company number');
            $before=[$read('staffing_clients',$id),$read('companies',$company)];
            $expect($request($url.'update',['id'=>$id,'primary_contact_phone'=>'No token'],true,$child)[0]===409,$mode.': tokenless edit is rejected');
            $expect($request($url.'update',['id'=>$id,'payment_terms_days'=>1.5,'edit_revision'=>$detail['edit_revision']],true,$child)[0]===422,$mode.': fractional payment terms are rejected');
            $expect([$read('staffing_clients',$id),$read('companies',$company)]===$before,$mode.': invalid client requests preserve both rows');
            $pdo->exec("CREATE TRIGGER client_native_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='staffing.client.updated' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic client audit failure'; END IF; END");$trigger=true;
            $expect($request($url.'update',['id'=>$id,'primary_contact_phone'=>'Must roll back','edit_revision'=>$detail['edit_revision']],true,$child)[0]===500,$mode.': required client audit failure is returned');
            $expect([$read('staffing_clients',$id),$read('companies',$company)]===$before,$mode.': client audit failure rolls back company values and ownership too');
            $pdo->exec('DROP TRIGGER client_native_audit_failure');$trigger=false;
            [$status,$saved]=$request($url.'update',['id'=>$id,'primary_contact_phone'=>'','edit_revision'=>$detail['edit_revision']],true,$child);$saved=$saved['client']??[];
            $expect($status===200&&(int)$saved['id']===$id&&(int)$saved['company_id']===$company&&$read('companies',$company)['primary_contact_phone']==='',$mode.': explicit clearing preserves the same client/company identities');
            companySourceObserve($pdo,$companyOwner,'companies',$company,['primary_contact_phone'=>'Later source'],['system'=>'jobdiva','workspace_id'=>$child,'external_id'=>'native-client-'.$mode]);
            $state=companySourceState($read('companies',$company),'companies');
            $expect($read('companies',$company)['primary_contact_phone']===''&&$state['proposed']['primary_contact_phone']==='Later source'&&in_array('primary_contact_phone',$state['owned'],true),$mode.': native company clearing stays owned when a source proposes another value');
            $expect($request($url.'update',['id'=>$id,'notes'=>'Stale source review','edit_revision'=>$saved['edit_revision']],true,$child)[0]===409,$mode.': intervening company/source changes invalidate the old client form');
            [$status,$detail]=$request($url.'get&id='.$id,null,true,$child);$detail=$detail['client'];
            [$status,$bulk]=$request($url.'bulk_update',['ids'=>[$id],'field'=>'payment_terms_days','value'=>45,'edit_revisions'=>[$id=>$detail['edit_revision']]],true,$child);
            $expect($status===200&&$bulk['updated']===1&&(int)$read('staffing_clients',$id)['payment_terms_days']===45,$mode.': bulk edit uses a current per-client revision');
            [$status,$bulk]=$request($url.'bulk_update',['ids'=>[$id],'field'=>'payment_terms_days','value'=>60,'edit_revisions'=>[$id=>$detail['edit_revision']]],true,$child);
            $expect($status===200&&$bulk['failed']===1&&(int)$read('staffing_clients',$id)['payment_terms_days']===45,$mode.': stale bulk edit reports the failed row without overwriting it');
            [$status,$detail]=$request($url.'get&id='.$id,null,true,$child);$detail=$detail['client'];
            $pdo->prepare('INSERT INTO people(tenant_id,first_name,last_name) VALUES(?,"Synthetic client","Worker")')->execute([$owner]);$person=(int)$pdo->lastInsertId();$people[]=$person;
            $pdo->prepare('INSERT INTO placements(tenant_id,person_id,title,status,engagement_type,start_date,client_id) VALUES(?,?,"Synthetic client assignment","active","W2","2026-11-01",?)')->execute([$owner,$person,$id]);$placement=(int)$pdo->lastInsertId();$placements[]=$placement;
            $expect($request($url.'delete',['id'=>$id,'edit_revision'=>$detail['edit_revision']],true,$child)[0]===409&&$read('staffing_clients',$id)['status']==='active',$mode.': active bill-to placement prevents client closure even without an end-client match');
            $pdo->prepare('UPDATE placements SET status="ended" WHERE tenant_id=? AND id=?')->execute([$owner,$placement]);
            $expect($request($url.'delete',['id'=>$id,'edit_revision'=>$detail['edit_revision']],true,$child)[0]===200&&$read('staffing_clients',$id)['status']==='closed',$mode.': closure retains client and company numbers after assignments end');
            [$status,$detail]=$request($url.'get&id='.$id,null,true,$child);$detail=$detail['client'];
            $pdo->prepare('INSERT INTO companies(tenant_id,name) VALUES(?,?)')->execute([$companyOwner,'Native client survivor '.$mode]);$survivor=(int)$pdo->lastInsertId();$companies[]=$survivor;
            $review=companyMergePreview($child,['id'=>$actor,'role'=>'admin'],$survivor,$company);
            companyMergeApply($child,['id'=>$actor,'role'=>'admin'],$review['review_token'],['fields'=>array_fill_keys($review['fields'],'survivor'),'custom_fields'=>[],'primary_contact_id'=>0,'primary_addresses'=>[]]);
            $expect($request($url.'update',['id'=>$id,'name'=>'Stale pre-merge name','edit_revision'=>$detail['edit_revision']],true,$child)[0]===409&&(int)$read('staffing_clients',$id)['company_id']===$survivor,$mode.': a pre-merge client form cannot overwrite the surviving company');
            $pdo->prepare('UPDATE tenant_module_scope SET scope_mode=? WHERE tenant_id=? AND module_key="placements"')->execute([$clientMode==='shared'?'isolated':'shared',$child]);
            $expect($request($url.'update',['id'=>$id,'notes'=>'Wrong directory','edit_revision'=>$detail['edit_revision']],true,$child)[0]!==200,$mode.': changed client ownership cannot edit the former directory');
        }
        foreach(['companies'=>'isolated','placements'=>'shared']as$module=>$mode)$pdo->prepare('UPDATE tenant_module_scope SET scope_mode=? WHERE tenant_id=? AND module_key=?')->execute([$mode,$child,$module]);
        $expect($request($url.'create',['name'=>'Refused shared client'],true,$child)[0]===409,'Shared client cannot be created with a private child company directory');
    }finally{
        if($trigger)$pdo->exec('DROP TRIGGER client_native_audit_failure');
        foreach($placements as$id){$pdo->prepare('DELETE FROM placements WHERE id=?')->execute([$id]);$pdo->prepare('DELETE FROM core_record_numbers WHERE entity_type="placement" AND record_id=?')->execute([$id]);}
        foreach($people as$id){$pdo->prepare('DELETE FROM people WHERE id=?')->execute([$id]);$pdo->prepare('DELETE FROM core_record_numbers WHERE entity_type="person" AND record_id=?')->execute([$id]);}
        foreach($clients as$id){$pdo->prepare('DELETE FROM staffing_clients WHERE id=?')->execute([$id]);$pdo->prepare('DELETE FROM core_record_numbers WHERE entity_type="client" AND record_id=?')->execute([$id]);}
        foreach($companies as$id){$pdo->prepare('DELETE FROM companies WHERE id=?')->execute([$id]);$pdo->prepare('DELETE FROM core_record_numbers WHERE entity_type="company" AND record_id=?')->execute([$id]);}
        if($membership!==null){RBACResolver::revokeModule($membership,'placements',$actor);deactivateMembership($actor,$child);}
        if($created){$pdo->prepare('DELETE FROM audit_log WHERE tenant_id=?')->execute([$child]);$pdo->prepare('DELETE FROM tenant_module_scope WHERE tenant_id=?')->execute([$child]);$pdo->prepare('UPDATE tenants SET is_active=0 WHERE id=?')->execute([$child]);}
    }
}
