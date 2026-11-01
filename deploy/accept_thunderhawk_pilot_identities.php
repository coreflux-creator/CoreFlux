<?php
/** Actual source identities, isolated provider records, native delivery and repeated-send checks. */
declare(strict_types=1);
const TH_ID_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_ID_INSTANCE='9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--publish-held-identities'||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==TH_ID_ROOT)
    throw new RuntimeException('Exact held pilot required');
require_once TH_ID_ROOT.'/core/db.php';
require_once TH_ID_ROOT.'/core/sub_tenants.php';
require_once TH_ID_ROOT.'/core/quanta/worker_delivery.php';
require_once TH_ID_ROOT.'/core/quanta/worker_writer.php';
require_once TH_ID_ROOT.'/core/integrations/purepay_links.php';
require_once TH_ID_ROOT.'/core/rbac/permissions.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||COREFLUX_STAGING!==true||integrationInstanceId()!==TH_ID_INSTANCE
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND parent_id IS NULL AND is_active=1')->fetchColumn()!==1
    ||quantaApiBase()!=='https://helloquanta.app:19443/api/v1'||purepayCoreApiBase()!=='https://purepay.online:19443/api/v1'
    ||effectiveTenantIdForModule('people',2002)!==2002||effectiveTenantIdForModule('companies',2002)!==2002
    ||!RBACResolver::can(4,2002,'integrations','admin'))throw new RuntimeException('Pilot scope differs');
$report=['checked_at'=>gmdate('c'),'tenant_id'=>2002,'production_changed'=>false,'worker_access_granted'=>false,
    'notifications_sent'=>false,'compensation_changed'=>false,'payroll_or_financial_postings'=>false];
$phase='source';
try{
    $p=purepayCoreLinkPreview($pdo,2002,4,TH_ID_INSTANCE,2002,'person','P-358','emp_ea232efec736');
    if(strtolower(trim($p['source']['record']['email']))!==strtolower(trim($p['provider_record']['email']??'')))
        throw new RuntimeException('The reviewed worker identities no longer match');
    $c=purepayCoreLinkPreview($pdo,2002,4,TH_ID_INSTANCE,2002,'client','CL-1280','cus_e25f880f86');
    if($c['source']['record']['name']!==$c['provider_record']['name']
        ||$c['organization']['id']!=='org_0369a63ff2cb'||$p['organization']['id']!==$c['organization']['id'])
        throw new RuntimeException('The reviewed customer or company identity differs');
    if(!$p['delivery_enabled']||!$c['delivery_enabled'])throw new RuntimeException('Core identity delivery is held');
    foreach(['person'=>$p,'client'=>$c] as $kind=>$preview){
        $phase='purepay_'.$kind;$d=$preview['delivery'];
        $audit=static fn()=>platformAuditLogWrite(2002,4,'purepay.core.record_linked',null,['pilot'=>'thunderhawk-20261030','kind'=>$kind],['source'=>'purepay']);
        $sent=purepayCoreLinkSend($pdo,2002,4,TH_ID_INSTANCE,2002,(int)$d['id'],$d['review_hash'],$audit);
        $again=purepayCoreLinkSend($pdo,2002,4,TH_ID_INSTANCE,2002,(int)$d['id'],$d['review_hash'],$audit);
        if(($sent['state']??null)!=='delivered'||$again['remote_id']!==$sent['remote_id'])throw new RuntimeException('Link replay did not retain identity');
        $report[$phase]=['delivery_id'=>(int)$sent['id'],'state'=>$sent['state'],'provider_id'=>$sent['remote_id'],
            'receipt_id'=>$sent['remote_receipt_id'],'repeat_send_same_identity'=>true];
    }
    $phase='quanta_preview';
    $preview=quantaWorkerProfilesPreview(2002,4,[358]);$r=$preview['rows'][0]??[];
    if($preview['workspace']['id']!=='4f93d4e7-af74-4765-b812-3464bb6d974b'||!$preview['delivery_enabled'])
        throw new RuntimeException('Worker destination or delivery access differs');
    if(($r['status']??null)==='preview'){
        $d=$r['delivery'];$phase='quanta_send';
        $sent=quantaWorkerProfilesSend(2002,4,(int)$d['id'],$d['review_hash']);
        $again=quantaWorkerProfilesSend(2002,4,(int)$d['id'],$d['review_hash']);
        if($sent['state']!=='delivered'||$again['remote_id']!==$sent['remote_id'])throw new RuntimeException('Worker replay did not retain identity');
    }elseif(($r['status']??null)==='unchanged'){
        $history=quantaWorkerProfilesHistory(2002,358);$sent=$history['rows'][0]??[];
        if(($sent['state']??null)!=='delivered')throw new RuntimeException('Worker exists without a completed pilot receipt');
    }else throw new RuntimeException('Worker review is blocked');
    $phase='quanta_remote_replay';
    [$row,$payload]=quantaWorkerDeliveryEnvelope(2002,(int)$sent['id'],$sent['review_hash']);
    $access=quantaWorkerDeliveryAccess(2002);
    $replay=quantaWriteWorkerProfile($access['api_key'],$payload['workspace_id'],$payload['body'],$row['idempotency_key']);
    if($replay['worker_id']!==$sent['remote_id']||$replay['delivery_id']!==$sent['remote_receipt_id'])throw new RuntimeException('Provider replay changed identity');
    $phase='quanta_readback';
    $fresh=quantaWorkerProfilesPreview(2002,4,[358]);
    $record=quantaWorkerProfileRead($access['api_key'],$payload['workspace_id'],$payload['body']['person']);
    $workers=quantaListAll($access['api_key'],'/workers',[],5000);
    if(($fresh['rows'][0]['status']??null)!=='unchanged'||count($workers)!==1||$record['worker_id']!==$sent['remote_id']
        ||($record['worker']['status']??null)!=='disabled')throw new RuntimeException('Disabled worker or duplicate check failed');
    $report['quanta_worker']=['delivery_id'=>(int)$sent['id'],'state'=>$sent['state'],'worker_id'=>$sent['remote_id'],
        'receipt_id'=>$sent['remote_receipt_id'],'status'=>'disabled','worker_count'=>count($workers),
        'native_preview_after_delivery'=>'unchanged','remote_replay_same_worker_and_receipt'=>true];
    $report['passed']=true;
}catch(Throwable $e){$report['passed']=false;$report['phase']=$phase;$report['failure_class']=get_class($e);$report['failure_line']=$e->getLine();}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
exit($report['passed']?0:1);
