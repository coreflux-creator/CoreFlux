<?php
/** Real Core ledger, company binding and client reads; fixture-only provider contract. */
declare(strict_types=1);
$inCi=getenv('GITHUB_ACTIONS')==='true'&&getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1'&&in_array('--confirm-disposable-fixture',$argv,true)&&(string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if(!$inCi&&!$local)throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));putenv('COREFLUX_DISABLE_DATABASE=1');
putenv('COREFLUX_PUREPAY_CUSTOMER_DELIVERY');
require_once __DIR__.'/../core/integrations/purepay_customer_inputs.php';
require_once __DIR__.'/../core/migrate.php';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$inCi?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),$options);
$db='coreflux_ppcustomers_'.($inCi?'ci':gmdate('YmdHis').'_'.getmypid());$pdo->exec('CREATE DATABASE '.$db);$pdo->exec('USE '.$db);
$observer=new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$db.';charset=utf8mb4','root',$inCi?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),$options);
foreach(['158_integration_deliveries.sql','162_integration_delivery_receipt.sql','164_purepay_core_connection.sql']as$file)foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file))as$sql)$pdo->exec($sql);
$pdo->exec('CREATE TABLE staffing_clients(id BIGINT PRIMARY KEY,tenant_id BIGINT,name VARCHAR(255),legal_name VARCHAR(255),primary_contact_email VARCHAR(255),primary_contact_phone VARCHAR(40),status VARCHAR(30))');
$pdo->exec('CREATE TABLE core_record_numbers(tenant_id BIGINT,entity_type VARCHAR(32),record_id BIGINT,canonical_record_id BIGINT,record_number VARCHAR(64),PRIMARY KEY(tenant_id,entity_type,record_number))');
$pdo->exec('CREATE TABLE fixture_audit(id INT AUTO_INCREMENT PRIMARY KEY,event VARCHAR(40))');
for($i=1;$i<=18;$i++){
    $pdo->exec("INSERT INTO staffing_clients VALUES({$i},10,'Client {$i}','Client {$i} LLC','client{$i}@example.test','555-0100','active')");
    $pdo->exec("INSERT INTO core_record_numbers VALUES(10,'client',{$i},{$i},'CL-{$i}')");
}
$passed=0;$check=static function(bool $ok,string $message)use(&$passed):void{if(!$ok)throw new RuntimeException($message);$passed++;echo "PASS {$message}\n";};
$fails=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}};
$instance='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$context=['organization'=>['id'=>'org-a','name'=>'Fixture A'],'contract_version'=>PUREPAY_CORE_VERSION,'link_contract_version'=>PUREPAY_CORE_LINK_VERSION,
    'customer_input_contract_version'=>PUREPAY_CUSTOMER_INPUT_VERSION,'capabilities'=>['read:coreflux','write:coreflux','write:customer_inputs'],'execution_side_effects'=>false];
$customers=[];$links=[];$posts=[];$reads=[];$mode=null;$interleave=null;
$response=static fn(array $data,int $status=200):array=>['status'=>$status,'body'=>json_encode($data,JSON_THROW_ON_ERROR)];
$ref=static fn(int $i):array=>integrationRecordReference($instance,10,'client','CL-'.$i);
$identity=static fn(array $r):string=>purepayCustomerExternalIdentity($r)['external_customer_id'];
$reply=static function(array $r,array $customer,string $basis='external_identity'):array{
    return ['contract_version'=>PUREPAY_CUSTOMER_INPUT_VERSION,'organization_id'=>'org-a','reference'=>$r,'customer'=>$customer,'identity_basis'=>$basis];
};
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$json)use(&$context,&$customers,&$links,&$posts,&$reads,&$mode,&$interleave,$response,$identity,$reply,$observer,$check){
    $prefix='/api/v1'.PUREPAY_CORE_PATH;$full=parse_url($url,PHP_URL_PATH);
    if(!str_starts_with($full,$prefix.'/'))throw new RuntimeException('Non-Core provider route forbidden');
    $path=substr($full,strlen($prefix));parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);
    if($method==='GET'){
        $reads[]=$path;
        if($path==='/context')return $response($context);
        if($path==='/customers/by-reference'){
            $r=$query;$r['directory_tenant_id']=(int)$r['directory_tenant_id'];$key=$identity($r);
            if($interleave){$fn=$interleave;$interleave=null;$fn();}
            if($mode==='ambiguous')return $response(['detail'=>['code'=>'customer_external_identity_ambiguous']],409);
            if($mode==='wrong_404')return $response(['detail'=>'route missing'],404);
            if(!isset($customers[$key]))return $response(['detail'=>['code'=>'customer_external_identity_not_found']],404);
            $value=$reply($r,$customers[$key],isset($links[$key])?'core_link':'external_identity');
            if($mode==='foreign_lookup')$value['customer']['organization_id']='org-b';
            return $response($value);
        }
        if(str_starts_with($path,'/records/client/')){
            $id=substr($path,strlen('/records/client/'));
            foreach($customers as $c)if($c['id']===$id)return $response($c+['record_version'=>hash('sha256',$id)]);
            return $response([],404);
        }
        if($path==='/links/resolve'){
            $r=array_intersect_key($query,array_flip(['instance_id','directory_tenant_id','entity_type','number']));$r['directory_tenant_id']=(int)$r['directory_tenant_id'];
            $left=$links[$identity($r)]??null;$right=null;
            foreach($links as $link)if($link['provider_id']===$query['provider_id'])$right=$link;
            return $response(['reference_link'=>$left,'provider_link'=>$right]);
        }
    }
    if($method==='POST'){
        $body=json_decode($json,true,64,JSON_THROW_ON_ERROR);$posts[]=['path'=>$path,'body'=>$body,'headers'=>$headers];
        if($path==='/links'){
            $link=['id'=>'cfl_'.integrationDeliveryHash(['org-a',$body['reference']]),'organization_id'=>'org-a','reference'=>$body['reference'],'provider_id'=>$body['provider_id'],'provider_record_version'=>$body['expected_record_version']];
            $links[$identity($body['reference'])]=$link;return $response($link);
        }
        if($path==='/customers'){
            $keys=array_values(array_filter($headers,static fn($h)=>str_starts_with($h,'Idempotency-Key: ')));
            $s=$observer->prepare('SELECT state FROM integration_deliveries WHERE idempotency_key=?');$s->execute([substr($keys[0]??'',17)]);
            $check($s->fetchColumn()==='sending','Exclusive customer claim committed before provider IO');
            if($mode==='before_write_failure')return $response(['detail'=>'unavailable'],503);
            $r=$body['reference'];$key=$identity($r);
            $customer=['id'=>'cus_generated_'.count($posts),'organization_id'=>'org-a','name'=>$body['name'],'email'=>$body['email'],'phone'=>$body['phone']]+purepayCustomerExternalIdentity($r);
            $customers[$key]=$customer;
            if($mode==='lost')throw new RuntimeException('Lost response after provider insert');
            if($mode==='duplicate')return $response(['detail'=>['code'=>'customer_external_identity_exists','customer_id'=>$customer['id']]],409);
            $value=$reply($r,$customer);
            if($mode==='wrong_org')$value['organization_id']='org-b';
            if($mode==='stale_snapshot')$customers[$key]['name']='Current provider name';
            return $response($value,201);
        }
    }
    throw new RuntimeException('Unexpected provider operation '.$method.' '.$path);
};
$connect=static function(string $key='fixture-dedicated-core-key')use($pdo,$instance):void{
    purepayCoreConnect($pdo,1,$key,$instance);$r=purepayCoreReview($pdo,1,$instance);purepayCoreConfirm($pdo,1,7,$instance,$r['review_hash'],'org-a');
};
$preview=static fn(int $i):array=>purepayCustomerInputPreview($pdo,1,7,$instance,10,'CL-'.$i);
$send=static fn(array $p,?callable $audit=null):array=>purepayCustomerInputSend($pdo,1,7,$instance,10,$p['delivery']['id'],$p['delivery']['review_hash'],$audit);
$recover=static fn(array $p,?callable $audit=null):array=>purepayCustomerInputReconcile($pdo,1,$instance,10,$p['delivery']['id'],$p['delivery']['review_hash'],$audit);
$state=static fn(array $p):string=>integrationDeliveryGet($pdo,1,$p['delivery']['id'])['state'];
$audit=static fn()=>$pdo->exec("INSERT INTO fixture_audit(event) VALUES('customer.confirmed')");
$baseline=$pdo->query('SELECT * FROM staffing_clients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$connect();$p=$preview(1);
$check(!$p['delivery_enabled']&&count($posts)===0,'Review is read-only and sharing defaults off');
$check($fails(fn()=>$send($p))&&count($posts)===0,'Disabled environment prevents provider writes');
$without=$context;unset($without['customer_input_contract_version']);$connection=purepayCoreConnection($pdo,1);
$check(purepayCoreHash($connection,'fixture-dedicated-core-key',$instance,$context)===purepayCoreHash($connection,'fixture-dedicated-core-key',$instance,$without),'Read contract advertisement preserves existing company binding');
unset($context['customer_input_contract_version']);$check($fails(fn()=>$preview(1)),'Older provider cannot activate customer transport');$context['customer_input_contract_version']=PUREPAY_CUSTOMER_INPUT_VERSION;
putenv('COREFLUX_PUREPAY_CUSTOMER_DELIVERY=1');
$check($preview(1)['delivery']['id']===$p['delivery']['id'],'Identical review reuses one durable request');
$check($fails(fn()=>purepayCustomerInputPreview($pdo,1,7,$instance,1,'CL-1')),'Selected workspace cannot replace the shared client directory');
$check($fails(fn()=>purepayCustomerInputSend($pdo,2,7,$instance,10,$p['delivery']['id'],$p['delivery']['review_hash'])),'Foreign workspace cannot submit a client delivery');
$check($fails(fn()=>purepayCustomerInputSend($pdo,1,7,$instance,20,$p['delivery']['id'],$p['delivery']['review_hash'])),'Changed directory cannot submit original review');
$result=$send($p,$audit);$check($result['state']==='delivered'&&count($posts)===1,'Reviewed customer creation stores provider-generated ID');
$check($result['remote_id']===$customers[$identity($ref(1))]['id'],'Native provider ID remains independent of Core number');
$check($send($p)===$result&&count($posts)===1,'Repeated send returns original delivery without another provider write');
$check($preview(1)['existing_customer']['customer']['id']===$result['remote_id'],'Reload finds current customer by exact identity');
$check(!array_diff(array_keys($posts[0]['body']),['contract_version','reference','name','email','phone']),'Write contains no banking, external overrides or QuickBooks fields');
$check(count(array_filter($posts[0]['headers'],static fn($h)=>str_starts_with($h,'Idempotency-Key: ')))===1,'Durable idempotency key reaches provider');
$check($pdo->query('SELECT * FROM staffing_clients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$baseline,'Sharing preserves Core client rows');
$p=$preview(2);$mode='lost';$result=$send($p);$mode=null;$count=count($posts);
$check($result['state']==='uncertain','Lost response remains an unresolved attempt');
$check($fails(fn()=>$send($p))&&count($posts)===$count,'Unresolved creation cannot be resent');
$check($preview(2)['delivery']['id']===$p['delivery']['id'],'Reload preserves original unresolved delivery');
$pdo->exec("UPDATE staffing_clients SET name='Renamed later' WHERE id=2");$connect('fixture-rotated-dedicated-key');
$check($recover($p,$audit)['delivery']['state']==='delivered'&&count($posts)===$count,'Read-only recovery survives profile changes and reviewed credential rotation');
$check($recover($p)['delivery']['state']==='delivered'&&count($posts)===$count,'Recovery replay makes no provider write');
$p=$preview(3);$mode='before_write_failure';$send($p);$mode=null;$count=count($posts);
$check($fails(fn()=>$recover($p))&&$state($p)==='uncertain'&&count($posts)===$count,'Exact 404 does not cancel an unresolved write or authorize resend');
$p=$preview(4);$pdo->exec("UPDATE staffing_clients SET primary_contact_phone='555-0199' WHERE id=4");
$check($fails(fn()=>$send($p))&&$state($p)==='conflict'&&count($posts)===$count,'Source drift after review prevents dispatch');
$p=$preview(5);$mode='wrong_org';$result=$send($p);$mode=null;
$check($result['state']==='uncertain','Foreign organization in create reply cannot confirm outcome');
$check($recover($p)['delivery']['state']==='delivered','Exact current read can recover malformed create reply');
$p=$preview(6);$mode='duplicate';$send($p);$mode=null;$count=count($posts);
$check($recover($p)['delivery']['state']==='delivered'&&count($posts)===$count,'Duplicate identity response recovers existing customer with no second create');
$p=$preview(7);$badAudit=static function()use($pdo):void{$pdo->exec("INSERT INTO fixture_audit(event) VALUES('must.rollback')");throw new RuntimeException('Audit failed');};
$check($send($p,$badAudit)['state']==='uncertain','Failed local audit leaves provider outcome held');
$check((int)$pdo->query("SELECT COUNT(*) FROM fixture_audit WHERE event='must.rollback'")->fetchColumn()===0,'Failed outcome/audit transaction rolls back atomically');
$check($recover($p,$audit)['delivery']['state']==='delivered','Read-only recovery completes the original provider customer');
$mode='wrong_404';$check($fails(fn()=>$preview(8)),'Missing route is not mistaken for absent customer');
$mode='ambiguous';$check($fails(fn()=>$preview(8)),'Ambiguous identity prevents creation');$mode=null;
$p=$preview(8);$mode='stale_snapshot';$send($p);$mode=null;
$check($preview(8)['existing_customer']['customer']['name']==='Current provider name','Review uses current lookup rather than create/replay snapshot');
$mode='foreign_lookup';$check($fails(fn()=>$preview(8)),'Foreign customer organization inside valid envelope is rejected');$mode=null;
$originalId=$customers[$identity($ref(8))]['id'];$customers[$identity($ref(8))]['id']='unexpected-replacement';
$check($fails(fn()=>$preview(8)),'Completed delivery cannot silently adopt a changed provider ID');$customers[$identity($ref(8))]['id']=$originalId;
$interleave=static function()use($pdo):void{$pdo->exec('UPDATE purepay_core_connections SET revision=revision+1 WHERE tenant_id=1');};
$check($fails(fn()=>$preview(9)),'Credential revision change during read invalidates result');$connect('fixture-restored-key');
$pdo->exec("UPDATE staffing_clients SET status='closed' WHERE id=9");$check($fails(fn()=>$preview(9)),'Closed client cannot be newly provisioned');
// Legacy linked customers have no external pair. Preserve them through both ledgers.
$legacy=['id'=>'legacy-native-id','organization_id'=>'org-a','name'=>'Legacy customer','email'=>null,'phone'=>null,'external_source'=>null,'external_customer_id'=>null];
$customers[$identity($ref(10))]=$legacy;
putenv('COREFLUX_PUREPAY_CORE_LINK_DELIVERY=1');
$link=purepayCoreLinkPreview($pdo,1,7,$instance,10,'client','CL-10','legacy-native-id');
purepayCoreLinkSend($pdo,1,7,$instance,10,$link['delivery']['id'],$link['delivery']['review_hash']);$count=count($posts);
$p=$preview(10);$check($p['delivery']===null&&$p['existing_customer']['identity_basis']==='core_link'&&$p['existing_customer']['customer']['id']==='legacy-native-id','Existing Core link wins without assigning a new external identity');
$check(count($posts)===$count&&$customers[$identity($ref(10))]===$legacy,'Legacy customer and native ID stay unchanged');
unset($links[$identity($ref(10))]);$check($fails(fn()=>$preview(10))&&count($posts)===$count,'Dangling provider link cannot fall through to creation');
$context['capabilities']=['read:coreflux','write:coreflux'];$connect('fixture-read-only-customer-key');$p=$preview(11);
$check(!$p['can_create']&&$fails(fn()=>$send($p))&&count($posts)===$count,'Read capability permits lookup but not creation');
$context['capabilities'][]='write:customer_inputs';$connect('fixture-reenabled-customer-key');
$p=$preview(12);$mode='lost';$send($p);$mode=null;putenv('COREFLUX_PUREPAY_CUSTOMER_DELIVERY');$count=count($posts);
$check($recover($p)['delivery']['state']==='delivered'&&count($posts)===$count,'Recovery remains read-only with delivery disabled');
$check(count(array_filter($posts,static fn($p)=>!in_array($p['path'],['/customers','/links'],true)))===0,'No payroll, QuickBooks, invoices or payment routes used');
echo "PASS {$passed} PurePay customer delivery database checks (provider fixtures only)\n";
$pdo->exec('DROP DATABASE '.$db);
