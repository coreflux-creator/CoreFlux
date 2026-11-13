<?php
/** Reviewed company consolidation. Financial history and raw source identities remain immutable. */
declare(strict_types=1);
require_once __DIR__.'/companies.php';
require_once __DIR__.'/company_source_ownership.php';
require_once __DIR__.'/../../../core/jobdiva/party_scope.php';
require_once __DIR__.'/../../../core/integrations/identity.php';
require_once __DIR__.'/../../../core/custom_field_storage.php';

function companyMergeAtomic(PDO $pdo,callable $operation): mixed
{
    $outer=$pdo->inTransaction();$save='company_merge_'.bin2hex(random_bytes(8));
    if($outer)$pdo->exec('SAVEPOINT '.$save);else$pdo->beginTransaction();
    try{$result=$operation();if($outer)$pdo->exec('RELEASE SAVEPOINT '.$save);else$pdo->commit();return$result;}
    catch(Throwable $e){if($pdo->inTransaction()){if($outer){$pdo->exec('ROLLBACK TO SAVEPOINT '.$save);$pdo->exec('RELEASE SAVEPOINT '.$save);}else$pdo->rollBack();}throw$e;}
}

/** Financial snapshots and approval actor identities stay on their original number. */
function companyMergeReferences(): array
{
    return [
        'ap_vendors_index'=>['company_id','ap','redirect'],
        'staffing_clients'=>['company_id','placements','redirect'],
        'staffing_jobs'=>['company_id','placements','redirect'],
        'placements'=>['end_client_company_id','placements','redirect'],
        'placement_client_chain'=>['company_id','placements','redirect'],
        'placement_corp_details'=>['company_id','placements','redirect'],
        'placement_economic_parties'=>['company_id','placements','redirect'],
        'placement_referrals'=>['referrer_company_id','placements','redirect'],
        'company_contacts'=>['company_id','companies','redirect'],
        'company_addresses'=>['company_id','companies','redirect'],
        'ap_bills'=>['vendor_company_id','ap','retain'],
        'ap_payments'=>['vendor_company_id','ap','retain'],
        'ap_1099_ledger'=>['vendor_company_id','ap','retain'],
        'billing_invoices'=>['client_company_id','billing','retain'],
        'accounting_journal_entry_lines'=>['counterparty_company_id','accounting','retain'],
        'treasury_liability_statement_lines'=>['counterparty_company_id','treasury','retain'],
        'people_graph_actor_links'=>['company_id','people','retain'],
    ];
}

function companyMergeFields(): array
{
    // Tax identifiers and agreement/document attestations keep their protected workflows.
    return array_values(array_diff(companySourceFields('companies'),['ein_last4','msa_signed_at']));
}

function companyMergeRequireSchema(PDO $pdo): void
{
    $q=$pdo->query('SELECT INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="companies" AND INDEX_NAME IN("uq_companies_tenant_name","uq_companies_tenant_active_name") ORDER BY INDEX_NAME,SEQ_IN_INDEX');
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==2||array_column($rows,'COLUMN_NAME')!==['tenant_id','active_name_key']
        ||array_filter($rows,static fn($r)=>$r['INDEX_NAME']!=='uq_companies_tenant_active_name'||(int)$r['NON_UNIQUE']!==0||$r['SUB_PART']!==null)){
        throw new RuntimeException('Company merge is unavailable until its database upgrade is complete.',503);
    }
}

/** Current sharing policies, not a request-supplied owner or a process cache. */
function companyMergeScope(PDO $pdo,int $workspace,bool $lock): array
{
    $scope=jobdivaPartyDirectoryScope($pdo,$workspace,$lock);$owner=$scope['owner'];$suffix=$lock?' FOR UPDATE':'';
    $q=$pdo->prepare('SELECT id,parent_id,tenant_type,is_active FROM tenants WHERE id=? OR parent_id=? ORDER BY id'.$suffix);$q->execute([$owner,$owner]);$tenants=$q->fetchAll(PDO::FETCH_ASSOC);
    $owners=['companies'=>[$owner]];$policies=[];
    foreach($tenants as$t){
        $id=(int)$t['id'];$q=$pdo->prepare('SELECT module_key,scope_mode FROM tenant_module_scope WHERE tenant_id=? ORDER BY module_key'.$suffix);$q->execute([$id]);$modes=$q->fetchAll(PDO::FETCH_KEY_PAIR);
        $companyOwner=$t['tenant_type']==='sub'&&!empty($t['parent_id'])&&($modes['companies']??SUBTENANT_MODULE_SCOPE_DEFAULTS['companies'])==='shared'?(int)$t['parent_id']:$id;
        if(!(int)$t['is_active']||$companyOwner!==$owner)continue;
        $policies[]=['tenant'=>$t,'modes'=>$modes];
        foreach(['ap','placements','billing','accounting','treasury','people']as$module){
            $mode=$modes[$module]??SUBTENANT_MODULE_SCOPE_DEFAULTS[$module];
            if(!in_array($mode,['shared','isolated'],true))throw new RuntimeException('Review the directory sharing policy before merging.',409);
            $moduleOwner=$t['tenant_type']==='sub'&&!empty($t['parent_id'])&&$mode==='shared'?(int)$t['parent_id']:$id;
            $owners[$module][]=$moduleOwner;
        }
    }
    foreach($owners as&$ids){$ids=array_values(array_unique($ids));sort($ids);}unset($ids);
    return ['workspace'=>$workspace,'owner'=>$owner,'scope'=>$scope,'policies'=>$policies,'owners'=>$owners];
}

function companyMergeSnapshot(PDO $pdo,int $workspace,int $survivor,int $retired,array $user): array
{
    companyMergeRequireSchema($pdo);
    if($survivor<=0||$retired<=0||$survivor===$retired)throw new InvalidArgumentException('Choose two different companies.');
    $scope=companyMergeScope($pdo,$workspace,true);$owner=$scope['owner'];$ids=[$survivor,$retired];sort($ids);
    $records=[];
    foreach($ids as$id){
        $identity=companyIdentityResolve($pdo,$owner,$id,true);
        if(!$identity||$identity['merged']||!$identity['company_number'])throw new RuntimeException('Choose two current companies in this directory with permanent company numbers.',409);
        $row=$identity['company'];$q=$pdo->prepare('SELECT role FROM company_roles WHERE company_id=? ORDER BY role FOR UPDATE');$q->execute([$id]);$row['roles']=$q->fetchAll(PDO::FETCH_COLUMN);
        $state=companySourceState($row,'companies');unset($state['observed_at']);$row['coreflux_source_state']=$state;
        unset($row['updated_at'],$row['last_used_at'],$row['use_count']);
        $records[$id]=$row+['company_number'=>$identity['company_number']];
    }
    $q=$pdo->prepare('SELECT * FROM core_record_numbers WHERE tenant_id=? AND entity_type="company" AND canonical_record_id IN (?,?) ORDER BY record_id FOR UPDATE');$q->execute([$owner,$survivor,$retired]);$numbers=$q->fetchAll(PDO::FETCH_ASSOC);
    $family=array_map('intval',array_column($numbers,'record_id'));$marks=implode(',',array_fill(0,count($family),'?'));
    $q=$pdo->prepare('SELECT id,tenant_id,source_system,internal_entity_type,external_id,internal_entity_id,content_hash,direction,sync_status FROM external_entity_mappings WHERE internal_entity_type="company" AND internal_entity_id IN ('.$marks.') ORDER BY id FOR UPDATE');$q->execute($family);$mappings=$q->fetchAll(PDO::FETCH_ASSOC);
    $references=[];$impact=[];
    foreach(companyMergeReferences()as$table=>[$column,$module,$disposition]){
        $pk=$table==='placement_corp_details'?'placement_id':'id';
        // tenant-leak-allow: inspect globally unique company references, then validate every mutable row against the fresh module-owner set before exposing or changing it
        $q=$pdo->prepare('SELECT '.$pk.' AS id,tenant_id,'.$column.' FROM '.$table.' WHERE '.$column.' IN ('.$marks.') ORDER BY tenant_id,'.$pk.' FOR UPDATE');$q->execute($family);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as$r)if($disposition==='redirect'&&!in_array((int)$r['tenant_id'],$scope['owners'][$module]??[],true)){
            throw new RuntimeException('A company has a current reference outside its configured directory. Review that reference before merging.',409);
        }
        $references[$table]=$rows;
        $impact[]=['table'=>$table,'action'=>$disposition,'count'=>count(array_filter($rows,static fn($r)=>(int)$r[$column]!==$survivor))];
    }
    $children=[];
    foreach(['company_contacts','company_addresses']as$table){
        $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE tenant_id=? AND company_id IN ('.$marks.') ORDER BY id FOR UPDATE');$q->execute([$owner,...$family]);$children[$table]=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    $q=$pdo->prepare('SELECT * FROM custom_fields WHERE tenant_id=? AND module="companies" ORDER BY id FOR UPDATE');$q->execute([$owner]);$defs=$q->fetchAll(PDO::FETCH_ASSOC);
    $custom=[];$customPublic=[];
    foreach($defs as$def){
        $q=$pdo->prepare('SELECT record_id,value FROM custom_values WHERE tenant_id=? AND field_id=? AND record_id IN (?,?) ORDER BY record_id FOR UPDATE');$q->execute([$owner,$def['id'],$survivor,$retired]);$values=$q->fetchAll(PDO::FETCH_KEY_PAIR);
        $custom[]=['definition'=>$def,'values'=>$values];
        if($def['deleted_at']!==null||!(int)$def['is_active']||!empty($def['pii'])||!customFieldUserCanViewDefinition($user,$def)||!customFieldUserCanEditDefinition($user,$def))continue;
        $def=customFieldNormalizeDefinitionRow($def+['field_key'=>$def['field_name'],'required'=>$def['is_required'],'options_json'=>$def['options']]);
        $customPublic[$def['field_key']]=['label'=>$def['field_label'],'survivor'=>customFieldStorageCurrent($pdo,$owner,'companies',$survivor,$def,true),'retired'=>customFieldStorageCurrent($pdo,$owner,'companies',$retired,$def,true)];
    }
    $business=['scope'=>$scope,'records'=>$records,'numbers'=>$numbers,'mappings'=>$mappings,'references'=>$references,'children'=>$children,'custom'=>$custom];
    $public=[];foreach(['survivor'=>$survivor,'retired'=>$retired]as$side=>$id)$public[$side]=['id'=>$id,'company_number'=>$records[$id]['company_number'],'values'=>array_intersect_key($records[$id],array_flip(companyMergeFields()))];
    $public+=['fields'=>companyMergeFields(),'impact'=>$impact,'numbers'=>array_map(static fn($n)=>['record_number'=>$n['record_number'],'record_id'=>(int)$n['record_id'],'canonical_record_id'=>(int)$n['canonical_record_id']],$numbers),
        // A shared directory does not grant integration access in other workspaces.
        // Their bindings participate in the fingerprint and remain unchanged, but
        // only this workspace's source identifiers are exposed in the review.
        'sources'=>array_values(array_map(static fn($m)=>array_intersect_key($m,array_flip(['source_system','external_id','internal_entity_id','tenant_id'])),array_filter($mappings,static fn($m)=>(int)$m['tenant_id']===$workspace))),
        'contacts'=>array_map(static fn($r)=>array_intersect_key($r,array_flip(['id','company_id','name','email','is_primary'])),$children['company_contacts']),
        'addresses'=>array_map(static fn($r)=>array_intersect_key($r,array_flip(['id','company_id','kind','label','line1','city','is_primary'])),$children['company_addresses']),
        'custom_fields'=>$customPublic];
    return ['hash'=>hash('sha256',serialize($business)),'scope_hash'=>mappingHash($scope),'owner'=>$owner,'records'=>$records,'family'=>$family,'references'=>$references,'children'=>$children,'custom'=>$customPublic,'public'=>$public];
}

function companyMergePreview(int $workspace,array $user,int $survivor,int $retired): array
{
    $actor=(int)($user['id']??0);if($actor<=0)throw new InvalidArgumentException('A current reviewer is required.');
    $pdo=getDB();return companyMergeAtomic($pdo,static function()use($pdo,$workspace,$user,$actor,$survivor,$retired):array{
        $snapshot=companyMergeSnapshot($pdo,$workspace,$survivor,$retired,$user);
        $instance=integrationInstanceId();if(!$instance)throw new RuntimeException('The permanent Core installation identity is unavailable.',503);
        $review=['kind'=>'company_merge_v1','id'=>bin2hex(random_bytes(32)),'workspace'=>$workspace,'actor'=>$actor,'instance'=>$instance,'owner'=>$snapshot['owner'],
            'survivor'=>$survivor,'retired'=>$retired,'hash'=>$snapshot['hash'],'scope_hash'=>$snapshot['scope_hash'],'expires_at'=>time()+1800];
        return $snapshot['public']+['review_token'=>base64_encode(encryptField(json_encode($review,JSON_THROW_ON_ERROR))),'expires_at'=>gmdate('c',$review['expires_at'])];
    });
}

function companyMergeOpen(int $workspace,int $actor,string $token): array
{
    try{if(strlen($token)>5000)throw new RuntimeException();$bytes=base64_decode($token,true);if($bytes===false)throw new RuntimeException();$r=json_decode((string)decryptField($bytes),true,32,JSON_THROW_ON_ERROR);}
    catch(Throwable){throw new InvalidArgumentException('This merge review is invalid. Preview again.');}
    if(($r['kind']??'')!=='company_merge_v1'||($r['workspace']??0)!==$workspace||($r['actor']??0)!==$actor||($r['instance']??'')!==integrationInstanceId()
        ||!preg_match('/^[a-f0-9]{64}$/D',(string)($r['id']??''))||!is_int($r['survivor']??null)||!is_int($r['retired']??null)||!is_int($r['expires_at']??null)){
        throw new InvalidArgumentException('This merge review belongs to another reviewer, workspace or installation.');
    }
    return$r;
}

function companyMergeReceipt(PDO $pdo,array $review,string $choicesHash): ?array
{
    $q=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=? AND actor_user_id=? AND event="company.merged.reviewed" AND target_id=? AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.review_id"))=? ORDER BY id LIMIT 1');
    $q->execute([$review['workspace'],$review['actor'],$review['survivor'],$review['id']]);$json=$q->fetchColumn();if($json===false)return null;
    $receipt=json_decode($json,true,64,JSON_THROW_ON_ERROR);
    if(($receipt['review_hash']??'')!==mappingHash($review)||($receipt['choices_hash']??'')!==$choicesHash)throw new RuntimeException('This merge review was already used for different choices.',409);
    return$receipt['result'];
}

function companyMergeApply(int $workspace,array $user,string $token,array $choices): array
{
    $actor=(int)($user['id']??0);$review=companyMergeOpen($workspace,$actor,$token);$pdo=getDB();$digest=mappingHash($choices);
    return companyMergeAtomic($pdo,static function()use($pdo,$workspace,$user,$actor,$review,$choices,$digest):array{
        $scope=companyMergeScope($pdo,$workspace,true);
        if($scope['owner']!==$review['owner']||mappingHash($scope)!==$review['scope_hash'])throw new RuntimeException('The company directory or sharing policy changed. Review again.',409);
        if($receipt=companyMergeReceipt($pdo,$review,$digest))return$receipt+['replayed'=>true];
        if($review['expires_at']<time())throw new RuntimeException('This merge review expired. Preview again.',409);
        $s=$review['survivor'];$v=$review['retired'];$owner=$scope['owner'];$snapshot=companyMergeSnapshot($pdo,$workspace,$s,$v,$user);
        if(!hash_equals($review['hash'],$snapshot['hash']))throw new RuntimeException('The companies, source links or affected records changed. Preview again.',409);
        if(array_diff(array_keys($choices),['fields','custom_fields','primary_contact_id','primary_addresses'])||!is_array($choices['fields']??null)||!is_array($choices['custom_fields']??null)
            ||!is_int($choices['primary_contact_id']??null)||!is_array($choices['primary_addresses']??null))throw new InvalidArgumentException('Review field values and primary contacts and addresses.');
        $fields=companyMergeFields();if(array_diff($fields,array_keys($choices['fields']))||array_diff(array_keys($choices['fields']),$fields))throw new InvalidArgumentException('Choose a value for every company field.');
        $values=[];
        foreach($fields as$field){
            $choice=$choices['fields'][$field];if(!in_array($choice,['survivor','retired'],true)&&!($field==='roles'&&$choice==='union'))throw new InvalidArgumentException('Choose a reviewed company value.');
            $values[$field]=$choice==='union'?array_values(array_unique(array_merge($snapshot['records'][$s]['roles'],$snapshot['records'][$v]['roles']))):($snapshot['records'][$choice==='survivor'?$s:$v][$field]??null);
        }
        if(trim((string)$values['name'])===''||trim((string)$values['country'])==='')throw new InvalidArgumentException('The surviving company needs a name and country.');
        $contactIds=array_map('intval',array_column($snapshot['children']['company_contacts'],'id'));$primary=$choices['primary_contact_id'];
        if($primary!==0&&!in_array($primary,$contactIds,true))throw new InvalidArgumentException('Choose a contact from these companies.');
        $addressIds=[];foreach($snapshot['children']['company_addresses']as$r)$addressIds[$r['kind']][]=(int)$r['id'];
        if(array_diff(array_keys($addressIds),array_keys($choices['primary_addresses']))||array_diff(array_keys($choices['primary_addresses']),array_keys($addressIds)))throw new InvalidArgumentException('Choose the primary address for every kind.');
        foreach($choices['primary_addresses']as$kind=>$id)if(!is_int($id)||($id!==0&&!in_array($id,$addressIds[$kind],true)))throw new InvalidArgumentException('Choose an address from these companies.');
        if(array_diff(array_keys($snapshot['custom']),array_keys($choices['custom_fields']))||array_diff(array_keys($choices['custom_fields']),array_keys($snapshot['custom'])))throw new InvalidArgumentException('Review every available custom field.');
        foreach($choices['custom_fields']as$key=>$side){
            if(!in_array($side,['survivor','retired'],true))throw new InvalidArgumentException('Choose a reviewed custom value.');
            if($side==='retired')customFieldStorageWrite($pdo,$owner,'companies',$s,$key,$snapshot['custom'][$key]['retired']['value'],null,false);
        }
        // Preserve the original retired profile, role history, private identifiers
        // and document references. Freeze all source-owned fields on both records.
        foreach([$s,$v]as$id){$state=companySourceState(['coreflux_source_state'=>json_encode($snapshot['records'][$id]['coreflux_source_state'],JSON_THROW_ON_ERROR)],'companies');$state['owned']=companySourceFields('companies');sort($state['owned']);$state['revision']++;
            companySourcePersist($pdo,$owner,'companies',$id,[],$state);}
        // Retirement frees its active name without renaming the historical record.
        $pdo->prepare('UPDATE companies SET deleted_at=NOW() WHERE tenant_id=? AND id=?')->execute([$owner,$v]);
        $state=companySourceWithRecord($pdo,$owner,'companies',$s,static fn(array $row):array=>companySourceState($row,'companies'));
        companySourcePersist($pdo,$owner,'companies',$s,$values,$state);
        $redirected=[];$retained=[];
        foreach(companyMergeReferences()as$table=>[$column,$module,$action]){
            $count=0;$pk=$table==='placement_corp_details'?'placement_id':'id';
            foreach($snapshot['references'][$table]as$row){
                if((int)$row[$column]===$s)continue;
                if($action==='redirect'){$q=$pdo->prepare('UPDATE '.$table.' SET '.$column.'=? WHERE tenant_id=? AND '.$pk.'=? AND '.$column.'=?');$q->execute([$s,$row['tenant_id'],$row['id'],$row[$column]]);if($q->rowCount()!==1)throw new RuntimeException('A company reference changed during merge.',409);}
                $count++;
            }
            if($action==='redirect')$redirected[$table]=$count;else$retained[$table]=$count;
        }
        foreach($snapshot['children']['company_contacts']as$r){$isPrimary=(int)$r['id']===$primary?1:0;companyNativeFields($pdo,$workspace,$owner,'company_contacts',(int)$r['id'],['is_primary'=>$isPrimary],$actor);}
        foreach($snapshot['children']['company_addresses']as$r){$pdo->prepare('UPDATE company_addresses SET is_primary=? WHERE tenant_id=? AND company_id=? AND id=?')->execute([(int)$r['id']===$choices['primary_addresses'][$r['kind']]?1:0,$owner,$s,$r['id']]);}
        $pdo->prepare('UPDATE companies s JOIN companies v ON v.tenant_id=s.tenant_id AND v.id=? SET s.use_count=s.use_count+v.use_count,s.last_used_at=GREATEST(COALESCE(s.last_used_at,"1970-01-01"),COALESCE(v.last_used_at,"1970-01-01")) WHERE s.tenant_id=? AND s.id=?')->execute([$v,$owner,$s]);
        coreRecordNumbersMerge($pdo,$owner,'company',$s,$v,$actor);
        $result=['survivor_id'=>$s,'retired_id'=>$v,'company_number'=>$snapshot['records'][$s]['company_number'],'retired_number'=>$snapshot['records'][$v]['company_number'],'redirected'=>$redirected,'retained_history'=>$retained,'review_id'=>$review['id']];
        companySourceAudit($pdo,$workspace,$owner,'companies',$s,'company.merged.reviewed',$fields,$actor,['review_id'=>$review['id'],'review_hash'=>mappingHash($review),'choices_hash'=>$digest,'choices'=>$choices,
            'before'=>$snapshot['public']['survivor']['values'],'selected_values'=>$values,'custom_before'=>$snapshot['custom'],'result'=>$result]);
        return$result+['replayed'=>false];
    });
}
