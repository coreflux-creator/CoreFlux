<?php
/** Verify preview routing at real IO boundaries, without network or database access. */
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
putenv('COREFLUX_QUANTA_PREVIEW_API_BASE');
$preview='https://clockin-hub-16.preview.emergentagent.com/api/v1';
$hosted='https://helloquanta.app:19443/api/v1';
$live='https://helloquanta.app/api/v1';
$mode=$argv[1]??null;
if ($mode===null) {
    foreach (['default','production','false-staging','string-staging','foreign','http','suffix','constant-invalid','constant','staging','hosted','hosted-production','hosted-port','hosted-switch'] as $scenario) {
        $process=proc_open([PHP_BINARY,__FILE__,$scenario],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start isolated endpoint check');
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
        if ($status!==0) throw new RuntimeException($scenario.': '.$out.$err);
        echo $out;
    }
    echo "PASS 14 isolated Quanta endpoint scenarios\n";
    exit;
}
if ($mode!=='default') putenv('COREFLUX_QUANTA_PREVIEW_API_BASE='.$preview);
if ($mode==='false-staging') define('COREFLUX_STAGING',false);
elseif ($mode==='string-staging') define('COREFLUX_STAGING','true');
elseif (!in_array($mode,['default','production','hosted-production'],true)) define('COREFLUX_STAGING',true);
if (in_array($mode,['hosted','hosted-production'],true)) putenv('COREFLUX_QUANTA_PREVIEW_API_BASE='.$hosted);
if ($mode==='hosted-port') putenv('COREFLUX_QUANTA_PREVIEW_API_BASE=https://helloquanta.app:19444/api/v1');
if ($mode==='foreign') putenv('COREFLUX_QUANTA_PREVIEW_API_BASE=https://foreign.example/api/v1');
if ($mode==='http') putenv('COREFLUX_QUANTA_PREVIEW_API_BASE=http://clockin-hub-16.preview.emergentagent.com/api/v1');
if ($mode==='suffix') putenv('COREFLUX_QUANTA_PREVIEW_API_BASE='.$preview.'/');
if ($mode==='constant-invalid') define('COREFLUX_QUANTA_PREVIEW_API_BASE',true);
if ($mode==='constant') {
    // Private FPM configuration wins over the process environment.
    define('COREFLUX_QUANTA_PREVIEW_API_BASE',$preview);
    putenv('COREFLUX_QUANTA_PREVIEW_API_BASE=https://foreign.example/api/v1');
}
require_once __DIR__.'/../core/quanta/worker_writer.php';
require_once __DIR__.'/../core/quanta/worker_profile_contract.php';
require_once __DIR__.'/../core/quanta/onboarding_documents.php';
$calls=[];
$transport=static function($method,$url)use(&$calls){$calls[]=[$method,$url];return ['status'=>403,'body'=>'{}'];};
$GLOBALS['__quanta_transport']=$transport;
$GLOBALS['__quanta_write_transport']=$transport;
$GLOBALS['__quanta_core_write_transport']=$transport;
$GLOBALS['__quanta_document_transport']=static function($method,$url)use(&$calls){$calls[]=[$method,$url];return 403;};
$person=integrationRecordReference('381e452a-5d11-4e2e-9daa-e9427501898a',999,'person','P-1');
$link=['person'=>$person,'worker_id'=>'worker-1'];
$profile=['person'=>$person,'email'=>'synthetic@example.test','full_name'=>'Synthetic Worker','phone'=>null,'worker_id'=>null,'expected_worker'=>null];
$idem=str_repeat('a',64);
$receipt=['case_id'=>'cfo_'.str_repeat('b',64),'id'=>'cfs_'.str_repeat('c',64)];
$operations=[
    ['GET','/workers',static fn()=>quantaGet('fixture-key','/workers')],
    ['GET',QUANTA_APPROVED_ENTRIES_PATH,static fn()=>quantaGet('fixture-key',QUANTA_APPROVED_ENTRIES_PATH)],
    ['POST','/workers',static fn()=>quantaWriteWorker('fixture-key',null,['email'=>'synthetic@example.test','full_name'=>'Synthetic Worker','active'=>false],$idem)],
    ['POST',QUANTA_CORE_CONTRACT_PATH.'/person-links',static fn()=>quantaWriteContract('fixture-key','a','person.link',$link,$link,$idem)],
    ['POST',QUANTA_CORE_CONTRACT_PATH.'/worker-profiles',static fn()=>quantaWriteWorkerProfile('fixture-key','a',$profile,$idem)],
    ['GET',QUANTA_CORE_CONTRACT_PATH.'/onboarding/'.$receipt['case_id'].'/documents/'.$receipt['id'],static fn()=>quantaOnboardingDocumentBytes('fixture-key',$receipt,['size_bytes'=>10])],
];
$allowed=in_array($mode,['default','constant','staging','hosted','hosted-switch'],true);
foreach ($operations as [$method,$path,$call]) {
    try {$call();throw new LogicException('Fixture unexpectedly accepted');}
    catch (RuntimeException $e) {
        if ($allowed) {
            if (!($e instanceof QuantaApiException || $e instanceof QuantaWorkerWriteException || $e instanceof QuantaContractWriteException)) throw $e;
        } elseif ($e->getMessage()!=='Quanta preview access requires the reviewed isolated staging destination.') throw $e;
    }
}
if (!$allowed) {
    if ($calls!==[]) throw new RuntimeException('Invalid endpoint transmitted a request');
} else {
    $base=match($mode){'default'=>$live,'hosted'=>$hosted,default=>$preview};
    $expected=array_map(static fn($operation)=>[$operation[0],$base.$operation[1]],$operations);
    if ($calls!==$expected) throw new RuntimeException('Read, write or document request escaped its configured destination');
}
if (in_array($mode,['staging','hosted-switch'],true)) {
    $context=['workspace'=>['id'=>'a','name'=>'Synthetic A'],'contract_version'=>QUANTA_CORE_CONTRACT_VERSION,
        'capabilities'=>['person_links','assignment_metadata','conditional_assignment_updates','durable_delivery_receipts'],'execution_side_effects'=>false];
    $previewHash=quantaWorkspaceBindingHash(999,1,'fixture-key',$person['instance_id'],$context);
    if ($mode==='hosted-switch') putenv('COREFLUX_QUANTA_PREVIEW_API_BASE='.$hosted);
    else putenv('COREFLUX_QUANTA_PREVIEW_API_BASE');
    $liveHash=quantaWorkspaceBindingHash(999,1,'fixture-key',$person['instance_id'],$context);
    if ($previewHash===$liveHash || quantaWorkspaceBindingMatches(['state'=>'verified','binding_hash'=>$previewHash],$liveHash)) {
        throw new RuntimeException('A preview binding authorized a different provider origin');
    }
}
echo 'PASS '.$mode.': '.($allowed?'six IO boundaries use the selected origin':'all six IO boundaries reject before transport')."\n";
