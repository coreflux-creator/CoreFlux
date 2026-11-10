<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/purepay_people_setup.php';
$person=integrationRecordReference('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',10,'person','P-1');
$link=['id'=>'cfl_'.integrationDeliveryHash(['org-a',$person]),'provider_id'=>'employee-a'];
$now=time();
$data=['contract_version'=>PUREPAY_PEOPLE_SETUP_VERSION,'organization_id'=>'org-a','person'=>$person,
    'provider_id'=>'employee-a','link_id'=>$link['id'],'indicators'=>['employee_status'=>'active','invitation_recorded'=>true,
        'profile_fields_present'=>true,'tax_election_record'=>'provider_recorded','bank_reference_present'=>true],
    'status_revision'=>str_repeat('a',64),'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),
    'onboarding_completion'=>'not_evaluated','payroll_authorization'=>'not_evaluated'];
$passed=0;
$check=static function($ok,$label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed++;};
$read=static fn($value)=>purepayPeopleSetupReceipt($value,'org-a',$person,$link,$now);
$fails=static function($value)use($read){try{$read($value);return false;}catch(RuntimeException $e){return true;}};
$check($read($data)===$data,'Exact current observation accepted');
foreach(['organization_id'=>'other','provider_id'=>'other','link_id'=>'cfl_'.str_repeat('b',64),'contract_version'=>'future',
    'onboarding_completion'=>'complete','payroll_authorization'=>true,'status_revision'=>'invalid',
    'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-301)]as$field=>$value)$check($fails(array_replace($data,[$field=>$value])),'Reject '.$field);
$bad=$data;$bad['person']['directory_tenant_id']=20;$check($fails($bad),'Reject wrong directory');
foreach(['invitation_recorded','profile_fields_present','bank_reference_present']as$field) {
    $bad=$data;unset($bad['indicators'][$field]);$check($fails($bad),'Reject absent '.$field);
    $bad['indicators'][$field]='true';$check($fails($bad),'Reject truthy '.$field);
}
foreach(['employee_status'=>'completed','tax_election_record'=>'approved']as$field=>$value) {
    $bad=$data;$bad['indicators'][$field]=$value;$check($fails($bad),'Reject invented '.$field);
}
foreach(['2026-02-31T12:00:00Z','not-a-date',gmdate('Y-m-d\TH:i:s\Z',$now+61)]as$stamp) {
    $bad=$data;$bad['observed_at']=$stamp;$check($fails($bad),'Reject invalid or future clock');
}
$extra=$data;$extra['private_document']='never-return';$extra['indicators']['bank_last4']='never-return';
$check($read($extra)===$data,'Strip uncontracted private fields');
echo json_encode(['passed'=>$passed,'scope'=>'employee setup observation validation']),"\n";
