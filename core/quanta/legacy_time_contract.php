<?php
/** Reviewed historical associations; original source rows and approvals are never rewritten. */
declare(strict_types=1);
require_once __DIR__.'/time_control_contract.php';
require_once __DIR__.'/time_review_contract.php';

const QUANTA_LEGACY_TIME_VERSION='2026-11-12.1';

function quantaLegacyTimeBody(array $body): array
{
    $fields=['contract_version','assignment_id','source_entry_id','expected_version','expected_context_hash','confirm_existing_time'];
    if(array_diff($fields,array_keys($body))||array_diff(array_keys($body),$fields)
        ||$body['contract_version']!==QUANTA_LEGACY_TIME_VERSION||$body['confirm_existing_time']!==true
        ||!is_int($body['expected_version'])||$body['expected_version']<0
        ||!is_string($body['assignment_id'])||!preg_match('/^cfa_[a-f0-9]{64}$/D',$body['assignment_id'])
        ||!is_string($body['expected_context_hash'])||!preg_match('/^[a-f0-9]{64}$/D',$body['expected_context_hash']))throw new InvalidArgumentException('Review the complete original time link');
    quantaTimeControlIdentifier($body['source_entry_id']);return $body;
}

function quantaLegacyTimeProof(array $proof,string $workspace,array $body): array
{
    $fields=['source_revision','review_id','review_version','review_publication','assignment_identity','assignment_version','worksite_id'];
    if(array_diff($fields,array_keys($proof))||array_diff(array_keys($proof),$fields)
        ||!is_int($proof['review_version'])||$proof['review_version']<1
        ||!is_int($proof['assignment_version'])||$proof['assignment_version']<1
        ||!is_array($proof['review_publication'])||!is_array($proof['assignment_identity']))throw new RuntimeException('Complete historical review evidence required');
    $publication=quantaTimeReviewBody($proof['review_publication']+['expected_version'=>0]);unset($publication['expected_version']);
    if(array_key_exists('expected_version',$proof['review_publication'])
        ||$publication['assignment_id']!==$body['assignment_id']||$publication['source_entry_id']!==$body['source_entry_id']
        ||$publication['source_revision']!==$proof['source_revision']||$publication['assignment_version']!==$proof['assignment_version']
        ||$proof['review_id']!=='cft_'.integrationDeliveryHash([$workspace,$body['assignment_id'],$body['source_entry_id']]))throw new RuntimeException('Original review identity changed');
    $identity=$proof['assignment_identity'];quantaTimeControlIdentifier($identity['worker_id']??null);
    $identity=quantaTimeControlIdentity($identity,$workspace,$identity['worker_id']);
    if($identity['id']!==$body['assignment_id'])throw new RuntimeException('Historical assignment changed');
    if($proof['worksite_id']!==null)quantaTimeControlIdentifier($proof['worksite_id']);
    return $proof;
}

/** Snapshot must come from quantaTimeReviewSnapshot, including its checked Core imports. */
function quantaLegacyTimeContext(array $context,array $snapshot): array
{
    $fields=['contract_version','workspace_id','assignment_id','source_entry_id','source_entry','proof','current',
        'configuration_enabled','approval_authority','financial_execution','context_hash'];
    if(array_diff($fields,array_keys($context))||array_diff(array_keys($context),$fields)
        ||$context['contract_version']!==QUANTA_LEGACY_TIME_VERSION||$context['approval_authority']!==false||$context['financial_execution']!==false
        ||!is_bool($context['configuration_enabled'])||!is_array($context['source_entry'])||!is_array($context['proof'])
        ||($snapshot['status']??null)!=='unchanged')throw new RuntimeException('Reconcile current Core time before linking its history');
    // The proof includes the provider's exact source revision. Preserve that
    // digest instead of re-encoding native JSON objects as PHP arrays.
    $hashed=$context;unset($hashed['context_hash'],$hashed['source_entry']);
    if(!is_string($context['context_hash'])||!hash_equals(quantaMetadataRequestHash($hashed),$context['context_hash']))throw new RuntimeException('Historical review context changed');
    $review=$snapshot['remote'];$publication=quantaTimeReviewBody($snapshot['payload']['body']);unset($publication['expected_version']);
    $body=quantaLegacyTimeBody(['contract_version'=>QUANTA_LEGACY_TIME_VERSION,'assignment_id'=>$publication['assignment_id'],
        'source_entry_id'=>$publication['source_entry_id'],'expected_version'=>$context['current']['version']??0,
        'expected_context_hash'=>$context['context_hash'],'confirm_existing_time'=>true]);
    if($context['workspace_id']!==$review['workspace_id']||$context['assignment_id']!==$body['assignment_id']
        ||$context['source_entry_id']!==$body['source_entry_id']||array_key_exists('core_assignment',$context['source_entry'])
        ||integrationDeliveryHash($context['source_entry'])!==integrationDeliveryHash($review['source_entry']))throw new RuntimeException('Original source time changed');
    $proof=quantaLegacyTimeProof($context['proof'],$context['workspace_id'],$body);
    if(integrationDeliveryHash($proof['review_publication'])!==integrationDeliveryHash($publication)
        ||$proof['source_revision']!==$review['source_revision']||$proof['review_id']!==($review['current']['id']??null)
        ||$proof['review_version']!==($review['current']['version']??null)
        ||$proof['assignment_version']!==$review['assignment']['version'])throw new RuntimeException('Quanta history differs from the current Core import proof');
    foreach(['person','placement','worker_id'] as $field)
        if(integrationDeliveryHash([$proof['assignment_identity'][$field]])!==integrationDeliveryHash([$review['assignment'][$field]]))throw new RuntimeException('Original Core identity changed');
    if($context['current']!==null){
        $current=$context['current'];
        if(!is_array($current)||($current['id']??null)!=='cflt_'.integrationDeliveryHash([$context['workspace_id'],$body['source_entry_id']])
            ||($current['tenant_id']??null)!==$context['workspace_id']||($current['assignment_id']??null)!==$body['assignment_id']
            ||($current['source_entry_id']??null)!==$body['source_entry_id'])throw new RuntimeException('Existing historical owner differs');
    }
    return ['body'=>$body,'expected'=>quantaLegacyTimeExpected($context['workspace_id'],$body,$proof),'context'=>$context];
}

function quantaLegacyTimeExpected(string $workspace,array $body,array $proof): array
{
    quantaTimeControlIdentifier($workspace);$body=quantaLegacyTimeBody($body);$proof=quantaLegacyTimeProof($proof,$workspace,$body);
    return ['id'=>'cflt_'.integrationDeliveryHash([$workspace,$body['source_entry_id']]),'tenant_id'=>$workspace,
        'source_entry_id'=>$body['source_entry_id'],'assignment_id'=>$body['assignment_id'],'worker_id'=>$proof['assignment_identity']['worker_id'],
        'worksite_id'=>$proof['worksite_id'],'version'=>$body['expected_version']+1,'proof'=>$proof,'request_hash'=>quantaMetadataRequestHash($body)];
}

function quantaLegacyTimeExpectedValidate(string $workspace,array $body,array $expected): void
{
    if(!is_array($expected['proof']??null)||integrationDeliveryHash($expected)!==integrationDeliveryHash(quantaLegacyTimeExpected($workspace,$body,$expected['proof'])))throw new RuntimeException('Historical acknowledgement differs from the reviewed proof');
}

/** Fixed receipt recovery route; it cannot publish another association. */
function quantaLegacyTimeRecoverRemote(string $key,string $deliveryKey): array
{
    if($key===''||preg_match('/[\r\n]/',$key)||!preg_match('/^[a-f0-9]{64}$/D',$deliveryKey))throw new InvalidArgumentException('Original connection and request required');
    $url=quantaApiBase().QUANTA_CORE_CONTRACT_PATH.'/legacy-time-deliveries/'.$deliveryKey.'/reconcile';
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
    if($status!==200||!is_array($result))throw new RuntimeException('The original historical link receipt is still unconfirmed');
    return $result;
}
