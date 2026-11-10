<?php
/** Provider setup observations only; no onboarding completion or execution authority. */
declare(strict_types=1);
require_once __DIR__.'/purepay_payroll_inputs.php';

const PUREPAY_PEOPLE_SETUP_VERSION='2026-10-22.1';

function purepayPeopleSetupReceipt(array $data,string $org,array $person,array $link,?int $now=null): array
{
    if(($data['contract_version']??null)!==PUREPAY_PEOPLE_SETUP_VERSION || ($data['organization_id']??null)!==$org
        || !is_array($data['person']??null) || integrationDeliveryHash($data['person'])!==integrationDeliveryHash($person)
        || ($data['provider_id']??null)!==$link['provider_id'] || ($data['link_id']??null)!==$link['id']
        || ($data['onboarding_completion']??null)!=='not_evaluated' || ($data['payroll_authorization']??null)!=='not_evaluated'
        || !is_string($data['status_revision']??null) || !preg_match('/^[a-f0-9]{64}$/D',$data['status_revision'])) {
        throw new RuntimeException('The employee setup observation does not match the confirmed person link.');
    }
    $indicators=$data['indicators']??null;
    if(!is_array($indicators) || !in_array($indicators['employee_status']??null,['active','inactive','unknown'],true)
        || !in_array($indicators['tax_election_record']??null,['employee_confirmed','provider_recorded','not_recorded'],true)) {
        throw new RuntimeException('PurePayroll returned an unsupported setup status.');
    }
    foreach(['invitation_recorded','profile_fields_present','bank_reference_present']as$field) {
        if(!is_bool($indicators[$field]??null))throw new RuntimeException('PurePayroll returned an incomplete setup observation.');
    }
    $stamp=$data['observed_at']??null;
    if(!is_string($stamp) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/D',$stamp))throw new RuntimeException('PurePayroll did not timestamp this observation.');
    try {$date=new DateTimeImmutable($stamp);}catch(Throwable $e){throw new RuntimeException('PurePayroll returned an invalid observation time.');}
    $errors=DateTimeImmutable::getLastErrors();
    $now??=time();
    if(($errors!==false && ($errors['warning_count'] || $errors['error_count'])) || $date->getTimestamp()<$now-300 || $date->getTimestamp()>$now+60)throw new RuntimeException('The employee setup observation is stale. Refresh it from PurePayroll.');
    // Never forward arbitrary provider fields, documents or sensitive values.
    return ['contract_version'=>PUREPAY_PEOPLE_SETUP_VERSION,'organization_id'=>$org,'person'=>$person,
        'provider_id'=>$link['provider_id'],'link_id'=>$link['id'],
        'indicators'=>array_intersect_key($indicators,array_flip(['employee_status','invitation_recorded','profile_fields_present','tax_election_record','bank_reference_present'])),
        'status_revision'=>$data['status_revision'],'observed_at'=>$stamp,
        'onboarding_completion'=>'not_evaluated','payroll_authorization'=>'not_evaluated'];
}

function purepayPeopleSetupRead(PDO $pdo,int $tenant,string $instance,int $owner,string $number): array
{
    $source=purepayCoreLinkSource($pdo,$instance,$owner,'person',$number);
    $access=purepayCoreLinkAccess($pdo,$tenant,$instance,'person',false);
    if(($access['review']['people_setup_contract_version']??null)!==PUREPAY_PEOPLE_SETUP_VERSION
        || !in_array('read:people_setup',$access['review']['capabilities'],true))throw new RuntimeException('Review a provider key with explicit employee setup access first.');
    $org=$access['review']['organization']['id'];
    $link=purepayPayrollInputLink($pdo,$tenant,$access['connection'],$source,$org);
    $query=$source['reference'];unset($query['entity_type']);$query['provider_id']=$link['provider_id'];
    $data=purepayCoreGet($access['key'],'/people/setup?'.http_build_query($query));
    $result=purepayPeopleSetupReceipt($data,$org,$source['reference'],$link);
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    $liveSource=purepayCoreLinkSource($pdo,$instance,$owner,'person',$number);
    $liveLink=purepayPayrollInputLink($pdo,$tenant,$access['connection'],$liveSource,$org);
    if(integrationDeliveryHash($source)!==integrationDeliveryHash($liveSource) || $link!==$liveLink)throw new RuntimeException('The Core person or link changed during the read. Refresh the review.');
    return $result;
}
