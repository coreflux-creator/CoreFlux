<?php
/** Included only inside the disposable staffing_onboarding_mariadb fixture. */
$fixturePdo=$pdo;require_once __DIR__.'/../../core/quanta/onboarding_delivery.php';$pdo=$fixturePdo;
putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));
$beforeChecks=$passed;
$reject=static function(callable $f,string $label)use($check){try{$f();}catch(RuntimeException|InvalidArgumentException $e){$check(true,$label);return;}throw new LogicException('Accepted '.$label);};
$pdo->exec('CREATE TABLE tenants(id BIGINT PRIMARY KEY,parent_id BIGINT,tenant_type VARCHAR(20),is_active INT)');
$pdo->exec("INSERT INTO tenants VALUES(10,NULL,'master',1),(11,10,'sub',1)");
$pdo->exec('CREATE TABLE tenant_module_scope(tenant_id BIGINT,module_key VARCHAR(40),scope_mode VARCHAR(20))');
$pdo->exec("INSERT INTO tenant_module_scope VALUES(11,'placements','isolated')");
$pdo->exec('CREATE TABLE quanta_connections(id BIGINT PRIMARY KEY,tenant_id BIGINT UNIQUE,status VARCHAR(20),api_key_ct VARBINARY(2048))');
foreach(['158_integration_deliveries.sql','162_integration_delivery_receipt.sql','159_quanta_workspace_binding.sql','148_quanta_worker_identity.sql']as$file)
    foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../../core/migrations/'.$file))as$sql)$pdo->exec($sql);
$qScope=['tenant_id'=>11,'people_tenant_id'=>10,'placements_tenant_id'=>11,'actor_user_id'=>90,'permissions'=>['view'=>true,'manage'=>true,'review'=>true]];
$qHead=static fn($key)=>null;$qInstance='381e452a-5d11-4e2e-9daa-e9427501898a';$qWorkspace='synthetic-quanta';$qKey='synthetic-onboarding-key';
putenv('COREFLUX_INTEGRATION_INSTANCE_ID='.$qInstance);
$qContext=['workspace'=>['id'=>$qWorkspace,'name'=>'Synthetic Quanta'],'contract_version'=>QUANTA_CORE_CONTRACT_VERSION,
    'execution_side_effects'=>false,'capabilities'=>['person_links','assignment_metadata','conditional_assignment_updates','durable_delivery_receipts']];
$pdo->prepare('INSERT INTO quanta_connections VALUES(11,11,"active",?,1)')->execute([encryptField($qKey)]);
quantaWorkspaceBind($pdo,11,90,['id'=>11,'binding_revision'=>1],$qKey,$qInstance,$qContext,quantaWorkspaceBindingHash(11,11,$qKey,$qInstance,$qContext));
$pdo->exec("INSERT INTO quanta_worker_links(tenant_id,worker_id,person_id,linked_by_user_id) VALUES(11,'worker-2',2,90)");
$qId=onboardingCommand($pdo,$qScope,['action'=>'create','request_id'=>onboardingUuid(),'person_id'=>2,'placement_id'=>2],$qHead)['case_id'];
$qView=static fn()=>onboardingView($pdo,$qScope,$qId,$qHead);
$qCommand=static fn($action,$fields=[])=>onboardingCommand($pdo,$qScope,['action'=>$action,'request_id'=>onboardingUuid(),'case_id'=>$qId,'expected_version'=>$qView()['version']]+$fields,$qHead);
$qTask=$qCommand('add_task',['title'=>'Orientation / café','instructions'=>'Confirm orientation was delivered.','assignee'=>'worker','evidence_kind'=>'attestation','required'=>true])['task_id'];
$qCommand('confirm_plan');
$qPerson=integrationRecordReference($qInstance,10,'person','P-2');$qPlacement=integrationRecordReference($qInstance,11,'placement','PL-2');
$qAssignment=['id'=>quantaCoreRemoteId($qWorkspace,$qPlacement),'tenant_id'=>$qWorkspace,'person'=>$qPerson,'placement'=>$qPlacement,
    'person_link_id'=>quantaCoreRemoteId($qWorkspace,$qPerson),'worker_id'=>'worker-2','version'=>1,'status'=>'draft'];
$qRemote=null;$qReceipts=[];$qWrites=0;$qMode='ok';$qDuringRead=null;
$GLOBALS['__quanta_transport']=static function($method,$url,$headers)use(&$qRemote,&$qReceipts,&$qAssignment,$qContext,$qWorkspace,&$qDuringRead){
    $path=parse_url($url,PHP_URL_PATH);
    if(str_ends_with($path,'/onboarding-document-context'))$out=['workspace_id'=>$qWorkspace,'contract_version'=>QUANTA_ONBOARDING_DOCUMENT_VERSION,
        'document_transfer'=>true,'approval_authority'=>false,'max_bytes'=>QUANTA_ONBOARDING_DOCUMENT_MAX_BYTES];
    elseif(str_ends_with($path,'/onboarding-context'))$out=['workspace_id'=>$qWorkspace,'contract_version'=>QUANTA_ONBOARDING_CONTRACT_VERSION,
        'capabilities'=>['conditional_publication','worker_attestation_receipts','durable_delivery_receipts'],'approval_authority'=>false,'document_transfer'=>false];
    elseif(str_ends_with($path,'/context'))$out=$qContext;
    elseif(str_contains($path,'/assignments/'))$out=$qAssignment;
    elseif(str_contains($path,'/onboarding-deliveries/'))$out=$qReceipts[basename($path)]??null;
    elseif(str_contains($path,'/onboarding/')){$out=$qRemote;if($qDuringRead){$f=$qDuringRead;$qDuringRead=null;$f();}}
    else throw new RuntimeException('Unexpected fixture GET');
    return ['status'=>$out===null?404:200,'body'=>json_encode($out)];
};
$GLOBALS['__quanta_core_write_transport']=static function($method,$url,$headers,$json)use(&$qRemote,&$qReceipts,&$qWrites,&$qMode,$qWorkspace,$qAssignment,$pdo,$check){
    $qWrites++;$check(!$pdo->inTransaction(),'onboarding provider write follows a committed claim');
    $key=substr(array_values(array_filter($headers,static fn($h)=>str_starts_with($h,'Idempotency-Key: ')))[0],17);
    $body=json_decode($json,true);$publication=$body;unset($publication['expected_version']);$receiptId='cfd_'.hash('sha256',$key);
    $code=$qRemote?200:201;
    $qRemote=['id'=>quantaOnboardingRemoteId($qWorkspace,$body),'tenant_id'=>$qWorkspace,'publication'=>$publication,
        'projection_hash'=>onboardingHash($publication),'version'=>($body['expected_version']??0)+1,'worker_id'=>'worker-2',
        'assignment_id'=>$qAssignment['id'],'person_link_id'=>$qAssignment['person_link_id'],'submissions'=>$qRemote['submissions']??[],
        'last_delivery_id'=>$receiptId,'delivery_id'=>$receiptId,'assignment_current'=>true];
    $qReceipts[$key]=['id'=>$receiptId,'state'=>'complete','request_hash'=>quantaOnboardingRequestHash($body),'status_code'=>$code,'response'=>$qRemote];
    if($qMode==='lost_ack')throw new RuntimeException('Synthetic lost acknowledgement');
    return ['status'=>$code,'body'=>json_encode($qRemote)];
};
$qPreview=static fn($state='published')=>quantaOnboardingPreview($qScope,$qId,$state,$qHead);
$qSend=static fn($p)=>quantaOnboardingSend($qScope,$qId,(int)$p['delivery']['id'],$p['delivery']['review_hash'],$qHead);
$qRecover=static fn($p)=>quantaOnboardingCheckReceipt($qScope,$qId,(int)$p['delivery']['id'],$p['delivery']['review_hash'],$qHead);
putenv('COREFLUX_QUANTA_ONBOARDING_DELIVERY');$p=$qPreview();
$reject(fn()=>$qSend($p),'onboarding publication defaults off');$check($qWrites===0,'disabled publication makes no provider write');
putenv('COREFLUX_QUANTA_ONBOARDING_DELIVERY=1');$sent=$qSend($p);$qDelivery=(int)$sent['id'];
$check($sent['state']==='delivered'&&$sent['remote_receipt_id']!==null,'publication stores a confirmed receipt');
$check($qSend($p)['state']==='delivered'&&$qWrites===1,'replayed publication does not resend');
$check($qPreview()['status']==='unchanged','identical publication is unchanged');
$check($qView()['readiness']==='incomplete','publication cannot approve an employer requirement');
$makeReceipt=static function()use(&$qRemote,$qTask){$request=onboardingUuid();$p=$qRemote['publication'];$task=array_values(array_filter($p['tasks'],static fn($t)=>$t['id']===$qTask))[0];return [
    'id'=>'cfs_'.onboardingHash([$qRemote['id'],'worker-2',$request]),'request_id'=>$request,'request_hash'=>str_repeat('a',64),
    'worker_id'=>'worker-2','identity_id'=>'identity-2','case_id'=>$qRemote['id'],'core_case_id'=>$p['core_case_id'],'core_version'=>$p['core_version'],
    'source_revision'=>$p['source_revision'],'projection_hash'=>$qRemote['projection_hash'],'task_id'=>$qTask,'task_version'=>$task['version'],
    'kind'=>'attestation','acknowledged'=>true,'submitted_at'=>'2026-10-22T18:00:00+00:00','status'=>'awaiting_core_review'];};
$r=$makeReceipt();$qRemote['submissions'][]=$r;$qRemote['version']++;
$status=quantaOnboardingStatus($qScope,$qId,$qHead);
$check($status['receipts'][0]['delivery_id']===$qDelivery&&!$status['receipts'][0]['imported'],'intake identifies the original confirmed publication');
$reject(fn()=>quantaOnboardingImport($qScope,$qId,$r['id'],$qDelivery,$qView()['version']-1,$qHead),'stale case review prevents evidence import');
$version=$qView()['version'];$imported=quantaOnboardingImport($qScope,$qId,$r['id'],$qDelivery,$version,$qHead);
$task=$qView()['tasks'][0];
$check($task['state']==='submitted'&&$task['evidence']['source']==='quanta_worker'&&$task['evidence']['provider_identity_id']==='identity-2','worker actor is retained and evidence awaits review');
$check($qView()['readiness']==='incomplete'&&$qView()['payroll_authorization']==='not_evaluated','worker evidence grants neither employer approval nor payroll authority');
$check(quantaOnboardingImport($qScope,$qId,$r['id'],$qDelivery,$version,$qHead)['replayed'],'response-loss import returns the same committed receipt');
$qCommand('review',['task_id'=>$qTask,'decision'=>'approve','reason'=>'Employer checked the worker attestation']);
$check($qView()['readiness']==='complete','separate staff review completes current requirements');
$check(quantaOnboardingImport($qScope,$qId,$r['id'],$qDelivery,$version,$qHead)['replayed']&&$qView()['readiness']==='complete','receipt replay after approval cannot reset the review');
$qCommand('reopen',['task_id'=>$qTask,'reason'=>'New orientation required']);
$old=quantaOnboardingSnapshot($qScope,$qId,$qHead);$spoof=$r;$spoof['identity_id']='other-identity';
$reject(fn()=>quantaOnboardingImportVerified($pdo,$qScope,$old,$spoof,$qDelivery,$qView()['version'],$qHead),'same receipt identity with changed actor conflicts');
$p=$qPreview();$qMode='lost_ack';$reject(fn()=>$qSend($p),'lost publication acknowledgement is surfaced');$writes=$qWrites;
$check(integrationDeliveryGet($pdo,11,(int)$p['delivery']['id'])['state']==='uncertain','lost acknowledgement holds the attempt');
$reject(fn()=>$qSend($p),'uncertain publication cannot be resent');
$check($qRecover($p)['delivery']['state']==='delivered'&&$qWrites===$writes,'provider receipt recovers a publication without another write');$qMode='ok';
$qDelivery=(int)$p['delivery']['id'];$r2=$makeReceipt();$qRemote['submissions'][]=$r2;$qRemote['version']++;
$before=$qView()['version'];$pdo->exec("CREATE TRIGGER fixture_reject_portal_event BEFORE INSERT ON staffing_onboarding_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic portal event failure'");
try{quantaOnboardingImport($qScope,$qId,$r2['id'],$qDelivery,$before,$qHead);throw new LogicException('audit failure ignored');}catch(PDOException $e){$check(true,'portal import audit failure is surfaced');}
$pdo->exec('DROP TRIGGER fixture_reject_portal_event');$check($qView()['version']===$before,'failed evidence event rolls back the case change');
$qDuringRead=static function()use($pdo){$pdo->exec("UPDATE quanta_worker_links SET worker_id='other-worker' WHERE tenant_id=11");};
$reject(fn()=>quantaOnboardingImport($qScope,$qId,$r2['id'],$qDelivery,$before,$qHead),'worker mapping change during provider read prevents import');
$pdo->exec("UPDATE quanta_worker_links SET worker_id='worker-2' WHERE tenant_id=11");
$qDuringRead=static function()use($pdo){$pdo->exec("UPDATE tenant_module_scope SET scope_mode='shared' WHERE tenant_id=11 AND module_key='placements'");};
$reject(fn()=>quantaOnboardingImport($qScope,$qId,$r2['id'],$qDelivery,$before,$qHead),'directory change during provider read prevents import');
$pdo->exec("UPDATE tenant_module_scope SET scope_mode='isolated' WHERE tenant_id=11 AND module_key='placements'");
$qDuringRead=static function()use($pdo){$pdo->exec('UPDATE quanta_connections SET binding_revision=binding_revision+1 WHERE tenant_id=11');};
$reject(fn()=>quantaOnboardingImport($qScope,$qId,$r2['id'],$qDelivery,$before,$qHead),'connection rotation during provider read prevents import');
$pdo->exec('UPDATE quanta_connections SET binding_revision=1 WHERE tenant_id=11');
$withdraw=$qPreview('withdrawn');$qSend($withdraw);
$reject(fn()=>quantaOnboardingImport($qScope,$qId,$r2['id'],$qDelivery,$before,$qHead),'withdrawal prevents new evidence import');
$check(count($qRemote['submissions'])===2,'withdrawal retains submission history');
$reject(fn()=>quantaOnboardingStatus(array_replace($qScope,['tenant_id'=>12]),$qId,$qHead),'another operating workspace cannot read the case exchange');
require __DIR__.'/quanta_onboarding_document_checks.php';
unset($GLOBALS['__quanta_transport'],$GLOBALS['__quanta_core_write_transport']);
putenv('COREFLUX_QUANTA_ONBOARDING_DELIVERY');putenv('COREFLUX_INTEGRATION_INSTANCE_ID');
echo 'PASS '.($passed-$beforeChecks)." Quanta onboarding delivery/intake checks\n";
