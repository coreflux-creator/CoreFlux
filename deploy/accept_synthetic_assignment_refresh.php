<?php
/** Publish only the reviewed location extension for the existing P-15 / PL-13 demo. */
declare(strict_types=1);
const ASSIGNMENT_PILOT_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const ASSIGNMENT_PILOT_INSTANCE='9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
const ASSIGNMENT_PILOT_QUANTA='0598d4ef-3075-4289-9714-c93f904aa85d';
const ASSIGNMENT_PILOT_REMOTE='cfa_33fdaed15cffaba6f9468cba05de1ac9d39651031f2eb5bfdc7079f3a87c45ac';
const ASSIGNMENT_PILOT_WORKER='cfw_2c5e38cf6059e5d00f16b534feb7e2006c697e0e82b5b009cabcfa86eef0892c';
const ASSIGNMENT_PILOT_PUREPAY='asg_core_0a33a112da5a16fd7d1ed5105e02368300ff7266efc49214933955327002921e';

function assignmentPilotRowsHash(array $rows): string
{
    // Database snapshots include binary ciphertext; preserve bytes without decoding or exposing it.
    return hash('sha256',serialize($rows));
}

function assignmentPilotReference(string $kind,string $number): array
{
    return integrationRecordReference(ASSIGNMENT_PILOT_INSTANCE,999,$kind,$number);
}

function assignmentPilotQuantaChange(array $snapshot): void
{
    $source=$snapshot['source'];$plan=$snapshot['plan'];$remote=$snapshot['remote']['assignment'];
    if ($snapshot['verified']['context']['workspace']['id']!==ASSIGNMENT_PILOT_QUANTA
        || $source['reference']!==assignmentPilotReference('placement','PL-13')
        || $source['person']!==assignmentPilotReference('person','P-15')
        || $source['worker_id']!==ASSIGNMENT_PILOT_WORKER || $plan['remote_id']!==ASSIGNMENT_PILOT_REMOTE
        || ($remote['id']??null)!==ASSIGNMENT_PILOT_REMOTE || ($remote['worker_id']??null)!==ASSIGNMENT_PILOT_WORKER
        || $plan['operation']!=='assignment.update' || !in_array($plan['status'],['preview','unchanged'],true)
        || $source['assignment']['work_location']!==['city'=>null,'state'=>'NJ','country'=>'US']) {
        throw new RuntimeException('Reviewed demo location or identity changed');
    }
    if ($plan['status']==='preview' && (array_keys($plan['body'])!==['expected_version','work_location']
        || $plan['body']['work_location']!==$source['assignment']['work_location']
        || $plan['body']['expected_version']!==$remote['version'])) {
        throw new RuntimeException('Changes exceed the reviewed location extension');
    }
}

function assignmentPilotOriginalLocation(array $row,array $payload): void
{
    if ((int)$row['tenant_id']!==999 || $row['provider']!=='quanta' || $row['operation']!=='assignment.update'
        || $row['entity_type']!=='placement' || (int)$row['entity_tenant_id']!==999 || (int)$row['entity_id']!==13
        || array_keys(integrationDeliveryCanonical($payload['body']))!==['expected_version','work_location']
        || integrationDeliveryHash($payload['body']['work_location'])!==integrationDeliveryHash(['city'=>null,'state'=>'NJ','country'=>'US'])
        || integrationDeliveryHash($payload['expected']['placement'])!==integrationDeliveryHash(assignmentPilotReference('placement','PL-13'))
        || integrationDeliveryHash($payload['expected']['person'])!==integrationDeliveryHash(assignmentPilotReference('person','P-15'))) {
        throw new RuntimeException('Original attempt is not the reviewed demo location extension');
    }
}

function assignmentPilotCommercialChange(array $old,array $fresh): void
{
    if (($old['contract_version']??null)!=='2026-10-22.2'
        || integrationDeliveryHash($old['placement']??[])!==integrationDeliveryHash(assignmentPilotReference('placement','PL-13'))
        || integrationDeliveryHash($old['person']??[])!==integrationDeliveryHash(assignmentPilotReference('person','P-15'))
        || ($old['work_context']['worksite_state']??null)!=='NJ' || ($old['work_context']['worksite_country']??null)!=='US'
        || array_key_exists('worksite_city',$old['work_context']??[]) || !is_string($old['source_revision']??null)) {
        throw new RuntimeException('Original commercial packet changed');
    }
    $expected=$old;$expected['contract_version']='2026-10-25.1';$expected['work_context']['worksite_city']=null;
    unset($expected['source_revision'],$expected['previous_revision']);
    $expected['source_revision']=integrationDeliveryHash($expected);
    $expected['previous_revision']=$old['source_revision'];
    if (integrationDeliveryHash($fresh)!==integrationDeliveryHash($expected)) {
        throw new RuntimeException('Commercial changes exceed the reviewed location extension');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__) return;
$mode=$argv[1]??'';
if (PHP_SAPI!=='cli' || !in_array($mode,['--publish-demo-location','--publish-demo-commercial'],true)
    || getenv('COREFLUX_ENV')!=='staging' || realpath(getcwd())!==ASSIGNMENT_PILOT_ROOT) {
    fwrite(STDERR,"Exact isolated staging and explicit demo assignment publication required.\n");exit(2);
}
$quanta=$mode==='--publish-demo-location';
$files=['core/integrations/delivery.php','core/integrations/identity.php','core/sub_tenants.php',
    ...($quanta?['core/quanta/metadata_delivery.php','core/quanta/core_contract.php','core/quanta/workspace.php','core/quanta/client.php']
        :['core/integrations/purepay_assignment_inputs.php','core/integrations/purepay_assignment_contract.php','core/integrations/purepay_links.php','core/integrations/purepay_core.php'])];
foreach($files as $file) {
    $reviewed=dirname(__DIR__).'/'.$file;$installed=ASSIGNMENT_PILOT_ROOT.'/'.$file;
    if (!is_file($reviewed) || !is_file($installed) || realpath($reviewed)===realpath($installed)
        || !hash_equals(hash_file('sha256',$reviewed),hash_file('sha256',$installed))) {
        fwrite(STDERR,"Installed assignment services differ from the reviewed source.\n");exit(2);
    }
}
$flag=$quanta?'COREFLUX_QUANTA_METADATA_DELIVERY':'COREFLUX_PUREPAY_ASSIGNMENT_DELIVERY';
$previous=getenv($flag);$phase='environment';$exit=0;
$report=['checked_at'=>gmdate('c'),'mode'=>$mode,'tenant_id'=>999,'production_changed'=>false,
    'native_assignment_activated'=>false,'time_approved'=>false,'financial_execution'=>false,'credentials_changed'=>false];
try {
    require_once ASSIGNMENT_PILOT_ROOT.'/core/db.php';
    require_once ASSIGNMENT_PILOT_ROOT.'/core/sub_tenants.php';
    require_once ASSIGNMENT_PILOT_ROOT.'/core/quanta/metadata_delivery.php';
    require_once ASSIGNMENT_PILOT_ROOT.'/core/integrations/purepay_assignment_inputs.php';
    require_once ASSIGNMENT_PILOT_ROOT.'/core/RBAC.php';
    require_once ASSIGNMENT_PILOT_ROOT.'/core/rbac/permissions.php';
    require_once ASSIGNMENT_PILOT_ROOT.'/core/rbac/legacy_map.php';
    $pdo=getDB();
    if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
        || !defined('COREFLUX_STAGING') || COREFLUX_STAGING!==true || integrationInstanceId()!==ASSIGNMENT_PILOT_INSTANCE
        || (int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn()!==1) throw new RuntimeException('Simulation identity changed');
    foreach(['people','placements','companies','ap','time'] as $module)if(effectiveTenantIdForModule($module,999)!==999)throw new RuntimeException('Directory ownership changed');
    if (!$pdo->query("SELECT id FROM people WHERE id=15 AND tenant_id=999 AND status='active' AND deleted_at IS NULL
            AND email_primary='core-integration-20261023@example.com'")->fetchColumn()
        || !$pdo->query("SELECT id FROM placements WHERE id=13 AND tenant_id=999 AND person_id=15 AND status='active'
            AND title='Synthetic Core Integration 20261023' AND deleted_at IS NULL")->fetchColumn())throw new RuntimeException('Synthetic identity changed');
    $operator=$pdo->query("SELECT id,role FROM users WHERE id=4 AND is_active=1 AND email='stage-admin@coreflux.test'")->fetch(PDO::FETCH_ASSOC);
    if(!$operator || (RBACResolver::activeMembership(4,999)['status']??null)!=='active')throw new RuntimeException('Existing operator unavailable');
    $operator['global_role']=$operator['role'];$operator['role']=RBACResolver::personaTypeOf(4,999);
    $permissions=$quanta?['integrations.quanta.manage','people.view','placements.view']
        :['accounting.bank.manage','placements.view','placements.financials.view','people.view','ap.view','payroll.view'];
    foreach($permissions as $permission){[$module,$action]=RbacLegacyMap::resolve($permission);
        if(!RBAC::hasPermission($operator,$permission)||!RBACResolver::can($operator,999,$module,$action))throw new RuntimeException('Existing operator lacks permission');}
    setRequestTenantId(999);
    $businessSnapshot=static function()use($pdo):array {
        $queries=['people'=>'SELECT * FROM people WHERE tenant_id=999 AND id=15',
            'placement'=>'SELECT * FROM placements WHERE tenant_id=999 AND id=13',
            'rates'=>'SELECT * FROM placement_rates WHERE tenant_id=999 AND placement_id=13 ORDER BY id',
            'time'=>'SELECT * FROM time_entries WHERE tenant_id=999 ORDER BY id',
            'sheets'=>'SELECT * FROM staffing_timesheets WHERE tenant_id=999 ORDER BY id',
            'imports'=>'SELECT * FROM quanta_time_imports WHERE tenant_id=999 ORDER BY id',
            'quanta_connections'=>'SELECT * FROM quanta_connections ORDER BY id',
            'purepay_core_connections'=>'SELECT * FROM purepay_core_connections ORDER BY id',
            'payment_connections'=>'SELECT * FROM purepay_connections ORDER BY tenant_id'];
        $out=[];foreach($queries as $name=>$sql)$out[$name]=assignmentPilotRowsHash($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));return $out;
    };
    $phase='local_preservation_snapshot';$before=$businessSnapshot();
    $history=$pdo->query('SELECT * FROM integration_deliveries WHERE state="delivered" OR tenant_id<>999 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if(defined($flag) && constant($flag)!==true)throw new RuntimeException('Persistent configuration prohibits publication');
    putenv($flag.'=1');
    if($quanta) {
        if(quantaApiBase()!=='https://clockin-hub-16.preview.emergentagent.com/api/v1')throw new RuntimeException('Quanta preview destination changed');
        $phase='quanta_preflight';$access=quantaWorkspaceForDelivery(999);
        if($access['context']['workspace']['id']!==ASSIGNMENT_PILOT_QUANTA)throw new RuntimeException('Quanta workspace changed');
        $remote=quantaContractRecord($access['api_key'],ASSIGNMENT_PILOT_QUANTA,assignmentPilotReference('placement','PL-13'));
        if(!$remote)throw new RuntimeException('Existing demo assignment missing');
        $site=$remote['worksite_id']??null;
        $snapshot=quantaMetadataSnapshot(999,'placement',13,$site);assignmentPilotQuantaChange($snapshot);
        $phase='quanta_review';$preview=quantaMetadataPreview(999,4,'placement',13,$site);
        if(!empty($preview['receipt_only'])) {
            $d=$preview['delivery'];[$row,$payload]=quantaMetadataEnvelope(999,(int)$d['id'],$d['review_hash']);assignmentPilotOriginalLocation($row,$payload);
            $recovered=quantaMetadataReceipt(999,4,(int)$d['id'],$d['review_hash']);
            if($recovered['delivery']['state']!=='delivered')throw new RuntimeException('Original location attempt remains held');
            $report['recovered_delivery_id']=(int)$d['id'];$preview=quantaMetadataPreview(999,4,'placement',13,$site);
        }
        if(($preview['status']??null)==='preview') {
            $d=$preview['delivery'];[$row,$payload]=quantaMetadataEnvelope(999,(int)$d['id'],$d['review_hash']);assignmentPilotOriginalLocation($row,$payload);
            $phase='quanta_send';$sent=quantaMetadataSend(999,4,(int)$d['id'],$d['review_hash']);
            $again=quantaMetadataSend(999,4,(int)$d['id'],$d['review_hash']);
            if($sent['state']!=='delivered' || $sent!==$again)throw new RuntimeException('Original delivery replay differs');
            $report['delivery']=array_intersect_key($sent,array_flip(['id','state','remote_id','remote_receipt_id']));
        } elseif(($preview['status']??null)==='unchanged') $report['already_unchanged']=true;
        else throw new RuntimeException('Unexpected demo location review');
        $phase='quanta_readback';$fresh=quantaMetadataSnapshot(999,'placement',13,$site);assignmentPilotQuantaChange($fresh);
        if($fresh['plan']['status']!=='unchanged' || $fresh['remote']['worker']!==$snapshot['remote']['worker']
            || $fresh['remote']['person_link']!==$snapshot['remote']['person_link'])throw new RuntimeException('Location or worker identity did not reconcile');
        $left=$snapshot['remote']['assignment'];$right=$fresh['remote']['assignment'];
        foreach(['work_location','version','updated_at','last_delivery_id'] as $field){unset($left[$field],$right[$field]);}
        if(integrationDeliveryHash($left)!==integrationDeliveryHash($right))throw new RuntimeException('Unrelated assignment metadata changed');
        $original=$pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=999 AND remote_receipt_id=:r AND provider="quanta" AND entity_id=13 AND entity_type="placement" ORDER BY id DESC LIMIT 1');
        $original->execute(['r'=>$fresh['remote']['assignment']['last_delivery_id']]);$row=$original->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Original location receipt is unavailable');
        [$row,$payload]=quantaMetadataEnvelope(999,(int)$row['id'],$row['review_hash']);assignmentPilotOriginalLocation($row,$payload);
        $query=['operation'=>'assignment.update','record_id'=>ASSIGNMENT_PILOT_REMOTE];
        $first=quantaGet($access['api_key'],QUANTA_CORE_CONTRACT_PATH.'/metadata-deliveries/'.$row['idempotency_key'],$query);
        $repeat=quantaGet($access['api_key'],QUANTA_CORE_CONTRACT_PATH.'/metadata-deliveries/'.$row['idempotency_key'],$query);
        $outcome=quantaMetadataReceiptOutcome($row,$payload,ASSIGNMENT_PILOT_QUANTA,$first);
        if($outcome['state']!=='confirmed'||$outcome['receipt_id']!==$row['remote_receipt_id']||$first!==$repeat)throw new RuntimeException('Original location receipt did not reconcile');
        $report['quanta']=['assignment_id'=>ASSIGNMENT_PILOT_REMOTE,'worker_id'=>ASSIGNMENT_PILOT_WORKER,
            'version'=>$fresh['remote']['assignment']['version'],'work_location'=>$fresh['remote']['assignment']['work_location'],
            'original_receipt_replay_identical'=>true,'other_assignment_fields_unchanged'=>true,'worker_identity_unchanged'=>true];
    } else {
        $phase='purepay_preflight';$owners=['people'=>999,'placements'=>999,'companies'=>999,'ap'=>999];
        if(purepayCoreApiBase()!=='https://pure-pay-support.preview.emergentagent.com/api/v1')throw new RuntimeException('PurePay preview destination changed');
        $old=integrationDeliveryGet($pdo,999,5);
        if($old['provider']!=='purepayroll'||$old['operation']!=='assignments.inputs'||$old['state']!=='delivered'||(int)$old['entity_id']!==13)throw new RuntimeException('Original commercial delivery changed');
        [$old,$saved]=purepayAssignmentInputEnvelope($pdo,999,5,$old['review_hash'],ASSIGNMENT_PILOT_INSTANCE,$owners);
        if($saved['organization_id']!=='org_purepay_demo')throw new RuntimeException('Original company changed');
        $originalStatus=purepayAssignmentInputStatus($pdo,999,ASSIGNMENT_PILOT_INSTANCE,$owners,5,$old['review_hash']);
        if($originalStatus['provider_review']['assignment_id']!==ASSIGNMENT_PILOT_PUREPAY
            || $originalStatus['provider_review']['assignment_status']!=='draft')throw new RuntimeException('Native demo assignment is not held');
        $phase='purepay_source';$snapshot=purepayAssignmentInputSnapshot($pdo,999,ASSIGNMENT_PILOT_INSTANCE,$owners,'PL-13',$saved['body']['source_revision']);
        assignmentPilotCommercialChange($saved['body'],$snapshot['payload']['body']);
        if($snapshot['access']['review']['organization']['id']!=='org_purepay_demo')throw new RuntimeException('Reviewed company changed');
        $phase='purepay_review';$preview=purepayAssignmentInputPreview($pdo,999,4,ASSIGNMENT_PILOT_INSTANCE,$owners,'PL-13',$saved['body']['source_revision']);
        assignmentPilotCommercialChange($saved['body'],$preview['packet']);$d=$preview['delivery'];
        $audit=static function()use($pdo,$d):void {
            $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at) VALUES(999,4,"purepay.core.assignment_input_send",NULL,:m,NULL,NOW())')
                ->execute(['m'=>json_encode(['action'=>'assignment_input_send','delivery_id'=>(int)$d['id'],'fixture'=>'location-extension'])]);
        };
        $phase='purepay_send';
        if(in_array($d['state'],['sending','uncertain'],true))$sent=purepayAssignmentInputStatus($pdo,999,ASSIGNMENT_PILOT_INSTANCE,$owners,(int)$d['id'],$d['review_hash'],$audit)['delivery'];
        else $sent=purepayAssignmentInputSend($pdo,999,4,ASSIGNMENT_PILOT_INSTANCE,$owners,(int)$d['id'],$d['review_hash'],$audit);
        if($sent['state']!=='delivered')throw new RuntimeException('Commercial attempt remains held');
        $replay=purepayAssignmentInputSend($pdo,999,4,ASSIGNMENT_PILOT_INSTANCE,$owners,(int)$d['id'],$d['review_hash'],$audit);
        $phase='purepay_receipt';
        $first=purepayAssignmentInputStatus($pdo,999,ASSIGNMENT_PILOT_INSTANCE,$owners,(int)$d['id'],$d['review_hash'],$audit);
        $repeat=purepayAssignmentInputStatus($pdo,999,ASSIGNMENT_PILOT_INSTANCE,$owners,(int)$d['id'],$d['review_hash'],$audit);
        if($sent!==$replay||$first!==$repeat||$first['provider_review']['assignment_id']!==ASSIGNMENT_PILOT_PUREPAY
            ||$first['provider_review']['assignment_status']!=='draft'||!in_array($first['provider_review']['state'],['revision_ready','created'],true))throw new RuntimeException('Commercial receipt or held identity differs');
        $report['delivery']=array_intersect_key($sent,array_flip(['id','state','remote_id','remote_receipt_id']));
        $report['purepay']=['provider_review'=>$first['provider_review'],'source_revision'=>$preview['packet']['source_revision'],
            'previous_revision'=>$preview['packet']['previous_revision'],'worksite_city'=>$preview['packet']['work_context']['worksite_city'],
            'commercial_values_preserved'=>true,'original_receipt_replay_identical'=>true,'native_revision_review_pending'=>$first['provider_review']['state']==='revision_ready'];
    }
    $phase='preservation';
    foreach($history as $original)if(integrationDeliveryGet($pdo,(int)$original['tenant_id'],(int)$original['id'])!==$original)throw new RuntimeException('Existing delivery history changed');
    if($before!==$businessSnapshot())throw new RuntimeException('Local business or connection state changed');
    $report['completed_and_nonpilot_deliveries_unchanged']=true;$report['local_business_and_connections_unchanged']=true;$report['passed']=true;
} catch(Throwable $e) {
    $report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($e);
    $report['source_file']=basename($e->getFile());$report['source_line']=$e->getLine();
    if($e instanceof QuantaApiException)$report['provider_http_status']=$e->httpStatus;
    $exit=1;
} finally {
    putenv($previous===false?$flag:$flag.'='.$previous);$report['publication_flag_restored']=getenv($flag)===$previous;
    if(function_exists('setRequestTenantId'))setRequestTenantId(null);
}
echo 'CORE_PILOT_ASSIGNMENT_REFRESH='.json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($exit);
