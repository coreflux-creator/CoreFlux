<?php
/** Included only by the explicitly isolated MariaDB delivery fixture. Uses fake provider IO. */
require_once __DIR__.'/../../core/quanta/metadata_delivery.php';
$pdo->exec('CREATE TABLE tenants (id BIGINT PRIMARY KEY,parent_id BIGINT NULL,tenant_type VARCHAR(20),name VARCHAR(80),is_active INT)');
$pdo->exec("INSERT INTO tenants VALUES (10,NULL,'master','Synthetic directory',1),(11,10,'sub','Synthetic pilot',1),(12,10,'sub','Other synthetic pilot',1)");
$pdo->exec('CREATE TABLE tenant_module_scope (tenant_id BIGINT,module_key VARCHAR(40),scope_mode VARCHAR(20))');
$pdo->exec("INSERT INTO tenant_module_scope VALUES(11,'companies','isolated')");
$pdo->exec('CREATE TABLE people (id BIGINT,tenant_id BIGINT,first_name VARCHAR(100),last_name VARCHAR(100),email_primary VARCHAR(255),status VARCHAR(20),deleted_at DATETIME NULL)');
$pdo->exec("INSERT INTO people VALUES (101,10,'Synthetic','Worker','worker@example.test','active',NULL),(102,12,'Other','Worker','other@example.test','active',NULL)");
$pdo->exec('CREATE TABLE placements (id BIGINT,tenant_id BIGINT,person_id BIGINT,title VARCHAR(200),engagement_type VARCHAR(30),status VARCHAR(30),start_date DATE,end_date DATE NULL,actual_end_date DATE NULL,client_id BIGINT,end_client_company_id BIGINT,deleted_at DATETIME NULL)');
$pdo->exec("INSERT INTO placements VALUES (201,10,101,'Synthetic assignment','c2c','active','2026-10-01',NULL,NULL,301,401,NULL)");
$pdo->exec('ALTER TABLE placements ADD worksite_city VARCHAR(255),ADD worksite_state VARCHAR(100),ADD worksite_country CHAR(2)');
$pdo->exec("UPDATE placements SET worksite_city='Hamilton',worksite_state='NJ',worksite_country='US'");
$pdo->exec('CREATE TABLE placement_corp_details (placement_id BIGINT,tenant_id BIGINT,ap_vendor_id BIGINT)');
$pdo->exec('INSERT INTO placement_corp_details VALUES (201,10,501)');
$pdo->exec('CREATE TABLE staffing_clients (id BIGINT,tenant_id BIGINT)');$pdo->exec('INSERT INTO staffing_clients VALUES (301,10)');
$pdo->exec('CREATE TABLE companies (id BIGINT,tenant_id BIGINT,deleted_at DATETIME NULL)');$pdo->exec('INSERT INTO companies VALUES (401,11,NULL)');
$pdo->exec('CREATE TABLE ap_vendors_index (id BIGINT,tenant_id BIGINT)');$pdo->exec('INSERT INTO ap_vendors_index VALUES (501,11)');
$pdo->exec('CREATE TABLE core_record_numbers (record_id BIGINT,tenant_id BIGINT,entity_type VARCHAR(20),record_number VARCHAR(64))');
$pdo->exec("INSERT INTO core_record_numbers VALUES (101,10,'person','P-9001'),(102,12,'person','P-9001'),(201,10,'placement','PL-9002'),(301,10,'client','CL-9003'),(401,11,'company','CO-9004'),(501,11,'vendor','V-9005')");
$pdo->exec('CREATE TABLE quanta_worker_links (id BIGINT,tenant_id BIGINT,worker_id VARCHAR(128),person_id BIGINT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec("INSERT INTO quanta_worker_links(id,tenant_id,worker_id,person_id) VALUES (1,11,'worker-1',101)");
$pdo->exec('CREATE TABLE audit_log (id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT,actor_user_id BIGINT,event VARCHAR(100),meta_json TEXT)');
$fixtureKey='synthetic-metadata-key'; $workspace='workspace-pilot';
$context['workspace']=['id'=>$workspace,'name'=>'Synthetic pilot'];
$context['assignment_location_contract_version']=QUANTA_ASSIGNMENT_LOCATION_VERSION;
$context['metadata_receipt_contract_version']=QUANTA_METADATA_RECEIPT_VERSION;
$pdo->prepare('INSERT INTO quanta_connections(id,tenant_id,status,api_key_ct,binding_revision) VALUES(11,11,"active",?,1)')->execute([encryptField($fixtureKey)]);
$bindingHash=quantaWorkspaceBindingHash(11,11,$fixtureKey,$instance,$context);
quantaWorkspaceBind($pdo,11,7,['id'=>11,'binding_revision'=>1],$fixtureKey,$instance,$context,$bindingHash);
putenv('COREFLUX_INTEGRATION_INSTANCE_ID='.$instance);
putenv('COREFLUX_QUANTA_METADATA_DELIVERY');
$worker=['id'=>'worker-1','full_name'=>'Synthetic Worker','email'=>'worker@example.test','active'=>true,'external_id'=>'purepay-employee-88','employee_id'=>'provider-worker-99','roles'=>['worker']];
$providerRows=[];$providerReceipts=[];$writes=[];$mode='ok';
$GLOBALS['__quanta_transport']=static function($method,$url,$headers)use(&$providerRows,&$providerReceipts,&$worker,&$context){
    $path=parse_url($url,PHP_URL_PATH);
    if($path==='/api/v1/integrations/coreflux/context')$result=$context;
    elseif($path==='/api/v1/workers')$result=['items'=>[$worker],'has_more'=>false];
    elseif($path==='/api/v1/worksites')$result=['items'=>[['id'=>'site-1','name'=>'Synthetic site','active'=>true]],'has_more'=>false];
    elseif(str_contains($path,'/metadata-deliveries/')) { $id=basename($path); if(!isset($providerReceipts[$id]))return ['status'=>404,'body'=>'{}']; $result=$providerReceipts[$id]; }
    else { $id=basename($path); if(!isset($providerRows[$id]))return ['status'=>404,'body'=>'{}']; $result=$providerRows[$id]; }
    return ['status'=>200,'body'=>json_encode($result)];
};
$GLOBALS['__quanta_core_write_transport']=static function($method,$url,$headers,$json)use(&$providerRows,&$providerReceipts,&$writes,&$mode,$pdo,$workspace,$check){
    $check(!$pdo->inTransaction(),'metadata claim commits before provider IO');
    $body=json_decode($json,true);$writes[]=[$method,$url,$headers,$body];
    $requestHash=quantaMetadataRequestHash($body);
    $idempotency=substr(array_values(array_filter($headers,static fn($h)=>str_starts_with($h,'Idempotency-Key: ')))[0],17);
    $stmt=$pdo->prepare('SELECT state FROM integration_deliveries WHERE idempotency_key=?');$stmt->execute([$idempotency]);
    $check($stmt->fetchColumn()==='sending','provider receives only a durably claimed delivery');
    if($mode==='timeout')throw new RuntimeException('Synthetic connection dropped');
    $receipt='cfd_'.hash('sha256',$idempotency);
    if(str_ends_with($url,'/person-links')){
        $id=quantaCoreRemoteId($workspace,$body['person']);$row=$body+['id'=>$id,'tenant_id'=>$workspace];$status=201;
    }elseif($method==='POST'){
        $id=quantaCoreRemoteId($workspace,$body['placement']);
        $row=$body+['id'=>$id,'tenant_id'=>$workspace,'version'=>1,'worker_id'=>'worker-1','person_link_id'=>quantaCoreRemoteId($workspace,$body['person'])];$status=201;
    }else{
        $id=basename($url);$row=$providerRows[$id];
        if($row['version']!==$body['expected_version'])return ['status'=>409,'body'=>'{}'];
        unset($body['expected_version']);$row=array_replace($row,$body,['version'=>$row['version']+1]);$status=200;
    }
    $row['delivery_id']=$receipt;$providerRows[$id]=$row;
    $providerReceipts[$idempotency]=['id'=>$receipt,'request_hash'=>$requestHash,'request_path'=>parse_url($url,PHP_URL_PATH),
        'state'=>'complete','status_code'=>$status,'response'=>$row];
    if($mode==='timeout_after')throw new RuntimeException('Lost successful provider reply');
    return ['status'=>$status,'body'=>json_encode($row)];
};
$source=quantaMetadataSource($pdo,$instance,['people'=>10,'placements'=>10,'companies'=>11,'ap'=>11],'placement',201);
$check($source['reference']['number']==='PL-9002' && $source['person']['number']==='P-9001','source uses registry numbers rather than database primary keys');
$check($source['assignment']['work_location']===['city'=>'Hamilton','state'=>'NJ','country'=>'US'],'source retains the assignment city, state and country independently of the Quanta worksite');
$check($source['assignment']['vendor']['directory_tenant_id']===11 && $source['assignment']['client']['directory_tenant_id']===10,'shared staffing and isolated vendor directories stay distinct');
$check($source['assignment']['end_client']['directory_tenant_id']===11&&$source['person']['directory_tenant_id']===10,
    'end-client company uses its separate Companies directory while the worker uses People');
$check($fails(fn()=>quantaMetadataSource($pdo,$instance,['people'=>10,'placements'=>10,'companies'=>10,'ap'=>11],'placement',201)),
    'company identity cannot fall back to the People directory');
$check($fails(fn()=>quantaMetadataSource($pdo,$instance,['people'=>10,'placements'=>10,'ap'=>11],'placement',201)),
    'missing Companies owner cannot publish an inferred end-client identity');
$pdo->exec("UPDATE companies SET deleted_at='2026-01-01' WHERE id=401");
$check($fails(fn()=>quantaMetadataSource($pdo,$instance,['people'=>10,'placements'=>10,'companies'=>11,'ap'=>11],'placement',201)),
    'deleted end-client company cannot enter a new assignment publication');
$pdo->exec('UPDATE companies SET deleted_at=NULL WHERE id=401');
require_once __DIR__.'/../../core/integrations/staffing_exchange_fields.php';
foreach (staffingExchangeFieldMappings() as $field => $providers) {
    foreach ($providers['quanta'] ?? [] as $mapping) {
        if ($mapping['route'] !== '/api/v1/integrations/coreflux/assignments') continue;
        $value=$source['assignment'];$found=true;
        foreach(explode('.',$mapping['path']) as $part){
            if(!is_array($value)||!array_key_exists($part,$value)){$found=false;break;}
            $value=$value[$part];
        }
        $check($found, 'dictionary assignment path exists in the actual metadata payload: '.$field);
        if (preg_match('/typed_reference:(\w+)/', $mapping['transform'], $kind)) {
            $check($value['entity_type'] === $kind[1], 'dictionary relationship kind agrees with the actual sender: '.$field);
        }
    }
}
$check($fails(fn()=>quantaMetadataSource($pdo,$instance,['people'=>10,'placements'=>10,'companies'=>11,'ap'=>12],'placement',201)),'foreign vendor directory cannot be guessed from a numeric ID');
$check($fails(fn()=>quantaMetadataSource($pdo,$instance,['people'=>10,'placements'=>10,'companies'=>11,'ap'=>11],'person',102)),'foreign person with the same display number cannot be published');
$check($fails(fn()=>quantaMetadataPreview(11,7,'placement',201,'site-1')),'assignment requires a confirmed shared person link');
$p=quantaMetadataPreview(11,7,'person',101,null);$pid=(int)$p['delivery']['id'];
$check($p['status']==='preview' && count($writes)===0,'identity preview makes no provider writes');
$check($fails(fn()=>quantaMetadataSend(11,7,$pid,$p['delivery']['review_hash'])) && count($writes)===0,'environment gate blocks delivery before IO');
putenv('COREFLUX_QUANTA_METADATA_DELIVERY=1');
$check($fails(fn()=>quantaMetadataSend(12,7,$pid,$p['delivery']['review_hash'])) && count($writes)===0,'foreign workspace cannot send another workspace delivery');
$check($fails(fn()=>quantaMetadataSend(11,7,$pid,str_repeat('0',64))) && count($writes)===0,'wrong review hash cannot send');
$sent=quantaMetadataSend(11,7,$pid,$p['delivery']['review_hash']);
$check($sent['state']==='delivered' && count($writes)===1,'reviewed person identity delivered once');
$check($sent['remote_receipt_id']===$providerRows[$sent['remote_id']]['delivery_id'],'provider receipt is stored atomically with confirmed delivery');
$check(quantaMetadataSend(11,7,$pid,$p['delivery']['review_hash'])['state']==='delivered' && count($writes)===1,'successful repeat returns existing delivery without resending');
$check(quantaMetadataPreview(11,7,'person',101,null)['status']==='unchanged','provider readback confirms shared person identity');
$check($worker['external_id']==='purepay-employee-88' && $worker['employee_id']==='provider-worker-99' && $worker['roles']===['worker'],'identity exchange preserves PurePayroll identifiers and portal access');
$p=quantaMetadataPreview(11,7,'placement',201,'site-1');$aid=(int)$p['delivery']['id'];
$check($p['proposed']['client']['number']==='CL-9003' && $p['proposed']['vendor']['number']==='V-9005' && $p['proposed']['end_client']['number']==='CO-9004','assignment preview aligns all commercial-party identifiers');
$pdo->exec("UPDATE tenant_module_scope SET scope_mode='shared' WHERE tenant_id=11 AND module_key='companies'");
$check($fails(fn()=>quantaMetadataSend(11,7,$aid,$p['delivery']['review_hash']))&&count($writes)===1,
    'company-scope change after preview blocks publication before provider IO');
$pdo->exec("UPDATE tenant_module_scope SET scope_mode='isolated' WHERE tenant_id=11 AND module_key='companies'");
$pdo->exec("UPDATE placements SET title='Changed after review' WHERE id=201");
$check($fails(fn()=>quantaMetadataSend(11,7,$aid,$p['delivery']['review_hash'])) && count($writes)===1,'Core source edits invalidate review before IO');
$p=quantaMetadataPreview(11,7,'placement',201,'site-1');$aid=(int)$p['delivery']['id'];
$sent=quantaMetadataSend(11,7,$aid,$p['delivery']['review_hash']);$remoteId=$sent['remote_id'];
$check($sent['state']==='delivered' && count($writes)===2,'assignment create follows person link without executing financial actions');
$originalCompanyReceipt=integrationDeliveryGet($pdo,11,$aid);
$pdo->exec("UPDATE tenant_module_scope SET scope_mode='shared' WHERE tenant_id=11 AND module_key='companies'");
$check($fails(fn()=>quantaMetadataReceipt(11,7,$aid,$p['delivery']['review_hash']))&&count($writes)===2,
    'original company receipt cannot silently rebind to a different current company directory');
$pdo->exec("UPDATE tenant_module_scope SET scope_mode='isolated' WHERE tenant_id=11 AND module_key='companies'");
$check(quantaMetadataReceipt(11,7,$aid,$p['delivery']['review_hash'])['delivery']['state']==='delivered'
    &&integrationDeliveryGet($pdo,11,$aid)===$originalCompanyReceipt&&count($writes)===2,
    'original receipt reads preserve company identity, delivery bytes and provider write count');
$check(quantaMetadataPreview(11,7,'placement',201,'site-1')['status']==='unchanged','assignment readback agrees with Core');
$pdo->exec("UPDATE placements SET title='Second reviewed title' WHERE id=201");
$p=quantaMetadataPreview(11,7,'placement',201,'site-1');$aid=(int)$p['delivery']['id'];
$providerRows[$remoteId]['version']=2;
$check($fails(fn()=>quantaMetadataSend(11,7,$aid,$p['delivery']['review_hash'])) && count($writes)===2,'remote edit invalidates the reviewed assignment version');
$p=quantaMetadataPreview(11,7,'placement',201,'site-1');$aid=(int)$p['delivery']['id'];
quantaMetadataSend(11,7,$aid,$p['delivery']['review_hash']);
$check($providerRows[$remoteId]['version']===3 && count($writes)===3,'fresh conditional update increments the provider version once');
$pdo->exec("UPDATE placements SET status='ended',actual_end_date='2026-10-20' WHERE id=201");
$p=quantaMetadataPreview(11,7,'placement',201,'site-1');$aid=(int)$p['delivery']['id'];
$mode='timeout_after';
$check($fails(fn()=>quantaMetadataSend(11,7,$aid,$p['delivery']['review_hash'])),'interrupted assignment attempt is reported');
$check(integrationDeliveryGet($pdo,11,$aid)['state']==='uncertain','interrupted attempt requires reconciliation');
$count=count($writes);$mode='ok';
$check($fails(fn()=>quantaMetadataSend(11,7,$aid,$p['delivery']['review_hash'])) && count($writes)===$count,'timeout cannot be retried by clicking send again');
$pdo->exec("UPDATE placements SET title='Changed after timeout' WHERE id=201");
$next=quantaMetadataPreview(11,7,'placement',201,'site-1');
$check($fails(fn()=>quantaMetadataSend(11,7,(int)$next['delivery']['id'],$next['delivery']['review_hash'])) && count($writes)===$count,'a new preview cannot bypass an uncertain prior attempt');
$check((int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE tenant_id=11 AND event='quanta.metadata.delivered'")->fetchColumn()===3,'confirmed writes retain provider receipt lineage in the audit trail');
$savedRow=integrationDeliveryGet($pdo,11,$aid);$key=$savedRow['idempotency_key'];$savedReceipt=$providerReceipts[$key];
$check($next['receipt_only'] && (int)$next['delivery']['id']===$aid,'a fresh preview preserves the original uncertain envelope');
$check(count(quantaMetadataPending(11)['rows'])===1 && quantaMetadataPending(12)['rows']===[],'local pending recovery is company scoped');
$pdo->exec("UPDATE placements SET deleted_at=NOW() WHERE id=201");
$check(count(quantaMetadataPending(11)['rows'])===1,'deleted source remains recoverable without a provider read');
$context['metadata_receipt_contract_version']='unsupported';
$check(count(quantaMetadataPending(11)['rows'])===1,'local recovery listing remains available when provider capabilities are unavailable');
$check($fails(fn()=>quantaMetadataReceipt(11,7,$aid,$savedRow['review_hash'])),'unsupported provider receipt capability cannot release a held attempt');
$context['metadata_receipt_contract_version']=QUANTA_METADATA_RECEIPT_VERSION;
$pdo->exec('UPDATE quanta_connections SET binding_revision=2 WHERE tenant_id=11');
$check($fails(fn()=>quantaMetadataReceipt(11,7,$aid,$savedRow['review_hash'])),'connection rotation cannot read or resolve a receipt from the old binding');
$pdo->exec('UPDATE quanta_connections SET binding_revision=1 WHERE tenant_id=11');
$check($fails(fn()=>quantaMetadataReceipt(11,7,$aid,str_repeat('0',64))),'wrong review hash cannot recover a different envelope');
unset($providerReceipts[$key]);
$check(quantaMetadataReceipt(11,7,$aid,$savedRow['review_hash'])['receipt_state']==='not_found','missing receipt does not clear the delivery hold');
$providerReceipts[$key]=$savedReceipt;
foreach(['in_progress','uncertain'] as $receiptState) {
    $providerReceipts[$key]['state']=$receiptState;
    $check(quantaMetadataReceipt(11,7,$aid,$savedRow['review_hash'])['receipt_state']==='unconfirmed','unfinished original receipt stays held');
}
$providerReceipts[$key]=$savedReceipt;
foreach(['request_hash'=>str_repeat('f',64),'request_path'=>'/api/v1/integrations/coreflux/onboarding'] as $field=>$value) {
    $providerReceipts[$key][$field]=$value;
    $check($fails(fn()=>quantaMetadataReceipt(11,7,$aid,$savedRow['review_hash'])),'unrelated receipt cannot resolve original attempt');
    $providerReceipts[$key]=$savedReceipt;
}
$providerReceipts[$key]['response']['tenant_id']='foreign-workspace';
$check($fails(fn()=>quantaMetadataReceipt(11,7,$aid,$savedRow['review_hash'])),'foreign response cannot confirm a delivery');
$providerReceipts[$key]=$savedReceipt;
$check($fails(fn()=>quantaMetadataReceipt(12,7,$aid,$savedRow['review_hash'])),'foreign Core tenant cannot check another receipt');
$providerRows[$remoteId]['title']='Later provider edit';$providerRows[$remoteId]['version']++;
$recovered=quantaMetadataReceipt(11,7,$aid,$savedRow['review_hash']);
$check($recovered['delivery']['state']==='delivered' && $recovered['receipt_state']==='confirmed','lost reply resolves from original receipt after source deletion and later provider edits');
$check(count($writes)===$count && $providerRows[$remoteId]['title']==='Later provider edit','recovery does not resend or overwrite the current provider record');
$check(quantaMetadataReceipt(11,7,$aid,$savedRow['review_hash'])['delivery']===$recovered['delivery'],'recovery replay retains the original delivery');
$check((int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE event='quanta.metadata.receipt_reconciled'")->fetchColumn()===1,'receipt recovery has one audit event');
$check(quantaMetadataPending(11)['rows']===[],'confirmed receipt leaves the pending queue');
$pdo->exec('UPDATE placements SET deleted_at=NULL WHERE id=201 AND tenant_id=10');
foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../../core/migrations/162_integration_delivery_receipt.sql'))as$sql)$pdo->exec($sql);
$check(integrationDeliveryGet($pdo,11,$pid)['remote_receipt_id']===$providerRows[quantaCoreRemoteId($workspace,$source['person'])]['delivery_id'],'receipt migration replay preserves confirmed lineage');
require_once __DIR__.'/../../core/integrations/staffing_field_contract.php';
$pdo->exec('CREATE TABLE custom_fields(id BIGINT,tenant_id BIGINT,module VARCHAR(32),field_name VARCHAR(80),label VARCHAR(80),type VARCHAR(32),is_active INT,visible_to_roles_json JSON)');
$pdo->exec("INSERT INTO custom_fields VALUES(1,11,'companies','company_badge','Company badge','text',1,NULL),
    (2,10,'companies','wrong_directory','Other directory','text',1,NULL),
    (3,11,'companies','restricted','Restricted','text',1,'[\"tenant_admin\"]'),
    (4,10,'placements','site_badge','Site badge','text',1,NULL)");
$pdo->exec('CREATE TABLE people_custom_field_defs(id BIGINT,tenant_id BIGINT,field_key VARCHAR(80),field_label VARCHAR(80),field_type VARCHAR(32),deleted_at DATETIME NULL)');
$pdo->exec("INSERT INTO people_custom_field_defs VALUES(1,10,'worker_badge','Worker badge','text',NULL)");
$dictionary=staffingFieldContractWithCustomFields(['tenant_id'=>11,'fields'=>[]],['role'=>'employee']);
$mapped=array_column($dictionary['fields'],null,'key');
$check($mapped['companies.custom.company_badge']['owner_tenant_id']===11
    &&$mapped['people.custom.worker_badge']['owner_tenant_id']===10
    &&$mapped['placements.custom.site_badge']['owner_tenant_id']===10,
    'field dictionary follows the same separate company, person and placement owners as delivery');
$check(!isset($mapped['companies.custom.wrong_directory'])&&!isset($mapped['companies.custom.restricted']),
    'company dictionary excludes other-directory and role-restricted definitions');
unset($GLOBALS['__quanta_transport'],$GLOBALS['__quanta_core_write_transport']);
putenv('COREFLUX_QUANTA_METADATA_DELIVERY');putenv('COREFLUX_INTEGRATION_INSTANCE_ID');
