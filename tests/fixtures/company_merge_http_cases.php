<?php
/** Real login/session permissions and independent Companies/AP ownership. */
function companyMergeHttpCases(PDO $pdo,int $parent,int $actor,callable $request,callable $expect): void
{
    require_once __DIR__.'/../../modules/people/lib/company_merge_review.php';
    $child=990662;$foreign=990663;$membership=null;$companyIds=[];$vendorIds=[];$trigger=false;
    $previewUrl='/modules/people/api/companies.php?action=merge_preview';$applyUrl='/modules/people/api/companies.php?action=merge';
    $read=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC)?:[];};
    $choices=static fn(array $review):array=>['fields'=>array_fill_keys($review['fields'],'survivor'),'custom_fields'=>[],'primary_contact_id'=>0,'primary_addresses'=>[]];
    try{
        if($pdo->query('SELECT id FROM tenants WHERE id IN(990662,990663)')->fetchColumn())throw new RuntimeException('Company merge HTTP fixture already exists');
        $pdo->prepare('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type,parent_id) VALUES(?,"Synthetic merge HTTP child",1,1,"sub",?)')->execute([$child,$parent]);
        $pdo->prepare('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type) VALUES(?,"Synthetic merge HTTP foreign",1,1,"master")')->execute([$foreign]);
        $membership=(int)provisionMembership($actor,$child,'admin',['status'=>'active'])['membership_id'];
        $input=['survivor_id'=>1,'retired_id'=>2];
        foreach([$previewUrl,$applyUrl]as$url)$expect($request($url,$input,false,$child)[0]===401,'Anonymous reviewed company merge is denied: '.$url);
        RBACResolver::grantModule($membership,'people','write',[$child],$actor);
        RBACResolver::grantModule($membership,'integrations','read',[$child],$actor);
        foreach([$previewUrl,$applyUrl]as$url)$expect($request($url,$input,true,$child)[0]===403,'Integration reader cannot merge: '.$url);
        RBACResolver::grantModule($membership,'integrations','admin',[$child],$actor);
        RBACResolver::grantModule($membership,'people','read',[$child],$actor);
        foreach([$previewUrl,$applyUrl]as$url)$expect($request($url,$input,true,$child)[0]===403,'People reader cannot merge: '.$url);
        RBACResolver::grantModule($membership,'people','write',[$child],$actor);
        $expect($request($previewUrl,null,true,$child)[0]===405,'Merge review requires POST');
        $expect($request($applyUrl,$input,true,$child)[0]===422,'Legacy ID-only merge cannot bypass review');
        $expect($request($previewUrl,$input+['owner'=>$foreign],true,$child)[0]===422,'Merge review refuses caller-supplied directory owner');
        foreach(['shared','isolated']as$mode){
            $owner=$mode==='shared'?$parent:$child;$apOwner=$mode==='shared'?$child:$parent;
            foreach(['companies'=>$mode,'ap'=>$mode==='shared'?'isolated':'shared']as$module=>$setting)$pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,?,?) ON DUPLICATE KEY UPDATE scope_mode=VALUES(scope_mode)')->execute([$child,$module,$setting]);
            $pair=[];
            foreach(['Alpha','Beta']as$name){$pdo->prepare('INSERT INTO companies(tenant_id,name,phone,ein_full_ct,ein_last4) VALUES(?,?,?,?,?)')->execute([$owner,'Synthetic merge '.$mode.' '.$name,$name,'private-'.$name,'1234']);$pair[]=(int)$pdo->lastInsertId();}
            [$a,$b]=$pair;$companyIds=array_merge($companyIds,$pair);
            $pdo->prepare('INSERT INTO companies(tenant_id,name) VALUES(?,?)')->execute([$foreign,'Foreign merge '.$mode]);$other=(int)$pdo->lastInsertId();$companyIds[]=$other;
            $pdo->prepare('INSERT INTO ap_vendors_index(tenant_id,vendor_name,company_id) VALUES(?,?,?)')->execute([$apOwner,'Merge vendor '.$mode,$b]);$vendor=(int)$pdo->lastInsertId();$vendorIds[]=$vendor;
            mappingUpsert($child,'purepay','company','merge-http-'.$mode,$b,['preserved'=>true],'pull',$actor);
            // Another workspace's source metadata is retained internally, not disclosed.
            mappingUpsert($parent,'jobdiva','company','private-other-workspace-'.$mode,$b,['preserved'=>true],'pull',$actor);
            $input=['survivor_id'=>$a,'retired_id'=>$b];
            $expect($request($previewUrl,['survivor_id'=>$a,'retired_id'=>$other],true,$child)[0]===409,"$mode: foreign directory company cannot enter review");
            [$status,$response]=$request($previewUrl,$input,true,$child);$review=$response['review']??[];
            $expect($status===200&&!str_contains(json_encode($review),'private-')&&count($review['sources'])===1,"$mode: preview exposes only this workspace's source IDs and no private identifiers");
            $expect(count(array_filter($review['impact'],static fn($r)=>$r['table']==='ap_vendors_index'&&$r['count']===1))===1,"$mode: AP references are resolved independently of Companies sharing");
            $body=['review_token'=>$review['review_token'],'choices'=>$choices($review),'confirm_merge'=>true];
            $expect($request($applyUrl,array_replace($body,['confirm_merge'=>false]),true,$child)[0]===422,"$mode: apply requires explicit confirmation");
            $expect($request($applyUrl,$body,true,$parent)[0]!==200,"$mode: a review cannot move between workspaces");
            $pdo->prepare('UPDATE companies SET phone=? WHERE tenant_id=? AND id=?')->execute(['Changed after review',$owner,$a]);
            $expect($request($applyUrl,$body,true,$child)[0]===409&&$read('companies',$b)['deleted_at']===null,"$mode: stale preview cannot retire a company");
            [$status,$response]=$request($previewUrl,$input,true,$child);$body['review_token']=$response['review']['review_token'];$body['choices']['fields']['name']='retired';
            $before=[$read('companies',$a),$read('companies',$b),$read('ap_vendors_index',$vendor)];
            $pdo->exec("CREATE TRIGGER company_merge_http_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='company.merged.reviewed' AND NEW.tenant_id=990662 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic merge audit failure'; END IF; END");$trigger=true;
            [$status]=$request($applyUrl,$body,true,$child);
            $expect($status===500&&[$read('companies',$a),$read('companies',$b),$read('ap_vendors_index',$vendor)]===$before,"$mode: HTTP audit failure rolls back the company and AP reference together");
            $pdo->exec('DROP TRIGGER company_merge_http_audit_failure');$trigger=false;
            RBACResolver::grantModule($membership,'integrations','read',[$child],$actor);
            $expect($request($applyUrl,$body,true,$child)[0]===403,"$mode: same session cannot apply after integration downgrade");
            RBACResolver::grantModule($membership,'integrations','admin',[$child],$actor);
            [$status,$result]=$request($applyUrl,$body,true,$child);
            $expect($status===200&&!$result['replayed']&&$read('companies',$b)['deleted_at']!==null&&(int)$read('ap_vendors_index',$vendor)['company_id']===$a,"$mode: reviewed merge updates the correct independently-owned vendor");
            $expect($read('companies',$a)['name']===$before[1]['name']&&$read('companies',$b)['name']===$before[1]['name'],"$mode: choosing the other name preserves retired history");
            $pdo->prepare('UPDATE companies SET phone=? WHERE tenant_id=? AND id=?')->execute(['Later native value',$owner,$a]);
            [$status,$result]=$request($applyUrl,$body,true,$child);
            $expect($status===200&&$result['replayed']&&$read('companies',$a)['phone']==='Later native value',"$mode: lost-response retry returns receipt and preserves subsequent edits");
            $bad=$body;$bad['choices']['fields']['phone']='retired';$expect($request($applyUrl,$bad,true,$child)[0]===409,"$mode: used review cannot apply changed choices");
            $q=$pdo->prepare('SELECT internal_entity_id FROM external_entity_mappings WHERE tenant_id=? AND source_system="purepay" AND internal_entity_type="company" AND external_id=?');$q->execute([$child,'merge-http-'.$mode]);
            $expect((int)$q->fetchColumn()===$b&&companyIdentityResolve($pdo,$owner,$b)['id']===$a,"$mode: source mapping remains original while permanent number resolves to survivor");
            $mappingUrl='/api/integrations/mappings.php?entity_type=company';
            foreach([$a,$b]as$id){
                [$status,$linked]=$request($mappingUrl.'&internal_id='.$id,null,true,$child);
                $expect($status===200&&$linked['canonical_internal_id']===$a&&count($linked['mappings'])===1
                    &&$linked['mappings'][0]['internal_entity_id']===$b&&!isset($linked['mappings'][0]['payload_snapshot'])
                    &&!str_contains(json_encode($linked),'private-other-workspace'),"$mode: company mapping view follows either number without exposing another workspace or source payload");
            }
            $expect($request($mappingUrl.'&internal_id='.$other,null,true,$child)[0]===404,"$mode: source-list access cannot reveal a foreign company");
            [$status,$reverse]=$request($mappingUrl.'&action=find_internal&source_system=purepay&external_id=merge-http-'.$mode,null,true,$child);
            $expect($status===200&&$reverse['mapping']['internal_entity_id']===$b&&$reverse['mapping']['canonical_internal_id']===$a,"$mode: reverse mapping distinguishes original source record from canonical company");
            mappingUpsert($child,'purepay','company','merge-http-second-'.$mode,$a,['private'=>'never return'],'pull',$actor);
            [$status,$forward]=$request($mappingUrl.'&action=find_external&source_system=purepay&internal_id='.$a,null,true,$child);
            $expect($status===200&&$forward['mapping']===null&&$forward['requires_source_selection']&&count($forward['mappings'])===2,"$mode: multiple source identities require a choice instead of selecting an arbitrary ID");
            RBACResolver::revokeModule($membership,'people',$actor);
            $expect($request($mappingUrl.'&internal_id='.$a,null,true,$child)[0]===403,"$mode: integration access alone cannot inspect company source identities");
            RBACResolver::grantModule($membership,'people','write',[$child],$actor);
            RBACResolver::revokeModule($membership,'integrations',$actor);
            $expect($request($mappingUrl.'&internal_id='.$a,null,true,$child)[0]===403,"$mode: company access alone cannot inspect source identities");
            RBACResolver::grantModule($membership,'integrations','admin',[$child],$actor);
        }
    }finally{
        if($trigger)$pdo->exec('DROP TRIGGER company_merge_http_audit_failure');
        foreach($vendorIds as$id)$pdo->prepare('DELETE FROM ap_vendors_index WHERE id=?')->execute([$id]);
        foreach($companyIds as$id){
            $pdo->prepare('DELETE FROM external_entity_mappings WHERE internal_entity_type="company" AND internal_entity_id=? AND tenant_id IN(?,?)')->execute([$id,$parent,$child]);
            $pdo->prepare('DELETE FROM companies WHERE id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM core_record_numbers WHERE entity_type="company" AND record_id=?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM entity_sync_history WHERE tenant_id=?')->execute([$child]);
        $pdo->prepare('DELETE FROM audit_log WHERE tenant_id=?')->execute([$child]);
        if($membership!==null){RBACResolver::revokeModule($membership,'integrations',$actor);RBACResolver::revokeModule($membership,'people',$actor);deactivateMembership($actor,$child);}
        $pdo->prepare('DELETE FROM tenant_module_scope WHERE tenant_id=?')->execute([$child]);
        $pdo->exec('UPDATE tenants SET is_active=0 WHERE id IN(990662,990663)');
    }
}
