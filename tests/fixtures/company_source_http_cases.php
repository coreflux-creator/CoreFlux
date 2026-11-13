<?php
/** Native sessions, directory scopes and reviewed company/contact ownership. */
function companySourceHttpCases(PDO $pdo,int $parent,int $actor,callable $request,callable $expect): void
{
    require_once __DIR__.'/../../core/jobdiva/party_sync.php';
    $child=990611;$foreign=990612;$membership=null;$companyIds=[];$trigger=false;
    $route='/modules/people/api/companies.php';
    $read=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC);};
    try {
        if($pdo->query('SELECT id FROM tenants WHERE id IN(990611,990612)')->fetchColumn())throw new RuntimeException('Company source HTTP fixture already exists');
        $pdo->prepare('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type,parent_id) VALUES(?,"Synthetic company source child",1,1,"sub",?)')->execute([$child,$parent]);
        $pdo->prepare('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type) VALUES(?,"Synthetic company source foreign",1,1,"master")')->execute([$foreign]);
        $membership=(int)provisionMembership($actor,$child,'admin',['status'=>'active'])['membership_id'];
        $identity='/api/admin/integrations/jobdiva_party_review.php';
        $identityBody=['record_type'=>'companies','record_id'=>1,'source_id'=>'1001'];
        $expect($request($identity.'?action=preview',$identityBody,false,$child)[0]===401,'Anonymous company identity preview is denied');
        RBACResolver::grantModule($membership,'people','write',[$child],$actor);
        RBACResolver::grantModule($membership,'integrations','read',[$child],$actor);
        foreach(['preview','apply','status']as$action)$expect($request($identity.'?action='.$action,$identityBody,true,$child)[0]===403,'Integration reader cannot '.$action.' company links');
        RBACResolver::grantModule($membership,'integrations','admin',[$child],$actor);
        RBACResolver::grantModule($membership,'people','read',[$child],$actor);
        foreach(['preview','apply','status']as$action)$expect($request($identity.'?action='.$action,$identityBody,true,$child)[0]===403,'People reader cannot '.$action.' company links');
        RBACResolver::grantModule($membership,'people','write',[$child],$actor);
        $expect($request($identity.'?action=preview',null,true,$child)[0]===405,'Identity review refuses GET even with both permissions');
        $expect($request($identity.'?action=preview',$identityBody+['owner'=>$foreign],true,$child)[0]===422,'Identity endpoint refuses a caller-supplied directory owner');
        $expect($request($identity.'?action=preview',$identityBody,true,$child)[0]===409,'Authorized preview requires an actual JobDiva connection before provider transport');
        $expect($request($identity.'?action=apply',['review_token'=>'invalid'],true,$child)[0]===422,'Identity commit requires explicit confirmation');
        $expect($request($identity.'?action=apply',['review_token'=>'invalid','confirm_review'=>true],true,$child)[0]===422,'Identity commit refuses an invalid review token');
        $expect($request($identity.'?action=status',['review_token'=>'invalid'],true,$child)[0]===422,'Identity status cannot inspect an invented review');
        RBACResolver::revokeModule($membership,'integrations',$actor);
        $expect($request($identity.'?action=status',['review_token'=>'invalid'],true,$child)[0]===403,'Same native session loses link access immediately after integration grant removal');
        foreach(['shared','isolated']as$mode){
            $owner=$mode==='shared'?$parent:$child;
            $pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,"companies",?) ON DUPLICATE KEY UPDATE scope_mode=VALUES(scope_mode)')->execute([$child,$mode]);
            RBACResolver::grantModule($membership,'people','write',[$child],$actor);
            $pdo->prepare('INSERT INTO companies(tenant_id,name,phone) VALUES(?,?,?)')->execute([$owner,'Synthetic '.$mode.' company source','Core phone']);
            $company=(int)$pdo->lastInsertId();$companyIds[]=$company;
            $pdo->prepare('INSERT INTO company_contacts(tenant_id,company_id,name,phone,email) VALUES(?,?,?,?,?)')->execute([$owner,$company,'Synthetic contact','Core contact phone','contact@example.invalid']);
            $contact=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO companies(tenant_id,name) VALUES(?,?)')->execute([$foreign,'Foreign '.$mode.' company source']);
            $foreignCompany=(int)$pdo->lastInsertId();$companyIds[]=$foreignCompany;
            // Legacy inconsistent child rows must not leak through an otherwise
            // valid parent ID, nor be silently moved to the current directory.
            $pdo->prepare('INSERT INTO company_contacts(tenant_id,company_id,name,phone) VALUES(?,?,?,?)')->execute([$foreign,$company,'Foreign child contact','private foreign phone']);
            $foreignContact=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO company_addresses(tenant_id,company_id,line1,city) VALUES(?,?,?,?)')->execute([$foreign,$company,'Private foreign address','Foreign city']);
            $foreignAddress=(int)$pdo->lastInsertId();
            $sources=['companies'=>[$company,'company-'.$mode],'company_contacts'=>[$contact,'contact-'.$mode]];
            foreach($sources as$table=>[$id,$external]){
                mappingUpsert($child,'jobdiva',$table==='companies'?'company':'contact',$external,$id,['id'=>$external],'pull',$actor);
                companySourceObserve($pdo,$owner,$table,$id,['phone'=>'JobDiva phone','name'=>'JobDiva name'],['system'=>'jobdiva','workspace_id'=>$child,'external_id'=>$external]);
                $reviewUrl=$route.'?action=source_review&record_type='.$table.'&id='.$id;
                $acceptUrl=$route.'?action=source_accept&record_type='.$table.'&id='.$id;
                $expect($request($reviewUrl,null,false,$child)[0]===401,"$mode $table: anonymous review is denied");
                $expect($request($acceptUrl,['confirm_source'=>true],false,$child)[0]===401,"$mode $table: anonymous acceptance is denied");
                RBACResolver::grantModule($membership,'people','read',[$child],$actor);
                $expect($request($reviewUrl,null,true,$child)[0]===403,"$mode $table: reader cannot access source proposals");
                $expect($request($acceptUrl,['confirm_source'=>true],true,$child)[0]===403,"$mode $table: reader cannot accept source proposals");
                RBACResolver::grantModule($membership,'people','write',[$child],$actor);
                [$status,$response]=$request($reviewUrl,null,true,$child);$review=$response['review']??[];
                $expect($status===200&&$review['directory_owner_id']===$owner&&$review['source']['workspace_id']===$child
                    &&$review['current']['phone']!==$review['proposed']['phone'],"$mode $table: writer reviews native and source values in the configured directory");
                $expect($request($reviewUrl,[],true,$child)[0]===405&&$request($acceptUrl,null,true,$child)[0]===405,"$mode $table: review and acceptance enforce their HTTP methods");
                $body=['confirm_source'=>true,'fields'=>['phone'],'review_token'=>$review['review_token']];
                if($mode==='shared'){
                    $before=$read($table,$id);
                    $expect($request($reviewUrl,null,true,$parent)[0]===404&&$request($acceptUrl,$body,true,$parent)[0]===404&&$read($table,$id)===$before,"$mode $table: sharing a directory does not expose or release another workspace source authority");
                }
                foreach([['confirm_source'=>'true'],['fields'=>[]],['fields'=>['tenant_id']],['fields'=>['phone','phone']],['review_token'=>[]]]as$invalid)
                    $expect($request($acceptUrl,array_replace($body,$invalid),true,$child)[0]===422,"$mode $table: malformed selection or confirmation is rejected");
                $nativeUrl=$route.'?id='.$id.($table==='company_contacts'?'&action=contact':'');
                $expect($request($nativeUrl,['phone'=>null],true,$child,false,'PATCH')[0]===200,"$mode $table: native clearing uses the actual edit API");
                $cleared=$read($table,$id);
                $expect($request($acceptUrl,$body,true,$child)[0]===409&&$read($table,$id)===$cleared,"$mode $table: intervening native edit invalidates review without a partial write");
                companySourceObserve($pdo,$owner,$table,$id,['phone'=>'Updated JobDiva phone','name'=>'JobDiva name'],['system'=>'jobdiva','workspace_id'=>$child,'external_id'=>$external]);
                $expect($read($table,$id)['phone']===null,"$mode $table: source refresh preserves native clearing");
                [$status,$response]=$request($reviewUrl,null,true,$child);$body['review_token']=$response['review']['review_token'];
                [$status,$accepted]=$request($acceptUrl,$body,true,$child);
                $expect($status===200&&!$accepted['replayed']&&$read($table,$id)['phone']==='Updated JobDiva phone'
                    &&$read($table,$id)['name']!=='JobDiva name',"$mode $table: explicit review accepts only selected fields");
                $after=$read($table,$id);
                [$status,$retry]=$request($acceptUrl,$body,true,$child);
                $expect($status===200&&$retry['replayed']&&$read($table,$id)===$after,"$mode $table: retry returns the saved acceptance without changing the row");
                $expect($request($acceptUrl,array_replace($body,['fields'=>['name']]),true,$child)[0]===409,"$mode $table: same review cannot be reused for different fields");
                $expect($request($nativeUrl,['phone'=>'Later Core phone'],true,$child,false,'PATCH')[0]===200,"$mode $table: later native edit succeeds");
                [$status,$retry]=$request($acceptUrl,$body,true,$child);
                $expect($status===200&&$retry['replayed']&&$read($table,$id)['phone']==='Later Core phone',"$mode $table: old acceptance retry never overwrites a later native edit");
                [$status,$response]=$request($reviewUrl,null,true,$child);$body['review_token']=$response['review']['review_token'];
                $beforeAuditFailure=$read($table,$id);
                $pdo->exec("CREATE TRIGGER company_source_http_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='company.source.accepted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic company audit failure'; END IF; END");$trigger=true;
                $expect($request($acceptUrl,$body,true,$child)[0]===500&&$read($table,$id)===$beforeAuditFailure,"$mode $table: required audit failure rolls back values and ownership");
                $pdo->exec('DROP TRIGGER company_source_http_audit_failure');$trigger=false;
                RBACResolver::grantModule($membership,'people','read',[$child],$actor);
                $expect($request($acceptUrl,$body,true,$child)[0]===403,"$mode $table: same session loses acceptance permission immediately on downgrade");
                RBACResolver::grantModule($membership,'people','write',[$child],$actor);
            }
            [$status,$detail]=$request($route.'?id='.$company,null,true,$child);
            $expect($status===200&&$detail['can_review_source']&&!$detail['can_link_source']&&!array_key_exists('coreflux_source_state',$detail['company'])
                &&!array_key_exists('coreflux_source_state',$detail['company']['contacts'][0]),"$mode: ordinary detail excludes private ownership state and exposes review permission");
            $expect(count($detail['company']['contacts'])===1&&(int)$detail['company']['contacts'][0]['id']===$contact
                &&$detail['company']['addresses']===[],"$mode: inconsistent foreign contacts and addresses are omitted from company detail");
            $expect($request($route.'?action=source_review&record_type=company_contacts&id='.$foreignContact,null,true,$child)[0]===404
                &&$request($route.'?action=contact&id='.$foreignContact,['phone'=>'should not write'],true,$child,false,'PATCH')[0]===404,
                "$mode: a valid parent cannot authorize a foreign contact review or edit");
            $expect($read('company_contacts',$foreignContact)['phone']==='private foreign phone'
                &&$read('company_addresses',$foreignAddress)['line1']==='Private foreign address',"$mode: foreign child records remain unchanged");
            $expect($request($route.'?action=source_review&id='.$foreignCompany,null,true,$child)[0]===404,"$mode: foreign company ID cannot reveal source values");
            $expect($request($route.'?action=source_accept&id='.$foreignCompany,['confirm_source'=>true,'fields'=>['phone'],'review_token'=>'foreign'],true,$child)[0]===404,"$mode: foreign company ID cannot accept source values");
            $pdo->prepare('UPDATE companies SET deleted_at=NOW() WHERE id=?')->execute([$company]);
            $expect($request($route.'?action=source_review&record_type=company_contacts&id='.$contact,null,true,$child)[0]===404,"$mode: contact review cannot bypass its retired parent");
        }
    } finally {
        if($trigger)$pdo->exec('DROP TRIGGER company_source_http_audit_failure');
        $pdo->prepare('DELETE FROM external_entity_mappings WHERE tenant_id=?')->execute([$child]);
        $pdo->prepare('DELETE FROM entity_sync_history WHERE tenant_id=?')->execute([$child]);
        if($companyIds){$ids=implode(',',array_map('intval',$companyIds));$pdo->exec('DELETE FROM companies WHERE id IN('.$ids.')');$pdo->exec("DELETE FROM core_record_numbers WHERE entity_type='company' AND record_id IN(".$ids.')');}
        if($membership!==null){RBACResolver::revokeModule($membership,'integrations',$actor);RBACResolver::revokeModule($membership,'people',$actor);deactivateMembership($actor,$child);}
        $pdo->prepare('DELETE FROM tenant_module_scope WHERE tenant_id=?')->execute([$child]);
        $pdo->exec('UPDATE tenants SET is_active=0 WHERE id IN(990611,990612)');
    }
}
