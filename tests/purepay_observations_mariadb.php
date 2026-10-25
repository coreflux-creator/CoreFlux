<?php
/** Disposable native payroll observations: no input, authority or payroll fixture is created. */
declare(strict_types=1);
$inCi=getenv('GITHUB_ACTIONS')==='true' && getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1' && in_array('--confirm-disposable-fixture',$argv,true) && (string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if(!$inCi&&!$local)throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/purepay_observations.php';
require_once __DIR__.'/../core/migrate.php';
$password=$inCi?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$password,$options);
$db='coreflux_ppobserve_'.($inCi?'ci':gmdate('YmdHis').'_'.getmypid());$pdo->exec('CREATE DATABASE '.$db);$pdo->exec('USE '.$db);
$observer=new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$db.';charset=utf8mb4','root',$password,$options);
foreach(['158_integration_deliveries.sql','162_integration_delivery_receipt.sql','164_purepay_core_connection.sql','174_payroll_native_observations.sql'] as $file)
    foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file)) as $sql)$pdo->exec($sql);
$pdo->exec('CREATE TABLE people(id BIGINT PRIMARY KEY,tenant_id BIGINT,first_name VARCHAR(50),last_name VARCHAR(50),email_primary VARCHAR(100),status VARCHAR(20),deleted_at DATETIME NULL)');
$pdo->exec('CREATE TABLE core_record_numbers(tenant_id BIGINT,entity_type VARCHAR(32),record_id BIGINT,canonical_record_id BIGINT,record_number VARCHAR(64))');
$pdo->exec("INSERT INTO people VALUES(1,10,'Synthetic','Worker','worker@example.test','active',NULL),(2,10,'Other','Worker','other@example.test','active',NULL)");
$pdo->exec("INSERT INTO core_record_numbers VALUES(10,'person',1,1,'P-1'),(10,'person',2,2,'P-2')");
$passed=0;$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
$fails=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}};
$instance='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';$owners=['people'=>10,'placements'=>20,'time'=>1];
$wire=json_decode(file_get_contents(__DIR__.'/fixtures/purepay_observations/full.json'),true,64,JSON_THROW_ON_ERROR);
$org=$wire['organization_id'];$run=$wire['run_id'];$employee=$wire['lines'][0]['employee_id'];
$context=['organization'=>['id'=>$org,'name'=>'Synthetic observations'],'contract_version'=>PUREPAY_CORE_VERSION,
    'link_contract_version'=>PUREPAY_CORE_LINK_VERSION,'capabilities'=>['read:coreflux','read:payroll'],'execution_side_effects'=>false];
$mode=null;$reads=0;$requests=[];
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$json)use(&$context,&$wire,&$mode,&$reads,&$requests,$observer):array {
    $path=substr(parse_url($url,PHP_URL_PATH),strlen('/api/v1'.PUREPAY_CORE_PATH));$requests[]=[$method,$path];
    if($method!=='GET')throw new RuntimeException('Observation attempted a provider write');
    if($path==='/context')return ['status'=>200,'body'=>json_encode($context)];
    if($path!=='/payroll/observations' && $path!=='/payroll/observations/'.$wire['run_id'])throw new RuntimeException('Unexpected observation URL');
    $reads++;
    if($mode==='lost')throw new RuntimeException('Synthetic lost read');
    if($mode==='absent')return ['status'=>404,'body'=>'{}'];
    if($mode==='rotated')$observer->exec('UPDATE purepay_core_connections SET revision=revision+1 WHERE tenant_id=1');
    if($mode==='disconnected')$observer->exec('UPDATE purepay_core_connections SET status="revoked",revision=revision+1 WHERE tenant_id=1');
    $value=$wire;$value['observed_at']=gmdate('Y-m-d\TH:i:s\Z');
    if($mode==='stale')$value['observed_at']=gmdate('Y-m-d\TH:i:s\Z',time()-600);
    if($mode==='wrong_org')$value['organization_id']='other-org';
    if($mode==='wrong_run')$value['run_id']='other-run';
    if($mode==='invalid')$value['totals']['gross']='not-money';
    if($path==='/payroll/observations')$value=['contract_version'=>PUREPAY_OBSERVATION_VERSION,
        'organization_id'=>$context['organization']['id'],'items'=>$mode==='duplicate'?[$value,$value]:[$value],
        'next_cursor'=>$mode==='repeat_cursor'?'repeated':null,'consistency'=>PUREPAY_OBSERVATION_CONSISTENCY];
    return ['status'=>200,'body'=>json_encode($value)];
};
$connect=static function(string $key='synthetic-observation-key')use($pdo,$instance,$org):void {
    purepayCoreConnect($pdo,1,$key,$instance);$r=purepayCoreReview($pdo,1,$instance);purepayCoreConfirm($pdo,1,7,$instance,$r['review_hash'],$org);
};
$connect();$originalBinding=purepayCoreConnection($pdo,1)['binding_hash'];
$check($fails(fn()=>purepayObservationPage($pdo,1,$instance)),'provider without observation contract is unavailable');
$context['payroll_observation_contract_version']=PUREPAY_OBSERVATION_VERSION;
$check(purepayCoreReview($pdo,1,$instance)['verified'] && purepayCoreConnection($pdo,1)['binding_hash']===$originalBinding,'optional read schema preserves existing company confirmation');
$read=static fn()=>purepayObservationRead($pdo,1,$instance,$owners,$run);
$save=static fn(string $hash,?callable $audit=null)=>purepayObservationSave($pdo,1,7,$instance,$owners,$run,$hash,$audit);
$history=static fn(?int $before=null)=>purepayObservationHistory($pdo,1,$instance,$owners,$run,$before);
$count=static fn():int=>(int)$pdo->query('SELECT COUNT(*) FROM payroll_native_observations')->fetchColumn();
$page=purepayObservationPage($pdo,1,$instance);
$check(count($page['items'])===1 && $page['tenant_id']===1 && $page['organization_id']===$org,'native payroll lists under the reviewed company');
$initial=$read();$check($initial['comparison']['people'][0]['link_status']==='unlinked' && $initial['comparison']['people'][0]['person']===null,'provider employee stays unlinked without a completed Core receipt');
$check($count()===0 && (int)$pdo->query('SELECT COUNT(*) FROM integration_deliveries')->fetchColumn()===0,'listing and review write no observation or delivery');
$audits=0;$audit=static function()use(&$audits):void{$audits++;};
$first=$save($initial['review_hash'],$audit);
$check(!$first['replayed'] && $first['id']>0 && $audits===1,'explicit review stores and audits an observation without payroll authority');
$row=$pdo->query('SELECT * FROM payroll_native_observations')->fetch(PDO::FETCH_ASSOC);
$check(!str_contains($row['payload_ct'],'"gross"') && purepayObservationSaved($row,$initial['context'])['observation']['run_id']===$run,'native payroll is encrypted and validated when read back');
$again=$save($initial['review_hash'],$audit);
$check($again['replayed'] && $again['id']===$first['id'] && $audits===1,'identical provider and Core versions reuse the saved capture');
$beforeReads=$reads;$check(count($history()['items'])===1 && $reads===$beforeReads,'saved history checks current access without rereading provider payroll');
$link=static function(int $id,string $provider)use($pdo,$instance,$org):int {
    $connection=purepayCoreConnection($pdo,1);$ref=integrationRecordReference($instance,10,'person','P-'.$id);
    $body=['reference'=>$ref,'provider_id'=>$provider,'expected_record_version'=>str_repeat('a',64)];
    $ctx=['provider'=>'purepay','connection_ref'=>(string)$connection['id'],'binding_hash'=>str_repeat('a',64),'entity_type'=>'person','entity_tenant_id'=>10,'entity_id'=>$id,
        'operation'=>'record.link','source_hash'=>str_repeat('b',64),'remote_hash'=>str_repeat('c',64)];
    $r=integrationDeliveryPreview($pdo,1,7,$ctx,['organization_id'=>$org,'body'=>$body]);
    $pdo->prepare('UPDATE integration_deliveries SET state="delivered",remote_id=?,remote_receipt_id=? WHERE id=?')->execute([$provider,'cfl_'.integrationDeliveryHash([$org,$ref]),$r['id']]);return (int)$r['id'];
};
$linkId=$link(1,$employee);$linked=$read();
$check($linked['comparison']['people'][0]['person']['number']==='P-1' && $linked['comparison']['people'][0]['link_status']==='linked','completed same-context receipt maps provider employee to Core person number');
$check($fails(fn()=>$save($initial['review_hash'])) && $count()===1,'a new Core match invalidates the earlier review');
$mapped=$save($linked['review_hash'],$audit);
$check(!$mapped['replayed'] && $mapped['id']!==$first['id'] && $count()===2,'same payroll with changed Core mapping appends a separate comparison');
$pdo->exec('UPDATE people SET deleted_at=NOW() WHERE id=1');
$check($read()['comparison']['people'][0]['link_status']==='requires_review','deleted Core identity never receives a claimed match');
$pdo->exec('UPDATE people SET deleted_at=NULL WHERE id=1');
$ambiguous=$link(2,$employee);$check($read()['comparison']['people'][0]['link_status']==='ambiguous','two delivered identities are not guessed by names');
$pdo->prepare('UPDATE integration_deliveries SET state="cancelled" WHERE id=?')->execute([$ambiguous]);
$pdo->exec("UPDATE people SET last_name='Changed' WHERE id=1");
$check($fails(fn()=>$save($linked['review_hash'])),'current Core source change invalidates an unsaved review');
$pdo->exec("UPDATE people SET last_name='Worker' WHERE id=1");
$changed=$wire;$changed['totals']['gross']='99.99';$changed['observation_hash']=purepayObservationHash($changed);$wire=$changed;$next=$read();
$check($next['comparison']['totals']['gross']['difference']==='-0.01','a provider ledger difference remains explicit for review');
$check($fails(fn()=>$save($next['review_hash'],static function(){throw new RuntimeException('Audit failed');})) && $count()===2,'audit failure rolls back capture');
$third=$save($next['review_hash'],$audit);$check($count()===3 && $history($third['id'])['items'][0]['id']===$mapped['id'],'changed provider figures append with usable history cursor');
foreach(['lost','absent','stale','wrong_org','wrong_run','invalid','rotated','disconnected'] as $mode) {
    $check($fails($read) && $count()===3,'unsafe '.$mode.' provider read leaves history unchanged');
    $mode=null;$connect();
}
foreach(['duplicate','repeat_cursor'] as $mode) {
    $check($fails(fn()=>purepayObservationPage($pdo,1,$instance,25,$mode==='repeat_cursor'?'repeated':null)),'unsafe '.$mode.' list is refused');
}
$mode=null;
$check($fails(fn()=>purepayObservationPage($pdo,1,$instance,101)) && $fails(fn()=>purepayObservationPage($pdo,1,$instance,25,'bad cursor')),'page bounds and malformed cursors are rejected');
$check($fails(fn()=>purepayObservationRead($pdo,2,$instance,$owners,$run)),'foreign tenant cannot read provider payroll');
$check($fails(fn()=>purepayObservationRead($pdo,1,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',$owners,$run)),'foreign installation cannot read provider payroll');
$check($fails(fn()=>purepayObservationHistory($pdo,1,$instance,['people'=>11,'placements'=>20,'time'=>1],$run)),'saved history cannot be read under another directory owner');
$foreign=purepayObservationRead($pdo,1,$instance,['people'=>11,'placements'=>20,'time'=>1],$run);
$check($foreign['comparison']['people'][0]['person']===null,'authorized different directory never borrows another directory identity');
$context['capabilities']=['read:coreflux'];$check($fails($read) && $fails($history),'revoked payroll scope blocks live and saved payroll');
$context['capabilities']=['read:coreflux','read:payroll'];$connect('synthetic-rotated-observation-key');
$check(count($history()['items'])===3,'reviewed rotation preserves saved history for the same company');
$context['payroll_observation_contract_version']='future';$check($fails($read) && $fails($history),'unknown observation contract blocks new and saved observations');
$context['payroll_observation_contract_version']=PUREPAY_OBSERVATION_VERSION;
$pdo->prepare('UPDATE payroll_native_observations SET payload_ct=? WHERE id=?')->execute([encryptField('{"unexpected":"changed"}'),$first['id']]);
$check($fails($history),'damaged stored evidence is refused');
foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/174_payroll_native_observations.sql')) as $sql)$pdo->exec($sql);
$check($count()===3,'migration replay retains captures');
$tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($tables);
$check($tables===['core_record_numbers','integration_deliveries','integration_delivery_subjects','payroll_native_observations','people','purepay_core_connections'],'observation works without time, payroll, authority, payment or ledger tables');
$check(count(array_filter($requests,static fn($r)=>$r[0]!=='GET'))===0,'all provider operations are read-only');
unset($GLOBALS['__purepay_transport']);
echo "PurePayroll native observations MariaDB: {$passed} passed\n";
