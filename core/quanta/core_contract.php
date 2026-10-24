<?php
/** Shared Core identity and assignment metadata contract. No access or financial actions. */
declare(strict_types=1);
require_once __DIR__.'/workspace.php';

final class QuantaContractWriteException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $uncertain)
    {
        parent::__construct($uncertain ? 'Quanta delivery needs reconciliation before another attempt.' : 'Quanta rejected this metadata change.');
    }
}

function quantaCoreReferenceValidate(array $ref, string $kind): array
{
    if (array_diff(array_keys($ref), ['instance_id','directory_tenant_id','entity_type','number'])
        || ($ref['entity_type']??null)!==$kind || !is_int($ref['directory_tenant_id']??null)
        || !is_string($ref['instance_id']??null) || !is_string($ref['number']??null)) {
        throw new InvalidArgumentException('A typed canonical Core reference is required');
    }
    return integrationRecordReference($ref['instance_id'],$ref['directory_tenant_id'],$kind,$ref['number']);
}

/** Matches Quanta reference_id(). Inputs are ASCII identifiers; never hash names or emails. */
function quantaCoreRemoteId(string $workspaceId, array $ref): string
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$workspaceId)) throw new InvalidArgumentException('Invalid Quanta workspace');
    $kind=$ref['entity_type']??'';
    if (!in_array($kind,['person','placement'],true)) throw new InvalidArgumentException('Only people and placements have shared contract records');
    $ref=quantaCoreReferenceValidate($ref,$kind);
    return ($kind==='person'?'cfl_':'cfa_').integrationDeliveryHash([$workspaceId,$ref]);
}

function quantaContractDate(mixed $date): string
{
    $parsed=is_string($date)?DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;
    if (!$parsed || $parsed->format('Y-m-d')!==$date) throw new InvalidArgumentException('Use a valid calendar date');
    return $date;
}

function quantaAssignmentPayloadValidate(array $body, bool $creating=true): array
{
    $required=['placement','person','title','engagement_type','start_date','end_date','status','worksite_id','client','vendor','end_client'];
    if (array_diff($required,array_keys($body)) || array_diff(array_keys($body),$required)) throw new InvalidArgumentException('Complete assignment metadata required');
    foreach (['placement'=>'placement','person'=>'person','client'=>'client','vendor'=>'vendor','end_client'=>'company'] as $field=>$kind) {
        if (in_array($field,['client','vendor','end_client'],true) && $body[$field]===null) continue;
        if (!is_array($body[$field])) throw new InvalidArgumentException('Invalid assignment reference');
        $body[$field]=quantaCoreReferenceValidate($body[$field],$kind);
        if ($body[$field]['instance_id']!==$body['placement']['instance_id']) throw new InvalidArgumentException('Assignment references must share a Core installation');
    }
    $name=$body['title'];
    if (!is_string($name) || trim($name)==='' || preg_match_all('/./us',$name)===false
        || preg_match_all('/./us',$name)>200 || preg_match('/[\x00-\x1f\x7f]/',$name)) throw new InvalidArgumentException('A printable assignment title is required');
    if (!in_array($body['engagement_type'],['w2','1099','c2c'],true)) throw new InvalidArgumentException('This engagement type needs an explicit Quanta mapping');
    if (!in_array($body['status'],$creating?['draft','active']:['draft','active','ended','cancelled'],true)) throw new InvalidArgumentException('This placement status needs a separate lifecycle review');
    quantaContractDate($body['start_date']);
    if ($body['end_date']!==null && quantaContractDate($body['end_date'])<$body['start_date']) throw new InvalidArgumentException('End date precedes start date');
    if ($body['status']==='ended' && $body['end_date']===null) throw new InvalidArgumentException('Ended assignments require an end date');
    if ($body['worksite_id']!==null && (!is_string($body['worksite_id']) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$body['worksite_id']))) throw new InvalidArgumentException('Invalid worksite');
    return $body;
}

/** Identity and immutable relationships must agree before a conditional edit is proposed. */
function quantaAssignmentPlan(string $workspaceId, array $desired, ?array $remote): array
{
    $desired=quantaAssignmentPayloadValidate($desired,$remote===null);
    $id=quantaCoreRemoteId($workspaceId,$desired['placement']);
    if ($remote===null) return ['operation'=>'assignment.create','remote_id'=>$id,'body'=>$desired,'expected'=>$desired,'status'=>'preview'];
    if (($remote['id']??null)!==$id || ($remote['tenant_id']??null)!==$workspaceId || !is_int($remote['version']??null) || $remote['version']<1) throw new RuntimeException('Quanta returned an invalid assignment identity or version');
    foreach (['placement','person','engagement_type','start_date','worksite_id','client','vendor','end_client'] as $field) {
        if (!array_key_exists($field,$remote) || integrationDeliveryHash([$remote[$field]])!==integrationDeliveryHash([$desired[$field]])) {
            throw new RuntimeException('Assignment relationships changed. Review the existing assignment before publishing.');
        }
    }
    $changes=[];
    foreach (['title','end_date','status'] as $field) if (!array_key_exists($field,$remote) || $desired[$field]!==$remote[$field]) $changes[$field]=$desired[$field];
    $transitions=['draft'=>['draft','active','cancelled'],'active'=>['active','ended','cancelled']];
    if ($changes && !in_array($desired['status'],$transitions[$remote['status']??'']??[],true)) throw new RuntimeException('Closed or incompatible assignments need a lifecycle review');
    return ['operation'=>'assignment.update','remote_id'=>$id,'body'=>['expected_version'=>$remote['version']]+$changes,
        'expected'=>$desired+['version'=>$remote['version']+1],'status'=>$changes?'preview':'unchanged'];
}

function quantaContractRecord(string $key,string $workspaceId,array $reference): ?array
{
    $id=quantaCoreRemoteId($workspaceId,$reference);
    $path=QUANTA_CORE_CONTRACT_PATH.($reference['entity_type']==='person'?'/person-links/':'/assignments/').$id;
    try { $row=quantaGet($key,$path); }
    catch(QuantaApiException $e) { if($e->httpStatus===404)return null; throw $e; }
    $field=$reference['entity_type']==='person'?'person':'placement';
    if (($row['id']??null)!==$id || ($row['tenant_id']??null)!==$workspaceId
        || !is_array($row[$field]??null) || integrationDeliveryHash($row[$field])!==integrationDeliveryHash($reference)) {
        throw new RuntimeException('Quanta returned a record outside the expected identity');
    }
    return $row;
}

/** Validate the complete request again at the IO boundary; only these three routes can write. */
function quantaWriteContract(string $key,string $workspaceId,string $operation,array $body,array $expected,string $idempotencyKey): array
{
    if ($key==='' || preg_match('/[\r\n]/',$key) || !preg_match('/^[a-f0-9]{64}$/D',$idempotencyKey)) throw new InvalidArgumentException('A scoped key and durable delivery identity are required');
    $method='POST';
    if ($operation==='person.link') {
        if (array_diff(array_keys($body),['person','worker_id']) || !is_array($body['person']??null)
            || !is_string($body['worker_id']??null) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$body['worker_id'])) throw new InvalidArgumentException('Only a person reference and existing worker identity may be linked');
        $body['person']=quantaCoreReferenceValidate($body['person'],'person');
        if (integrationDeliveryHash($body)!==integrationDeliveryHash($expected)) throw new InvalidArgumentException('Link confirmation differs from the request');
        $id=quantaCoreRemoteId($workspaceId,$body['person']); $path='/person-links'; $codes=[200,201];
    } elseif ($operation==='assignment.create') {
        $body=quantaAssignmentPayloadValidate($body);
        if (integrationDeliveryHash($body)!==integrationDeliveryHash($expected)) throw new InvalidArgumentException('Assignment confirmation differs from the request');
        $id=quantaCoreRemoteId($workspaceId,$body['placement']); $path='/assignments'; $codes=[201]; $expected['version']=1;
    } elseif ($operation==='assignment.update') {
        if (array_diff(array_keys($body),['expected_version','title','end_date','status']) || count($body)<2
            || !is_int($body['expected_version']??null) || $body['expected_version']<1
            || ($expected['version']??null)!==$body['expected_version']+1) throw new InvalidArgumentException('A conditional assignment revision is required');
        $version=$expected['version']; unset($expected['version']);
        $expected=quantaAssignmentPayloadValidate($expected,false)+['version'=>$version];
        foreach ($body as $field=>$value) if ($field!=='expected_version' && $expected[$field]!==$value) throw new InvalidArgumentException('Assignment confirmation differs from the request');
        $id=quantaCoreRemoteId($workspaceId,$expected['placement']); $path='/assignments/'.$id; $codes=[200]; $method='PATCH';
    } elseif ($operation==='onboarding.publish') {
        require_once __DIR__.'/onboarding_contract.php';
        $publication=$body;unset($publication['expected_version']);
        $id=quantaOnboardingRemoteId($workspaceId,$publication);$path='/onboarding';$codes=[200,201];
        if(($expected['id']??null)!==$id||($expected['tenant_id']??null)!==$workspaceId
            ||($expected['version']??null)!==($body['expected_version']??0)+1
            ||($expected['projection_hash']??null)!==onboardingHash($publication)
            ||onboardingHash($expected['publication']??[])!==onboardingHash($publication))throw new InvalidArgumentException('Onboarding confirmation differs from the publication');
    } else { throw new InvalidArgumentException('Unsupported Quanta contract operation'); }
    $url=quantaApiBase().QUANTA_CORE_CONTRACT_PATH.$path;
    $json=json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $headers=['Authorization: Bearer '.$key,'Accept: application/json','Content-Type: application/json','Idempotency-Key: '.$idempotencyKey];
    try {
        if (isset($GLOBALS['__quanta_core_write_transport']) && is_callable($GLOBALS['__quanta_core_write_transport'])) {
            $result=($GLOBALS['__quanta_core_write_transport'])($method,$url,$headers,$json);
            $status=(int)($result['status']??0); $raw=(string)($result['body']??'');
        } else {
            $ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_POSTFIELDS=>$json,CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
            $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
            if($raw===false)throw new RuntimeException('Transport interrupted');
        }
    } catch(Throwable $e) { throw new QuantaContractWriteException('network_error',true); }
    if (!in_array($status,$codes,true)) {
        throw new QuantaContractWriteException('provider_rejected_or_unconfirmed',!in_array($status,[400,401,403,404,422],true));
    }
    $record=json_decode($raw,true);
    if (!is_array($record) || ($record['id']??null)!==$id || ($record['tenant_id']??null)!==$workspaceId
        || !is_string($record['delivery_id']??null) || !preg_match('/^cfd_[a-f0-9]{64}$/D',$record['delivery_id'])) throw new QuantaContractWriteException('invalid_success_response',true);
    foreach ($expected as $field=>$value) {
        if (!array_key_exists($field,$record) || integrationDeliveryHash([$record[$field]])!==integrationDeliveryHash([$value])) throw new QuantaContractWriteException('response_mismatch',true);
    }
    return $record;
}
