<?php
/** Disposable source/delivery fixture; no application configuration, real company or provider calls. */
declare(strict_types=1);
$inCi=getenv('GITHUB_ACTIONS')==='true' && getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1' && in_array('--confirm-disposable-fixture',$argv,true) && (string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if(!$inCi&&!$local)throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/purepay_people_inputs.php';require_once __DIR__.'/../core/migrate.php';
$password=$inCi?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$password,$options);
$db='coreflux_people_input_'.($inCi?'ci':gmdate('YmdHis').'_'.getmypid());$pdo->exec('CREATE DATABASE '.$db.' CHARACTER SET utf8mb4');$pdo->exec('USE '.$db);
$observer=new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$db.';charset=utf8mb4','root',$password,$options);
foreach(['158_integration_deliveries.sql','162_integration_delivery_receipt.sql','164_purepay_core_connection.sql']as$file)foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file))as$sql)$pdo->exec($sql);
$pdo->exec('CREATE TABLE people(id BIGINT PRIMARY KEY,tenant_id BIGINT,first_name VARCHAR(100),last_name VARCHAR(100),email_primary VARCHAR(254),phone_primary VARCHAR(40),status VARCHAR(20),ssn_ct TEXT,deleted_at DATETIME NULL)');
$pdo->exec("INSERT INTO people VALUES(101,10,'Zoë / 李','Worker','new@example.test',NULL,'active','never-send',NULL),(102,11,'Other','Worker','other@example.test',NULL,'active','never-send',NULL)");
$pdo->exec('CREATE TABLE placements(id BIGINT PRIMARY KEY,tenant_id BIGINT,title VARCHAR(100),person_id BIGINT,client_id BIGINT,engagement_type VARCHAR(20),status VARCHAR(20),start_date DATE,end_date DATE,actual_end_date DATE,deleted_at DATETIME NULL)');
$pdo->exec("INSERT INTO placements VALUES(201,20,'Synthetic placement',101,NULL,'w2','active','2026-10-01',NULL,NULL,NULL),(202,20,'Other person',102,NULL,'w2','active','2026-10-01',NULL,NULL,NULL)");
$pdo->exec('CREATE TABLE core_record_numbers(tenant_id BIGINT,entity_type VARCHAR(32),record_id BIGINT,canonical_record_id BIGINT,record_number VARCHAR(64))');
$pdo->exec("INSERT INTO core_record_numbers VALUES(10,'person',101,101,'P-9001'),(11,'person',102,102,'P-9002'),(20,'placement',201,201,'PL-9001'),(20,'placement',202,202,'PL-9002')");
$passed=0;$posts=0;$audits=0;$receipts=[];$mode='ok';
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS {$label}\n";};
$fails=static function(callable $f):bool{try{$f();return false;}catch(Throwable $e){return true;}};
$instance='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';$owners=['people'=>10,'placements'=>20];
$context=['organization'=>['id'=>'org-a','name'=>'Synthetic A'],'contract_version'=>PUREPAY_CORE_VERSION,'link_contract_version'=>PUREPAY_CORE_LINK_VERSION,
    'people_input_contract_version'=>PUREPAY_PEOPLE_INPUT_VERSION,'capabilities'=>['read:coreflux','read:payroll','write:people_inputs'],'execution_side_effects'=>false];
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$json)use(&$context,&$receipts,&$posts,&$mode,$observer,$check){
    $path=substr(parse_url($url,PHP_URL_PATH),strlen('/api/v1'.PUREPAY_CORE_PATH));$response=static fn($body,int $status=200):array=>['status'=>$status,'body'=>json_encode($body)];
    if($method==='GET' && $path==='/context')return $response($context);
    if($method==='GET' && str_starts_with($path,'/people/inputs/'))return isset($receipts[basename($path)])?$response($receipts[basename($path)]):$response([],404);
    if($method==='POST' && $path==='/people/inputs'){
        $posts++;$body=json_decode($json,true,64,JSON_THROW_ON_ERROR);$hash=integrationDeliveryHash($body);
        $check(in_array('Idempotency-Key: '.$hash,$headers,true),'exact contact snapshot hash on wire');
        $check((int)$observer->query('SELECT COUNT(*) FROM integration_deliveries WHERE operation="people.inputs" AND state="sending"')->fetchColumn()>0,'person attempt committed before provider call');
        $id='cpp_'.integrationDeliveryHash(['org-a',$hash]);
        $receipt=['id'=>$id,'organization_id'=>'org-a','content_hash'=>$hash,'created_at'=>'2026-10-22T00:00:00Z'];
        $receipts[$id]=$receipt+['packet'=>$body,'state'=>'ready','employee_id'=>null,'existing_link'=>null];
        if($mode==='lost')throw new RuntimeException('Synthetic lost response');
        return $response($receipt);
    }
    throw new RuntimeException('Unexpected provider route');
};
purepayCoreConnect($pdo,1,'synthetic-people-key',$instance);
$review=purepayCoreReview($pdo,1,$instance);purepayCoreConfirm($pdo,1,7,$instance,$review['review_hash'],'org-a');
$source=purepayPeopleInputSource($pdo,$instance,$owners,'P-9001','PL-9001');
$check($source['body']['person']['number']==='P-9001' && $source['body']['person']['directory_tenant_id']===10,'typed person comes from authorized registry, not SQL ID');
$check($source['body']['first_name']==='Zoë / 李' && $source['body']['phone']===null && !str_contains(json_encode($source['body']),'never-send'),'Unicode and nulls preserved; restricted fields excluded');
require_once __DIR__.'/../core/integrations/staffing_exchange_fields.php';
$mapped=0;
foreach(staffingExchangeFieldMappings() as $providers)foreach($providers['purepayroll']??[] as $mapping){
    if($mapping['route']!=='/api/v1/integrations/coreflux/people/inputs')continue;
    $check(array_key_exists($mapping['path'],$source['body']) && $mapping['contract_version']===$source['body']['contract_version'],'published person mapping exists in actual source packet');$mapped++;
}
$check($mapped===6,'all six reviewed person fields have explicit sender mappings');
$check($fails(fn()=>purepayPeopleInputSource($pdo,$instance,$owners,'P-9001','PL-9002')),'another person’s placement refused');
$check($fails(fn()=>purepayPeopleInputSource($pdo,$instance,['people'=>11,'placements'=>20],'P-9001','PL-9001')),'foreign directory refused');
foreach(['c2c','1099']as$type){$pdo->exec("UPDATE placements SET engagement_type='{$type}' WHERE id=201");$check($fails(fn()=>purepayPeopleInputSource($pdo,$instance,$owners,'P-9001','PL-9001')),'non-W2 classification never inferred');}
$pdo->exec("UPDATE placements SET engagement_type='w2',status='ended' WHERE id=201");
$check($fails(fn()=>purepayPeopleInputSource($pdo,$instance,$owners,'P-9001','PL-9001')),'ended assignment cannot seed a new profile');
$pdo->exec("UPDATE placements SET status='active' WHERE id=201");
$preview=static fn():array=>purepayPeopleInputPreview($pdo,1,7,$instance,$owners,'P-9001','PL-9001');
$audit=static function()use(&$audits){$audits++;};
$send=static fn(array $r):array=>purepayPeopleInputSend($pdo,1,7,$instance,$owners,(int)$r['delivery']['id'],$r['delivery']['review_hash'],$audit);
$status=static fn(array $r):array=>purepayPeopleInputStatus($pdo,1,$instance,$owners,(int)$r['delivery']['id'],$r['delivery']['review_hash'],$audit);
putenv('COREFLUX_PUREPAY_PEOPLE_DELIVERY');$r=$preview();
$check(!$r['delivery_enabled'] && $posts===0 && $fails(fn()=>$send($r)),'preview performs no delivery and rollout defaults off');
putenv('COREFLUX_PUREPAY_PEOPLE_DELIVERY=1');
$pdo->exec("UPDATE people SET phone_primary='555-0100' WHERE id=101");
$check($fails(fn()=>$send($r)) && $posts===0,'contact drift blocks stale send');
$r=$preview();$mode='lost';$sent=$send($r);
$check($sent['state']==='uncertain' && $posts===1,'lost response retained as uncertain');
$check($fails(fn()=>$send($r)) && $posts===1,'uncertain attempt cannot resend');
$mode='ok';$confirmed=$status($r);
$check($confirmed['delivery']['state']==='delivered' && $confirmed['provider_review']['state']==='ready' && $audits===1,'receipt recovery confirms inbox delivery, not employee creation');
$check($send($r)['state']==='delivered' && $posts===1 && $audits===1,'confirmed replay does not write or re-audit');
$remoteId=$confirmed['delivery']['remote_id'];$packet=$receipts[$remoteId]['packet'];
$receipts[$remoteId]['state']='created';$employee='emp_core_'.integrationDeliveryHash(['org-a',$packet['person']]);
$receipts[$remoteId]['employee_id']=$employee;
$receipts[$remoteId]['existing_link']=['id'=>'cfl_'.integrationDeliveryHash(['org-a',$packet['person']]),'organization_id'=>'org-a','reference'=>$packet['person'],
    'provider_id'=>$employee,'provider_record_version'=>str_repeat('a',64)];
$check($status($r)['provider_review']['employee_id']===$employee,'created native identity verified against exact person reference');
$check((int)$pdo->query('SELECT COUNT(*) FROM integration_deliveries WHERE operation="record.link"')->fetchColumn()===0,'provider creation does not silently adopt Core local employee link');
$receipts[$remoteId]['employee_id']='foreign-employee';$check($fails(fn()=>$status($r)),'wrong native employee refused');$receipts[$remoteId]['employee_id']=$employee;
$receipts[$remoteId]['organization_id']='org-b';$check($fails(fn()=>$status($r)),'wrong provider company refused');$receipts[$remoteId]['organization_id']='org-a';
$originalRow=integrationDeliveryGet($pdo,1,(int)$r['delivery']['id']);
$legacyPayload=json_decode((string)decryptField($originalRow['payload_ct']),true,64,JSON_THROW_ON_ERROR);
$legacyPayload['owners']=integrationDeliveryCanonical($owners+['ap'=>30]);
$legacyCipher=encryptField(integrationDeliveryJson($legacyPayload));
$pdo->prepare('UPDATE integration_deliveries SET payload_ct=? WHERE id=?')->execute([$legacyCipher,$originalRow['id']]);
$check($status($r)['provider_review']['employee_id']===$employee && $posts===1,'legacy AP metadata does not block native person receipt recovery');
$check(integrationDeliveryGet($pdo,1,(int)$r['delivery']['id'])['payload_ct']===$legacyCipher,'legacy encrypted envelope remains unchanged');
$check($fails(fn()=>purepayPeopleInputStatus($pdo,1,$instance,['people'=>11,'placements'=>20],(int)$r['delivery']['id'],$r['delivery']['review_hash'])),'legacy receipt still rejects a foreign people directory');
$check($fails(fn()=>purepayPeopleInputStatus($pdo,1,$instance,['people'=>10,'placements'=>21],(int)$r['delivery']['id'],$r['delivery']['review_hash'])),'legacy receipt still rejects a foreign placement directory');
$normalized=purepayPeopleInputSnapshot($pdo,1,$instance,$owners+['ap'=>30],'P-9001','PL-9001');
$check($normalized['payload']['owners']===$owners && !isset($normalized['payload']['owners']['ap']),'new person envelopes retain only their authorized workflow directories');
$legacyHistory=purepayPeopleInputHistory($pdo,1,$instance,$owners,'P-9001');
$check(count($legacyHistory['items'])===2 && (int)$legacyHistory['items'][0]['delivery']['id']===(int)$originalRow['id'],'native history retains the original legacy delivery identity');
$check($fails(fn()=>purepayPeopleInputStatus($pdo,2,$instance,$owners,(int)$r['delivery']['id'],$r['delivery']['review_hash'])),'another tenant cannot read delivery');
$history=purepayPeopleInputHistory($pdo,1,$instance,$owners,'P-9001');
$check(count($history['items'])===2 && $history['items'][0]['packet']['phone']==='555-0100','scoped immutable history preserves reviewed snapshots');
$context['organization']=['id'=>'org-b','name'=>'Synthetic B'];$check($fails(fn()=>$status($r)),'changed live provider company invalidates access');
putenv('COREFLUX_PUREPAY_PEOPLE_DELIVERY');unset($GLOBALS['__purepay_transport']);
echo "People input MariaDB: {$passed} passed\n";
