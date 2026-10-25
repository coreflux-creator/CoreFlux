<?php
declare(strict_types=1);
define('COREFLUX_DISABLE_DATABASE',true);
define('COREFLUX_STAGING',true);
define('COREFLUX_PUREPAY_PREVIEW_API_BASE','https://pure-pay-support.preview.emergentagent.com/api/v1');
require_once __DIR__.'/../core/integrations/purepay_core.php';
require_once __DIR__.'/../deploy/refresh_purepay_demo_location_review.php';

$key='synthetic-review-key-never-live';
$row=['id'=>1,'tenant_id'=>999,'revision'=>3,'status'=>'active','core_instance_id'=>DEMO_LOCATION_INSTANCE,
    'remote_organization_id'=>DEMO_LOCATION_ORG,'remote_organization_name'=>'Meridian Holdings LLC',
    'remote_api_base'=>DEMO_LOCATION_BASE];
$old=['tenant_id'=>999,'organization'=>['id'=>DEMO_LOCATION_ORG,'name'=>'Meridian Holdings LLC'],
    'contract_version'=>PUREPAY_CORE_VERSION,'link_contract_version'=>PUREPAY_CORE_LINK_VERSION,
    'capabilities'=>['read:coreflux','read:payroll','write:assignment_inputs'],
    'execution_side_effects'=>false,'assignment_input_contract_version'=>'2026-10-22.2'];
$row['binding_hash']=purepayCoreHash($row,$key,DEMO_LOCATION_INSTANCE,$old);
$review=array_replace($old,['assignment_input_contract_version'=>'2026-10-25.1','verified'=>false]);
$review['review_hash']=purepayCoreHash($row,$key,DEMO_LOCATION_INSTANCE,$review);
$checks=0;
$assert=static function(bool $condition,string $label)use(&$checks):void {
    if (!$condition) throw new RuntimeException($label); $checks++;
};
$reject=static function(array $r,array $v,string $k)use($assert):void {
    try {purepayDemoLocationReviewDelta($r,$v,$k);$rejected=false;} catch(Throwable $e){$rejected=true;}
    $assert($rejected,'Unreviewed identity/context accepted');
};
$assert(purepayDemoLocationReviewDelta($row,$review,$key),'Exact schema delta did not pass');
$complete=$row;$complete['binding_hash']=$review['review_hash'];
$verified=array_replace($review,['verified'=>true]);
$assert(!purepayDemoLocationReviewDelta($complete,$verified,$key),'Exact replay requested another mutation');
$after=array_replace($complete,['verified_by'=>4,'verified_at'=>'2026-10-25 16:00:00','updated_at'=>'2026-10-25 16:00:00']);
$assert(purepayDemoLocationConnectionPreserved($row,$after),'Native confirmation timestamps counted as unrelated data');
foreach (['revision'=>4,'api_key_ct'=>'replacement-ciphertext','remote_organization_id'=>'foreign-org','status'=>'revoked'] as $field=>$value) {
    $assert(!purepayDemoLocationConnectionPreserved($row,array_replace($after,[$field=>$value])),
        'Unrelated connection mutation was accepted');
}
foreach (['id'=>2,'tenant_id'=>2,'revision'=>4,'status'=>'revoked','core_instance_id'=>'different-instance',
    'remote_organization_id'=>'foreign-org','remote_organization_name'=>'Foreign company',
    'remote_api_base'=>'https://purepay.online/api/v1','binding_hash'=>str_repeat('0',64)] as $field=>$value) {
    $reject(array_replace($row,[$field=>$value]),$review,$key);
}
$reject($row,$review,'rotated-synthetic-key');
$reject($row,array_replace($review,['assignment_input_contract_version'=>'2026-11-01.1']),$key);
$reject($row,array_replace($review,['verified'=>true]),$key);
$reject($complete,$review,$key);
$reject($row,array_replace($review,['review_hash'=>str_repeat('0',64)]),$key);
$expanded=$review;$expanded['capabilities'][]='write:coreflux';
$expanded['review_hash']=purepayCoreHash($row,$key,DEMO_LOCATION_INSTANCE,$expanded);
$reject($row,$expanded,$key);
$changed=$review;$changed['organization']['id']='other-company';
$changed['review_hash']=purepayCoreHash($row,$key,DEMO_LOCATION_INSTANCE,$changed);
$reject($row,$changed,$key);
$readHint=$review;$readHint['payroll_observation_contract_version']='2026-10-25.1';
$readHint['review_hash']=purepayCoreHash($row,$key,DEMO_LOCATION_INSTANCE,$readHint);
$assert(purepayDemoLocationReviewDelta($row,$readHint,$key),'Existing read-only observation hint invalidated the schema delta');
echo 'PASS '.$checks." exact demo identity, schema delta, replay and access-change guards\n";
