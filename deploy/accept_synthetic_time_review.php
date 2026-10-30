<?php
/** Share only the existing P-15 / PL-13 pending review, using installed native Core services. */
declare(strict_types=1);

const PILOT_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const PILOT_TENANT=999;
const PILOT_INSTANCE='9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
const PILOT_WORKSPACE='0598d4ef-3075-4289-9714-c93f904aa85d';
const PILOT_SOURCE='d9caaf9a-877b-4f59-89d9-c2b71983a676';
const PILOT_ASSIGNMENT='cfa_33fdaed15cffaba6f9468cba05de1ac9d39651031f2eb5bfdc7079f3a87c45ac';
const PILOT_WORKER='cfw_2c5e38cf6059e5d00f16b534feb7e2006c697e0e82b5b009cabcfa86eef0892c';

if(PHP_SAPI!=='cli' || ($argv[1]??'')!=='--share-demo-pending-review'
    || getenv('COREFLUX_ENV')!=='staging' || realpath(getcwd())!==PILOT_ROOT) {
    fwrite(STDERR,"The named isolated pilot and explicit demo time publication are required.\n");exit(2);
}
// The workflow uploads these reviewed files for comparison only; they are never installed or executed.
foreach(['core/quanta/time_review_delivery.php','core/quanta/time_review_contract.php','core/quanta/core_contract.php',
    'core/quanta/sync.php','core/quanta/workspace.php','core/integrations/delivery.php'] as $file) {
    $reviewed=dirname(__DIR__).'/'.$file;$installed=PILOT_ROOT.'/'.$file;
    if(!is_file($reviewed) || !is_file($installed) || realpath($reviewed)===realpath($installed)
        || !hash_equals(hash_file('sha256',$reviewed),hash_file('sha256',$installed))) {
        fwrite(STDERR,"Installed time-review services differ from the reviewed source.\n");exit(2);
    }
}
require_once PILOT_ROOT.'/core/db.php';
$pdo=getDB();
if(!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
    || !defined('COREFLUX_STAGING') || COREFLUX_STAGING!==true
    || (int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn()!==1) {
    fwrite(STDERR,"The isolated simulation database could not be verified.\n");exit(2);
}
require_once PILOT_ROOT.'/core/sub_tenants.php';
require_once PILOT_ROOT.'/core/quanta/time_review_delivery.php';
require_once PILOT_ROOT.'/core/RBAC.php';
require_once PILOT_ROOT.'/core/rbac/permissions.php';
require_once PILOT_ROOT.'/core/rbac/legacy_map.php';

$report=['checked_at'=>gmdate('c'),'tenant_id'=>PILOT_TENANT,'mode'=>'synthetic_pending_time_review',
    'application_deployed'=>false,'persistent_configuration_changed'=>false,'permissions_changed'=>false,
    'time_approved'=>false,'payroll_or_financial_writes'=>false];
$previousFlag=getenv('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY');$phase='guard';$exit=0;$access=null;
try {
    foreach(['people','placements','time'] as $module) if(effectiveTenantIdForModule($module,PILOT_TENANT)!==PILOT_TENANT) throw new RuntimeException('Directory owner changed');
    if(integrationInstanceId()!==PILOT_INSTANCE || quantaApiBase()!=='https://clockin-hub-16.preview.emergentagent.com/api/v1') throw new RuntimeException('Reviewed instance or destination changed');
    if(!$pdo->query("SELECT id FROM people WHERE tenant_id=999 AND id=15 AND status='active'
        AND email_primary='core-integration-20261023@example.com' AND deleted_at IS NULL")->fetchColumn()
        || !$pdo->query("SELECT id FROM placements WHERE tenant_id=999 AND id=13 AND person_id=15 AND status='active'
        AND title='Synthetic Core Integration 20261023' AND deleted_at IS NULL")->fetchColumn()) throw new RuntimeException('Synthetic identity changed');
    $operator=$pdo->query("SELECT id,role FROM users WHERE id=4 AND email='stage-admin@coreflux.test'")->fetch(PDO::FETCH_ASSOC);
    $membership=RBACResolver::activeMembership(4,PILOT_TENANT);
    if(!$operator || ($membership['status']??null)!=='active') throw new RuntimeException('Existing operator unavailable');
    $operator['global_role']=$operator['role'];$operator['role']=RBACResolver::personaTypeOf(4,PILOT_TENANT);
    foreach(['integrations.quanta.manage','time.view','placements.view','people.view'] as $permission) {
        [$module,$action]=RbacLegacyMap::resolve($permission);
        if(!RBAC::hasPermission($operator,$permission) || !RBACResolver::can($operator,PILOT_TENANT,$module,$action)) throw new RuntimeException('Existing operator lacks permission');
    }
    setRequestTenantId(PILOT_TENANT);
    $unchangedRows=static function()use($pdo,&$phase):array {
        $queries=['time'=>'SELECT * FROM time_entries WHERE tenant_id=999 ORDER BY id',
            'sheets'=>'SELECT * FROM staffing_timesheets WHERE tenant_id=999 ORDER BY id',
            'person'=>'SELECT * FROM people WHERE tenant_id=999 AND id=15',
            'placement'=>'SELECT * FROM placements WHERE tenant_id=999 AND id=13',
            'imports'=>'SELECT * FROM quanta_time_imports WHERE tenant_id=999 ORDER BY id'];
        $hashes=[];foreach($queries as $name=>$sql){$phase='local_snapshot_'.$name;$hashes[$name]=integrationDeliveryHash($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));}
        return $hashes;
    };
    $phase='local_snapshot';$before=$unchangedRows();
    $phase='source_preflight';
    $snapshot=quantaTimeReviewSnapshot(PILOT_TENANT,PILOT_SOURCE);
    if($snapshot['payload']['workspace_id']!==PILOT_WORKSPACE || $snapshot['payload']['body']['assignment_id']!==PILOT_ASSIGNMENT
        || $snapshot['payload']['body']['core_tenant_id']!==PILOT_TENANT || $snapshot['placement_number']!=='PL-13'
        || $snapshot['payload']['body']['components']!==[['entry_id'=>1,'component'=>'regular','status'=>'pending_review']]
        || $snapshot['entry']['worker_id']!==PILOT_WORKER || $snapshot['entry']['work_date']!=='2026-10-19'
        || (float)$snapshot['entry']['hours']!==8.0
        || $snapshot['entry']['source_approval']['timesheet_id']!=='8c9e9039-109f-421c-9e7d-585adb8400a7') throw new RuntimeException('Demo time identity or review state changed');
    $report['source_identity_verified']=true;
    if(defined('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY') && COREFLUX_QUANTA_TIME_REVIEW_DELIVERY!==true) throw new RuntimeException('Persistent configuration prohibits publication');
    putenv('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY=1');
    if(!quantaTimeReviewEnabled())throw new RuntimeException('Status sharing remains disabled');
    $phase='publish';
    $preview=quantaTimeReviewPreview(PILOT_TENANT,4,PILOT_SOURCE);
    if(!empty($preview['receipt_only'])) {
        $old=$preview['delivery'];
        $checked=quantaTimeReviewReceipt(PILOT_TENANT,(int)$old['id'],$old['review_hash']);
        if($checked['delivery']['state']!=='delivered')throw new RuntimeException('Prior attempt remains unconfirmed');
        $report['recovered_delivery_id']=(int)$old['id'];
        $preview=quantaTimeReviewPreview(PILOT_TENANT,4,PILOT_SOURCE);
    }
    if(($preview['state']??null)!=='pending_review' || !empty($preview['receipt_only'])) throw new RuntimeException('Only the observed pending status may be shared');
    if($preview['status']==='unchanged') $report['already_unchanged']=true;
    else {
        $d=$preview['delivery'];
        $sent=quantaTimeReviewSend(PILOT_TENANT,4,(int)$d['id'],$d['review_hash']);
        $replayed=quantaTimeReviewSend(PILOT_TENANT,4,(int)$d['id'],$d['review_hash']);
        if($sent['state']!=='delivered' || $sent!==$replayed)throw new RuntimeException('Delivery replay differs');
        $report['delivery']=array_intersect_key($sent,array_flip(['id','state','remote_id','remote_receipt_id']));
        $report['native_replay_verified']=true;
    }
    $phase='fresh_comparison';
    $fresh=quantaTimeReviewSnapshot(PILOT_TENANT,PILOT_SOURCE);
    if($fresh['status']!=='unchanged' || $fresh['remote']['current']['state']!=='pending_review')throw new RuntimeException('Provider status did not reconcile');
    $current=$fresh['remote']['current'];
    $report['remote']=array_intersect_key($current,array_flip(['id','version','state','assignment_id','source_entry_id']));
    $report['fresh_comparison']='unchanged';
    $report['existing_time_and_identity_unchanged']=$before===$unchangedRows();
    if(!$report['existing_time_and_identity_unchanged'])throw new RuntimeException('Time or identity changed during publication');
    $report['passed']=true;
}catch(Throwable $e){
    $report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($e);
    if($e instanceof QuantaApiException)$report['provider_http_status']=$e->httpStatus;
    $exit=1;
}finally{
    $access=null;
    putenv($previousFlag===false?'COREFLUX_QUANTA_TIME_REVIEW_DELIVERY':'COREFLUX_QUANTA_TIME_REVIEW_DELIVERY='.$previousFlag);
    $report['publication_flag_restored']=getenv('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY')===$previousFlag;
    setRequestTenantId(null);
}
echo 'CORE_PILOT_TIME_REVIEW='.json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($exit);
