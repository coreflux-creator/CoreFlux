<?php
/** Current, independently scoped native placement references held through a write. */
declare(strict_types=1);
require_once __DIR__.'/placements.php';
require_once __DIR__.'/../../people/lib/company_relationships.php';
require_once __DIR__.'/../../people/lib/company_source_ownership.php';

function placementNativeDirectoryWrite(int $workspace, int $expectedOwner, callable $write): mixed
{
    $pdo=getDB();
    return companyRelationshipAtomic($pdo,static function()use($pdo,$workspace,$expectedOwner,$write):mixed{
        $scope=companyRelationshipScope($pdo,$workspace,'placements');
        if($scope['owner']!==$expectedOwner)throw new RuntimeException('The placement directory changed. Refresh before saving.',409);
        return $write($scope,$pdo);
    });
}

function placementResolveCurrentCompanyRoles(int $owner,array $input,array $existing,?int $actor,int $workspace): array
{
    if(!array_intersect(array_keys($input),['client_id','end_client_company_id','end_client_name']))return[];
    $pdo=getDB();
    if(!$pdo->inTransaction())throw new LogicException('Placement company choices require the placement write transaction.');
    $scope=companyRelationshipScope($pdo,$workspace,'placements');
    if($scope['owner']!==$owner)throw new RuntimeException('The placement directory changed. Refresh before saving.',409);
    $patch=[];
    if(array_key_exists('client_id',$input)){
        $id=companyRelationshipId($input['client_id']);
        if($id!==null){
            $q=$pdo->prepare('SELECT * FROM staffing_clients WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$owner,$id]);$client=$q->fetch(PDO::FETCH_ASSOC);
            if(!$client)throw new InvalidArgumentException('Choose a billing client in this placement directory.',422);
            if(($existing['client_id']??null)!=$id&&$client['status']!=='active')throw new InvalidArgumentException('Choose an active billing client.',422);
            if(empty($client['company_id']))throw new RuntimeException('Review this billing client\'s company identity before linking it.',409);
            companyRelationshipCurrent($pdo,$scope,(int)$client['company_id']);
        }
        $patch['client_id']=$id;
    }
    if(array_key_exists('end_client_company_id',$input)||array_key_exists('end_client_name',$input)){
        $id=companyRelationshipId($input['end_client_company_id']??null);
        $raw=$input['end_client_name']??'';
        if(!is_string($raw)&&$raw!==null)throw new InvalidArgumentException('Use a company name or select its company number.',422);
        $name=trim((string)$raw);
        if(strlen($name)>255)throw new InvalidArgumentException('The work-recipient company name is too long.',422);
        if(($id!==null||$name!=='')&&jobdivaPartyDirectoryScope($pdo,$owner,true)['owner']!==$scope['company_owner'])throw new PlacementCompanyScopeConflict('This shared placement cannot use a company private to this workspace.',409);
        if($id!==null){
            $company=companyRelationshipCurrent($pdo,$scope,$id);$name=$company['name'];
        }elseif($name!==''){
            $q=$pdo->prepare('SELECT * FROM companies WHERE tenant_id=? AND name=? ORDER BY id FOR UPDATE');$q->execute([$scope['company_owner'],$name]);$matches=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($matches)>1)throw new RuntimeException('Multiple company records use that name. Select the current company number.',409);
            if($matches){$id=(int)$matches[0]['id'];$company=companyRelationshipCurrent($pdo,$scope,$id);$name=$company['name'];}
            else{
                $q=$pdo->prepare('INSERT INTO companies(tenant_id,name,country,created_by_user_id) VALUES(?,?,"US",?)');$q->execute([$scope['company_owner'],$name,$actor]);$id=(int)$pdo->lastInsertId();
                coreRecordNumbersAttach($pdo,$scope['company_owner'],'company',[['id'=>$id]]);
                try{companySourceAudit($pdo,$workspace,$scope['company_owner'],'companies',$id,'company.created',['name','country'],$actor,['source'=>'native_placement','after'=>['name'=>$name,'country'=>'US']]);}
                catch(PDOException $e){throw new RuntimeException('The company creation could not be recorded. Please try again.',503,$e);}
            }
        }
        $patch['end_client_company_id']=$id;$patch['end_client_name']=$name!==''?$name:null;
    }
    return$patch;
}

function placementResolveNativeJob(PDO $pdo,array $scope,mixed $value): ?int
{
    $id=companyRelationshipId($value);
    if($id===null)return null;
    $jobScope=companyRelationshipScope($pdo,$scope['workspace'],'staffing');
    if(companyRelationshipScope($pdo,$scope['owner'],'staffing')['owner']!==$jobScope['owner'])throw new RuntimeException('This shared placement cannot use a private job directory.',409);
    $q=$pdo->prepare('SELECT id FROM staffing_jobs WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$jobScope['owner'],$id]);
    if(!$q->fetchColumn())throw new InvalidArgumentException('Choose a job in this workspace job directory.',422);
    return$id;
}

function placementResolveNativePerson(PDO $pdo,array $scope,mixed $value): int
{
    $id=companyRelationshipId($value);
    if($id===null)throw new InvalidArgumentException('Choose an existing person.',422);
    $q=$pdo->prepare('SELECT id FROM people WHERE tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE');$q->execute([$scope['owner'],$id]);
    if(!$q->fetchColumn())throw new InvalidArgumentException('Choose a person in this placement directory.',422);
    return$id;
}

function placementResolveNativeAccountingEntity(PDO $pdo,array $scope,mixed $value): int
{
    $id=companyRelationshipId($value);
    $accounting=companyRelationshipScope($pdo,$scope['workspace'],'accounting');
    if(companyRelationshipScope($pdo,$scope['owner'],'accounting')['owner']!==$accounting['owner'])throw new RuntimeException('This shared placement cannot use a private accounting entity.',409);
    $q=$pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id=? AND id=? AND active=1 FOR UPDATE');$q->execute([$accounting['owner'],$id]);
    if(!$q->fetchColumn())throw new InvalidArgumentException('Choose an active legal entity in this accounting workspace.',422);
    return(int)$id;
}
