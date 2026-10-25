<?php
/** Exercise actual financial routing and origin binding; no database or network. */
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
foreach (['PUREPAY_API_BASE','PUREPAY_CORE_API_BASE','COREFLUX_PUREPAY_PREVIEW_API_BASE',
    'COREFLUX_PUREPAY_FINANCIAL_PREVIEW_API_BASE'] as $name) putenv($name);
$mode = $argv[1] ?? null;
$scenarios = ['default','legacy','environment','constant','production','false-staging','string-staging',
    'foreign','http','suffix','constant-false','constant-empty','constant-invalid','binding-change'];
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
$preview = 'https://pure-pay-support.preview.emergentagent.com/api/v1';
$live = 'https://purepay.online/api/v1';
$setting = 'COREFLUX_PUREPAY_FINANCIAL_PREVIEW_API_BASE';
if ($mode === 'false-staging') define('COREFLUX_STAGING',false);
elseif ($mode === 'string-staging') define('COREFLUX_STAGING','true');
elseif ($mode !== 'production') define('COREFLUX_STAGING',true);
require_once __DIR__.'/../core/purepay_service.php';
$originalFingerprint = purepayConnectionFingerprint(999,'fixture-financial-key');
if (!in_array($mode,['default','legacy'],true)) putenv($setting.'='.$preview);
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
$calls = [];
$GLOBALS['__purepay_transport'] = static function($method,$url) use (&$calls): array {
    $calls[] = [$method,$url]; return ['status'=>200,'body'=>'{}'];
};
$allowed = in_array($mode,['default','legacy','environment','constant','binding-change'],true);
try {
    purepayGetWallet('fixture-financial-key');
    if (!$allowed) throw new LogicException('Invalid financial preview accepted');
} catch (RuntimeException $e) {
    if ($allowed || $e->getMessage() !== 'PurePay financial preview requires the reviewed isolated staging destination.') throw $e;
}
if (!$allowed && $calls !== []) throw new RuntimeException('Invalid configuration transmitted data');
if ($allowed) {
    $expected = match($mode) {'default'=>$live,'legacy'=>'https://legacy.fixture.test/api/v1',default=>$preview};
    if ($calls !== [['GET',$expected.'/wallet']]) throw new RuntimeException('Wrong financial destination');
    if (!in_array($mode,['default','legacy'],true)) {
        if ($originalFingerprint === purepayConnectionFingerprint(999,'fixture-financial-key')) {
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
