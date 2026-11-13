<?php
/** Exact source identities and independently scoped client/company projection. */
declare(strict_types=1);
require_once __DIR__.'/client_write.php';
require_once __DIR__.'/../../../core/integrations/stable_mapping.php';

function staffingClientSourceState(array $row): array
{
    $fields=staffingClientNativeFields();
    if(($row['coreflux_source_state']??null)===null)return['version'=>1,'revision'=>0,'source'=>null,'company_id'=>(int)($row['company_id']??0),'owned'=>$fields,'proposed'=>[]];
    $state=json_decode($row['coreflux_source_state'],true,64,JSON_THROW_ON_ERROR);
    if(!is_array($state)||($state['version']??null)!==1||!is_int($state['revision']??null)||$state['revision']<0
        ||!is_int($state['company_id']??null)||!is_array($state['owned']??null)||!array_is_list($state['owned'])
        ||array_filter($state['owned'],static fn($f)=>!is_string($f))||array_diff($state['owned'],$fields)
        ||!is_array($state['proposed']??null)||array_diff(array_keys($state['proposed']),$fields)||!array_key_exists('source',$state))throw new RuntimeException('Review the stored client field ownership.',409);
    if($state['source']!==null)staffingClientSourceIdentity($state['source']);
    if($state['proposed'])staffingClientValidateNative($state['proposed']);
    if(isset($state['secondary'])&&!is_array($state['secondary']))throw new RuntimeException('Review the secondary client sources.',409);
    foreach($state['secondary']??[]as$key=>$observation){
        if(!is_array($observation)||!is_array($observation['source']??null)||!is_array($observation['proposed']??null)
            ||$key!==mappingHash(staffingClientSourceIdentity($observation['source'])))throw new RuntimeException('Review the secondary client source evidence.',409);
        staffingClientValidateNative($observation['proposed']);
    }
    // A reviewed company merge retains the CL, but never grants the old
    // source authority over the survivor's client profile.
    if($state['company_id']!==(int)($row['company_id']??0))$state['owned']=$fields;
    return$state;
}

function staffingClientSourceIdentity(array $source): array
{
    if(array_diff(array_keys($source),['system','workspace_id','external_id'])
        ||!in_array($source['system']??null,['jobdiva','quickbooks_online'],true)
        ||!is_int($source['workspace_id']??null)||$source['workspace_id']<=0
        ||!is_string($source['external_id']??null)||$source['external_id']==='')throw new InvalidArgumentException('An exact client source identity is required.');
    return$source;
}

function staffingClientSourcePersist(PDO $pdo,int $owner,int $id,array $state): void
{
    if(!$pdo->inTransaction())throw new LogicException('Client ownership requires its write transaction.');
    $pdo->prepare('UPDATE staffing_clients SET coreflux_source_state=? WHERE tenant_id=? AND id=?')->execute([json_encode($state,JSON_THROW_ON_ERROR),$owner,$id]);
}

function staffingClientSourceClaim(PDO $pdo,int $owner,int $id,array $before,array $patch): void
{
    $state=staffingClientSourceState($before);$changed=[];
    foreach(staffingClientNativeFields()as$field)if(array_key_exists($field,$patch)&&(!$before||!array_key_exists($field,$before)||$before[$field]!==$patch[$field]))$changed[]=$field;
    if(!$changed&&($before['coreflux_source_state']??null)!==null)return;
    $state['owned']=array_values(array_unique([...$state['owned'],...$changed]));sort($state['owned']);$state['revision']++;
    $state['company_id']=(int)($patch['company_id']??$before['company_id']??0);
    staffingClientSourcePersist($pdo,$owner,$id,$state);
}

/** Source workspace is retained; callers must not substitute a resolved owner. */
function staffingClientProjectSource(int $workspace,?int $companyId,string $name,array $extra=[]): array
{
    $name=trim($name);if($name==='')throw new InvalidArgumentException('A client name is required.');
    $pdo=getDB();return companyRelationshipAtomic($pdo,static function()use($pdo,$workspace,$companyId,$name,$extra):array{
        $scope=companyRelationshipScope($pdo,$workspace,'placements');$owner=$scope['owner'];$companyOwner=$scope['company_owner'];
        if(jobdivaPartyDirectoryScope($pdo,$owner,true)['owner']!==$companyOwner)throw new RuntimeException('This shared client cannot use a private company directory.',409);
        $system=$extra['source_system']??'manual';$external=trim((string)($extra['source_external_id']??''));
        if(!in_array($system,['manual','jobdiva','qbo'],true))throw new InvalidArgumentException('Unsupported client source.');
        $source=$external!==''?staffingClientSourceIdentity(['system'=>$system==='qbo'?'quickbooks_online':$system,'workspace_id'=>$workspace,'external_id'=>$external]):null;
        $type=$system==='qbo'?'customer':'staffing_client';$mapping=null;$existing=null;
        if($source){
            $q=$pdo->prepare('SELECT * FROM external_entity_mappings WHERE tenant_id=? AND source_system=? AND internal_entity_type=? AND external_id=? FOR UPDATE');
            $q->execute([$workspace,$source['system'],$type,$external]);$mapping=$q->fetch(PDO::FETCH_ASSOC)?:null;
        }
        $preferred=companyRelationshipId($extra['preferred_client_id']??null);
        if($preferred&&$mapping&&$preferred!==(int)$mapping['internal_entity_id'])throw new RuntimeException('The retained client and source mapping disagree.',409);
        $id=$mapping?(int)$mapping['internal_entity_id']:$preferred;
        if($id){$q=$pdo->prepare('SELECT * FROM staffing_clients WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$owner,$id]);$existing=$q->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$existing)throw new RuntimeException('The mapped client is unavailable in this directory. Review its existing identity.',409);
            if(!$companyId)$companyId=(int)$existing['company_id'];
        }
        $newCompany=false;
        if(!$companyId){
            if($system==='jobdiva')throw new RuntimeException('Resolve the exact assignment billing company before creating its client.',409);
            $q=$pdo->prepare('SELECT id FROM companies WHERE tenant_id=? AND name=? AND deleted_at IS NULL FOR UPDATE');$q->execute([$companyOwner,$name]);$named=(int)$q->fetchColumn();
            if($named&&$source)throw new RuntimeException('A Core company already uses this name. Review the source link before importing.',409);
            $companyId=$named;
            if(!$companyId){$country=trim((string)($extra['billing_country']??''))?:'US';$pdo->prepare('INSERT INTO companies(tenant_id,name,country,created_by_user_id) VALUES(?,?,?,?)')->execute([$companyOwner,$name,$country,$extra['created_by_user_id']??null]);$companyId=(int)$pdo->lastInsertId();$newCompany=true;}
        }
        $identity=companyIdentityResolve($pdo,$companyOwner,$companyId,true);
        if(!$identity)throw new RuntimeException('The client company is unavailable. Review its identity.',409);
        $companyId=$identity['id'];$company=$identity['company'];
        if($system==='jobdiva'&&$source){
            $link=mappingFindInternal($workspace,'jobdiva','company',$external);
            $bound=$link?companyIdentityResolve($pdo,$companyOwner,(int)$link['internal_entity_id'],true):null;
            if(!$bound||$bound['id']!==$companyId)throw new RuntimeException('The exact JobDiva company binding does not identify this billing company.',409);
        }
        if($existing&&(int)$existing['company_id']!==$companyId)throw new RuntimeException('The source would move an existing client to another company. Review its identity.',409);
        if(!$existing){
            $q=$pdo->prepare('SELECT * FROM staffing_clients WHERE tenant_id=? AND company_id=? ORDER BY id FOR UPDATE');$q->execute([$owner,$companyId]);$matches=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($matches)>1)throw new RuntimeException('This company has multiple retained clients. Review the exact client/source link.',409);
            $existing=$matches[0]??null;
        }
        $actor=$extra['created_by_user_id']??null;
        // QBO profile observations obey the company authority too. Another
        // source's profile stays in the client proposal for explicit review.
        $incoming=array_intersect_key($extra,array_flip(staffingClientNativeFields()));$incoming['name']=$name;
        $incoming=array_filter($incoming,static fn($v)=>$v!==null);$incoming=staffingClientValidateNative($incoming);
        if($source&&$system==='qbo'){
            $companyState=companySourceState($company,'companies');
            if($newCompany||($companyState['source']!==null&&mappingHash($companyState['source'])===mappingHash($source))){
                $values=[];foreach(staffingClientCompanyFields()as$field=>$target)if(array_key_exists($field,$incoming))$values[$target]=$incoming[$field];
                if($newCompany)$values['roles']=['client'];
                companySourceObserve($pdo,$companyOwner,'companies',$companyId,$values,$source,$newCompany);
                $company=companyIdentityResolve($pdo,$companyOwner,$companyId,true)['company'];
            }
        }
        $profile=[];foreach(staffingClientCompanyFields()as$field=>$target)$profile[$field]=$company[$target]??null;
        $profile['billing_country']=$profile['billing_country']?:'US';
        $proposed=$system==='qbo'?$incoming:array_replace($incoming,$profile);
        $new=!$existing;
        if($new){
            $q=$pdo->prepare('SELECT id FROM staffing_clients WHERE tenant_id=? AND name=? FOR UPDATE');$q->execute([$owner,$profile['name']]);
            if($q->fetchColumn())throw new RuntimeException('A namesake client already exists with a different company. Review its identity.',409);
            $values=$profile+['tenant_id'=>$owner,'company_id'=>$companyId,'payment_terms_days'=>$incoming['payment_terms_days']??30,'status'=>$incoming['status']??'active','source_system'=>$system];
            $q=$pdo->prepare('INSERT INTO staffing_clients (`'.implode('`,`',array_keys($values)).'`) VALUES('.implode(',',array_fill(0,count($values),'?')).')');$q->execute(array_values($values));$id=(int)$pdo->lastInsertId();
            $q=$pdo->prepare('SELECT * FROM staffing_clients WHERE tenant_id=? AND id=?');$q->execute([$owner,$id]);$existing=$q->fetch(PDO::FETCH_ASSOC);
            if($system==='manual'){
                $q=$pdo->prepare('SELECT role FROM company_roles WHERE company_id=? ORDER BY role FOR UPDATE');$q->execute([$companyId]);
                companyNativeFields($pdo,$workspace,$companyOwner,'companies',$companyId,['roles'=>array_values(array_unique([...$q->fetchAll(PDO::FETCH_COLUMN),'client']))],$actor);
            }
        }
        $id=(int)$existing['id'];$state=staffingClientSourceState($existing);$beforeState=$state;
        if($new&&$source){$state['owned']=[];$state['source']=$source;}
        $secondary=$source&&$state['source']!==null&&mappingHash($state['source'])!==mappingHash($source);
        if($secondary)$state['secondary'][mappingHash($source)]=['source'=>$source,'proposed'=>$proposed];
        elseif($source){$state['source']=$source;$state['proposed']=$proposed;}
        $apply=[];
        foreach($proposed as$field=>$value){
            if($secondary)continue;
            if(in_array($field,$state['owned'],true))continue;
            if(isset(staffingClientCompanyFields()[$field]))$value=$profile[$field]??null;
            if($existing[$field]!==$value)$apply[$field]=$value;
        }
        if(isset($apply['status'])&&in_array($apply['status'],['closed','inactive'],true)){
            $q=$pdo->prepare('SELECT id FROM placements WHERE tenant_id=? AND client_id=? AND status="active" AND deleted_at IS NULL LIMIT 1 FOR UPDATE');$q->execute([$owner,$id]);if($q->fetchColumn())unset($apply['status']);
        }
        if($apply){$q=$pdo->prepare('UPDATE staffing_clients SET '.implode(',',array_map(static fn($f)=>'`'.$f.'`=?',array_keys($apply))).' WHERE tenant_id=? AND id=?');$q->execute([...array_values($apply),$owner,$id]);}
        if(mappingHash($state)!==mappingHash($beforeState)||$apply)$state['revision']++;
        staffingClientSourcePersist($pdo,$owner,$id,$state);
        if($source)mappingBindStable($workspace,$source['system'],$type,$external,$id,$extra['source_payload']??$proposed,'pull',$actor);
        $saved=array_replace($existing,$apply);$held=[];
        foreach($proposed as$field=>$value)if($secondary||in_array($field,$state['owned'],true)||($saved[$field]??null)!==$value)$held[]=$field;
        companySourceAudit($pdo,$workspace,$owner,'staffing_clients',$id,'staffing.client.source_observed',array_keys($apply),$actor,['source'=>$source,'created'=>$new,'held_fields'=>$held]);
        return['client_id'=>$id,'company_id'=>$companyId,'name'=>$saved['name'],'created'=>$new,'changed'=>$new||(bool)$apply,'held_fields'=>$held];
    });
}

function staffingClientSourceReview(int $workspace,int $id,bool $lock=false): array
{
    $pdo=getDB();$scope=companyRelationshipScope($pdo,$workspace,'placements',$lock);$suffix=$lock?' FOR UPDATE':'';
    $q=$pdo->prepare('SELECT * FROM staffing_clients WHERE tenant_id=? AND id=?'.$suffix);$q->execute([$scope['owner'],$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Client not found in this directory.',404);
    $q=$pdo->prepare('SELECT * FROM companies WHERE tenant_id=? AND id=?'.$suffix);$q->execute([$scope['company_owner'],$row['company_id']]);$company=$q->fetch(PDO::FETCH_ASSOC)?:[];
    $state=staffingClientSourceState($row);$observations=$state['secondary']??[];
    if($state['source'])$observations[mappingHash($state['source'])]=['source'=>$state['source'],'proposed'=>$state['proposed']];
    $observations=array_filter($observations,static fn($o)=>$o['source']['workspace_id']===$workspace);
    $revision=staffingClientRevision($pdo,$scope,$row,$company);
    return ['client_id'=>$id,'current'=>array_intersect_key($row,array_flip(staffingClientNativeFields())),'owned_fields'=>$state['owned'],'observations'=>$observations,
        'edit_revision'=>$revision,'review_token'=>mappingHash(['workspace'=>$workspace,'revision'=>$revision,'observations'=>$observations])];
}

/** Accept once, retain Core ownership and recover the original receipt on replay. */
function staffingClientSourceAccept(int $workspace,int $id,string $sourceKey,array $fields,string $token,int $actor): array
{
    if(!$fields||!array_is_list($fields)||array_filter($fields,static fn($f)=>!is_string($f))||count(array_unique($fields))!==count($fields))throw new InvalidArgumentException('Choose the client source fields to apply.');
    sort($fields);$digest=mappingHash(['source_key'=>$sourceKey,'fields'=>$fields]);$pdo=getDB();
    return companyRelationshipAtomic($pdo,static function()use($pdo,$workspace,$id,$sourceKey,$fields,$token,$actor,$digest):array{
        $scope=companyRelationshipScope($pdo,$workspace,'placements');
        $q=$pdo->prepare('SELECT * FROM staffing_clients WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$scope['owner'],$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Client not found in this directory.',404);
        $q=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event="staffing.client.source_accepted" AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.review_token"))=? ORDER BY id DESC LIMIT 1 FOR UPDATE');$q->execute([$workspace,$id,$token]);$receipt=$q->fetchColumn();
        if($receipt!==false){$receipt=json_decode($receipt,true,32,JSON_THROW_ON_ERROR);if(!hash_equals($receipt['request_digest'],$digest))throw new RuntimeException('This source review already saved a different selection.',409);return$receipt['result']+['replayed'=>true];}
        $review=staffingClientSourceReview($workspace,$id,true);
        if($token===''||!hash_equals($review['review_token'],$token))throw new RuntimeException('The client, company or source changed. Refresh the review.',409);
        $observation=$review['observations'][$sourceKey]??null;
        if(!$observation||array_diff($fields,array_keys($observation['proposed'])))throw new InvalidArgumentException('Choose fields from the observed source in this workspace.');
        $values=array_intersect_key($observation['proposed'],array_flip($fields));
        $saved=staffingClientSaveNative($workspace,$id,$values,$actor,$review['edit_revision']);
        $companyValues=[];foreach(staffingClientCompanyFields()as$field=>$target)if(array_key_exists($field,$values))$companyValues[$target]=$values[$field];
        if($companyValues)companyNativeFields($pdo,$workspace,$scope['company_owner'],'companies',(int)$saved['company_id'],$companyValues,$actor);
        $state=staffingClientSourceState($saved);$state['owned']=array_values(array_unique([...$state['owned'],...$fields]));sort($state['owned']);$state['revision']++;
        staffingClientSourcePersist($pdo,$scope['owner'],$id,$state);
        $result=['client_id'=>$id,'applied_fields'=>$fields];
        companySourceAudit($pdo,$workspace,$scope['owner'],'staffing_clients',$id,'staffing.client.source_accepted',$fields,$actor,['review_token'=>$token,'request_digest'=>$digest,'result'=>$result]);
        return$result+['replayed'=>false];
    });
}
