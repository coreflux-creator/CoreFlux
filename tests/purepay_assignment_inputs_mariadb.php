<?php
/** Disposable SQL fixture with real graph DDL and a closed, synthetic provider transport. */
declare(strict_types=1);
$inCi=getenv('GITHUB_ACTIONS')==='true' && getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1' && in_array('--confirm-disposable-fixture',$argv,true) && (string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if(!$inCi&&!$local)throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DISABLE_DATABASE=1');putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));
require_once __DIR__.'/purepay_assignment_fixture.php';
require_once __DIR__.'/../core/integrations/purepay_assignment_inputs.php';
require_once __DIR__.'/../core/migrate.php';
$password=$inCi?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$password,$options);
$db='coreflux_assignment_input_'.($inCi?'ci':gmdate('YmdHis').'_'.getmypid());
$pdo->exec('CREATE DATABASE '.$db.' CHARACTER SET utf8mb4');$pdo->exec('USE '.$db);
$observer=new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$db.';charset=utf8mb4','root',$password,$options);
foreach(['158_integration_deliveries.sql','162_integration_delivery_receipt.sql','164_purepay_core_connection.sql'] as $file)
    foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file)) as $sql)$pdo->exec($sql);
$fixture=purepayAssignmentFixtureRows();
$insert=static function(string $table,array $row)use($pdo):void {
    $columns=array_keys($row);
    $pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($row));
};
$table=static function(string $name,array $row)use($pdo):void {
    $fields=[];
    foreach($row as $key=>$value) {
        $type=($key==='id' || (str_ends_with($key,'_id') && !in_array($key,['external_id','jobdiva_job_id'],true)) || $key==='superseded_by')?'BIGINT':
            (str_ends_with($key,'_pct')?'DECIMAL(8,6)':(in_array($key,['pay_rate','bill_rate'],true)?'DECIMAL(10,4)':
            (str_ends_with($key,'_multiplier')?'DECIMAL(4,2)':'LONGTEXT')));
        $fields[]='`'.$key.'` '.$type.($key==='id'?' PRIMARY KEY':' NULL');
    }
    $pdo->exec('CREATE TABLE '.$name.' ('.implode(',',$fields).')');
};
$placement=array_diff_key($fixture['placement'],['billing_company_id'=>true])+['tenant_id'=>20,'deleted_at'=>null];$table('placements',$placement);$insert('placements',$placement);
$person=$fixture['person']+['tenant_id'=>10,'deleted_at'=>null];$table('people',$person);$insert('people',$person);
$pdo->exec('CREATE TABLE staffing_clients(id BIGINT PRIMARY KEY,tenant_id BIGINT,company_id BIGINT)');$insert('staffing_clients',['id'=>1,'tenant_id'=>20,'company_id'=>10]);
$pdo->exec('CREATE TABLE companies(id BIGINT PRIMARY KEY,tenant_id BIGINT)');$insert('companies',['id'=>10,'tenant_id'=>10]);$insert('companies',['id'=>11,'tenant_id'=>10]);
$pdo->exec('CREATE TABLE ap_vendors_index(id BIGINT PRIMARY KEY,tenant_id BIGINT,default_terms VARCHAR(40),default_pwp TINYINT)');
$insert('ap_vendors_index',['id'=>1,'tenant_id'=>30,'default_terms'=>'NET5','default_pwp'=>1]);
$pdo->exec('CREATE TABLE core_record_numbers(tenant_id BIGINT,entity_type VARCHAR(32),record_id BIGINT,canonical_record_id BIGINT,record_number VARCHAR(64))');
foreach([[10,'person',9001,'P-9001'],[20,'placement',901,'PL-901'],[20,'client',1,'CL-1'],[10,'company',10,'CO-10'],[10,'company',11,'CO-11'],[30,'vendor',1,'V-1']] as [$owner,$kind,$id,$number])
    $insert('core_record_numbers',['tenant_id'=>$owner,'entity_type'=>$kind,'record_id'=>$id,'canonical_record_id'=>$id,'record_number'=>$number]);
$table('placement_rates',$fixture['rates'][0]);
foreach($fixture['rates'] as $rate){$rate['tenant_id']=20;$insert('placement_rates',$rate);}
foreach(['126_staffing_economic_graph.sql','138_placement_one_time_items.sql'] as $file) {
    foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file)) as $sql)
        if(preg_match('/CREATE TABLE IF NOT EXISTS (staffing_operating_cycles|placement_economic_parties|placement_economic_items)\s*\(/',$sql))$pdo->exec($sql);
}
foreach($fixture['cycles'] as $row)$insert('staffing_operating_cycles',$row+['tenant_id'=>20]);
foreach($fixture['parties'] as $row)$insert('placement_economic_parties',array_diff_key($row,array_flip(['vendor_default_terms','vendor_default_pwp']))+['tenant_id'=>20,'placement_id'=>901]);
foreach($fixture['items'] as $row)$insert('placement_economic_items',$row+['tenant_id'=>20,'placement_id'=>901]);
$passed=0;$posts=0;$audits=0;$receipts=[];$mode='ok';
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo 'PASS '.$label."\n";};
$fails=static function(callable $f):bool{try{$f();return false;}catch(Throwable){return true;}};
$instance='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';$owners=['people'=>10,'placements'=>20,'ap'=>30];
$context=['organization'=>['id'=>'org-a','name'=>'Synthetic company'],'contract_version'=>PUREPAY_CORE_VERSION,'link_contract_version'=>PUREPAY_CORE_LINK_VERSION,
    'assignment_input_contract_version'=>PUREPAY_ASSIGNMENT_INPUT_VERSION,'capabilities'=>['read:coreflux','read:payroll','write:assignment_inputs'],'execution_side_effects'=>false];
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$json)use(&$context,&$posts,&$receipts,&$mode,$observer,$check):array {
    $path=substr(parse_url($url,PHP_URL_PATH),strlen('/api/v1'.PUREPAY_CORE_PATH));
    $response=static fn(array $body,int $status=200):array=>['status'=>$status,'body'=>json_encode($body)];
    if($method==='GET' && $path==='/context')return $response($context);
    if($method==='GET' && str_starts_with($path,'/assignments/inputs/'))return isset($receipts[basename($path)])?$response($receipts[basename($path)]):$response([],404);
    if($method==='POST' && $path==='/assignments/inputs') {
        $posts++;$body=json_decode($json,true,64,JSON_THROW_ON_ERROR);$hash=integrationDeliveryHash($body);
        $check(in_array('Idempotency-Key: '.$hash,$headers,true),'exact commercial content hash on wire');
        $check((int)$observer->query('SELECT COUNT(*) FROM integration_deliveries WHERE operation="assignments.inputs" AND state="sending"')->fetchColumn()===1,'attempt committed before provider call');
        $id='cpa_'.integrationDeliveryHash(['org-a',$hash]);$receipt=['id'=>$id,'organization_id'=>'org-a','content_hash'=>$hash];
        $receipts[$id]=$receipt+['packet'=>$body,'state'=>'ready','assignment_id'=>null,'assignment_status'=>null,'accepted_revision'=>null,'existing_link'=>null];
        if($mode==='lost')throw new RuntimeException('Synthetic lost response');
        return $response($receipt);
    }
    throw new RuntimeException('Unexpected provider transport request');
};
purepayCoreConnect($pdo,1,'synthetic-commercial-key',$instance);
$review=purepayCoreReview($pdo,1,$instance);purepayCoreConfirm($pdo,1,7,$instance,$review['review_hash'],'org-a');
$source=purepayAssignmentInputSource($pdo,$instance,$owners,'PL-901');
$body=$source['body'];
$check($body['person']['directory_tenant_id']===10 && $body['client']['directory_tenant_id']===20
    && $body['current_commercial_context']['parties'][2]['roots']['vendor']['directory_tenant_id']===30,'every canonical reference uses its authorized directory owner');
$check($body['terms'][0]['economics']['parties'][0]['cycle']['cadence']==='weekly' && $body['current_commercial_context']['cycles'][0]['cadence']==='monthly','SQL source preserves frozen versus current cycles');
$check($body['terms'][0]['pay_rates']['overtime']==='63.187500','SQL decimals retain exact overtime precision');
$check($fails(fn()=>purepayAssignmentInputSource($pdo,$instance,['people'=>11,'placements'=>20,'ap'=>30],'PL-901')),'foreign people owner refused');
$check($fails(fn()=>purepayAssignmentInputSource($pdo,$instance,['people'=>10,'placements'=>20,'ap'=>31],'PL-901')),'foreign vendor owner refused');
$pdo->exec("UPDATE core_record_numbers SET canonical_record_id=99 WHERE entity_type='vendor'");
$check($fails(fn()=>purepayAssignmentInputSource($pdo,$instance,$owners,'PL-901')),'merged vendor cannot be exported under an obsolete number');
$pdo->exec("UPDATE core_record_numbers SET canonical_record_id=record_id WHERE entity_type='vendor'");
$pdo->exec('UPDATE staffing_operating_cycles SET tenant_id=21 WHERE id=3');
$check($fails(fn()=>purepayAssignmentInputSource($pdo,$instance,$owners,'PL-901')),'foreign operating cycle refused');
$pdo->exec('UPDATE staffing_operating_cycles SET tenant_id=20 WHERE id=3');
$preview=static fn(?string $previous=null):array=>purepayAssignmentInputPreview($pdo,1,7,$instance,$owners,'PL-901',$previous);
$audit=static function()use(&$audits):void{$audits++;};
$send=static fn(array $r):array=>purepayAssignmentInputSend($pdo,1,7,$instance,$owners,(int)$r['delivery']['id'],$r['delivery']['review_hash'],$audit);
$status=static fn(array $r):array=>purepayAssignmentInputStatus($pdo,1,$instance,$owners,(int)$r['delivery']['id'],$r['delivery']['review_hash'],$audit);
putenv('COREFLUX_PUREPAY_ASSIGNMENT_DELIVERY');$r=$preview();
$check(!$r['delivery_enabled'] && $posts===0 && $fails(fn()=>$send($r)),'preview has no provider write and rollout is disabled by default');
putenv('COREFLUX_PUREPAY_ASSIGNMENT_DELIVERY=1');
$pdo->exec("UPDATE placement_economic_parties SET fee_pct=0.045 WHERE id=3");
$check($fails(fn()=>$send($r)) && $posts===0,'current fee drift blocks a stale commercial send');
$r=$preview();$mode='lost';$sent=$send($r);
$check($sent['state']==='uncertain' && $posts===1,'lost provider response is retained as uncertain');
$check($fails(fn()=>$send($r)) && $posts===1,'uncertain commercial attempt cannot resend blindly');
$mode='ok';$confirmed=$status($r);
$check($confirmed['delivery']['state']==='delivered' && $confirmed['provider_review']['state']==='ready' && $audits===1,'receipt recovery confirms delivery only');
$check($send($r)['state']==='delivered' && $posts===1 && $audits===1,'delivered retry does not post or audit twice');
$rid=$confirmed['delivery']['remote_id'];$packet=$receipts[$rid]['packet'];$native='asg_core_'.integrationDeliveryHash(['org-a',$packet['placement']]);
$receipts[$rid]['state']='created';$receipts[$rid]['assignment_id']=$native;$receipts[$rid]['assignment_status']='draft';$receipts[$rid]['accepted_revision']=$packet['source_revision'];
$receipts[$rid]['existing_link']=['id'=>'cfl_'.integrationDeliveryHash(['org-a',$packet['placement']]),'organization_id'=>'org-a','reference'=>$packet['placement'],
    'provider_id'=>$native,'provider_record_version'=>str_repeat('a',64)];
$check($status($r)['provider_review']['assignment_id']===$native,'native receipt validates exact PL identity and accepted revision');
$check((int)$pdo->query('SELECT COUNT(*) FROM integration_deliveries WHERE operation="record.link"')->fetchColumn()===0,'native creation never silently adopts the local Core link');
$receipts[$rid]['accepted_revision']=str_repeat('b',64);$check($fails(fn()=>$status($r)),'wrong accepted revision refused');$receipts[$rid]['accepted_revision']=$packet['source_revision'];
$receipts[$rid]['assignment_id']='foreign-native';$check($fails(fn()=>$status($r)),'foreign assignment identity refused');$receipts[$rid]['assignment_id']=$native;
$receipts[$rid]['packet']['terms'][0]['pay_rates']['regular']='99.0000';$check($fails(fn()=>$status($r)),'modified provider economics refused');$receipts[$rid]['packet']=$packet;
$check($fails(fn()=>purepayAssignmentInputStatus($pdo,2,$instance,$owners,(int)$r['delivery']['id'],$r['delivery']['review_hash'])),'another Core tenant cannot read the receipt');
$pdo->exec("UPDATE placement_economic_parties SET fee_pct=0.050 WHERE id=3");$revision=$preview($packet['source_revision']);
$check($revision['packet']['previous_revision']===$packet['source_revision'] && $revision['packet']['source_revision']!==$packet['source_revision'],'revision binds explicit predecessor to changed source');
$history=purepayAssignmentInputHistory($pdo,1,$instance,$owners,'PL-901');
$check(count($history['items'])>=3 && integrationDeliveryHash($history['items'][0]['packet'])===integrationDeliveryHash($revision['packet']),'history returns complete immutable review packets');
$context['organization']['id']='org-b';$check($fails(fn()=>$send($revision)),'changed provider company blocks delivery');
echo "PASS $passed scoped commercial delivery checks\n";
$pdo->exec('DROP DATABASE '.$db);
