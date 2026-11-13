<?php
/** Explicit company/contact identity links. Provider reads; no profile replacement. */
declare(strict_types=1);
require_once __DIR__.'/client.php';
require_once __DIR__.'/assignment_identity.php';
require_once __DIR__.'/party_sync.php';
require_once __DIR__.'/../integrations/identity.php';
require_once __DIR__.'/../sub_tenants.php';

function jobdivaPartyReviewScope(PDO $pdo,int $workspace,bool $lock=false): array
{
    return jobdivaPartyDirectoryScope($pdo,$workspace,$lock);
}

function jobdivaPartyReviewConnection(PDO $pdo,int $workspace,bool $lock=false): string
{
    $q=$pdo->prepare('SELECT id,client_id,username,password_enc,status FROM jobdiva_connections WHERE tenant_id=?'.($lock?' FOR UPDATE':''));$q->execute([$workspace]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||!in_array($row['status'],['connected','degraded'],true))throw new RuntimeException('Connect JobDiva before reviewing its records.',409);
    unset($row['status']);$instance=integrationInstanceId();
    if(!$instance)throw new RuntimeException('The permanent Core installation identity is unavailable.',503);
    return mappingHash(['instance'=>$instance,'connection'=>$row]);
}

function jobdivaPartyReviewRequest(array $input): array
{
    if(array_diff(array_keys($input),['record_type','record_id','source_id'])||!in_array($input['record_type']??null,['companies','company_contacts'],true)
        ||!is_int($input['record_id']??null)||$input['record_id']<=0||!is_string($input['source_id']??null)
        ||!preg_match('/^[1-9][0-9]{0,17}$/D',$input['source_id']))throw new InvalidArgumentException('Choose an existing Core record and enter its JobDiva company or contact ID.');
    return $input;
}

/** Conflicting source aliases are an identity error, never first-match wins. */
function jobdivaPartyReviewValue(array $row,array $aliases,bool $required=false): ?string
{
    $values=[];
    foreach($row as$key=>$value){
        if(!in_array(strtolower((string)preg_replace('/[^a-z0-9]/i','',(string)$key)),$aliases,true))continue;
        if($value===null||$value==='')continue;
        if(!is_string($value)&&!is_int($value))throw new RuntimeException('JobDiva returned an unrecognized identity field. Review the source record.',409);
        $value=trim((string)$value);if($value!=='')$values[]=$value;
    }
    $values=array_values(array_unique($values));
    if(count($values)>1||($required&&!$values))throw new RuntimeException('JobDiva returned missing or conflicting identity details. Review the source record.',409);
    return $values[0]??null;
}

function jobdivaPartyReviewHasField(array $row,array $aliases): bool
{
    foreach(array_keys($row)as$key)if(in_array(strtolower((string)preg_replace('/[^a-z0-9]/i','',(string)$key)),$aliases,true))return true;
    return false;
}

/** Optional reader is an internal test dependency; the API never accepts one. */
function jobdivaPartyReviewSource(int $workspace,array $request,?callable $reader=null): array
{
    $company=$request['record_type']==='companies';$id=$request['source_id'];
    try{$response=$reader?$reader($workspace,$request['record_type'],$id):jobdivaCall($workspace,'GET',
        $company?'/apiv2/bi/CompaniesDetail':'/apiv2/bi/ContactsDetail',null,[$company?'companyIds':'contactIds'=>$id]);}
    catch(Throwable $e){throw new RuntimeException('Could not verify the JobDiva record. Check the source ID and connection, then review again.',409);}
    $rows=jobdivaAssignmentRowsFromResponse($response);
    if(count($rows)!==1||!is_array($rows[0]))throw new RuntimeException('JobDiva must return exactly one record for this ID.',409);
    $row=$rows[0];$actual=jobdivaPartyReviewValue($row,$company?['id','companyid']:['id','contactid'],true);
    if($actual!==$id)throw new RuntimeException('JobDiva returned a different record ID. No link was changed.',409);
    $name=jobdivaPartyReviewValue($row,$company?['name','companyname']:['name','fullname','contactname']);
    if(!$name&&!$company)$name=trim((jobdivaPartyReviewValue($row,['firstname'])??'').' '.(jobdivaPartyReviewValue($row,['lastname'])??''));
    if(!$name)throw new RuntimeException('The JobDiva record has no usable name for review.',409);
    $source=['id'=>$id,'name'=>$name];
    if(jobdivaPartyReviewHasField($row,['phone','phonenumber','mainphone']))$source['phone']=jobdivaPartyReviewValue($row,['phone','phonenumber','mainphone']);
    if(!$company){
        if(jobdivaPartyReviewHasField($row,['email','emailaddress']))$source['email']=jobdivaPartyReviewValue($row,['email','emailaddress']);
        $source['company_id']=jobdivaPartyReviewValue($row,['companyid','clientid'],true);
    }
    return $source;
}

function jobdivaPartyReviewTarget(PDO $pdo,int $workspace,array $scope,array $request,array $source,bool $lock=false): array
{
    jobdivaPartyReviewRequest($request);
    $table=$request['record_type'];$id=$request['record_id'];$owner=$scope['owner'];$suffix=$lock?' FOR UPDATE':'';
    $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE tenant_id=? AND id=?'.($table==='companies'?' AND deleted_at IS NULL'.$suffix:''));$q->execute([$owner,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Choose a current record from this company directory.',404);
    $companyId=$table==='companies'?$id:(int)$row['company_id'];
    $q=$pdo->prepare('SELECT id,name FROM companies WHERE tenant_id=? AND id=? AND deleted_at IS NULL'.$suffix);$q->execute([$owner,$companyId]);$parent=$q->fetch(PDO::FETCH_ASSOC);
    if(!$parent)throw new RuntimeException('The parent company is unavailable in this directory.',404);
    if($table==='company_contacts'&&$lock){
        $q=$pdo->prepare('SELECT * FROM company_contacts WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$owner,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||(int)$row['company_id']!==$companyId)throw new RuntimeException('The contact parent changed. Review again.',409);
    }
    $parentLink=null;
    if($table==='company_contacts'){
        $q=$pdo->prepare('SELECT id,external_id,internal_entity_id FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND internal_entity_type="company" AND external_id=?'.$suffix);
        $q->execute([$workspace,$source['company_id']]);$parentLink=$q->fetch(PDO::FETCH_ASSOC);
        if(!$parentLink||(int)$parentLink['internal_entity_id']!==$companyId)throw new RuntimeException('Link the contact’s exact JobDiva parent company first. A contact cannot move to another company here.',409);
    }
    $type=$table==='companies'?'company':'contact';
    $q=$pdo->prepare('SELECT id,external_id,internal_entity_id FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND internal_entity_type=? AND (external_id=? OR internal_entity_id=?) ORDER BY id'.$suffix);
    $q->execute([$workspace,$type,$source['id'],$id]);$links=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($links as$link)if($link['external_id']!==$source['id']||(int)$link['internal_entity_id']!==$id)throw new RuntimeException('This Core record or JobDiva identity is already linked elsewhere. Review the existing link; it cannot be replaced here.',409);
    $state=companySourceState($row,$table);$authority=['system'=>'jobdiva','workspace_id'=>$workspace,'external_id'=>$source['id']];
    if($state['source']!==null&&mappingHash($state['source'])!==mappingHash($authority))throw new RuntimeException('This record has another source authority. Review its existing identity.',409);
    $q=$pdo->prepare('SELECT record_number,canonical_record_id FROM core_record_numbers WHERE tenant_id=? AND entity_type="company" AND record_id=?'.$suffix);$q->execute([$owner,$companyId]);$number=$q->fetch(PDO::FETCH_ASSOC);
    if($number&&(int)$number['canonical_record_id']!==$companyId)throw new RuntimeException('This company number is an alias. Open its current company before linking.',409);
    $roles=[];if($table==='companies'){$q=$pdo->prepare('SELECT role FROM company_roles WHERE company_id=? ORDER BY role'.$suffix);$q->execute([$id]);$roles=$q->fetchAll(PDO::FETCH_COLUMN);}
    $business=$row;unset($business['updated_at'],$business['last_used_at'],$business['use_count'],$business['coreflux_source_state']);$semantic=$state;unset($semantic['observed_at']);
    return ['id'=>$id,'record_type'=>$table,'name'=>$row['name'],'phone'=>$row['phone']??null,'email'=>$row['email']??$row['primary_contact_email']??null,
        'company_id'=>$companyId,'company_name'=>$parent['name'],'company_number'=>$number['record_number']??null,'already_linked'=>(bool)$links,
        'hash'=>mappingHash(['row'=>$business,'ownership'=>$semantic,'roles'=>$roles,'parent'=>$parent,'parent_link'=>$parentLink,'links'=>$links,'number'=>$number])];
}

function jobdivaPartyReviewPreview(int $workspace,int $actor,array $input,?callable $reader=null): array
{
    if($actor<=0)throw new InvalidArgumentException('A current reviewer is required.');
    $request=jobdivaPartyReviewRequest($input);$pdo=getDB();$scope=jobdivaPartyReviewScope($pdo,$workspace);$binding=jobdivaPartyReviewConnection($pdo,$workspace);
    $source=jobdivaPartyReviewSource($workspace,$request,$reader);
    if(mappingHash($scope)!==mappingHash(jobdivaPartyReviewScope($pdo,$workspace))||!hash_equals($binding,jobdivaPartyReviewConnection($pdo,$workspace)))throw new RuntimeException('The connection or company directory changed. Review again.',409);
    $target=jobdivaPartyReviewTarget($pdo,$workspace,$scope,$request,$source);
    $review=['kind'=>'jobdiva_party_link_v1','id'=>bin2hex(random_bytes(32)),'workspace'=>$workspace,'actor'=>$actor,'scope'=>$scope,'binding'=>$binding,
        'request'=>$request,'source'=>$source,'target'=>$target,'expires_at'=>time()+1800];
    return ['review_token'=>base64_encode(encryptField(json_encode($review,JSON_THROW_ON_ERROR))),'source'=>$source,'target'=>$target,'expires_at'=>gmdate('c',$review['expires_at'])];
}

function jobdivaPartyReviewOpen(int $workspace,int $actor,string $token): array
{
    try{if(strlen($token)>50000)throw new RuntimeException();$bytes=base64_decode($token,true);if($bytes===false)throw new RuntimeException();$review=json_decode((string)decryptField($bytes),true,64,JSON_THROW_ON_ERROR);}
    catch(Throwable $e){throw new InvalidArgumentException('This review is invalid. Preview again.');}
    if(($review['kind']??null)!=='jobdiva_party_link_v1'||($review['workspace']??null)!==$workspace||($review['actor']??null)!==$actor
        ||!preg_match('/^[a-f0-9]{64}$/D',(string)($review['id']??'')))throw new InvalidArgumentException('This review belongs to another workspace or reviewer.');
    jobdivaPartyReviewRequest($review['request']);return $review;
}

function jobdivaPartyReviewReceipt(PDO $pdo,int $workspace,int $actor,array $review): ?array
{
    $q=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=? AND actor_user_id=? AND event="company.source.identity_linked" AND target_id=? AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.review_id"))=? ORDER BY id LIMIT 1');
    $q->execute([$workspace,$actor,$review['request']['record_id'],$review['id']]);$json=$q->fetchColumn();if($json===false)return null;
    $receipt=json_decode($json,true,64,JSON_THROW_ON_ERROR);
    if(($receipt['binding_hash']??'')!==$review['binding']||($receipt['review_hash']??'')!==mappingHash($review))throw new RuntimeException('The saved receipt does not match this identity review.',409);
    return $receipt['result'];
}

function jobdivaPartyReviewStatus(int $workspace,int $actor,string $token): array
{
    $review=jobdivaPartyReviewOpen($workspace,$actor,$token);
    return jobdivaPartyAtomic(static function(PDO $pdo)use($workspace,$actor,$review):array{
        if(mappingHash($review['scope'])!==mappingHash(jobdivaPartyReviewScope($pdo,$workspace,true))||!hash_equals($review['binding'],jobdivaPartyReviewConnection($pdo,$workspace,true)))throw new RuntimeException('The connection or company directory changed. Review again.',409);
        $result=jobdivaPartyReviewReceipt($pdo,$workspace,$actor,$review);return ['state'=>$result?'applied':'not_applied','result'=>$result];
    });
}

function jobdivaPartyReviewApply(int $workspace,int $actor,string $token,?callable $reader=null): array
{
    $review=jobdivaPartyReviewOpen($workspace,$actor,$token);$status=jobdivaPartyReviewStatus($workspace,$actor,$token);
    if($status['state']==='applied')return $status['result']+['replayed'=>true];
    if($review['expires_at']<time())throw new RuntimeException('This identity review expired. Preview again.',409);
    $source=jobdivaPartyReviewSource($workspace,$review['request'],$reader);
    if(mappingHash($source)!==mappingHash($review['source']))throw new RuntimeException('The JobDiva identity changed after review. Preview again.',409);
    return jobdivaPartyAtomic(static function(PDO $pdo)use($workspace,$actor,$review,$source):array{
        $scope=jobdivaPartyReviewScope($pdo,$workspace,true);
        if(mappingHash($review['scope'])!==mappingHash($scope)||!hash_equals($review['binding'],jobdivaPartyReviewConnection($pdo,$workspace,true)))throw new RuntimeException('The connection or company directory changed. Review again.',409);
        if($receipt=jobdivaPartyReviewReceipt($pdo,$workspace,$actor,$review))return $receipt+['replayed'=>true];
        $target=jobdivaPartyReviewTarget($pdo,$workspace,$scope,$review['request'],$source,true);
        if(!hash_equals($review['target']['hash'],$target['hash']))throw new RuntimeException('The Core record or its links changed after review. Preview again.',409);
        $table=$review['request']['record_type'];$id=$target['id'];$owner=$scope['owner'];$type=$table==='companies'?'company':'contact';
        companySourceWithRecord($pdo,$owner,$table,$id,static function(array $row)use($pdo,$workspace,$owner,$table,$id,$type,$source,$target,$actor):void{
            if(!$target['already_linked'])mappingUpsert($workspace,'jobdiva',$type,$source['id'],$id,['_coreflux_identity_review'=>$source],'pull',$actor);
            $state=companySourceState($row,$table);
            if($state['source']===null){
                $state['owned']=companySourceFields($table);$state['revision']++;
                companySourcePersist($pdo,$owner,$table,$id,[],$state);
                $proposed=array_intersect_key($source,array_flip(companySourceFields($table)));
                companySourceObserve($pdo,$owner,$table,$id,$proposed,['system'=>'jobdiva','workspace_id'=>$workspace,'external_id'=>$source['id']]);
            }
        });
        $result=['record_type'=>$table,'record_id'=>$id,'company_number'=>$target['company_number'],'source_id'=>$source['id'],'already_linked'=>$target['already_linked'],'review_id'=>$review['id']];
        companySourceAudit($pdo,$workspace,$owner,$table,$id,'company.source.identity_linked',[],$actor,
            ['review_id'=>$review['id'],'binding_hash'=>$review['binding'],'review_hash'=>mappingHash($review),'result'=>$result]);
        return $result+['replayed'=>false];
    });
}
