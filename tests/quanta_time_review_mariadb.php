<?php
/** Exercise the native review, claim, connection fences and receipt recovery on disposable MariaDB. */
declare(strict_types=1);
define('COREFLUX_QREVIEW_FIXTURE',true);
require __DIR__.'/quanta_time_provenance_mariadb.php';
require_once __DIR__.'/../core/quanta/time_review_delivery.php';
putenv('COREFLUX_INTEGRATION_INSTANCE_ID='.$instance);setRequestTenantId(1);
foreach(['158_integration_deliveries.sql','162_integration_delivery_receipt.sql'] as $file)$migrate($file);
$pdo->exec('ALTER TABLE core_record_numbers ADD canonical_record_id BIGINT');
$pdo->exec('UPDATE core_record_numbers SET canonical_record_id=record_id');
$pdo->exec('ALTER TABLE time_entries ADD approved_by_user_id BIGINT NULL, ADD approved_at DATETIME NULL, ADD updated_at DATETIME NULL');
$pdo->exec('UPDATE time_entries SET status="pending_review",approved_at=NULL,approved_by_user_id=NULL,updated_at="2026-10-24 00:00:00" WHERE id IN(1,2)');
$pdo->exec('CREATE TABLE audit_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT,actor_user_id BIGINT,event VARCHAR(128),meta_json LONGTEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$quantaContext=$context;$remote=$validRemote;$current=null;$posts=0;$reads=0;$receipts=[];$uncertain=false;$race=false;$passed=0;
$assignmentId=quantaCoreRemoteId('workspace-a',integrationRecordReference($instance,1,'placement','PL-42'));
$assignment=['id'=>$assignmentId,'version'=>1,'person'=>integrationRecordReference($instance,1,'person','P-19'),
    'placement'=>integrationRecordReference($instance,1,'placement','PL-42'),'worker_id'=>'worker-1'];
$GLOBALS['__quanta_transport']=static function($method,$url)use($quantaContext,$assignment,&$remote,&$current,&$reads,&$receipts,&$race,$observer):array {
    $path=parse_url($url,PHP_URL_PATH);
    if(str_ends_with($path,'/context'))return ['status'=>200,'body'=>json_encode($quantaContext)];
    if(str_ends_with($path,'/time-review-context')){
        if($race){$observer->exec('UPDATE time_entries SET hours=7 WHERE id=1');$race=false;}
        return ['status'=>200,'body'=>json_encode(['contract_version'=>QUANTA_TIME_REVIEW_VERSION,'workspace_id'=>'workspace-a',
            'assignment'=>$assignment,'source_entry'=>$remote,'source_revision'=>integrationDeliveryHash($remote),'current'=>$current,
            'approval_authority'=>false,'financial_execution'=>false])];
    }
    if(str_contains($path,'/time-review-deliveries/')){
        $reads++;$key=basename($path);return isset($receipts[$key])?['status'=>200,'body'=>json_encode($receipts[$key])]:['status'=>404,'body'=>'{}'];
    }
    throw new RuntimeException('Unexpected provider read');
};
$GLOBALS['__quanta_core_write_transport']=static function($method,$url,$headers,$json)use(&$posts,&$current,&$receipts,&$uncertain):array {
    if($method!=='POST' || !str_ends_with($url,'/time-reviews'))throw new RuntimeException('Unexpected provider write');
    $posts++;$body=json_decode($json,true,64,JSON_THROW_ON_ERROR);$key=null;
    foreach($headers as $header)if(str_starts_with($header,'Idempotency-Key: '))$key=substr($header,17);
    $rid='cfd_'.hash('sha256',$key);$current=quantaTimeReviewExpected('workspace-a',$body)+['updated_at'=>'2026-10-24T00:00:00Z'];
    $response=$current+['delivery_id'=>$rid];
    $receipts[$key]=['id'=>$rid,'request_hash'=>quantaTimeReviewRequestHash($body),'state'=>'complete','status_code'=>201,'response'=>$response];
    return ['status'=>$uncertain?500:201,'body'=>json_encode($response)];
};
$preview=static fn()=>quantaTimeReviewPreview(1,7,'entry-1');
$send=static fn($p)=>quantaTimeReviewSend(1,7,(int)$p['delivery']['id'],$p['delivery']['review_hash']);
$p=$preview();$before=$pdo->query('SELECT * FROM time_entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$check($p['state']==='pending_review'&&count($p['components'])===2&&$posts===0,'review observes all imported components without a provider write');
$check($preview()['delivery']['id']===$p['delivery']['id'],'identical review reuses its original encrypted envelope');
$check($fails(fn()=>$send($p))&&$posts===0,'disabled status sharing cannot send');
putenv('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY=1');
$check($fails(fn()=>quantaTimeReviewSend(2,7,(int)$p['delivery']['id'],$p['delivery']['review_hash']))&&$posts===0,'another company cannot send the review');
$race=true;$check($fails(fn()=>$send($p))&&$posts===0,'Core changes during provider observation fail the final locked proof');
$pdo->exec('UPDATE time_entries SET hours=6.5 WHERE id=1');
$result=$send($p);
$check($result['state']==='delivered'&&$posts===1,'native delivery confirms one pending review observation');
$check($send($p)===$result&&$posts===1,'successful replay returns the original receipt without another POST');
$check($before===$pdo->query('SELECT * FROM time_entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'status delivery never changes time, rates or financial extraction');
$check($preview()['status']==='unchanged'&&$posts===1,'matching provider observation does not prepare another write');
$pdo->exec('UPDATE time_entries SET status="approved",approved_by_user_id=8,approved_at="2026-10-24 00:05:00",updated_at="2026-10-24 00:05:00" WHERE id IN(1,2)');
$p2=$preview();$check($p2['state']==='accepted','native approval evidence changes the observation to accepted');
$uncertain=true;$check($fails(fn()=>$send($p2))&&$posts===2,'lost response retains an uncertain attempt');
$check($fails(fn()=>$send($p2))&&$posts===2,'uncertain attempt is never automatically posted again');
$remote['regular_hours']=6;
$reopened=$preview();
$check($reopened['receipt_only']===true&&$reopened['delivery']['id']===$p2['delivery']['id']&&$posts===2,'refresh retains the original uncertain receipt even when source hours changed');
$savedTransport=$GLOBALS['__quanta_transport'];
$GLOBALS['__quanta_transport']=static function(){throw new RuntimeException('Source unavailable');};
$pending=quantaTimeReviewPending(1);
$check(count($pending['rows'])===1 && $pending['rows'][0]['delivery']['id']===$p2['delivery']['id']
    && $pending['rows'][0]['placement_number']==='PL-42','independent recovery list survives a missing source or unavailable provider');
$check(!array_intersect(['payload_ct','binding_hash','claim_hash','idempotency_key'],array_keys($pending['rows'][0]['delivery'])),'recovery list exposes no encrypted payload or private transport data');
$check(quantaTimeReviewPending(2)['rows']===[],'another company cannot list pending deliveries');
$pdo->exec('UPDATE integration_deliveries SET entity_tenant_id=2 WHERE id='.(int)$p2['delivery']['id']);
$check(quantaTimeReviewPending(1)['rows']===[],'a former directory owner cannot appear in the current recovery list');
$pdo->exec('UPDATE integration_deliveries SET entity_tenant_id=1 WHERE id='.(int)$p2['delivery']['id']);
$GLOBALS['__quanta_transport']=$savedTransport;
$remote=$validRemote;
$pdo->exec('UPDATE time_entries SET status="rejected",updated_at="2026-10-24 00:06:00" WHERE id=1');
$recovered=quantaTimeReviewReceipt(1,(int)$p2['delivery']['id'],$p2['delivery']['review_hash']);
$check($recovered['receipt_state']==='confirmed'&&$recovered['delivery']['state']==='delivered'&&$reads===1&&$posts===2,'original receipt can be recovered after a later Core status change without resending');
$check(quantaTimeReviewPending(1)['rows']===[],'confirmed deliveries leave the pending list');
$check(quantaTimeReviewReceipt(1,(int)$p2['delivery']['id'],$p2['delivery']['review_hash'])['delivery']===$recovered['delivery']&&$reads===1,'recovered receipt replay performs no provider call');
$p3=$preview();$check($p3['state']==='needs_correction'&&$p3['delivery']['id']!==$p2['delivery']['id'],'later rejection requires a distinct reviewed publication');
$remote['regular_hours']=6;
$check($fails(fn()=>$send($p3))&&$posts===2,'changed original source hours block the new status');
$saved=integrationDeliveryGet($pdo,1,(int)$p3['delivery']['id']);
$context=array_intersect_key($saved,array_flip(['provider','connection_ref','binding_hash','entity_type','entity_tenant_id','entity_id','operation','source_hash','remote_hash']));
$context['entity_tenant_id']=(int)$context['entity_tenant_id'];$context['entity_id']=(int)$context['entity_id'];
$payload=json_decode(decryptField($saved['payload_ct']),true);
for($i=0;$i<51;$i++){
    $context['source_hash']=hash('sha256','pagination-fixture-'.$i);
    $row=integrationDeliveryPreview($pdo,1,7,$context,$payload);
    $pdo->exec('UPDATE integration_deliveries SET state="uncertain" WHERE id='.(int)$row['id']);
}
$page1=quantaTimeReviewPending(1);$page2=quantaTimeReviewPending(1,$page1['next_before']);
$check(count($page1['rows'])===50 && count($page2['rows'])===1 && $page2['next_before']===null,'bounded cursor reaches older unconfirmed attempts');
$ids=array_map(static fn($r)=>(int)$r['delivery']['id'],[...$page1['rows'],...$page2['rows']]);
$check(count(array_unique($ids))===51,'recovery pages do not repeat attempts');
$check($fails(fn()=>quantaTimeReviewPending(1,0)),'invalid recovery cursor rejected');
unset($GLOBALS['__quanta_transport'],$GLOBALS['__quanta_core_write_transport']);
putenv('COREFLUX_QUANTA_TIME_REVIEW_DELIVERY');putenv('COREFLUX_INTEGRATION_INSTANCE_ID');setRequestTenantId(null);
echo "Quanta time review MariaDB: $passed passed\n";
