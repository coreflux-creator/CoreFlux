<?php
/** Read-only completion proof after the native demo approval and reviewer retirement. */
declare(strict_types=1);
const PILOT_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const PILOT_SOURCE='d9caaf9a-877b-4f59-89d9-c2b71983a676';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--verify-approved-demo-time'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==PILOT_ROOT){fwrite(STDERR,"Exact isolated pilot and read-only verification mode required.\n");exit(2);}
foreach(['core/quanta/time_review_delivery.php','core/quanta/time_review_contract.php','core/quanta/core_contract.php',
    'core/quanta/sync.php','core/quanta/workspace.php','core/integrations/delivery.php','core/memberships.php',
    'core/rbac/permissions.php','modules/time/lib/time.php'] as $file){
    $reviewed=dirname(__DIR__).'/'.$file;$installed=PILOT_ROOT.'/'.$file;
    if(!is_file($reviewed)||!is_file($installed)||realpath($reviewed)===realpath($installed)
        ||!hash_equals(hash_file('sha256',$reviewed),hash_file('sha256',$installed))){fwrite(STDERR,"Native services differ.\n");exit(2);}
}
require_once PILOT_ROOT.'/core/db.php';$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!defined('COREFLUX_STAGING')||COREFLUX_STAGING!==true
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn()!==1){fwrite(STDERR,"Isolated simulation database required.\n");exit(2);}
require_once PILOT_ROOT.'/core/sub_tenants.php';
require_once PILOT_ROOT.'/core/quanta/time_review_delivery.php';
require_once PILOT_ROOT.'/core/memberships.php';
require_once PILOT_ROOT.'/core/rbac/permissions.php';
require_once PILOT_ROOT.'/modules/time/lib/time.php';
require_once __DIR__.'/synthetic_time_reviewer.php';
require_once __DIR__.'/synthetic_time_approval_guard.php';
$report=['checked_at'=>gmdate('c'),'mode'=>'read_only_approved_demo_verification','tenant_id'=>999,
    'local_writes'=>false,'provider_record_writes'=>false,'production_changed'=>false,'independent_human_review'=>false];$phase='guard';
try{
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');$pdo->beginTransaction();setRequestTenantId(999);
    if(integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
        ||quantaApiBase()!=='https://clockin-hub-16.preview.emergentagent.com/api/v1')throw new RuntimeException('Instance or destination differs');
    foreach(['people','placements','companies','time'] as $module)if(effectiveTenantIdForModule($module,999)!==999)throw new RuntimeException('Directory owner changed');
    $report['reviewer_retirement']=syntheticTimeReviewerVerifyRetired($pdo,51);
    $phase='current_status';$entry=timeEntryGet(1);$rate=timeResolveRateSnapshot(13,'2026-10-19');$snapshot=quantaTimeReviewSnapshot(999,PILOT_SOURCE);
    syntheticTimeApprovalGuard($entry??[],$rate??[],$snapshot);
    if($entry['status']!=='approved'||$snapshot['status']!=='unchanged'||($snapshot['remote']['current']['state']??null)!=='accepted')throw new RuntimeException('Approved state differs');
    foreach(['time.entry.approved','time.pilot.reviewed'] as $event){
        $st=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=999 AND actor_user_id=51 AND target_id=1 AND event=?');$st->execute([$event]);
        $rows=$st->fetchAll(PDO::FETCH_COLUMN);if(count($rows)!==1)throw new RuntimeException('Native approval receipt is not unique');
        $meta=json_decode($rows[0],true,512,JSON_THROW_ON_ERROR);
        if($event==='time.entry.approved'){
            if(($meta['approved_via']??null)!=='manual'||($meta['quanta_source_check']['workspace_id']??null)!=='0598d4ef-3075-4289-9714-c93f904aa85d'
                ||($meta['quanta_source_check']['source_entries']??null)!==1)throw new RuntimeException('Original source consumption proof differs');
        }elseif(($meta['synthetic']??null)!==true||($meta['independent_human_review']??null)!==false||($meta['source_entry_id']??null)!==PILOT_SOURCE)
            throw new RuntimeException('Synthetic approval audit marker differs');
    }
    $phase='original_delivery';$row=integrationDeliveryGet($pdo,999,17);
    if($row['provider']!=='quanta'||$row['operation']!=='time.review'||$row['state']!=='delivered'
        ||(int)$row['entity_tenant_id']!==999||(int)$row['entity_id']!==13)throw new RuntimeException('Original delivery differs');
    [$saved,$payload]=quantaTimeReviewEnvelope(999,17,$row['review_hash']);
    if(($payload['body']['source_entry_id']??null)!==PILOT_SOURCE||($payload['expected']['state']??null)!=='accepted')throw new RuntimeException('Saved review differs');
    $access=quantaWorkspaceForDelivery(999);
    if(!hash_equals($row['binding_hash'],$access['binding_hash'])||$payload['workspace_id']!==$access['context']['workspace']['id'])throw new RuntimeException('Original binding differs');
    $first=null;
    for($attempt=0;$attempt<2;$attempt++){
        $receipt=quantaGet($access['api_key'],QUANTA_CORE_CONTRACT_PATH.'/time-review-deliveries/'.$row['idempotency_key']);
        if(($receipt['id']??null)!==$row['remote_receipt_id']||($receipt['request_hash']??null)!==quantaTimeReviewRequestHash($payload['body'])
            ||($receipt['state']??null)!=='complete'||!in_array($receipt['status_code']??null,[200,201],true)
            ||($receipt['response']['delivery_id']??null)!==$receipt['id'])throw new RuntimeException('Original provider receipt differs');
        foreach($payload['expected'] as $key=>$value)
            if(!array_key_exists($key,$receipt['response']??[])||integrationDeliveryHash([$receipt['response'][$key]])!==integrationDeliveryHash([$value]))
                throw new RuntimeException('Provider receipt no longer matches the reviewed request');
        if($attempt===0)$first=$receipt;elseif($receipt!==$first)throw new RuntimeException('Receipt repeat differs');
    }
    if(integrationDeliveryGet($pdo,999,17)!==$row||timeEntryGet(1)!==$entry)throw new RuntimeException('Saved approval or delivery changed');
    $report+=['passed'=>true,'entry_id'=>1,'hours'=>8,'approver_user_id'=>51,'native_approval_state'=>'approved',
        'rate_snapshot_id'=>(int)$entry['rate_snapshot_id'],'native_approval_audit_verified'=>true,'synthetic_audit_verified'=>true,
        'source_identity_and_rates_verified'=>true,'remote_state'=>'accepted','remote_version'=>$snapshot['remote']['current']['version'],
        'delivery_id'=>17,'original_provider_receipt_verified'=>true,'provider_receipt_reads'=>2,'receipt_repeat_identical'=>true,
        'stored_time_and_delivery_unchanged'=>true,'reviewer_cleanup_pending'=>false];
    $pdo->commit();
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();$report['passed']=false;$report['failed_phase']=$phase;
    $report['failure_class']=get_class($e);$report['failure_source']=basename($e->getFile());$report['failure_line']=$e->getLine();
    if($e instanceof QuantaApiException)$report['provider_http_status']=$e->httpStatus;
}finally{setRequestTenantId(null);}
echo 'CORE_PILOT_TIME_APPROVAL_VERIFIED='.json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
