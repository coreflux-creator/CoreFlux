<?php
/** Check real adapter routing and identity binding without provider or database IO. */
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
foreach(['PUREPAY_API_BASE','PUREPAY_CORE_API_BASE','COREFLUX_PUREPAY_PREVIEW_API_BASE'] as $name) putenv($name);
$preview='https://beautiful-kalam-6.preview.emergentagent.com/api/v1';
$replacement='https://pure-pay-support.preview.emergentagent.com/api/v1';
$hosted='https://purepay.online:19443/api/v1';
$live='https://purepay.online/api/v1';
$mode=$argv[1]??null;
if($mode===null) {
    foreach(['default','legacy-core','production','false-staging','string-staging','foreign','http','suffix','constant-invalid','constant-false','constant-empty','constant','staging','replacement','replacement-production','replacement-switch','hosted','hosted-production','hosted-port','hosted-switch'] as $scenario) {
        $process=proc_open([PHP_BINARY,__FILE__,$scenario],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process)) throw new RuntimeException('Cannot start isolated endpoint check');
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
        if($status!==0) throw new RuntimeException($scenario.': '.$out.$err);
        echo $out;
    }
    echo "PASS 20 isolated PurePay endpoint scenarios\n";
    exit;
}
if(!in_array($mode,['default','legacy-core'],true)) putenv('COREFLUX_PUREPAY_PREVIEW_API_BASE='.$preview);
if($mode==='legacy-core') putenv('PUREPAY_CORE_API_BASE=https://fixture.example.test/api/v1');
if($mode==='false-staging') define('COREFLUX_STAGING',false);
elseif($mode==='string-staging') define('COREFLUX_STAGING','true');
elseif(!in_array($mode,['default','legacy-core','production','replacement-production','hosted-production'],true)) define('COREFLUX_STAGING',true);
if(in_array($mode,['hosted','hosted-production'],true)) putenv('COREFLUX_PUREPAY_PREVIEW_API_BASE='.$hosted);
if($mode==='hosted-port') putenv('COREFLUX_PUREPAY_PREVIEW_API_BASE=https://purepay.online:19444/api/v1');
if(in_array($mode,['replacement','replacement-production'],true)) putenv('COREFLUX_PUREPAY_PREVIEW_API_BASE='.$replacement);
if($mode==='foreign') putenv('COREFLUX_PUREPAY_PREVIEW_API_BASE=https://foreign.example/api/v1');
if($mode==='http') putenv('COREFLUX_PUREPAY_PREVIEW_API_BASE=http://beautiful-kalam-6.preview.emergentagent.com/api/v1');
if($mode==='suffix') putenv('COREFLUX_PUREPAY_PREVIEW_API_BASE='.$preview.'/');
if($mode==='constant-invalid') define('COREFLUX_PUREPAY_PREVIEW_API_BASE',true);
if($mode==='constant-false') define('COREFLUX_PUREPAY_PREVIEW_API_BASE',false);
if($mode==='constant-empty') define('COREFLUX_PUREPAY_PREVIEW_API_BASE','');
if($mode==='constant') {
    define('COREFLUX_PUREPAY_PREVIEW_API_BASE',$preview);
    putenv('COREFLUX_PUREPAY_PREVIEW_API_BASE=https://foreign.example/api/v1');
    putenv('PUREPAY_CORE_API_BASE=https://foreign.example/api/v1');
}
require_once __DIR__.'/../core/integrations/purepay_core.php';
$calls=[];
$GLOBALS['__purepay_transport']=static function($method,$url)use(&$calls){$calls[]=[$method,$url];return ['status'=>200,'body'=>'{}'];};
$allowed=in_array($mode,['default','legacy-core','constant','staging','replacement','replacement-switch','hosted','hosted-switch'],true);
try {
    purepayCoreGet('fixture-key','/context');
    if(!$allowed) throw new LogicException('Invalid preview accepted');
} catch(RuntimeException $e) {
    if($allowed || $e->getMessage()!=='PurePay preview access requires the reviewed isolated staging destination.') throw $e;
}
if(!$allowed && $calls!==[]) throw new RuntimeException('Invalid preview transmitted a request');
if($allowed) {
    $base=match($mode){'default'=>$live,'legacy-core'=>'https://fixture.example.test/api/v1','replacement'=>$replacement,'hosted'=>$hosted,default=>$preview};
    if($calls!==[['GET',$base.PUREPAY_CORE_PATH.'/context']]) throw new RuntimeException('Core read used the wrong destination');
    purepayCall('fixture-key','POST',PUREPAY_CORE_PATH.'/links',[],25,str_repeat('a',64),purepayCoreApiBase());
    if($calls[1]!==['POST',$base.PUREPAY_CORE_PATH.'/links']) throw new RuntimeException('Core write adapter used the wrong destination');
}
// The preview switch must never change the independent payment credential's rail.
purepayListVendors('fixture-ap-key');
if(end($calls)!==['GET',$live.'/vendors']) throw new RuntimeException('Preview configuration moved the AP rail');
if(in_array($mode,['staging','replacement-switch','hosted-switch'],true)) {
    $instance='381e452a-5d11-4e2e-9daa-e9427501898a';
    $context=['organization'=>['id'=>'fixture-org','name'=>'Synthetic Company'],'contract_version'=>PUREPAY_CORE_VERSION,'capabilities'=>['read:coreflux'],'execution_side_effects'=>false];
    $row=['id'=>1,'tenant_id'=>999,'revision'=>1,'remote_organization_id'=>'fixture-org','core_instance_id'=>$instance,'remote_api_base'=>$preview];
    $previewHash=purepayCoreHash($row,'fixture-key',$instance,$context);
    if($mode==='replacement-switch') putenv('COREFLUX_PUREPAY_PREVIEW_API_BASE='.$replacement);
    elseif($mode==='hosted-switch') putenv('COREFLUX_PUREPAY_PREVIEW_API_BASE='.$hosted);
    else putenv('COREFLUX_PUREPAY_PREVIEW_API_BASE');
    if($previewHash===purepayCoreHash($row,'fixture-key',$instance,$context)) throw new RuntimeException('Origin change retained review authorization');
    $rejected=false;
    try {purepayCoreAssertPin($row,$instance,$context);} catch(RuntimeException $e) {$rejected=true;}
    if(!$rejected) throw new RuntimeException('An existing preview identity was retargeted to production');
}
echo 'PASS '.$mode.": Core routing checked; AP rail unchanged\n";
