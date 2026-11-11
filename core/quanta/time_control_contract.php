<?php
/** Explicit reviewed time eligibility; neither metadata publication nor time approval. */
declare(strict_types=1);
require_once __DIR__.'/core_contract.php';

const QUANTA_TIME_CONTROL_VERSION='2026-11-11.1';

function quantaTimeControlIdentifier(mixed $id): string
{
    if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$id))throw new InvalidArgumentException('Invalid worker or worksite identity');
    return $id;
}

function quantaTimeControlBody(array $body): array
{
    $keys=['contract_version','worker_id','worksite_id','expected_version','expected_context_hash','assignments','confirm_core_control'];
    if(array_diff($keys,array_keys($body))||array_diff(array_keys($body),$keys)
        ||$body['contract_version']!==QUANTA_TIME_CONTROL_VERSION||$body['confirm_core_control']!==true
        ||!is_int($body['expected_version'])||$body['expected_version']<0
        ||!is_string($body['expected_context_hash'])||!preg_match('/^[a-f0-9]{64}$/D',$body['expected_context_hash'])
        ||!is_array($body['assignments'])||!array_is_list($body['assignments'])||count($body['assignments'])>50)throw new InvalidArgumentException('Complete reviewed time setup required');
    quantaTimeControlIdentifier($body['worker_id']);quantaTimeControlIdentifier($body['worksite_id']);$seen=[];
    foreach($body['assignments'] as $row){
        if(!is_array($row)||count($row)!==2||array_diff(array_keys($row),['id','version'])
            ||!is_string($row['id']??null)||!preg_match('/^cfa_[a-f0-9]{64}$/D',$row['id'])
            ||!is_int($row['version']??null)||$row['version']<1||isset($seen[$row['id']]))throw new InvalidArgumentException('Invalid or repeated reviewed assignment');
        $seen[$row['id']]=true;
    }
    usort($body['assignments'],static fn($a,$b)=>strcmp($a['id'],$b['id']));return $body;
}

function quantaTimeControlHash(array $body): string
{
    return quantaMetadataRequestHash(quantaTimeControlBody($body));
}

function quantaTimeControlIdentity(array $identity,string $workspace,string $worker): array
{
    $keys=['id','tenant_id','person','placement','worker_id','person_link_id','engagement_type','start_date','client','vendor','end_client'];
    if(array_diff($keys,array_keys($identity))||array_diff(array_keys($identity),$keys)
        ||$identity['tenant_id']!==$workspace||$identity['worker_id']!==$worker)throw new RuntimeException('Assignment belongs to another worker or workspace');
    foreach(['person'=>'person','placement'=>'placement','client'=>'client','vendor'=>'vendor','end_client'=>'company'] as $field=>$kind){
        if(in_array($field,['client','vendor','end_client'],true)&&$identity[$field]===null)continue;
        if(!is_array($identity[$field]))throw new RuntimeException('Typed assignment identity required');
        $identity[$field]=quantaCoreReferenceValidate($identity[$field],$kind);
        if($identity[$field]['instance_id']!==$identity['person']['instance_id'])throw new RuntimeException('Assignment Core instance changed');
    }
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$identity['start_date']);
    if(!$date||$date->format('Y-m-d')!==$identity['start_date']||!in_array($identity['engagement_type'],['w2','c2c','1099'],true)
        ||$identity['id']!==quantaCoreRemoteId($workspace,$identity['placement'])
        ||$identity['person_link_id']!==quantaCoreRemoteId($workspace,$identity['person']))throw new RuntimeException('Assignment identity requires reconciliation');
    return $identity;
}

function quantaTimeControlExpected(string $workspace,array $body,array $context): array
{
    $body=quantaTimeControlBody($body);quantaTimeControlIdentifier($workspace);
    $selected=[];
    foreach($body['assignments'] as $chosen){
        $matches=array_values(array_filter($context['assignments']??[],static fn($r)=>($r['identity']['id']??null)===$chosen['id']));
        if(count($matches)!==1||($matches[0]['version']??null)!==$chosen['version'])throw new RuntimeException('Reviewed assignment revision changed');
        $selected[]=['identity'=>quantaTimeControlIdentity($matches[0]['identity'],$workspace,$body['worker_id']),'reviewed_version'=>$chosen['version']];
    }
    return ['id'=>'cfta_'.integrationDeliveryHash([$workspace,$body['worker_id'],$body['worksite_id']]),
        'tenant_id'=>$workspace,'worker_id'=>$body['worker_id'],'worksite_id'=>$body['worksite_id'],
        'version'=>$body['expected_version']+1,'assignments'=>$selected,
        'native_membership_id'=>quantaTimeControlIdentifier($context['native_membership_id']??null),'request_hash'=>quantaTimeControlHash($body)];
}

function quantaTimeControlExpectedValidate(string $workspace,array $body,array $expected): void
{
    $context=['native_membership_id'=>$expected['native_membership_id']??null,'assignments'=>[]];
    foreach($expected['assignments']??[] as $row)$context['assignments'][]=['identity'=>$row['identity']??[], 'version'=>$row['reviewed_version']??null];
    if(integrationDeliveryHash($expected)!==integrationDeliveryHash(quantaTimeControlExpected($workspace,$body,$context)))throw new InvalidArgumentException('Time setup acknowledgement differs from the reviewed request');
}

/** Recover a receipt only. This fixed route never resends the policy publication. */
function quantaTimeControlRecoverRemote(string $key,string $deliveryKey): array
{
    if($key===''||preg_match('/[\r\n]/',$key)||!preg_match('/^[a-f0-9]{64}$/D',$deliveryKey))throw new InvalidArgumentException('Original connection and request required');
    $url=quantaApiBase().QUANTA_CORE_CONTRACT_PATH.'/time-access-deliveries/'.$deliveryKey.'/reconcile';
    $headers=['Authorization: Bearer '.$key,'Accept: application/json','Content-Type: application/json'];
    if(isset($GLOBALS['__quanta_core_write_transport'])&&is_callable($GLOBALS['__quanta_core_write_transport'])){
        $r=($GLOBALS['__quanta_core_write_transport'])('POST',$url,$headers,'{}');$status=(int)($r['status']??0);$raw=$r['body']??'';
    }else{
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'{}',CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    }
    $result=is_string($raw)?json_decode($raw,true):null;
    if($status!==200||!is_array($result))throw new RuntimeException('The original setup receipt is still unconfirmed');
    return $result;
}
