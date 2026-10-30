<?php
/** Company-specific feature gates reject writes before database or provider access. */
declare(strict_types=1);
if(function_exists('putenv'))putenv('COREFLUX_DISABLE_DATABASE=1');
elseif(getenv('COREFLUX_DISABLE_DATABASE')!=='1')throw new RuntimeException('Database access must be disabled');
require_once __DIR__.'/../core/quanta/metadata_delivery.php';
require_once __DIR__.'/../core/quanta/worker_delivery.php';
require_once __DIR__.'/../core/quanta/onboarding_delivery.php';
require_once __DIR__.'/../core/quanta/time_review_delivery.php';
require_once __DIR__.'/../core/integrations/purepay_people_inputs.php';
require_once __DIR__.'/../core/integrations/purepay_customer_inputs.php';
require_once __DIR__.'/../core/integrations/purepay_assignment_inputs.php';
require_once __DIR__.'/../core/integrations/purepay_payroll_authority.php';

$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{
    if(!$ok)throw new RuntimeException($label);
    $passed++;
};
$gates=[
    'quantaMetadataDeliveryEnabled'=>'COREFLUX_QUANTA_METADATA_DELIVERY',
    'quantaWorkerDeliveryEnabled'=>'COREFLUX_QUANTA_WORKER_DELIVERY',
    'quantaOnboardingDeliveryEnabled'=>'COREFLUX_QUANTA_ONBOARDING_DELIVERY',
    'quantaTimeReviewEnabled'=>'COREFLUX_QUANTA_TIME_REVIEW_DELIVERY',
    'purepayCoreLinkEnabled'=>'COREFLUX_PUREPAY_CORE_LINK_DELIVERY',
    'purepayPeopleInputEnabled'=>'COREFLUX_PUREPAY_PEOPLE_DELIVERY',
    'purepayCustomerInputEnabled'=>'COREFLUX_PUREPAY_CUSTOMER_DELIVERY',
    'purepayAssignmentInputEnabled'=>'COREFLUX_PUREPAY_ASSIGNMENT_DELIVERY',
    'purepayPayrollInputsEnabled'=>'COREFLUX_PUREPAY_PAYROLL_INPUT_DELIVERY',
    'purepayPayrollAuthorityEnabled'=>'COREFLUX_PUREPAY_PAYROLL_AUTHORITY',
];
if(($argv[1]??'')==='--constant-config'){
    $list=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
    define('COREFLUX_INTEGRATION_DELIVERY_TENANTS',$list);
    foreach($gates as$flag)define($flag,true);
    define('COREFLUX_QUANTA_ONBOARDING_DOCUMENTS',true);
    define('COREFLUX_QUANTA_ONBOARDING_DOCUMENT_TENANTS','2002,999');
    $check(!function_exists('putenv'),'Production hosting restriction is reproduced');
    foreach(array_merge(array_keys($gates),['quantaOnboardingDocumentsEnabled'])as$function){
        foreach([2002,2,999,2003]as$tenant)
            $check($function($tenant)===($list==='2002'&&$tenant===2002),'Private constants preserve the company boundary');
    }
    define('COREFLUX_TEST_DISABLED_FEATURE',false);
    $check(!integrationDeliveryFlagEnabled('COREFLUX_TEST_DISABLED_FEATURE'),'False constant wins over enabled environment');
    echo "Constant configuration without putenv: $passed passed\n";
    exit;
}
putenv('COREFLUX_INTEGRATION_DELIVERY_TENANTS');
foreach($gates as$function=>$flag){
    putenv($flag);$check(!$function(2002),'Flag still required: '.$function);
    putenv($flag.'=1');$check($function(2002)&&$function(),'Unconfigured installation retains prior flag behavior: '.$function);
}
putenv('COREFLUX_INTEGRATION_DELIVERY_TENANTS=2002');
foreach($gates as$function=>$flag){
    $check($function(2002),'Named pilot enabled: '.$function);
    foreach([null,0,-1,2,999,2003]as$tenant)$check(!$function($tenant),'Other or absent company denied: '.$function);
    putenv($flag.'=0');$check(!$function(2002),'Allowlist never enables disabled feature: '.$function);putenv($flag.'=1');
}
putenv('COREFLUX_INTEGRATION_DELIVERY_TENANTS= 2002, 2003 ');
$check(integrationDeliveryTenantAllowed(2002)&&integrationDeliveryTenantAllowed(2003),'Explicit multi-company rollout');
foreach(['',' ','2002,','2002,,2003','0,2002','-1,2002','02002','2002x','2002,other','2002.0','2e3','2002,999999999999999999999999']as$invalid){
    putenv('COREFLUX_INTEGRATION_DELIVERY_TENANTS='.$invalid);
    $check(!integrationDeliveryTenantAllowed(2002),'Malformed configuration fails closed');
}
putenv('COREFLUX_INTEGRATION_DELIVERY_TENANTS=2002');
putenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENTS=1');
putenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENT_TENANTS=2002,999');
$check(quantaOnboardingDocumentsEnabled(2002)&&!quantaOnboardingDocumentsEnabled(999),'Document gate requires both company lists');
putenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENT_TENANTS=999');
$check(!quantaOnboardingDocumentsEnabled(2002),'General pilot list cannot grant document access');

class DeliveryGateNoIOPDO extends PDO { public function __construct(){} }
$pdo=new DeliveryGateNoIOPDO();
$scope=['tenant_id'=>999,'people_tenant_id'=>999,'placements_tenant_id'=>999,'actor_user_id'=>4,'permissions'=>['manage'=>true]];
$attempts=[
    fn()=>quantaMetadataSend(999,4,1,'hash'),
    fn()=>quantaWorkerProfilesSend(999,4,1,'hash'),
    fn()=>quantaTimeReviewSend(999,4,1,'hash'),
    fn()=>quantaOnboardingSend($scope,'case',1,'hash',static fn()=>null),
    fn()=>purepayCoreLinkSend($pdo,999,4,'instance',999,1,'hash'),
    fn()=>purepayPeopleInputSend($pdo,999,4,'instance',[],1,'hash'),
    fn()=>purepayCustomerInputSend($pdo,999,4,'instance',999,1,'hash'),
    fn()=>purepayAssignmentInputSend($pdo,999,4,'instance',[],1,'hash'),
    fn()=>purepayPayrollInputSend($pdo,999,4,'instance',[],1,'hash'),
    fn()=>purepayPayrollAuthorityReserve($pdo,999,4,'instance',[],1,'hash','request'),
    fn()=>purepayPayrollAuthoritySend($pdo,999,'instance','reservation'),
    fn()=>purepayPayrollAuthorityRelease($pdo,999,4,'instance','reservation'),
];
foreach($attempts as$attempt){
    $denied=false;
    try{$attempt();}catch(RuntimeException $error){$denied=str_contains($error->getMessage(),'not been enabled')||str_contains($error->getMessage(),'not enabled');}
    $check($denied,'Unlisted company rejected at the real send boundary');
}
// A server constant can override an environment feature flag, never its company boundary.
define('COREFLUX_QUANTA_METADATA_DELIVERY',true);putenv('COREFLUX_QUANTA_METADATA_DELIVERY=0');
$check(quantaMetadataDeliveryEnabled(2002)&&!quantaMetadataDeliveryEnabled(999),'Constant precedence retains company isolation');
define('COREFLUX_PUREPAY_CORE_LINK_DELIVERY',false);
$check(!purepayCoreLinkEnabled(2002),'Disabled server constant remains authoritative');
putenv('COREFLUX_TEST_DISABLED_FEATURE=1');
putenv('COREFLUX_INTEGRATION_DELIVERY_TENANTS=999');
foreach(['2002',false,'2002,broken']as$list){
    $process=proc_open([PHP_BINARY,'-d','disable_functions=putenv',__FILE__,'--constant-config',json_encode($list,JSON_THROW_ON_ERROR)],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Cannot test restricted web configuration');
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    $check($exit===0&&$stderr===''&&str_contains($stdout,'46 passed'),'Restricted host constant configuration: '.$stderr);
}
echo "Integration delivery enablement: $passed passed\n";
