<?php
/** Dedicated inactive-worker/contact contract. The legacy publication sender stays disabled. */
declare(strict_types=1);
require_once __DIR__.'/core_contract.php';

const QUANTA_WORKER_PROFILE_VERSION = '2026-10-22.1';

function quantaWorkerObservationValidate(array $worker): array
{
    $fields=['email','full_name','phone','status','updated_at'];
    if (array_diff($fields,array_keys($worker)) || array_diff(array_keys($worker),$fields)
        || !is_string($worker['email']) || !filter_var($worker['email'],FILTER_VALIDATE_EMAIL)
        || !is_string($worker['full_name']) || !in_array($worker['status'],['active','disabled','invited'],true)
        || ($worker['phone']!==null && !is_string($worker['phone']))
        || ($worker['updated_at']!==null && (!is_string($worker['updated_at']) || strlen($worker['updated_at'])>100))) {
        throw new InvalidArgumentException('A complete reviewed Quanta worker observation is required');
    }
    return $worker;
}

function quantaWorkerProfileBodyValidate(array $body): array
{
    $fields=['person','email','full_name','phone','worker_id','expected_worker'];
    if (array_diff($fields,array_keys($body)) || array_diff(array_keys($body),$fields) || !is_array($body['person'])) {
        throw new InvalidArgumentException('Only the reviewed person reference and contact fields may be supplied');
    }
    $body['person']=quantaCoreReferenceValidate($body['person'],'person');
    if (!is_string($body['email']) || strlen($body['email'])>254 || !filter_var($body['email'],FILTER_VALIDATE_EMAIL)
        || $body['email']!==strtolower(trim($body['email']))) throw new InvalidArgumentException('Use the normalized primary email');
    foreach (['full_name'=>120,'phone'=>100] as $field=>$limit) {
        $value=$body[$field];
        if ($field==='phone' && $value===null) continue;
        if (!is_string($value) || trim($value)==='' || preg_match_all('/./us',$value)===false
            || preg_match_all('/./us',$value)>$limit || preg_match('/[\x00-\x1f\x7f]/',$value)) {
            throw new InvalidArgumentException('Use printable name and phone values');
        }
    }
    if ($body['worker_id']===null) {
        if ($body['expected_worker']!==null) throw new InvalidArgumentException('A new worker cannot reuse an existing worker observation');
    } else {
        if (!is_string($body['worker_id']) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$body['worker_id'])
            || !is_array($body['expected_worker'])) throw new InvalidArgumentException('Review the existing worker before updating contact fields');
        $body['expected_worker']=quantaWorkerObservationValidate($body['expected_worker']);
        if ($body['expected_worker']['email']!==$body['email']) throw new InvalidArgumentException('Resolve the worker email difference before publishing');
    }
    return $body;
}

/** The metadata binding does not itself authorize this additional capability. */
function quantaWorkerProfileContext(string $key): array
{
    $raw=quantaGet($key,QUANTA_CORE_CONTRACT_PATH.'/context');
    $context=quantaContractContextValidate($raw);
    if (($raw['worker_profile_contract_version']??null)!==QUANTA_WORKER_PROFILE_VERSION
        || array_diff(['inactive_worker_preparation','conditional_worker_contact_updates'],$raw['capabilities'])) {
        throw new RuntimeException('This Quanta environment has not enabled the reviewed worker-profile contract');
    }
    return $context+['worker_profile_contract_version'=>QUANTA_WORKER_PROFILE_VERSION];
}

function quantaWorkerProfileRecordValidate(array $record,string $workspace,array $person): array
{
    $person=quantaCoreReferenceValidate($person,'person');
    $id=quantaCoreRemoteId($workspace,$person);
    if (($record['id']??null)!==$id || ($record['person_link_id']??null)!==$id
        || ($record['tenant_id']??null)!==$workspace || !is_array($record['person']??null)
        || integrationDeliveryHash($record['person'])!==integrationDeliveryHash($person)
        || ($record['profile_contract_version']??null)!==QUANTA_WORKER_PROFILE_VERSION
        || !is_string($record['worker_id']??null) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$record['worker_id'])
        || ($record['access_changed']??null)!==false || ($record['invitation_sent']??null)!==false
        || !is_array($record['worker']??null)) throw new RuntimeException('Quanta returned a profile outside the reviewed workspace or person');
    quantaWorkerObservationValidate($record['worker']);
    return $record;
}

function quantaWorkerProfileRead(string $key,string $workspace,array $person): ?array
{
    $path=QUANTA_CORE_CONTRACT_PATH.'/worker-profiles/'.quantaCoreRemoteId($workspace,$person);
    try { $record=quantaGet($key,$path); }
    catch(QuantaApiException $e) { if($e->httpStatus===404)return null; throw $e; }
    return quantaWorkerProfileRecordValidate($record,$workspace,$person);
}

/** No API action calls this until reviewed delivery/reconciliation is connected. */
function quantaWriteWorkerProfile(string $key,string $workspace,array $body,string $idempotencyKey): array
{
    $body=quantaWorkerProfileBodyValidate($body);
    $id=quantaCoreRemoteId($workspace,$body['person']);
    if ($key==='' || preg_match('/[\r\n]/',$key) || !preg_match('/^[a-f0-9]{64}$/D',$idempotencyKey)) {
        throw new InvalidArgumentException('A scoped key and durable delivery identity are required');
    }
    $url=QUANTA_API_BASE.QUANTA_CORE_CONTRACT_PATH.'/worker-profiles';
    $json=json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $headers=['Authorization: Bearer '.$key,'Accept: application/json','Content-Type: application/json','Idempotency-Key: '.$idempotencyKey];
    try {
        if (isset($GLOBALS['__quanta_core_write_transport']) && is_callable($GLOBALS['__quanta_core_write_transport'])) {
            $result=($GLOBALS['__quanta_core_write_transport'])('POST',$url,$headers,$json);
            $status=(int)($result['status']??0); $raw=(string)($result['body']??'');
        } else {
            $ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json,CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
            $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
            if($raw===false)throw new RuntimeException('Transport interrupted');
        }
    } catch(Throwable $e) { throw new QuantaContractWriteException('network_error',true); }
    if ($status!==($body['worker_id']===null?201:200)) {
        throw new QuantaContractWriteException('worker_profile_rejected_or_unconfirmed',!in_array($status,[400,401,403,404,422],true));
    }
    try {
        $record=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($record)) throw new RuntimeException('Missing record');
        quantaWorkerProfileRecordValidate($record,$workspace,$body['person']);
        $expectedId=$body['worker_id']??('cfw_'.substr($id,4));
        $expected=['email'=>$body['email'],'full_name'=>$body['full_name'],
            'phone'=>$body['phone']??($body['expected_worker']['phone']??null),
            'status'=>$body['expected_worker']['status']??'disabled'];
        if ($record['worker_id']!==$expectedId || !is_string($record['delivery_id']??null)
            || !preg_match('/^cfd_[a-f0-9]{64}$/D',$record['delivery_id'])
            || !is_string($record['worker']['updated_at']) || $record['worker']['updated_at']==='') throw new RuntimeException('Unconfirmed worker identity or receipt');
        foreach ($expected as $field=>$value) if ($record['worker'][$field]!==$value) throw new RuntimeException('Worker contact response mismatch');
    } catch(Throwable $e) { throw new QuantaContractWriteException('invalid_worker_profile_response',true); }
    return $record;
}
