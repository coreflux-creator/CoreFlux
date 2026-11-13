<?php
/** Run only within the guarded native-login disposable CI fixture. */
declare(strict_types=1);

function apVendorDirectoryHttpCases(PDO $pdo, int $parent, int $actor, callable $request, callable $expect): void
{
    $child = 900012; $foreign = 900013; $membership = null;
    $prefix = 'Synthetic vendor directory ';
    $route = '/modules/ap/api/vendors.php';
    $companyIds = []; $trigger = false;
    $expect(!(int) $pdo->query('SELECT COUNT(*) FROM tenants WHERE id IN(900012,900013)')->fetchColumn(), 'Vendor directory fixture tenant IDs are unused');
    try {
        $pdo->prepare('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type,parent_id) VALUES(?,"Synthetic vendor directory child",1,1,"sub",?)')->execute([$child,$parent]);
        $pdo->prepare('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type) VALUES(?,"Synthetic vendor directory foreign",1,1,"master")')->execute([$foreign]);
        $membership = (int) provisionMembership($actor,$child,'admin',['status'=>'active'])['membership_id'];
        RBACResolver::grantModule($membership,'ap','admin',[$child],$actor);
        $company = static function(int $owner, string $name) use ($pdo,&$companyIds): int {
            $pdo->prepare('INSERT INTO companies(tenant_id,name) VALUES(?,?)')->execute([$owner,$name]);
            $id = (int) $pdo->lastInsertId(); $companyIds[] = $id; return $id;
        };
        $foreignCompany = $company($foreign,$prefix.'foreign');
        foreach ([['shared','isolated'],['isolated','shared'],['shared','shared'],['isolated','isolated']] as [$companyMode,$apMode]) {
            $label = "$companyMode Companies / $apMode AP";
            $name = $prefix.$companyMode.'-'.$apMode;
            $scope = $pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,?,?) ON DUPLICATE KEY UPDATE scope_mode=VALUES(scope_mode)');
            $scope->execute([$child,'companies',$companyMode]); $scope->execute([$child,'ap',$apMode]);
            $companyOwner = $companyMode === 'shared' ? $parent : $child;
            $vendorOwner = $apMode === 'shared' ? $parent : $child;
            $selected = $company($companyOwner,$name.' selected');
            $wrong = $company($companyMode === 'shared' ? $child : $parent,$name.' wrong');
            $replacement = $company($companyOwner,$name.' replacement');
            $deleted = $company($companyOwner,$name.' retired');
            $pdo->prepare('UPDATE companies SET deleted_at=NOW() WHERE id=?')->execute([$deleted]);
            $payload = ['vendor_name'=>$name,'vendor_type'=>'w9_business','company_id'=>$selected];
            [$status,$created] = $request($route,$payload,true,$child);
            $expect($status===201 && (int)($created['company_id']??0)===$selected, "$label: native create accepts the configured Companies directory");
            $id = (int)$created['id'];
            $read = $pdo->prepare('SELECT * FROM ap_vendors_index WHERE id=?'); $read->execute([$id]); $stored=$read->fetch(PDO::FETCH_ASSOC);
            $expect((int)$stored['tenant_id']===$vendorOwner && (int)$stored['company_id']===$selected, "$label: vendor and company retain their separate owners");
            [$status,$detail] = $request($route.'?id='.$id,null,true,$child);
            $expect($status===200 && $detail['vendor']['vendor_number']==='V-'.$id && $detail['vendor']['company_number']==='CO-'.$selected
                && $detail['vendor']['company_link_status']==='available', "$label: native detail resolves both canonical numbers");
            [$status,$listed]=$request($route.'?q='.rawurlencode($name).'&company_id='.$selected,null,true,$child);
            $expect($status===200 && $listed['total']===1 && (int)$listed['rows'][0]['id']===$id && $listed['rows'][0]['company_number']==='CO-'.$selected,
                "$label: filtered list and count use the same directory");
            [$status,$replayed]=$request($route,$payload,true,$child);
            $expect($status===201 && (int)$replayed['id']===$id, "$label: replay preserves the same vendor identity");
            foreach ([$wrong,$foreignCompany,$deleted,0,-1,false,1.5,[],(string)$selected.'junk'] as $invalid) {
                $bad=$payload; $bad['vendor_name']=$name.' rejected'; $bad['company_id']=$invalid;
                $expect($request($route,$bad,true,$child)[0]===422, "$label: invalid, foreign or retired company selection is rejected");
            }
            $bad=$payload; $bad['company_id']=$replacement;
            $expect($request($route,$bad,true,$child)[0]===409, "$label: same-name retry cannot reassign vendor identity");
            $read->execute([$id]);
            $expect($read->fetch(PDO::FETCH_ASSOC)===$stored, "$label: rejected associations and exact replay preserve the vendor row");
            $automatic=['vendor_name'=>$name.' automatic','vendor_type'=>'w9_business'];
            [$status,$auto]=$request($route,$automatic,true,$child);
            $autoCompany=(int)($auto['company_id']??0); if($autoCompany)$companyIds[]=$autoCompany;
            $q=$pdo->prepare('SELECT tenant_id,use_count FROM companies WHERE id=?');$q->execute([$autoCompany]);$companyRow=$q->fetch(PDO::FETCH_ASSOC);
            $expect($status===201 && (int)($companyRow['tenant_id']??0)===$companyOwner, "$label: automatic company creation uses Companies ownership");
            [$status,$again]=$request($route,$automatic,true,$child);$q->execute([$autoCompany]);
            $expect($status===201 && $again['id']===$auto['id'] && $again['company_id']===$auto['company_id'] && $q->fetch(PDO::FETCH_ASSOC)===$companyRow,
                "$label: automatic retry does not duplicate identity or increase company usage");
            $expect($request($route.'?action=toggle_pwp&id='.$id,['default_pwp'=>true],true,$child)[0]===200
                && (int)$pdo->query('SELECT default_pwp FROM ap_vendors_index WHERE id='.$id)->fetchColumn()===1,
                "$label: existing vendor settings write back to the AP owner");
            // Simulate a legacy wrong association. Reads must not reveal or repair it.
            $pdo->prepare('UPDATE ap_vendors_index SET company_id=? WHERE id=?')->execute([$foreignCompany,$id]);
            $read->execute([$id]);$legacy=$read->fetch(PDO::FETCH_ASSOC);
            [$status,$detail]=$request($route.'?id='.$id,null,true,$child);
            $expect($status===200 && $detail['vendor']['company_id']===null && $detail['vendor']['company_number']===null
                && $detail['vendor']['company_name']===null && $detail['vendor']['company_link_status']==='needs_review',
                "$label: foreign legacy relationship is unresolved without revealing its identity");
            [$status,$listed]=$request($route.'?company_id='.$foreignCompany,null,true,$child);
            $expect($status===200 && $listed['total']===0 && $listed['rows']===[], "$label: foreign company filter cannot discover linked vendors");
            $expect($request($route,['vendor_name'=>$name,'vendor_type'=>'w9_business'],true,$child)[0]===409,
                "$label: legacy wrong link cannot be silently adopted on update");
            $read->execute([$id]);$expect($read->fetch(PDO::FETCH_ASSOC)===$legacy,"$label: legacy association stays unchanged for explicit review");
        }
        RBACResolver::grantModule($membership,'ap','read',[$child],$actor);
        $expect($request($route,['vendor_name'=>$prefix.'denied'],true,$child)[0]===403,'Read-only AP grant cannot create a vendor or company');
        RBACResolver::grantModule($membership,'ap','admin',[$child],$actor);
        $failureName=$prefix.'audit failure';
        $pdo->exec("CREATE TRIGGER ap_vendor_directory_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='ap.vendor.created' AND JSON_UNQUOTE(JSON_EXTRACT(NEW.meta_json,'$.vendor_name'))=".$pdo->quote($failureName)." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic vendor audit failure'; END IF; END");$trigger=true;
        $expect($request($route,['vendor_name'=>$failureName,'vendor_type'=>'w9_business'],true,$child)[0]===500,'Required audit failure prevents vendor create success');
        foreach(['companies'=>'name','ap_vendors_index'=>'vendor_name']as$table=>$column){
            $q=$pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE '.$column.'=?');$q->execute([$failureName]);
            $expect((int)$q->fetchColumn()===0,'Required audit failure rolls back '.$table.' creation');
        }
        $pdo->exec('DROP TRIGGER ap_vendor_directory_audit_failure');$trigger=false;
    } finally {
        if($trigger)$pdo->exec('DROP TRIGGER ap_vendor_directory_audit_failure');
        $q=$pdo->prepare('SELECT id FROM ap_vendors_index WHERE tenant_id IN(?,?) AND vendor_name LIKE ?');$q->execute([$parent,$child,$prefix.'%']);
        $vendorIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        if($vendorIds){$ids=implode(',',$vendorIds);$pdo->exec('DELETE FROM ap_vendors_index WHERE id IN('.$ids.')');$pdo->exec("DELETE FROM core_record_numbers WHERE entity_type='vendor' AND record_id IN(".$ids.')');}
        if($companyIds){$ids=implode(',',array_unique($companyIds));$pdo->exec('DELETE FROM companies WHERE id IN('.$ids.')');$pdo->exec("DELETE FROM core_record_numbers WHERE entity_type='company' AND record_id IN(".$ids.')');}
        if($membership!==null){RBACResolver::revokeModule($membership,'ap',$actor);deactivateMembership($actor,$child);}
        $pdo->prepare('DELETE FROM tenant_module_scope WHERE tenant_id=?')->execute([$child]);
        $pdo->exec('UPDATE tenants SET is_active=0 WHERE id IN(900012,900013)');
    }
}
