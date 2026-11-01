<?php
/** A read-schema hint does not change previously reviewed identity or authority. */
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/purepay_core.php';
$context=['organization'=>['id'=>'org-fixture','name'=>'Synthetic company'],
    'contract_version'=>PUREPAY_CORE_VERSION,'execution_side_effects'=>false,
    'capabilities'=>['read:coreflux','read:payroll'],'link_contract_version'=>PUREPAY_CORE_LINK_VERSION];
$row=['tenant_id'=>999,'id'=>1,'revision'=>2];
$instance='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$hash=static fn(array $value):string=>purepayCoreHash($row,'synthetic-fixture-key',$instance,$value);
$passed=0;
$check=static function(bool $ok,string $message)use(&$passed):void {
    if(!$ok)throw new RuntimeException($message);$passed++;echo "PASS $message\n";
};
$with=$context+['payroll_observation_contract_version'=>'2026-10-25.1'];
$check(purepayCoreContextValidate($with)['payroll_observation_contract_version']==='2026-10-25.1','fresh read context carries its schema version');
$check($hash($with)===$hash($context),'adding a schema hint preserves the confirmed binding and old receipts');
$check($hash(array_replace($with,['payroll_observation_contract_version'=>'2026-10-26.1']))===$hash($context),'read-schema changes alone confer no new authority');
$check($hash(array_replace($with,['capabilities'=>['read:coreflux']]))!==$hash($context),'removing payroll access still invalidates review');
$check($hash(array_replace($with,['capabilities'=>['read:coreflux','read:payroll','write:coreflux']]))!==$hash($context),'expanding access still invalidates review');
$check($hash(array_replace($with,['organization'=>['id'=>'org-other','name'=>'Other company']]))!==$hash($context),'company changes still invalidate review');
foreach([['unexpected'=>'nested'],"2026-10-25.1\nprivate",str_repeat('1',33)]as$bad) {
    $failed=false;
    try {purepayCoreContextValidate(array_replace($context,['payroll_observation_contract_version'=>$bad]));}
    catch(RuntimeException $e){$failed=true;}
    $check($failed,'invalid schema hint is refused');
}
echo "PurePayroll observation context: $passed passed\n";
