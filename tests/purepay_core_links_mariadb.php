<?php
/** Real durable ledger and Core directory lookups; isolated synthetic provider with response-loss injection. */
declare(strict_types=1);
$inCi=getenv('GITHUB_ACTIONS')==='true' && getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1' && in_array('--confirm-disposable-fixture',$argv,true) && (string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if(!$inCi&&!$local)throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/purepay_links.php';
require_once __DIR__.'/../core/migrate.php';
$password=$inCi?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$password,$options);
$db='coreflux_pplinks_'.($inCi?'ci':gmdate('YmdHis').'_'.getmypid());$pdo->exec('CREATE DATABASE '.$db);$pdo->exec('USE '.$db);
$observer=new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$db.';charset=utf8mb4','root',$password,$options);
foreach(['158_integration_deliveries.sql','162_integration_delivery_receipt.sql','164_purepay_core_connection.sql']as$file)foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file))as$sql)$pdo->exec($sql);
$pdo->exec('CREATE TABLE people (id BIGINT PRIMARY KEY,tenant_id BIGINT,first_name VARCHAR(100),last_name VARCHAR(100),email_primary VARCHAR(255),status VARCHAR(30),deleted_at DATETIME NULL)');
$pdo->exec('CREATE TABLE placements (id BIGINT PRIMARY KEY,tenant_id BIGINT,title VARCHAR(255),person_id BIGINT,client_id BIGINT,engagement_type VARCHAR(20),status VARCHAR(30),start_date DATE,end_date DATE,actual_end_date DATE,deleted_at DATETIME NULL)');
$pdo->exec('CREATE TABLE staffing_clients (id BIGINT PRIMARY KEY,tenant_id BIGINT,name VARCHAR(255),legal_name VARCHAR(255),primary_contact_email VARCHAR(255),status VARCHAR(30))');
$pdo->exec('CREATE TABLE ap_vendors_index (id BIGINT PRIMARY KEY,tenant_id BIGINT,vendor_name VARCHAR(255),vendor_type VARCHAR(30))');
$pdo->exec('CREATE TABLE core_record_numbers (tenant_id BIGINT,entity_type VARCHAR(32),record_id BIGINT,canonical_record_id BIGINT,record_number VARCHAR(64),PRIMARY KEY(tenant_id,entity_type,record_number))');
$pdo->exec('CREATE TABLE fixture_audit (id INT AUTO_INCREMENT PRIMARY KEY,event VARCHAR(40))');
for($i=1;$i<=14;$i++) {
    $pdo->exec("INSERT INTO people VALUES({$i},10,'Worker','{$i}','worker{$i}@example.test','active',NULL)");
    $pdo->exec("INSERT INTO core_record_numbers VALUES(10,'person',{$i},{$i},'P-{$i}')");
}
$pdo->exec("INSERT INTO placements VALUES(1,10,'Assignment one',1,1,'w2','active','2026-10-01',NULL,NULL,NULL)");
$pdo->exec("INSERT INTO staffing_clients VALUES(1,10,'Client one','Client One LLC','client@example.test','active')");
$pdo->exec("INSERT INTO ap_vendors_index VALUES(1,1,'Vendor one','c2c_corp')");
$pdo->exec("INSERT INTO core_record_numbers VALUES(10,'placement',1,1,'PL-1'),(10,'client',1,1,'CL-1'),(1,'vendor',1,1,'V-1')");
$passed=0;$check=static function(bool $ok,string $label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS {$label}\n";};
$fails=static function(callable $f):bool{try{$f();return false;}catch(Throwable $e){return true;}};
$instance='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$context=['organization'=>['id'=>'org-a','name'=>'Synthetic A'],'contract_version'=>PUREPAY_CORE_VERSION,'link_contract_version'=>PUREPAY_CORE_LINK_VERSION,
    'capabilities'=>['read:coreflux','write:coreflux','read:payroll'],'execution_side_effects'=>false];
$records=[];
for($i=1;$i<=14;$i++)$records['person']['emp-'.$i]=['id'=>'emp-'.$i,'name'=>'Provider worker '.$i,'record_version'=>hash('sha256','employee-'.$i)];
foreach(['placement'=>'assignment-a','client'=>'client-a','vendor'=>'vendor-a']as$kind=>$id)$records[$kind][$id]=['id'=>$id,'name'=>$kind.' one','record_version'=>hash('sha256',$kind)];
$links=[];$posts=0;$mode=null;$interleave=null;
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$json)use(&$context,&$records,&$links,&$posts,&$mode,&$interleave,$observer,$check) {
    $path=substr(parse_url($url,PHP_URL_PATH),strlen('/api/v1'.PUREPAY_CORE_PATH));
    parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);
    $response=static fn($body,int $status=200):array=>['status'=>$status,'body'=>json_encode($body)];
    if($method==='GET') {
        if($path==='/context')return $response($context);
        if(str_starts_with($path,'/records/')) {
            [,,$kind,$id]=explode('/',$path);
            if($interleave){$fn=$interleave;$interleave=null;$fn();}
            return isset($records[$kind][$id])?$response($records[$kind][$id]):$response([],404);
        }
        if($path==='/links/resolve') {
            $ref=array_intersect_key($query,array_flip(['instance_id','directory_tenant_id','entity_type','number']));$ref['directory_tenant_id']=(int)$ref['directory_tenant_id'];
            $left=null;$right=null;
            foreach($links as$link) {
                if(integrationDeliveryHash($link['reference'])===integrationDeliveryHash($ref))$left=$link;
                if($link['provider_id']===$query['provider_id'] && $link['reference']['entity_type']===$ref['entity_type'])$right=$link;
            }
            return $response(['reference_link'=>$left,'provider_link'=>$right]);
        }
    }
    if($method==='POST' && $path==='/links') {
        $body=json_decode($json,true,64,JSON_THROW_ON_ERROR);$posts++;
        $check((int)$observer->query('SELECT COUNT(*) FROM integration_deliveries WHERE state="sending" AND attempted_at IS NOT NULL')->fetchColumn()>0,'independent connection sees committed claim before provider write');
        $check(count(array_filter($headers,static fn($h)=>str_starts_with($h,'Idempotency-Key: ')))===1,'stable idempotency header accompanies the attempt');
        if($mode==='no_receipt')throw new RuntimeException('Synthetic lost request');
        $ref=$body['reference'];$native=$records[$ref['entity_type']][$body['provider_id']];
        if($native['record_version']!==$body['expected_record_version'])return $response([],409);
        $receipt=['id'=>'cfl_'.integrationDeliveryHash(['org-a',$ref]),'organization_id'=>'org-a','reference'=>$ref,'provider_id'=>$body['provider_id'],'provider_record_version'=>$body['expected_record_version']];
        $links[$receipt['id']]=$receipt;
        if($mode==='lost')throw new RuntimeException('Synthetic response loss');
        if($mode==='wrong_receipt')$receipt['provider_id']='wrong-person';
        return $response($receipt);
    }
    throw new RuntimeException('Unexpected synthetic provider operation');
};
$connect=static function(string $key='synthetic-core-link-key')use($pdo,$instance) {
    purepayCoreConnect($pdo,1,$key,$instance);$review=purepayCoreReview($pdo,1,$instance);
    purepayCoreConfirm($pdo,1,7,$instance,$review['review_hash'],'org-a');
};
$preview=static fn(int $i):array=>purepayCoreLinkPreview($pdo,1,7,$instance,10,'person','P-'.$i,'emp-'.$i);
$send=static fn(array $p,?callable $audit=null):array=>purepayCoreLinkSend($pdo,1,7,$instance,(int)$p['source']['entity_tenant_id'],(int)$p['delivery']['id'],$p['delivery']['review_hash'],$audit);
$reconcile=static fn(array $p,?callable $audit=null):array=>purepayCoreLinkReconcile($pdo,1,$instance,(int)$p['source']['entity_tenant_id'],(int)$p['delivery']['id'],$p['delivery']['review_hash'],$audit);
$state=static fn(array $p):string=>integrationDeliveryGet($pdo,1,(int)$p['delivery']['id'])['state'];
$audit=static function()use($pdo){$pdo->exec("INSERT INTO fixture_audit(event) VALUES('link.confirmed')");};
$badAudit=static function()use($pdo){$pdo->exec("INSERT INTO fixture_audit(event) VALUES('must.rollback')");throw new RuntimeException('Synthetic audit failure');};
$connect();
$p=$preview(1);$check($posts===0 && !$p['delivery_enabled'],'preview creates no provider records and delivery defaults off');
$check($fails(fn()=>$send($p)) && $posts===0,'disabled environment cannot send');
putenv('COREFLUX_PUREPAY_CORE_LINK_DELIVERY=1');
$check($preview(1)['delivery']['id']===$p['delivery']['id'],'identical review reuses the durable envelope');
$check($fails(fn()=>purepayCoreLinkSource($pdo,$instance,1,'person','P-1')),'active company cannot substitute for the shared directory owner');
$check($fails(fn()=>purepayCoreLinkSource($pdo,$instance,10,'person','PL-1')),'wrong typed number is rejected');
$check($fails(fn()=>purepayCoreLinkSend($pdo,2,7,$instance,10,(int)$p['delivery']['id'],$p['delivery']['review_hash'])),'foreign company cannot send a delivery');
$check($fails(fn()=>purepayCoreLinkSend($pdo,1,7,$instance,99,(int)$p['delivery']['id'],$p['delivery']['review_hash'])),'changed directory ownership invalidates review');
$check($fails(fn()=>purepayCoreLinkSend($pdo,1,7,$instance,10,(int)$p['delivery']['id'],str_repeat('0',64))),'unreviewed delivery hash is rejected');
$before=$pdo->query('SELECT * FROM people ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$result=$send($p,$audit);$check($result['state']==='delivered' && $result['remote_id']==='emp-1' && $posts===1,'reviewed person link records its confirmed receipt');
$check($send($p)===$result && $posts===1,'successful replay sends nothing');
$check($preview(1)['delivery']['id']===$p['delivery']['id'],'fresh preview recovers the original delivered mapping');
$check($pdo->query('SELECT * FROM people ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$before,'native Core person records are unchanged');
$check((int)$pdo->query('SELECT COUNT(*) FROM fixture_audit')->fetchColumn()===1,'confirmed mapping and audit committed together');
foreach([['placement','PL-1','assignment-a',10],['client','CL-1','client-a',10],['vendor','V-1','vendor-a',1]]as[$kind,$number,$native,$owner]) {
    $p=purepayCoreLinkPreview($pdo,1,7,$instance,$owner,$kind,$number,$native);
    $check($send($p)['state']==='delivered',$kind.' uses its own typed identity and authorized directory');
}
$p=$preview(2);$count=$posts;$pdo->exec("UPDATE people SET last_name='Changed' WHERE id=2");
$check($fails(fn()=>$send($p)) && $posts===$count && $state($p)==='conflict','Core record changed after review prevents delivery');
$p=$preview(3);$records['person']['emp-3']['record_version']=hash('sha256','changed');
$check($fails(fn()=>$send($p)) && $posts===$count && $state($p)==='conflict','provider record changed after review prevents delivery');
$p=$preview(4);$mode='lost';$result=$send($p);$mode=null;
$check($result['state']==='uncertain','lost response remains held');$count=$posts;
$check($fails(fn()=>$send($p)) && $posts===$count,'uncertain delivery cannot be resent');
$check($preview(4)['delivery']['id']===$p['delivery']['id'],'review recovers the unresolved attempt after page reload');
$check($fails(fn()=>purepayCoreLinkPreview($pdo,1,7,$instance,10,'person','P-4','emp-5')),'held Core identity cannot be remapped to another provider record');
$pdo->exec("UPDATE people SET last_name='Later' WHERE id=4");$records['person']['emp-4']['record_version']=hash('sha256','later-profile');
$connect('synthetic-rotated-core-key');
$check($reconcile($p,$audit)['state']==='delivered' && $posts===$count,'read-only receipt reconciliation survives later profile changes and verified key rotation');
$check($reconcile($p)['state']==='delivered' && $posts===$count,'reconciliation replay is read-only and idempotent');
$p=$preview(5);$mode='wrong_receipt';$result=$send($p);$mode=null;
$check($result['state']==='uncertain','wrong provider identity in response cannot confirm a link');
$check($reconcile($p)['state']==='delivered','point lookup can recover the actual confirmed receipt');
$p=$preview(6);$mode='no_receipt';$send($p);$mode=null;$count=$posts;
$check($fails(fn()=>$reconcile($p)) && $state($p)==='uncertain' && $posts===$count,'absence of a receipt never implies permission to resend');
$p=$preview(7);$result=$send($p,$badAudit);
$check($result['state']==='uncertain' && (int)$pdo->query("SELECT COUNT(*) FROM fixture_audit WHERE event='must.rollback'")->fetchColumn()===0,'failed audit rolls back local mapping and leaves a reconcilable attempt');
$check($reconcile($p,$audit)['state']==='delivered','reconciliation recovers receipt after local commit failure');
$p=$preview(8);$mode='lost';$send($p);$mode=null;
$receiptId='cfl_'.integrationDeliveryHash(['org-a',$p['source']['reference']]);$saved=$links[$receiptId];$links[$receiptId]['provider_record_version']=str_repeat('0',64);
$check($fails(fn()=>$reconcile($p)) && $state($p)==='uncertain','different reviewed version cannot settle the held attempt');$links[$receiptId]=$saved;
$links[$receiptId]['organization_id']='other-org';
$check($fails(fn()=>$reconcile($p)),'receipt from another company cannot settle the attempt');$links[$receiptId]=$saved;
$check($reconcile($p)['state']==='delivered','corrected verified receipt settles the original attempt');
$ref=integrationRecordReference($instance,10,'person','P-9');$rid='cfl_'.integrationDeliveryHash(['org-a',$ref]);
$links[$rid]=['id'=>$rid,'organization_id'=>'org-a','reference'=>$ref,'provider_id'=>'emp-9','provider_record_version'=>hash('sha256','earlier-reviewed-version')];
$p=$preview(9);$count=$posts;
$check($p['existing_provider_link'] && $send($p)['state']==='delivered' && $posts===$count,'existing immutable provider link can be reviewed and recorded without another POST');
$p=$preview(10);$interleave=static function()use($pdo){purepayCoreDisconnect($pdo,1);};
$check($fails(fn()=>$send($p)) && $posts===$count,'disconnect during remote read prevents delivery');$connect();
$p=$preview(11);$context['link_contract_version']='future';
$check($fails(fn()=>$send($p)) && $posts===$count,'changed link contract invalidates company review');$context['link_contract_version']=PUREPAY_CORE_LINK_VERSION;
$p=$preview(12);$interleave=static function()use($pdo){$pdo->exec("UPDATE people SET last_name='Concurrent edit' WHERE id=12");};
$check($fails(fn()=>$send($p)) && $posts===$count,'Core edit during provider lookup invalidates the snapshot before claiming');
$context['capabilities']=['read:coreflux','read:payroll'];$connect();
$check($fails(fn()=>$preview(12)),'read-only Core key cannot prepare a write');
$context['capabilities']=['read:coreflux','write:coreflux','read:payroll'];$connect();
$pdo->exec('UPDATE core_record_numbers SET canonical_record_id=1 WHERE entity_type="person" AND record_id=13');
$check($fails(fn()=>$preview(13)),'merged number cannot silently create a second canonical link');
$pdo->exec('UPDATE people SET deleted_at=NOW() WHERE id=14');
$check($fails(fn()=>$preview(14)),'deleted Core source cannot be linked');
$history=purepayCoreLinkHistory($pdo,1,$instance,10,'person');
$check(count($history['items'])>0 && $history['next_before']===null,'history exposes reviewed links with an explicit end marker');
$held=array_values(array_filter($history['items'],static fn($item)=>$item['reference']['number']==='P-6'));
$check(count($held)===1 && $held[0]['delivery']['state']==='uncertain','held attempts remain discoverable after reloading');
$check(!str_contains(json_encode($history),'payload_ct') && !str_contains(json_encode($history),'claim_hash'),'history omits encrypted payload and attempt credentials');
$check(purepayCoreLinkHistory($pdo,1,$instance,99,'person')['items']===[],'history respects current directory ownership');
$firstId=(int)$history['items'][0]['delivery']['id'];
$older=purepayCoreLinkHistory($pdo,1,$instance,10,'person',$firstId);
$check(count($older['items'])===count($history['items'])-1 && !in_array($firstId,array_map(static fn($item)=>(int)$item['delivery']['id'],$older['items']),true),'history cursor excludes the preceding page boundary');
unset($records['person']['emp-8']);
$check(count(purepayCoreLinkHistory($pdo,1,$instance,10,'person')['items'])===count($history['items']),'history remains available if a provider record is no longer listed');
unset($GLOBALS['__purepay_transport']);putenv('COREFLUX_PUREPAY_CORE_LINK_DELIVERY');
echo "PurePay Core links MariaDB: {$passed} passed\n";
