<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/purepay_links.php';
require_once __DIR__.'/../deploy/inspect_purepay_preview_continuity.php';
$count=0;
$check=static function(bool $ok,string $message) use (&$count): void {
    if (!$ok) throw new RuntimeException($message);
    $count++;
};
$bodies=[]; $responses=[];
$responses['/context']=['organization'=>['id'=>CONTINUITY_ORG,'name'=>'Meridian Holdings LLC'],
    'contract_version'=>PUREPAY_CORE_VERSION,'link_contract_version'=>PUREPAY_CORE_LINK_VERSION,
    'execution_side_effects'=>false,'capabilities'=>['read:coreflux','read:payroll']];
foreach (purepayPreviewContinuityFixtures() as $kind=>$fixture) {
    $reference=integrationRecordReference(CONTINUITY_INSTANCE,999,$kind,$fixture['number']);
    $body=['reference'=>$reference,'provider_id'=>$fixture['provider_id'],'expected_record_version'=>str_repeat('a',64)];
    $bodies[$kind]=$body;
    // Mutable profile versions need not equal the historical immutable link version.
    $record=['id'=>$fixture['provider_id'],'record_version'=>str_repeat('b',64)];
    if ($kind==='person') $record['status']='inactive';
    if ($kind==='placement') $record += ['status'=>'draft','person_number'=>'P-15','placement_number'=>'PL-13',
        'client_number'=>'CL-1','employee_id'=>purepayPreviewContinuityFixtures()['person']['provider_id'],
        'customer_id'=>purepayPreviewContinuityFixtures()['client']['provider_id'],
        'auto_link'=>false,'auto_bills'=>false,'bills_drive_invoicing'=>false];
    $responses['/records/'.$kind.'/'.$fixture['provider_id']]=$record;
    $receipt=['id'=>'cfl_'.integrationDeliveryHash([CONTINUITY_ORG,$reference]),'organization_id'=>CONTINUITY_ORG,
        'reference'=>$reference,'provider_id'=>$fixture['provider_id'],'provider_record_version'=>str_repeat('a',64)];
    $responses['/links/resolve?'.http_build_query($reference+['provider_id'=>$fixture['provider_id']])]=
        ['reference_link'=>$receipt,'provider_link'=>$receipt];
}
$run=static function(array $changes=[],int $status=200) use ($responses,$bodies,$check): array {
    $calls=[];
    $GLOBALS['__purepay_transport']=static function($method,$url,$headers,$body) use (&$calls,$responses,$changes,$status,$check): array {
        $prefix=CONTINUITY_CANDIDATE.PUREPAY_CORE_PATH;
        $check($method==='GET' && $body===null && str_starts_with($url,$prefix.'/'),'Only fixed candidate GETs allowed');
        $path=substr($url,strlen($prefix)); $calls[]=$path;
        $check(array_key_exists($path,$responses),'No broad list or unrelated resource may be read');
        return ['status'=>$status,'body'=>json_encode($changes[$path] ?? $responses[$path],JSON_THROW_ON_ERROR)];
    };
    $result=purepayPreviewContinuityRead('fixture-secret-never-print',$bodies);
    $check(!str_contains(json_encode($result),'fixture-secret'),'Credential omitted from output');
    return [$result,$calls];
};
[$ok,$calls]=$run();
$check($ok['continuity_verified'] && count($calls)===7 && count($ok['checks'])===7,'All three records and both sides of their links checked');
$check(!$ok['configuration_changed'] && !$ok['provider_record_writes'],'Reader cannot claim a migration');
$wrong=$responses['/context']; $wrong['organization']['id']='another-org';
[$bad,$calls]=$run(['/context'=>$wrong]);
$check(!$bad['continuity_verified'] && count($calls)===1,'Foreign organization stops before record reads');
$wrong=$responses['/context']; $wrong['execution_side_effects']=true;
[$bad,$calls]=$run(['/context'=>$wrong]);
$check(!$bad['continuity_verified'] && count($calls)===1,'Execution contract must stay read-only');
foreach ([401,403,404,409,500,302] as $status) {
    [$bad,$calls]=$run(['/context'=>['detail'=>'fixture-secret-never-print']],$status);
    $check(!$bad['continuity_verified'] && count($calls)===1 && $bad['checks']['organization']['http_status']===$status,
        'HTTP failure stops without exposing provider text or following redirects');
}
$linkPath=array_keys($responses)[2];
foreach (['absent','one-sided','version','reference'] as $failure) {
    $wrong=$responses[$linkPath];
    if ($failure==='absent') $wrong=['reference_link'=>null,'provider_link'=>null];
    if ($failure==='one-sided') $wrong['provider_link']=null;
    if ($failure==='version') $wrong['reference_link']['provider_record_version']=str_repeat('c',64);
    if ($failure==='reference') $wrong['provider_link']['reference']['directory_tenant_id']=2;
    [$bad,$calls]=$run([$linkPath=>$wrong]);
    $check(!$bad['continuity_verified'] && count($calls)===3,'Missing or conflicting immutable links fail closed');
}
$placementPath='/records/placement/'.purepayPreviewContinuityFixtures()['placement']['provider_id'];
foreach (['status'=>'active','auto_bills'=>true,'employee_id'=>'other-person','placement_number'=>'PL-99'] as $field=>$value) {
    $wrong=$responses[$placementPath]; $wrong[$field]=$value;
    [$bad,$calls]=$run([$placementPath=>$wrong]);
    $check(!$bad['continuity_verified'] && count($calls)===6,'Changed placement ownership or operational state rejected');
}
unset($GLOBALS['__purepay_transport']);
echo 'PASS '.$count." PurePay preview continuity assertions\n";
