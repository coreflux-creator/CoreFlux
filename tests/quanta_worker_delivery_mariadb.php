<?php
/** Disposable MariaDB plus fake Quanta IO; never reads application DB credentials. */
declare(strict_types=1);
$inCi=getenv('GITHUB_ACTIONS')==='true'&&getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1'&&in_array('--confirm-disposable-fixture',$argv,true)&&(string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if(!$inCi&&!$local)throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DISABLE_DATABASE=1');putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));
require_once __DIR__.'/../core/quanta/worker_delivery.php';
require_once __DIR__.'/../core/migrate.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$inCi?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$database='coreflux_quanta_workers_'.($inCi?'ci':gmdate('YmdHis').'_'.getmypid());
$pdo->exec('CREATE DATABASE '.$database);$pdo->exec('USE '.$database);
$pdo->exec('CREATE TABLE quanta_connections(id BIGINT PRIMARY KEY,tenant_id BIGINT NOT NULL UNIQUE,status VARCHAR(20),api_key_ct VARBINARY(2048)) ENGINE=InnoDB');
foreach(['158_integration_deliveries.sql','162_integration_delivery_receipt.sql','159_quanta_workspace_binding.sql','148_quanta_worker_identity.sql']as$file)
    foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file))as$sql)$pdo->exec($sql);
$pdo->exec('CREATE TABLE tenants(id BIGINT PRIMARY KEY,parent_id BIGINT NULL,tenant_type VARCHAR(20),name VARCHAR(80),is_active INT)');
$pdo->exec("INSERT INTO tenants VALUES(10,NULL,'master','Synthetic directory',1),(11,10,'sub','Synthetic pilot',1),(12,10,'sub','Other pilot',1)");
$pdo->exec('CREATE TABLE tenant_module_scope(tenant_id BIGINT,module_key VARCHAR(40),scope_mode VARCHAR(20))');
$pdo->exec('CREATE TABLE people(id BIGINT PRIMARY KEY,tenant_id BIGINT,first_name VARCHAR(100),last_name VARCHAR(100),email_primary VARCHAR(255),phone_primary VARCHAR(100),status VARCHAR(20),deleted_at DATETIME NULL)');
$pdo->exec('CREATE TABLE core_record_numbers(record_id BIGINT,tenant_id BIGINT,entity_type VARCHAR(20),record_number VARCHAR(64))');
$pdo->exec('CREATE TABLE audit_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT,actor_user_id BIGINT,event VARCHAR(100),meta_json TEXT)');
foreach(range(101,108)as$id){
    $pdo->prepare('INSERT INTO people VALUES(?,10,"Synthetic","Worker",?,NULL,"active",NULL)')->execute([$id,'worker'.$id.'@example.com']);
    $pdo->prepare('INSERT INTO core_record_numbers VALUES(?,10,"person",?)')->execute([$id,'P-'.($id+9000)]);
}
$pdo->exec("INSERT INTO people VALUES(999,12,'Private','Worker','private@example.com',NULL,'active',NULL)");
$instance='381e452a-5d11-4e2e-9daa-e9427501898a';putenv('COREFLUX_INTEGRATION_INSTANCE_ID='.$instance);
$key='synthetic-worker-key';$workspace='workspace-a';
$context=['workspace'=>['id'=>$workspace,'name'=>'Synthetic Quanta'],'contract_version'=>QUANTA_CORE_CONTRACT_VERSION,
    'execution_side_effects'=>false,'worker_profile_contract_version'=>QUANTA_WORKER_PROFILE_VERSION,
    'capabilities'=>['person_links','assignment_metadata','conditional_assignment_updates','durable_delivery_receipts','inactive_worker_preparation','conditional_worker_contact_updates','worker_profile_receipt_lookup']];
$pdo->prepare('INSERT INTO quanta_connections(id,tenant_id,status,api_key_ct) VALUES(11,11,"active",?)')->execute([encryptField($key)]);
quantaWorkspaceBind($pdo,11,7,['id'=>11,'binding_revision'=>1],$key,$instance,$context,quantaWorkspaceBindingHash(11,11,$key,$instance,$context));
$passed=0;$check=static function($ok,$label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
$fails=static function(callable $fn){try{$fn();return false;}catch(Throwable $e){return true;}};
$profiles=[];$receipts=[];$writes=0;$mode='ok';
$GLOBALS['__quanta_transport']=static function($method,$url,$headers)use(&$context,&$profiles,&$receipts){
    $path=parse_url($url,PHP_URL_PATH);
    if(str_ends_with($path,'/context'))$out=$context;
    elseif($path==='/api/v1/workers')$out=['items'=>array_map(static fn($p)=>['id'=>$p['worker_id'],'email'=>$p['worker']['email']],array_values($profiles)),'has_more'=>false];
    elseif(str_contains($path,'/worker-profile-deliveries/')){if(!isset($receipts[basename($path)]))return['status'=>404,'body'=>'{}'];$out=$receipts[basename($path)];}
    else{if(!isset($profiles[basename($path)]))return['status'=>404,'body'=>'{}'];$out=$profiles[basename($path)];}
    return['status'=>200,'body'=>json_encode($out)];
};
$GLOBALS['__quanta_core_write_transport']=static function($method,$url,$headers,$json)use(&$profiles,&$receipts,&$writes,&$mode,$workspace,$pdo,$check){
    $writes++;$check(!$pdo->inTransaction(),'provider IO happens after the claim commits');
    $idem=substr(array_values(array_filter($headers,static fn($h)=>str_starts_with($h,'Idempotency-Key: ')))[0],17);
    $s=$pdo->prepare('SELECT state FROM integration_deliveries WHERE idempotency_key=?');$s->execute([$idem]);$check($s->fetchColumn()==='sending','provider receives a durable claimed attempt');
    $body=json_decode($json,true);$id=quantaCoreRemoteId($workspace,$body['person']);$receiptId='cfd_'.hash('sha256',$idem);
    if($mode==='no_receipt')throw new RuntimeException('Synthetic timeout before acknowledgement');
    if($mode==='reject'){
        $receipts[$idem]=['id'=>$receiptId,'request_hash'=>quantaWorkerProfileRequestHash($body),'state'=>'complete','status_code'=>409,'response'=>['detail'=>['error'=>'existing_worker_requires_review'],'delivery_id'=>$receiptId]];
        return['status'=>409,'body'=>json_encode($receipts[$idem]['response'])];
    }
    $wid=$body['worker_id']??('cfw_'.substr($id,4));
    $row=['id'=>$id,'tenant_id'=>$workspace,'person'=>$body['person'],'person_link_id'=>$id,'worker_id'=>$wid,
        'profile_contract_version'=>QUANTA_WORKER_PROFILE_VERSION,'access_changed'=>false,'invitation_sent'=>false,'delivery_id'=>$receiptId,
        'worker'=>['email'=>$body['email'],'full_name'=>$body['full_name'],'phone'=>$body['phone']??($body['expected_worker']['phone']??null),
            'status'=>$body['expected_worker']['status']??'disabled','updated_at'=>'2026-10-22T01:00:'.str_pad((string)$writes,2,'0',STR_PAD_LEFT).'+00:00']];
    $status=$body['worker_id']===null?201:200;$profiles[$id]=$row;
    $receipts[$idem]=['id'=>$receiptId,'request_hash'=>quantaWorkerProfileRequestHash($body),'state'=>'complete','status_code'=>$status,'response'=>$row];
    if($mode==='link_conflict')$pdo->prepare('INSERT INTO quanta_worker_links(tenant_id,worker_id,person_id) VALUES(11,?,108)')->execute([$wid]);
    if($mode==='scope_drift')$pdo->exec('INSERT INTO tenant_module_scope VALUES(11,"people","isolated")');
    if($mode==='lost_ack')throw new RuntimeException('Synthetic lost acknowledgement after provider completion');
    return['status'=>$status,'body'=>json_encode($row)];
};
$preview=static fn($id)=>quantaWorkerProfilesPreview(11,7,[$id])['rows'][0];
$send=static fn($p)=>quantaWorkerProfilesSend(11,7,(int)$p['delivery']['id'],$p['delivery']['review_hash']);
$status=static fn($p)=>quantaWorkerProfilesStatus(11,7,(int)$p['delivery']['id'],$p['delivery']['review_hash']);
putenv('COREFLUX_QUANTA_WORKER_DELIVERY');$p=$preview(101);
$check($p['reference']['number']==='P-9101' && $p['reference']['directory_tenant_id']===10,'shared people directory and canonical number are used');
$check($fails(fn()=>$send($p))&&$writes===0,'delivery flag defaults off');
$check($fails(fn()=>$preview(999)),'other directory source is rejected');
$check($fails(fn()=>quantaWorkerProfilesHistory(12,999)),'history cannot invent a canonical reference');
$pdo->exec("UPDATE people SET first_name='Changed after review' WHERE id=101");
putenv('COREFLUX_QUANTA_WORKER_DELIVERY=1');
$check($fails(fn()=>$send($p))&&$writes===0,'Core source drift prevents IO');
$p=$preview(101);$sent=$send($p);
$check($sent['state']==='delivered'&&$sent['remote_receipt_id']!==null,'confirmed result records receipt');
$s=$pdo->prepare('SELECT worker_id FROM quanta_worker_links WHERE tenant_id=11 AND person_id=101');$s->execute();
$check($s->fetchColumn()===$sent['remote_id'],'receipt and native worker link complete together');
$check($send($p)['state']==='delivered'&&$writes===1,'repeat send never calls provider again');
$check($preview(101)['status']==='unchanged','confirmed source produces no new contact write');
$profileId=quantaCoreRemoteId($workspace,$p['reference']);$profiles[$profileId]['worker']['status']='active';
$profiles[$profileId]['worker']['phone']='+15555550000';
$pdo->exec("UPDATE people SET first_name='Reviewed edit' WHERE id=101");$edit=$preview(101);
$check($edit['operation']==='worker.profile.update' && $edit['proposed']['phone']===null,'existing profile update preserves omitted phone');
$profiles[$profileId]['worker']['phone']='+15555559999';$before=$writes;
$check($fails(fn()=>$send($edit))&&$writes===$before,'remote contact drift prevents IO');
$edit=$preview(101);$updated=$send($edit);
$check($profiles[$profileId]['worker']['status']==='active'&&$profiles[$profileId]['worker']['phone']==='+15555559999','contact update preserves existing status and phone');

$mode='lost_ack';$p=$preview(102);$before=$writes;
$check($fails(fn()=>$send($p))&&$writes===$before+1,'lost acknowledgement is surfaced');
$id=(int)$p['delivery']['id'];$row=integrationDeliveryGet($pdo,11,$id);
$check($row['state']==='uncertain','lost acknowledgement remains held');
$check($fails(fn()=>$send($p))&&$writes===$before+1,'uncertain send cannot retry');
$check($preview(102)['status']==='blocked','provider-created worker is not recreated while Core link is missing');
$recovered=$status($p);
$check($recovered['delivery']['state']==='delivered'&&$writes===$before+1,'receipt lookup recovers without resending');
$check((int)$pdo->query('SELECT COUNT(*) FROM quanta_worker_links WHERE tenant_id=11 AND person_id=102')->fetchColumn()===1,'recovery creates exactly one local link');
$check($status($p)['delivery']['state']==='delivered'&&$writes===$before+1,'reconciliation repeat stays idempotent');

$mode='no_receipt';$p=$preview(103);$check($fails(fn()=>$send($p)),'unknown outcome held');$before=$writes;
$check($status($p)['receipt_state']==='not_found'&&$writes===$before,'missing receipt never means retry is safe');
$row=integrationDeliveryGet($pdo,11,(int)$p['delivery']['id']);
$receipts[$row['idempotency_key']]=['id'=>'cfd_'.str_repeat('a',64),'state'=>'uncertain','request_hash'=>str_repeat('f',64)];
$check($fails(fn()=>$status($p)),'wrong request hash cannot confirm a delivery');
unset($receipts[$row['idempotency_key']]);
foreach(['in_progress','uncertain'] as $receiptState){
    $receipts[$row['idempotency_key']]=['id'=>'cfd_'.str_repeat('a',64),'state'=>$receiptState,'request_hash'=>quantaWorkerProfileRequestHash(json_decode(decryptField($row['payload_ct']),true)['body'])];
    $check($status($p)['receipt_state']===$receiptState&&$writes===$before,'provider '.$receiptState.' receipt remains held without retry');
}
unset($receipts[$row['idempotency_key']]);
$pdo->exec('INSERT INTO tenant_module_scope VALUES(11,"people","isolated")');
$check($fails(fn()=>$status($p)),'changed directory scope blocks reconciliation');$pdo->exec('DELETE FROM tenant_module_scope WHERE tenant_id=11');

$mode='link_conflict';$p=$preview(104);$check($fails(fn()=>$send($p)),'competing local identity stops completion');$before=$writes;
$check(integrationDeliveryGet($pdo,11,(int)$p['delivery']['id'])['state']==='uncertain','link conflict preserves uncertain receipt state');
$check((int)$pdo->query('SELECT COUNT(*) FROM quanta_worker_links WHERE tenant_id=11 AND person_id=104')->fetchColumn()===0,'failed completion does not leave a partial local link');
$check($fails(fn()=>$status($p))&&$writes===$before,'receipt recovery does not overwrite a competing link');
$pdo->exec('DELETE FROM quanta_worker_links WHERE tenant_id=11 AND person_id=108');
$check($status($p)['delivery']['state']==='delivered'&&$writes===$before,'reviewed conflict removal permits receipt-only recovery');

$mode='reject';$p=$preview(105);$check($fails(fn()=>$send($p)),'ambiguous HTTP conflict is held until its receipt is read');$before=$writes;
$check($status($p)['delivery']['state']==='failed'&&$writes===$before,'durable rejection clears uncertainty without resending');
$check((int)$pdo->query('SELECT COUNT(*) FROM quanta_worker_links WHERE tenant_id=11 AND person_id=105')->fetchColumn()===0,'rejection creates no local identity');
$mode='ok';$p=$preview(106);$before=$writes;
$pdo->exec('UPDATE quanta_connections SET binding_revision=binding_revision+1 WHERE tenant_id=11');
$check($fails(fn()=>$send($p))&&$writes===$before,'connection revision drift blocks sending');
$pdo->exec('UPDATE quanta_connections SET binding_revision=binding_revision-1 WHERE tenant_id=11');
$mode='scope_drift';$race=$preview(108);$before=$writes;
$check($fails(fn()=>$send($race))&&$writes===$before+1,'directory changes during provider IO prevent local completion');
$check((int)$pdo->query('SELECT COUNT(*) FROM quanta_worker_links WHERE tenant_id=11 AND person_id=108')->fetchColumn()===0,'scope drift leaves no local identity link');
$check(integrationDeliveryGet($pdo,11,(int)$race['delivery']['id'])['state']==='uncertain','scope drift retains the attempted delivery for reconciliation');
$pdo->exec('DELETE FROM tenant_module_scope WHERE tenant_id=11');
$check($status($race)['delivery']['state']==='delivered'&&$writes===$before+1,'restored directory permits original receipt recovery without a second write');
$mode='ok';
$history=quantaWorkerProfilesHistory(11,101);
$check(count($history['rows'])>=2 && !str_contains(json_encode($history),'payload_ct') && !str_contains(json_encode($history),'idempotency_key'),'history exposes only safe delivery summaries');
$check($fails(fn()=>quantaWorkerProfilesStatus(12,7,(int)$p['delivery']['id'],$p['delivery']['review_hash'])),'another workspace cannot inspect a delivery');
$legacy=integrationDeliveryPreview($pdo,11,7,['provider'=>'quanta','connection_ref'=>'11','binding_hash'=>str_repeat('a',64),'entity_type'=>'person','entity_tenant_id'=>10,'entity_id'=>107,'operation'=>'worker.create','source_hash'=>str_repeat('b',64),'remote_hash'=>str_repeat('c',64)],['fields'=>['email'=>'legacy@example.com']]);
$check($fails(fn()=>quantaWorkerProfilesSend(11,7,(int)$legacy['id'],$legacy['review_hash'])),'legacy generic-worker previews cannot enter the new sender');
require_once __DIR__.'/../core/integrations/staffing_exchange_fields.php';
foreach(staffingExchangeFieldMappings()as$field=>$providers)foreach($providers['quanta']??[]as$mapping)if($mapping['route']==='/api/v1/integrations/coreflux/worker-profiles')
    $check(array_key_exists($mapping['path'],$p['proposed']),'field dictionary matches actual worker body: '.$field);
putenv('COREFLUX_QUANTA_WORKER_DELIVERY');putenv('COREFLUX_INTEGRATION_INSTANCE_ID');
echo "Quanta worker delivery MariaDB: $passed passed\n";
