<?php
/** Native SQL claims, directory fences and recovery; HTTP provider is synthetic. */
declare(strict_types=1);
define('COREFLUX_QLEGACY_FIXTURE',true);
require __DIR__.'/quanta_time_provenance_mariadb.php';
require_once __DIR__.'/../core/quanta/legacy_time_delivery.php';
putenv('COREFLUX_INTEGRATION_INSTANCE_ID='.$instance);setRequestTenantId(1);
foreach(['158_integration_deliveries.sql','162_integration_delivery_receipt.sql'] as $file)$migrate($file);
$pdo->exec('ALTER TABLE core_record_numbers ADD canonical_record_id BIGINT');$pdo->exec('UPDATE core_record_numbers SET canonical_record_id=record_id');
$pdo->exec('ALTER TABLE time_entries ADD approved_by_user_id BIGINT NULL, ADD approved_at DATETIME NULL, ADD updated_at DATETIME NULL');
$pdo->exec('UPDATE time_entries SET status="pending_review",approved_at=NULL,approved_by_user_id=NULL,updated_at="2026-10-24 00:00:00" WHERE id IN(1,2)');
$pdo->exec('CREATE TABLE audit_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT,actor_user_id BIGINT,event VARCHAR(128),meta_json LONGTEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$quantaContext=$context;$remote=$validRemote;$association=null;$receipts=[];$posts=0;$recoveries=0;$lost=false;$enabled=true;$race=false;$passed=0;
$person=integrationRecordReference($instance,1,'person','P-19');$placement=integrationRecordReference($instance,1,'placement','PL-42');
$identity=['id'=>quantaCoreRemoteId('workspace-a',$placement),'tenant_id'=>'workspace-a','person'=>$person,'placement'=>$placement,
    'worker_id'=>'worker-1','person_link_id'=>quantaCoreRemoteId('workspace-a',$person),'engagement_type'=>'w2','start_date'=>'2026-10-01','client'=>null,'vendor'=>null,'end_client'=>null];
$assignment=$identity+['version'=>1];$originalEntry=quantaNormalizeEntry($remote,[],'approved');
$GLOBALS['__quanta_transport']=static fn()=>['status'=>200,'body'=>json_encode($quantaContext)];
$access=quantaWorkspaceForDelivery(1);$local=quantaLegacyTimeLocal(1,$access,$originalEntry);
$reviewBody=quantaTimeReviewBody(['contract_version'=>QUANTA_TIME_REVIEW_VERSION,'assignment_id'=>$identity['id'],'assignment_version'=>1,
    'source_entry_id'=>'entry-1','source_revision'=>integrationDeliveryHash($remote),'core_tenant_id'=>1,'core_revision'=>$local['proof']['core_revision'],
    'components'=>$local['proof']['components'],'expected_version'=>0]);
$review=quantaTimeReviewExpected('workspace-a',$reviewBody);
$remoteContext=static function()use(&$association,&$enabled,&$remote,&$review,$identity):array {
    $proof=['source_revision'=>integrationDeliveryHash($remote),'review_id'=>$review['id'],'review_version'=>$review['version'],
        'review_publication'=>$review['publication'],'assignment_identity'=>$identity,'assignment_version'=>1,'worksite_id'=>'site-1'];
    $value=['contract_version'=>QUANTA_LEGACY_TIME_VERSION,'workspace_id'=>'workspace-a','assignment_id'=>$identity['id'],'source_entry_id'=>'entry-1',
        'source_entry'=>$remote,'proof'=>$proof,'current'=>$association,'configuration_enabled'=>$enabled,'approval_authority'=>false,'financial_execution'=>false];
    $hashed=$value;unset($hashed['source_entry']);return $value+['context_hash'=>quantaMetadataRequestHash($hashed)];
};
$GLOBALS['__quanta_transport']=static function($method,$url)use($quantaContext,$assignment,&$remote,&$review,&$receipts,&$race,$observer,$remoteContext):array {
    $path=parse_url($url,PHP_URL_PATH);
    if(str_ends_with($path,'/context'))return ['status'=>200,'body'=>json_encode($quantaContext)];
    if(str_ends_with($path,'/time-review-context'))return ['status'=>200,'body'=>json_encode(['contract_version'=>QUANTA_TIME_REVIEW_VERSION,
        'workspace_id'=>'workspace-a','assignment'=>$assignment,'source_entry'=>$remote,'source_revision'=>integrationDeliveryHash($remote),
        'current'=>$review,'approval_authority'=>false,'financial_execution'=>false])];
    if(str_ends_with($path,'/legacy-time-context')){
        $result=$remoteContext();if($race){$observer->exec('UPDATE time_entries SET hours=7 WHERE id=1');$race=false;}
        return ['status'=>200,'body'=>json_encode($result)];
    }
    if(str_contains($path,'/legacy-time-deliveries/')){ $key=basename($path);return isset($receipts[$key])?['status'=>200,'body'=>json_encode($receipts[$key])]:['status'=>404,'body'=>'{}']; }
    throw new RuntimeException('Unexpected provider read');
};
$GLOBALS['__quanta_core_write_transport']=static function($method,$url,$headers,$json)use(&$association,&$posts,&$recoveries,&$receipts,&$lost,$remoteContext):array {
    if(str_ends_with($url,'/reconcile')){
        $recoveries++;$key=basename(dirname($url));$r=$receipts[$key];
        if(isset($r['commit'])){$r['state']='complete';$r['response']=$r['commit'];unset($r['commit']);$receipts[$key]=$r;}
        return ['status'=>200,'body'=>json_encode($r)];
    }
    if($method!=='POST'||!str_ends_with($url,'/legacy-time'))throw new RuntimeException('Unexpected provider write');
    $posts++;$body=json_decode($json,true);$key=null;foreach($headers as $h)if(str_starts_with($h,'Idempotency-Key: '))$key=substr($h,17);
    $rid='cfd_'.hash('sha256',$key);$association=quantaLegacyTimeExpected('workspace-a',$body,$remoteContext()['proof'])+['last_delivery_id'=>$rid,'updated_at'=>'2026-11-11T12:00:00Z'];
    $response=$association+['delivery_id'=>$rid];$receipts[$key]=['id'=>$rid,'request_hash'=>quantaMetadataRequestHash($body),
        'state'=>$lost?'uncertain':'complete','status_code'=>201]+[$lost?'commit':'response'=>$response];
    return ['status'=>$lost?500:201,'body'=>json_encode($response)];
};
$preview=static fn()=>quantaLegacyTimePreview(1,7,'entry-1');
$send=static fn($p)=>quantaLegacyTimeSend(1,7,(int)$p['delivery']['id'],$p['delivery']['review_hash']);
$before=[];foreach(['time_entries','quanta_time_imports','quanta_time_approval_evidence','quanta_time_routes'] as $table)$before[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$p=$preview();$check($p['placement_number']==='PL-42'&&$p['person_number']==='P-19'&&$p['hours']===8.0&&$posts===0,'preview resolves original local identities and all imported hours');
$check($preview()['delivery']['id']===$p['delivery']['id'],'repeated review keeps one encrypted request');
$check($fails(fn()=>$send($p))&&$posts===0,'default-off rollout blocks historical publication');
putenv('COREFLUX_QUANTA_TIME_ACCESS_DELIVERY=1');putenv('COREFLUX_INTEGRATION_DELIVERY_TENANTS=1');
$check($fails(fn()=>quantaLegacyTimeSend(2,7,(int)$p['delivery']['id'],$p['delivery']['review_hash']))&&$posts===0,'foreign company cannot send');
$enabled=false;$check($fails(fn()=>$send($p))&&$posts===0,'provider rollout change invalidates review');$enabled=true;
$race=true;$check($fails(fn()=>$send($p))&&$posts===0,'Core mutation during provider observation blocks delivery');$pdo->exec('UPDATE time_entries SET hours=6.5 WHERE id=1');
$pdo->beginTransaction();quantaWorkspaceAssertCurrent($pdo,1,$access,true);quantaLegacyTimeLocal(1,$access,$originalEntry,true);
$observer->exec('SET SESSION innodb_lock_wait_timeout=1');
foreach(['UPDATE time_entries SET hours=7 WHERE id=1','UPDATE quanta_time_routes SET effective_from="2026-10-02" WHERE tenant_id=1',
    'UPDATE quanta_worker_links SET person_id=20 WHERE tenant_id=1','UPDATE placements SET start_date="2026-10-02" WHERE id=42',
    'UPDATE core_record_numbers SET record_number="PL-99" WHERE entity_type="placement"'] as $sql)$check($fails(fn()=>$observer->exec($sql)),'locked claim blocks concurrent writer: '.$sql);
$pdo->rollBack();
$lost=true;$check($fails(fn()=>$send($p))&&$posts===1,'lost acknowledgement retains one uncertain request');
$check($fails(fn()=>$send($p))&&$posts===1,'uncertain association is never re-sent');
$transport=$GLOBALS['__quanta_transport'];$GLOBALS['__quanta_transport']=static function(){throw new RuntimeException('Provider unavailable');};
$check($preview()['receipt_only']===true,'reload recovers pending request before reading missing source');
$queue=quantaLegacyTimePending(1);$check(count($queue['rows'])===1&&quantaLegacyTimePending(2)['rows']===[],'pending recovery is local and company isolated');
$check(!array_intersect(['payload_ct','idempotency_key','claim_hash'],array_keys($queue['rows'][0]['delivery'])),'recovery exposes no transport secrets');
$GLOBALS['__quanta_transport']=$transport;
// A changed directory must not recover the old company's proof even if it shares credentials.
$pdo->exec('UPDATE tenants SET parent_id=2,tenant_type="sub" WHERE id=1');
$pdo->exec('INSERT INTO tenant_module_scope VALUES(1,"people","shared")');
$check($fails(fn()=>quantaLegacyTimeReceipt(1,(int)$p['delivery']['id'],$p['delivery']['review_hash']))&&$recoveries===0,'changed person directory blocks original receipt recovery');
$pdo->exec('DELETE FROM tenant_module_scope WHERE tenant_id=1');$pdo->exec('UPDATE tenants SET parent_id=NULL,tenant_type="master" WHERE id=1');
$pdo->exec('UPDATE people SET deleted_at=NOW() WHERE id=19');$pdo->exec('UPDATE placements SET deleted_at=NOW() WHERE id=42');
$r=quantaLegacyTimeReceipt(1,(int)$p['delivery']['id'],$p['delivery']['review_hash']);
$check($r['receipt_state']==='confirmed'&&$r['delivery']['state']==='delivered'&&$posts===1&&$recoveries===1,'committed original recovers after records disappear without resending');
$check(quantaLegacyTimePending(1)['rows']===[],'confirmed original leaves recovery queue');
$check(quantaLegacyTimeReceipt(1,(int)$p['delivery']['id'],$p['delivery']['review_hash'])['delivery']===$r['delivery']&&$recoveries===1,'receipt replay performs no provider mutation');
$pdo->exec('UPDATE people SET deleted_at=NULL WHERE id=19');$pdo->exec('UPDATE placements SET deleted_at=NULL WHERE id=42');
$check($preview()['status']==='unchanged'&&$posts===1,'existing validated association offers no duplicate publication');
foreach($before as $table=>$rows)$check($rows===$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'preserved original '.$table);
unset($GLOBALS['__quanta_transport'],$GLOBALS['__quanta_core_write_transport']);
putenv('COREFLUX_QUANTA_TIME_ACCESS_DELIVERY');putenv('COREFLUX_INTEGRATION_DELIVERY_TENANTS');
echo "$passed historical-time MariaDB checks passed\n";
