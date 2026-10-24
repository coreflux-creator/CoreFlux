<?php
/** Narrow worker writer. No callers enable delivery until workspace/identity checks are complete. */
declare(strict_types=1);
require_once __DIR__.'/client.php';

final class QuantaWorkerWriteException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $uncertain, public readonly int $httpStatus=0)
    {
        parent::__construct($uncertain ? 'Quanta delivery needs reconciliation before another attempt.' : 'Quanta rejected this worker change.');
    }
}

/** API v1.1.0 preserves existing external_id / employee_id, roles, credentials and worksite membership. */
function quantaWriteWorker(string $apiKey, ?string $workerId, array $fields, string $idempotencyKey): array
{
    if ($apiKey==='' || preg_match('/[\r\n]/',$apiKey)) throw new InvalidArgumentException('Valid Quanta key required');
    if (!preg_match('/^[a-f0-9]{64}$/D',$idempotencyKey)) throw new InvalidArgumentException('Use the persisted delivery idempotency key');
    if ($workerId!==null && !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$workerId)) throw new InvalidArgumentException('Invalid Quanta worker identity');
    $create=$workerId===null;
    $allowed=$create?['email','full_name','phone','active']:['full_name','phone'];
    if (!$fields || array_diff(array_keys($fields),$allowed)) throw new InvalidArgumentException('Only reviewed worker contact fields may be published');
    if ($create && (!isset($fields['email'],$fields['full_name']) || ($fields['active']??null)!==false)) throw new InvalidArgumentException('New workers must enter the inactive onboarding workflow');
    if (isset($fields['email']) && (!is_string($fields['email']) || strlen($fields['email'])>254 || !filter_var($fields['email'],FILTER_VALIDATE_EMAIL))) throw new InvalidArgumentException('Invalid worker email');
    foreach (['full_name'=>120,'phone'=>100] as $name=>$max) {
        if (array_key_exists($name,$fields)) {
            $value=$fields[$name];
            if (!is_string($value) || trim($value)==='' || preg_match_all('/./us',$value)===false || preg_match_all('/./us',$value)>$max || preg_match('/[\x00-\x1f\x7f]/',$value)) throw new InvalidArgumentException('Invalid worker contact field');
        }
    }
    $method=$create?'POST':'PATCH';
    $url=quantaApiBase().'/workers'.($create?'':'/'.$workerId);
    $body=json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $headers=['Authorization: Bearer '.$apiKey,'Accept: application/json','Content-Type: application/json','Idempotency-Key: '.$idempotencyKey];
    if (isset($GLOBALS['__quanta_write_transport']) && is_callable($GLOBALS['__quanta_write_transport'])) {
        try {$response=($GLOBALS['__quanta_write_transport'])($method,$url,$headers,$body);}
        catch(Throwable $e){throw new QuantaWorkerWriteException('network_error',true);}
        $status=(int)($response['status']??0);$raw=(string)($response['body']??'');
    } else {
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if ($raw===false) throw new QuantaWorkerWriteException('network_error',true,$status);
    }
    if ($status<200 || $status>=300) {
        $rejected=in_array($status,[400,401,403,404,422],true);
        throw new QuantaWorkerWriteException($rejected?'request_rejected':'provider_outcome_unknown',!$rejected,$status);
    }
    if ($status!==($create?201:200)) throw new QuantaWorkerWriteException('unconfirmed_success_status',true,$status);
    $worker=json_decode($raw,true);
    if (!is_array($worker) || !is_string($worker['id']??null) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$worker['id']) || (!$create && $worker['id']!==$workerId)) {
        throw new QuantaWorkerWriteException('invalid_success_response',true,$status);
    }
    foreach ($fields as $name=>$value) {
        if (!array_key_exists($name,$worker) || $worker[$name]!==$value) throw new QuantaWorkerWriteException('response_mismatch',true,$status);
    }
    return $worker;
}
