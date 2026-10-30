<?php
/** Exercise actual financial routing and origin binding; no database or network. */
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
// These transport-only fixtures must not load a developer/CI database config
// or inherit its staging mode. Each child defines its own staging boundary.
foreach (['COREFLUX_ENV','COREFLUX_ACCOUNTING_DB_CONFIG_PATH','COREFLUX_DB_CONFIG_PATH'] as $name) putenv($name);
foreach (['PUREPAY_API_BASE','PUREPAY_CORE_API_BASE','COREFLUX_PUREPAY_PREVIEW_API_BASE',
    'COREFLUX_PUREPAY_FINANCIAL_PREVIEW_API_BASE','COREFLUX_PUREPAY_FINANCIAL_PREVIEW_TENANT_ID'] as $name) putenv($name);
$mode = $argv[1] ?? null;
$scenarios = ['default','legacy','environment','constant','production','false-staging','string-staging',
    'foreign','http','suffix','constant-false','constant-empty','constant-invalid','binding-change',
    'isolated-environment','isolated-constant','isolated-string-constant','isolated-missing-tenant',
    'isolated-empty-tenant','isolated-zero-tenant','isolated-negative-tenant','isolated-leading-zero-tenant',
    'isolated-spaced-tenant','isolated-overflow-tenant','isolated-exponent-tenant','isolated-list-tenant',
    'isolated-false-tenant','isolated-true-tenant','isolated-float-tenant','isolated-array-tenant',
    'isolated-http','isolated-port','isolated-suffix','isolated-production'];
if ($mode === null) {
    foreach ($scenarios as $scenario) {
        $process = proc_open([PHP_BINARY,__FILE__,$scenario],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start isolated financial preview check');
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException($scenario.': '.$out.$err);
        echo $out;
    }
    echo 'PASS '.count($scenarios)." isolated PurePay financial preview scenarios\n";
    exit;
}
if (!in_array($mode,$scenarios,true)) throw new RuntimeException('Unknown fixture');
if ($mode === 'false-staging') define('COREFLUX_STAGING',false);
elseif ($mode === 'string-staging') define('COREFLUX_STAGING','true');
elseif (!in_array($mode,['production','isolated-production'],true)) define('COREFLUX_STAGING',true);
require_once __DIR__.'/../core/purepay_service.php';
// Config bootstrap uses global temporary variables; initialize fixture values
// after loading the real service so its local setup cannot rename this setting.
$preview = 'https://pure-pay-support.preview.emergentagent.com/api/v1';
$isolated = str_starts_with($mode,'isolated-');
if ($isolated) $preview = 'https://purepay.online:19443/api/v1';
$tenantId = $isolated ? 2002 : 999;
$live = 'https://purepay.online/api/v1';
$setting = 'COREFLUX_PUREPAY_FINANCIAL_PREVIEW_API_BASE';
$tenantSetting = 'COREFLUX_PUREPAY_FINANCIAL_PREVIEW_TENANT_ID';
$originalFingerprint = purepayConnectionFingerprint($tenantId,'fixture-financial-key');
if (!in_array($mode,['default','legacy'],true)) putenv($setting.'='.$preview);
if ($isolated && $mode !== 'isolated-missing-tenant') putenv($tenantSetting.'=2002');
if ($mode === 'legacy') putenv('PUREPAY_API_BASE=https://legacy.fixture.test/api/v1');
if ($mode === 'foreign') putenv($setting.'=https://foreign.fixture.test/api/v1');
if ($mode === 'http') putenv($setting.'=http://pure-pay-support.preview.emergentagent.com/api/v1');
if ($mode === 'suffix') putenv($setting.'='.$preview.'/');
if ($mode === 'constant-false') define($setting,false);
if ($mode === 'constant-empty') define($setting,'');
if ($mode === 'constant-invalid') define($setting,true);
if ($mode === 'constant') {
    define($setting,$preview);
    putenv($setting.'=https://foreign.fixture.test/api/v1');
    putenv('PUREPAY_API_BASE=https://legacy.fixture.test/api/v1');
}
if (in_array($mode,['isolated-constant','isolated-string-constant'],true)) {
    define($setting,$preview);
    define($tenantSetting,$mode === 'isolated-constant' ? 2002 : '2002');
    putenv($setting.'=https://foreign.fixture.test/api/v1');
    putenv($tenantSetting.'=999');
}
$invalidTenantStrings = [
    'isolated-empty-tenant'=>'','isolated-zero-tenant'=>'0','isolated-negative-tenant'=>'-1',
    'isolated-leading-zero-tenant'=>'02002','isolated-spaced-tenant'=>' 2002 ',
    'isolated-overflow-tenant'=>'9223372036854775808','isolated-exponent-tenant'=>'2e3',
    'isolated-list-tenant'=>'2002,999',
];
if (array_key_exists($mode,$invalidTenantStrings)) putenv($tenantSetting.'='.$invalidTenantStrings[$mode]);
$invalidTenantConstants = [
    'isolated-false-tenant'=>false,'isolated-true-tenant'=>true,
    'isolated-float-tenant'=>2002.0,'isolated-array-tenant'=>[2002],
];
if (array_key_exists($mode,$invalidTenantConstants)) define($tenantSetting,$invalidTenantConstants[$mode]);
if ($mode === 'isolated-http') putenv($setting.'=http://purepay.online:19443/api/v1');
if ($mode === 'isolated-port') putenv($setting.'=https://purepay.online:19444/api/v1');
if ($mode === 'isolated-suffix') putenv($setting.'='.$preview.'/');
$calls = [];
$GLOBALS['__purepay_transport'] = static function($method,$url) use (&$calls): array {
    $calls[] = [$method,$url]; return ['status'=>200,'body'=>'{}'];
};
$allowed = in_array($mode,['default','legacy','environment','constant','binding-change',
    'isolated-environment','isolated-constant','isolated-string-constant'],true);
$expectedError = $mode === 'isolated-missing-tenant' || array_key_exists($mode,$invalidTenantStrings)
    || array_key_exists($mode,$invalidTenantConstants)
    ? 'PurePay financial preview requires an explicit simulation company.'
    : 'PurePay financial preview requires the reviewed isolated staging destination.';
try {
    purepayGetWallet('fixture-financial-key');
    if (!$allowed) throw new LogicException('Invalid financial preview accepted');
} catch (RuntimeException $e) {
    if ($allowed || $e->getMessage() !== $expectedError) throw $e;
}
if (!$allowed && $calls !== []) throw new RuntimeException('Invalid configuration transmitted data');
if ($allowed) {
    $expected = match($mode) {'default'=>$live,'legacy'=>'https://legacy.fixture.test/api/v1',default=>$preview};
    if ($calls !== [['GET',$expected.'/wallet']]) throw new RuntimeException('Wrong financial destination');
    if (!in_array($mode,['default','legacy'],true)) {
        if (purepayFinancialPreviewTenant() !== $tenantId) throw new RuntimeException('Wrong approved simulation company');
        if ($originalFingerprint === purepayConnectionFingerprint($tenantId,'fixture-financial-key')) {
            throw new RuntimeException('Origin change retained old payment authority');
        }
        foreach (['POST','PUT','PATCH','DELETE'] as $method) {
            try {
                purepayCall('fixture-financial-key',$method,'/bills/fixture/pay',[],25,str_repeat('a',64));
                throw new LogicException('Financial preview permitted a write');
            } catch (PurePayApiException $e) {
                if ($e->getMessage() !== 'PurePay financial preview is read-only.') throw $e;
            }
        }
        if (count($calls) !== 1) throw new RuntimeException('A financial write escaped the guard');
        foreach ([
            static fn()=>purepayStoreConnection(2,'fixture-key','Wrong company',4,'read_only'),
            static fn()=>purepayStoreConnection($tenantId,'fixture-key','Wrong scope',4,'read_write'),
            static fn()=>purepaySaveWebhookSecret(2,'whsec_fixture'),
            static fn()=>purepayConnectionFingerprint(2,'fixture-key'),
        ] as $forbiddenConnection) {
            try {
                $forbiddenConnection();
                throw new LogicException('Financial preview accepted an unreviewed company or write scope');
            } catch (PurePayApiException $e) {
                if ($e->getMessage() !== 'The financial preview requires the approved simulation company and a read-only connection.') throw $e;
            }
        }
        if (purepayGetConnection(2) !== null || count($calls)!==1) {
            throw new RuntimeException('Financial preview read another company connection or contacted a provider');
        }
        if ($isolated) {
            if (purepayGetConnection(999) !== null) throw new RuntimeException('New preview reused the old demo connection');
            try {
                purepayConnectionFingerprint(999,'fixture-financial-key');
                throw new LogicException('Old demo retained financial authority at the new preview');
            } catch (PurePayApiException $e) {
                if ($e->getMessage() !== 'The financial preview requires the approved simulation company and a read-only connection.') throw $e;
            }
        }
        // Existing reviewed staffing writes retain their independently chosen origin.
        require_once __DIR__.'/../core/integrations/purepay_core.php';
        $staffing = 'https://beautiful-kalam-6.preview.emergentagent.com/api/v1';
        putenv('COREFLUX_PUREPAY_PREVIEW_API_BASE='.$staffing);
        purepayCoreGet('fixture-staffing-key','/context');
        if (end($calls) !== ['GET',$staffing.'/integrations/coreflux/context']) throw new RuntimeException('Staffing read route changed');
        purepayCall('fixture-staffing-key','POST','/integrations/coreflux/links',[],25,str_repeat('b',64),purepayCoreApiBase());
        if (end($calls) !== ['POST',$staffing.'/integrations/coreflux/links']) throw new RuntimeException('Staffing write route changed');
    }
}
echo 'PASS '.$mode." financial destination and write boundary\n";
