<?php
/** Real Core SQL claims/isolation; provider HTTP responses are synthetic. */
declare(strict_types=1);
define('COREFLUX_QCONTROL_FIXTURE',true);
require __DIR__.'/quanta_time_provenance_mariadb.php';
require_once __DIR__.'/../core/quanta/time_control_delivery.php';
putenv('COREFLUX_INTEGRATION_INSTANCE_ID='.$instance);setRequestTenantId(1);
foreach(['158_integration_deliveries.sql','162_integration_delivery_receipt.sql'] as $file)$migrate($file);
$pdo->exec('ALTER TABLE core_record_numbers ADD canonical_record_id BIGINT');$pdo->exec('UPDATE core_record_numbers SET canonical_record_id=record_id');
$pdo->exec('ALTER TABLE people ADD status VARCHAR(20) DEFAULT "active"');
$pdo->exec('ALTER TABLE placements ADD client_id BIGINT NULL, ADD end_client_company_id BIGINT NULL, ADD worksite_city VARCHAR(100) NULL, ADD worksite_state VARCHAR(100) NULL, ADD worksite_country VARCHAR(2) NULL');
$pdo->exec('CREATE TABLE placement_corp_details(tenant_id BIGINT,placement_id BIGINT,ap_vendor_id BIGINT NULL)');
$pdo->exec('CREATE TABLE audit_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT,actor_user_id BIGINT,event VARCHAR(128),meta_json LONGTEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$owners=quantaTimeControlOwners(1);$local=quantaMetadataSource($pdo,$instance,$owners,'placement',42);
$assignment=$local['assignment']+['worksite_id'=>'site-1','id'=>quantaCoreRemoteId('workspace-a',$local['reference']),
    'tenant_id'=>'workspace-a','worker_id'=>'worker-1','person_link_id'=>quantaCoreRemoteId('workspace-a',$local['person']),'version'=>1];
$identity=array_intersect_key($assignment,array_flip(['id','tenant_id','person','placement','worker_id','person_link_id','engagement_type','start_date','client','vendor','end_client']));
$quantaContext=$context;$policy=null;$legacy=0;$enabled=true;$foreign=false;$posts=0;$recoveries=0;$receipts=[];$lost=false;$race=false;$passed=0;
$remoteContext=static function()use(&$policy,&$legacy,&$enabled,&$foreign,$identity,$assignment):array {
    $value=['contract_version'=>QUANTA_TIME_CONTROL_VERSION,'workspace_id'=>$foreign?'foreign':'workspace-a','worker_id'=>'worker-1','worksite_id'=>'site-1',
        'worker_name'=>'Synthetic Worker','worksite_name'=>'Synthetic site','configuration_enabled'=>$enabled,'native_membership_id'=>'grant-1',
        'worker_identity_id'=>'login-1','worker_status'=>'active','worker_roles'=>['worker'],'worksite_active'=>true,
        'unbound_time'=>['count'=>$legacy,'complete'=>true,'sample_entry_ids'=>[],'message'=>'Unlinked time needs review'],
        'assignments'=>[['identity'=>$identity,'version'=>$assignment['version'],'status'=>$assignment['status'],'title'=>$assignment['title'],'end_date'=>$assignment['end_date']]],
        'current'=>$policy,'notice'=>'Reviewed fixture'];
    return $value+['context_hash'=>quantaMetadataRequestHash($value)];
};
$GLOBALS['__quanta_transport']=static function($method,$url)use($quantaContext,$assignment,$local,&$receipts,$remoteContext,&$race,$observer):array {
    $path=parse_url($url,PHP_URL_PATH);
    if(str_ends_with($path,'/context'))return ['status'=>200,'body'=>json_encode($quantaContext)];
    if(str_ends_with($path,'/time-access-context'))return ['status'=>200,'body'=>json_encode($remoteContext())];
    if(str_contains($path,'/person-links/'))return ['status'=>200,'body'=>json_encode(['id'=>$assignment['person_link_id'],'tenant_id'=>'workspace-a','worker_id'=>'worker-1','person'=>$local['person']])];
    if(str_contains($path,'/assignments/')){
        if($race){$observer->exec('UPDATE placements SET title="Changed during review" WHERE id=42');$race=false;}
        return ['status'=>200,'body'=>json_encode($assignment)];
    }
    if(str_contains($path,'/time-access-deliveries/'))return isset($receipts[basename($path)])?['status'=>200,'body'=>json_encode($receipts[basename($path)])]:['status'=>404,'body'=>'{}'];
    throw new RuntimeException('Unexpected provider read');
};
$GLOBALS['__quanta_core_write_transport']=static function($method,$url,$headers,$json)use(&$posts,&$recoveries,&$receipts,&$policy,&$lost,$remoteContext):array {
    if($method!=='POST')throw new RuntimeException('Unexpected method');
    if(str_ends_with($url,'/reconcile')){
        $recoveries++;$key=basename(dirname($url));$r=$receipts[$key];
        if(isset($r['commit'])){$r['state']='complete';$r['response']=$r['commit'];unset($r['commit']);$receipts[$key]=$r;}
        return ['status'=>200,'body'=>json_encode($r)];
    }
    if(!str_ends_with($url,'/time-access'))throw new RuntimeException('Unexpected provider write');
    $posts++;$body=json_decode($json,true);$key=null;foreach($headers as $h)if(str_starts_with($h,'Idempotency-Key: '))$key=substr($h,17);
    $rid='cfd_'.hash('sha256',$key);$policy=quantaTimeControlExpected('workspace-a',$body,$remoteContext())+['last_delivery_id'=>$rid,'updated_at'=>'2026-11-11T12:00:00Z'];
    $response=$policy+['delivery_id'=>$rid];$receipt=['id'=>$rid,'request_hash'=>quantaTimeControlHash($body),'state'=>$lost?'uncertain':'complete','status_code'=>201];
    $receipts[$key]=$receipt+[$lost?'commit':'response'=>$response];
    return ['status'=>$lost?500:201,'body'=>json_encode($response)];
};
$preview=static fn(array $ids=[42])=>quantaTimeControlPreview(1,7,19,'site-1',$ids,$remoteContext()['context_hash']);
$send=static fn($p)=>quantaTimeControlSend(1,7,(int)$p['delivery']['id'],$p['delivery']['review_hash']);
$before=$pdo->query('SELECT * FROM time_entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$v=quantaTimeControlContext(1,19,'site-1')['view'];
$check($v['choices'][0]['placement_id']===42&&$v['person_number']==='P-19'&&$posts===0,'context resolves canonical local placement without writing Quanta');
$foreign=true;$check($fails(fn()=>quantaTimeControlContext(1,19,'site-1')),'foreign context is rejected');$foreign=false;
$legacy=1;$check($fails(fn()=>$preview())&&$fails(fn()=>$preview([]))&&$posts===0,'initial enable and hold both require legacy time adoption');$legacy=0;
$p=$preview();$check($p['selected_placements']===['PL-42']&&$preview()['delivery']['id']===$p['delivery']['id'],'review reuses one encrypted original request');
$check($fails(fn()=>$send($p))&&$posts===0,'default-off control cannot send');
putenv('COREFLUX_QUANTA_TIME_ACCESS_DELIVERY=1');putenv('COREFLUX_INTEGRATION_DELIVERY_TENANTS=1');
$check($fails(fn()=>quantaTimeControlSend(2,7,(int)$p['delivery']['id'],$p['delivery']['review_hash']))&&$posts===0,'another tenant cannot send');
$enabled=false;$check($fails(fn()=>$send($p))&&$posts===0,'provider rollout change invalidates review');$enabled=true;
$race=true;$check($fails(fn()=>$send($p))&&$posts===0,'placement mutation during provider read fails locked source proof');
$pdo->exec('UPDATE placements SET title="Synthetic assignment" WHERE id=42');
$sent=$send($p);$check($sent['state']==='delivered'&&$posts===1,'reviewed policy sends once');
$check($send($p)===$sent&&$posts===1,'successful replay never posts again');
$check($fails(fn()=>quantaTimeControlPreview(1,7,19,'site-1',[42],$v['context_hash'])),'stale setup review fails');
$legacy=1;$check($fails(fn()=>$preview())&&$posts===1,'later unbound time blocks enabling assignments');
$p2=$preview([]);$check($p2['selected_placements']===[],'existing policy can still hold new time with unbound history');
$lost=true;$check($fails(fn()=>$send($p2))&&$posts===2,'lost response remains uncertain');
$check($fails(fn()=>$send($p2))&&$posts===2,'uncertain setup cannot be re-sent');
$savedTransport=$GLOBALS['__quanta_transport'];$GLOBALS['__quanta_transport']=static function(){throw new RuntimeException('Source unavailable');};
$check($preview()['receipt_only']===true,'pending setup is found before reading unavailable source');
$rows=quantaTimeControlPending(1)['rows'];$check(count($rows)===1&&$rows[0]['delivery']['id']===$p2['delivery']['id'],'recovery list survives provider outage');
$check(!array_intersect(['payload_ct','idempotency_key','binding_hash','claim_hash'],array_keys($rows[0]['delivery'])),'recovery list exposes no secrets');
$check(quantaTimeControlPending(2)['rows']===[],'pending list is company isolated');
$GLOBALS['__quanta_transport']=$savedTransport;
$pdo->exec('UPDATE people SET deleted_at=NOW() WHERE id=19');$pdo->exec('UPDATE placements SET deleted_at=NOW() WHERE id=42');
$r=quantaTimeControlReceipt(1,(int)$p2['delivery']['id'],$p2['delivery']['review_hash']);
$check($r['receipt_state']==='confirmed'&&$r['delivery']['state']==='delivered'&&$recoveries===1&&$posts===2,'committed original recovers after local records disappear without reapplying policy');
$check(quantaTimeControlPending(1)['rows']===[],'confirmed receipt leaves recovery list');
$check(quantaTimeControlReceipt(1,(int)$p2['delivery']['id'],$p2['delivery']['review_hash'])['delivery']===$r['delivery']&&$recoveries===1,'recovered receipt replay has no further provider action');
$check($before===$pdo->query('SELECT * FROM time_entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'time setup never changes hours, approval, rates or financial extraction');
unset($GLOBALS['__quanta_transport'],$GLOBALS['__quanta_core_write_transport']);
putenv('COREFLUX_QUANTA_TIME_ACCESS_DELIVERY');putenv('COREFLUX_INTEGRATION_DELIVERY_TENANTS');
echo "$passed Core time-control MariaDB checks passed\n";
