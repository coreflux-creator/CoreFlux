<?php
/** Real encrypted captures/locks/audit rollback with only synthetic HTTP and records. */
declare(strict_types=1);
$ci=getenv('GITHUB_ACTIONS')==='true'&&getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1'&&in_array('--confirm-disposable-fixture',$argv,true)&&(string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if(!$ci&&!$local)throw new RuntimeException('Explicit disposable fixture required');
putenv('COREFLUX_DISABLE_DATABASE=1');putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));
require_once __DIR__.'/../core/integrations/purepay_financial_observations.php';
require_once __DIR__.'/../core/integrations/purepay_financial_line_links.php';
require_once __DIR__.'/../core/migrate.php';
require_once __DIR__.'/fixtures/staffing_dictionary_checks.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$ci?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$database='coreflux_financial_observations_'.getmypid();$pdo->exec('CREATE DATABASE '.$database);$pdo->exec('USE '.$database);
$passed=0;$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
$fails=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}};
$instance='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';$calls=[];$mode=null;$interleave=null;
$context=['contract_version'=>'2026-10-25.1','organization'=>['id'=>'org-a','name'=>'Synthetic finance'],'key_scope'=>'read','execution_authority'=>false];
$wire=[];foreach(['bill','invoice'] as $kind)$wire[$kind]=json_decode(file_get_contents(__DIR__.'/fixtures/purepay_financial_observations/'.$kind.'.json'),true,64,JSON_THROW_ON_ERROR);
$GLOBALS['__purepay_transport']=static function($method,$url)use(&$calls,&$wire,&$mode,&$interleave,&$context,$pdo):array{
    if($pdo->inTransaction()||$method!=='GET')throw new RuntimeException('Only provider reads outside locks');
    $path=parse_url($url,PHP_URL_PATH);$calls[]=$path;
    if($path==='/api/v1/context')return ['status'=>200,'body'=>json_encode($context)];
    $kind=str_contains($path,'/bills/')?'bill':'invoice';
    if($path!=='/api/v1/financial-observations/'.($kind==='bill'?'bills':'invoices').'/'.$kind.'-synthetic')throw new RuntimeException('Unexpected resource');
    $data=$wire[$kind];$data['observed_at']=$mode==='stale'?'2020-01-01T00:00:00+00:00':gmdate('c');
    if($mode==='wrong_org')$data['organization_id']='foreign';
    if($mode==='lost')throw new RuntimeException('Lost response');
    if($interleave){$fn=$interleave;$interleave=null;$fn();}
    return ['status'=>$mode==='missing'?404:200,'body'=>json_encode($data)];
};
$connect=static function(string $key='synthetic-finance-key')use($pdo):void{
    purepayStoreConnection(1,$key,'Synthetic finance',7,'read_only');$r=purepayFinancialReview($pdo,1);
    purepayFinancialConfirm($pdo,1,7,$r['review_hash'],$r['organization']['id']);
};
$read=static fn(string $kind='bill',?int $coreId=null)=>purepayFinancialObservationRead($pdo,1,$instance,$kind,$kind.'-synthetic',$coreId);
$audit=static function(array $meta)use($pdo):void{$pdo->prepare('INSERT INTO fixture_audit(meta) VALUES(?)')->execute([json_encode($meta)]);};
$save=static fn(array $r,?callable $fn=null)=>purepayFinancialObservationSave($pdo,1,7,$instance,$r['observation']['kind'],$r['observation']['document_id'],$r['comparison']['core_record']['id']??null,$r['review_hash'],$fn??$audit);
$history=static fn(?int $before=null)=>purepayFinancialObservationHistory($pdo,1,$instance,'bill','bill-synthetic',$before);
$count=static fn()=>(int)$pdo->query('SELECT COUNT(*) FROM purepay_financial_observations')->fetchColumn();
try {
    foreach(['146_purepay_rail.sql','176_purepay_financial_company.sql','183_purepay_financial_observations.sql','183_purepay_financial_observations.sql',
        '184_purepay_financial_line_links.sql','184_purepay_financial_line_links.sql'] as $name)
        foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$name)) as $sql)$pdo->exec($sql);
    $pdo->exec('CREATE TABLE fixture_audit(id INT AUTO_INCREMENT PRIMARY KEY,meta TEXT)');
    foreach(['ap_bills'=>'bill_number','billing_invoices'=>'invoice_number'] as $table=>$number) {
        $pdo->exec('CREATE TABLE '.$table.'(id INT PRIMARY KEY,tenant_id INT,'.$number.' VARCHAR(40),currency CHAR(3),status VARCHAR(40),subtotal DECIMAL(12,2),tax_total DECIMAL(12,2),total DECIMAL(12,2),amount_paid DECIMAL(12,2),amount_due DECIMAL(12,2))');
        $pdo->exec('INSERT INTO '.$table.' VALUES(1,1,"CORE-1","USD","partially_paid",100,0,99.99,60,39.99),(2,2,"FOREIGN","USD","paid",100,0,100,100,0)');
    }
    $check($fails($read),'unconnected company has no observation access');$connect();
    $initial=$read();$first=$save($initial);$check($count()===1&&!$first['replayed'],'explicit reviewed evidence captured');
    $row=$pdo->query('SELECT * FROM purepay_financial_observations')->fetch(PDO::FETCH_ASSOC);
    $check(!str_contains($row['payload_ct'],'bill-synthetic')&&purepayFinancialObservationJson(purepayFinancialObservationSaved($row,$initial['context'])['observation'])===purepayFinancialObservationJson($first['saved']['observation']),'evidence encrypted with authenticated readback');
    $check($save($read())['replayed']&&$count()===1,'lost acknowledgement replay creates no duplicate');
    $check((int)$pdo->query('SELECT COUNT(*) FROM fixture_audit')->fetchColumn()===1,'replay does not duplicate audit');
    $mapped=$read('bill',1);$check($mapped['comparison']['amounts'][0]['difference']==='0.01','selected company-local bill comparison is exact');
    $check($fails(fn()=>$read('bill',2)),'foreign Core bill cannot be compared');
    $check($fails(fn()=>$read('invoice',2)),'foreign Core invoice cannot be compared');
    $pdo->exec('UPDATE ap_bills SET total=100 WHERE id=1');
    $check($fails(fn()=>$save($mapped))&&$count()===1,'Core edit invalidates prior review');
    $mapped=$read('bill',1);
    $check($fails(fn()=>$save($mapped,static function(){throw new RuntimeException('Audit unavailable');}))&&$count()===1,'audit failure rolls back capture');
    $second=$save($mapped);$check($count()===2,'changed comparison appends immutable evidence');
    $check($history()['items'][0]['id']===$second['id']&&$history($second['id'])['items'][0]['id']===$first['id'],'bounded history reaches prior evidence');
    $inv=$save($read('invoice',1));$check($count()===3&&$inv['saved']['observation']['outgoing_payments']===[],'invoice collections remain separate from outgoing AP payments');
    foreach(['stale','wrong_org','lost','missing'] as $mode)$check($fails($read)&&$count()===3,$mode.' source leaves captures unchanged');$mode=null;
    $before=$read();$wire['bill']['document']['amount']='90.00';$wire['bill']['observation_hash']=purepayFinancialObservationHash($wire['bill']);
    $check($fails(fn()=>$save($before)),'changed provider figures invalidate prior review');
    $new=$read();$third=$save($new);$check($count()===4,'provider correction appends history');
    $interleave=static function()use($pdo):void{$pdo->exec('UPDATE purepay_connections SET status="revoked" WHERE tenant_id=1');};
    $check($fails($read),'disconnect during provider read invalidates result');$connect();
    $before=$read();$connect('synthetic-finance-key-rotated');
    $check($fails(fn()=>$save($before)),'rotation invalidates unsaved review');
    $check($save($read())['replayed']&&$count()===4,'reviewed same-company rotation retains immutable capture identity');
    $check($fails(fn()=>purepayFinancialObservationHistory($pdo,1,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','bill','bill-synthetic')),'foreign installation cannot decrypt history');
    $check($fails(fn()=>purepayFinancialObservationRead($pdo,2,$instance,'bill','bill-synthetic')),'foreign tenant cannot borrow connection');
    $context['key_scope']='write';$check($fails($read)&&$fails($history),'provider permission drift blocks new and historical evidence');$context['key_scope']='read';
    $pdo->prepare('UPDATE purepay_financial_observations SET payload_ct=? WHERE id=?')->execute([encryptField('{"changed":true}'),$first['id']]);
    $check($history()['items'][0]['id']===$third['id'],'history does not load unrequested damaged old capture');
    $check($fails(fn()=>$history($second['id'])),'damaged requested evidence is refused');
    $wire['bill']=json_decode(file_get_contents(__DIR__.'/fixtures/purepay_financial_observations/bill-v2.json'),true,64,JSON_THROW_ON_ERROR);
    $modern=$save($read());
    $check($modern['saved']['observation']['contract_version']==='2026-10-30.2'&&$count()===5,'v2 source prose appends a separate encrypted capture');
    $check($save($read())['replayed']&&$count()===5,'unchanged v2 labels replay without duplicate evidence');
    $check($history()['items'][0]['saved']['observation']['document']['line_items'][0]['description']==="Sep’26\nService — synthetic only",'v2 source prose survives encrypted history');
    $check($history($modern['id'])['items'][0]['id']===$third['id'],'v1 evidence remains reachable after a v2 capture');
    $pdo->exec('CREATE TABLE people(id INT PRIMARY KEY,tenant_id INT,first_name VARCHAR(50),last_name VARCHAR(50),deleted_at DATETIME NULL)');
    $pdo->exec('CREATE TABLE placements(id INT PRIMARY KEY,tenant_id INT,person_id INT,title VARCHAR(100),status VARCHAR(30),start_date DATE,end_date DATE,actual_end_date DATE,deleted_at DATETIME NULL)');
    $pdo->exec('CREATE TABLE core_record_numbers(tenant_id INT,entity_type VARCHAR(20),record_id INT,canonical_record_id INT,record_number VARCHAR(40),UNIQUE(tenant_id,entity_type,record_number))');
    $pdo->exec('INSERT INTO people VALUES(1,1,"Synthetic","Person",NULL),(2,2,"Foreign","Person",NULL)');
    $pdo->exec('INSERT INTO placements VALUES(1,1,1,"Synthetic placement","Active","2026-01-01","2026-12-31",NULL,NULL),
        (2,2,2,"Foreign placement","Active","2026-01-01",NULL,NULL,NULL),(3,1,1,"Second placement","Active","2026-01-01",NULL,NULL,NULL)');
    $pdo->exec('INSERT INTO core_record_numbers VALUES(1,"person",1,1,"P-1"),(2,"person",2,2,"P-2"),
        (1,"placement",1,1,"PL-1"),(2,"placement",2,2,"PL-2"),(1,"placement",3,3,"PL-3")');
    $owners=['people'=>1,'placements'=>1];$lineId=$wire['bill']['document']['line_items'][0]['line_id'];$captureId=$modern['id'];
    $linePreview=static fn(string $number='PL-1')=>purepayFinancialLinePreview($pdo,1,$instance,$owners,$captureId,$lineId,$number);
    $lineSave=static fn(array $review,?callable $fn=null)=>purepayFinancialLineSave($pdo,1,7,$instance,$owners,$review['capture_id'],$review['source']['line_id'],$review['target']['placement']['number'],$review['review_hash'],$fn??$audit);
    $lineCount=static fn()=>(int)$pdo->query('SELECT COUNT(*) FROM purepay_financial_line_links')->fetchColumn();
    $preview=$linePreview();
    $check($preview['target']['person']['number']==='P-1'&&$preview['basis']==='reviewer_confirmed_document_line'
        &&$preview['future_item_rule']===false&&$lineCount()===0,'source-line preview resolves canonical placement/person without a write or future rule');
    $check($fails(fn()=>$linePreview('PL-2')),'foreign placement number cannot be associated');
    $check($fails(fn()=>purepayFinancialLinePreview($pdo,1,$instance,['people'=>2,'placements'=>1],$captureId,$lineId,'PL-1')),'wrong authorized person directory cannot expose placement worker');
    $check($fails(fn()=>purepayFinancialLinePreview($pdo,2,$instance,$owners,$captureId,$lineId,'PL-1')),'foreign financial tenant cannot borrow captured line');
    $check($fails(fn()=>purepayFinancialLinePreview($pdo,1,$instance,$owners,$captureId,'missing-line','PL-1')),'unknown source line cannot be fabricated');
    $check($fails(fn()=>$lineSave($preview,static function(){throw new RuntimeException('Audit unavailable');}))&&$lineCount()===0,'association audit failure rolls back the link');
    $linked=$lineSave($preview);$check($lineCount()===1&&!$linked['replayed'],'confirmed source line appends a reviewed association');
    staffingDictionaryAssertPayload('purepay_financial_line_association','purepay',$linked['link']['saved']);
    $row=$pdo->query('SELECT * FROM purepay_financial_line_links')->fetch(PDO::FETCH_ASSOC);
    $check(!str_contains($row['payload_ct'],'Synthetic')&&purepayFinancialLineSaved($row,$preview['context'],$owners)['saved']['target']['placement']['number']==='PL-1','source association and person snapshot are encrypted');
    $check($lineSave($linePreview())['replayed']&&$lineCount()===1,'source association replay creates no duplicate');
    $check($fails(fn()=>$linePreview('PL-3'))&&$lineCount()===1,'same source line cannot silently switch to a different placement');
    $before=$linePreview();$pdo->exec('UPDATE placements SET title="Revised placement" WHERE id=1');
    $check($fails(fn()=>$lineSave($before))&&$lineCount()===1,'placement change invalidates the previous line review');
    $revised=$lineSave($linePreview());$check($lineCount()===2&&!$revised['replayed'],'reviewed placement correction appends evidence instead of overwriting history');
    $before=$linePreview();$connect('another-synthetic-rotation');
    $check($fails(fn()=>$lineSave($before)),'credential rotation invalidates pending source-line review');
    $check($lineSave($linePreview())['replayed']&&$lineCount()===2,'same-company rotation preserves association replay identity');
    $lh=purepayFinancialLineHistory($pdo,1,$instance,$owners,$captureId,$lineId);
    $older=purepayFinancialLineHistory($pdo,1,$instance,$owners,$captureId,$lineId,$lh['next_before']);
    $check(count($lh['items'])===1&&$lh['items'][0]['id']===$revised['link']['id']&&$older['items'][0]['id']===$linked['link']['id']
        &&$older['next_before']===null&&$lh['historical_evidence'],'bounded line association history reaches original and reviewed snapshots');
    $check($fails(fn()=>purepayFinancialLineHistory($pdo,1,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',$owners,$captureId,$lineId)),'foreign installation cannot read association history');
    $pdo->exec('UPDATE core_record_numbers SET canonical_record_id=3 WHERE entity_type="placement" AND record_id=1');
    $check($fails($linePreview),'merged placement number cannot silently redirect an association');
    $pdo->exec('UPDATE core_record_numbers SET canonical_record_id=1 WHERE entity_type="placement" AND record_id=1');
    $wire['bill']['document']['line_items'][0]['description']='Corrected source period';
    $wire['bill']['observation_hash']=purepayFinancialObservationHash($wire['bill']);
    $check($fails($linePreview)&&$lineCount()===2,'changed source description requires a new capture before association');
    $wire['bill']['document']['line_items'][0]['service_start']='2025-12-31';
    $wire['bill']['unavailable_fields']=purepayFinancialObservationMissing(array_diff_key($wire['bill'],array_flip(['unavailable_fields','observed_at','observation_hash'])));
    $wire['bill']['observation_hash']=purepayFinancialObservationHash($wire['bill']);
    $outside=$save($read());
    $check($fails(fn()=>purepayFinancialLinePreview($pdo,1,$instance,$owners,$outside['id'],$lineId,'PL-1')),'explicit service date outside placement is refused');
    $check($lineCount()===2,'failed source/date checks leave reviewed associations unchanged');
    $changed=$linked['link']['saved'];$changed['ledger_posted']=true;
    $damaged=$row;$damaged['payload_ct']=encryptField(purepayFinancialObservationJson($changed));
    $check($fails(fn()=>purepayFinancialLineSaved($damaged,$preview['context'],$owners)),'saved association cannot acquire posting authority');
    $check((int)$pdo->query('SELECT COUNT(*) FROM purepay_payment_links')->fetchColumn()===0,'capture creates no payment links');
    $check(!in_array('accounting_journal_entries',$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),true),'capture needs no posting or payroll tables');
    echo "Financial observations MariaDB: $passed passed\n";
}finally{unset($GLOBALS['__purepay_transport']);if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP DATABASE '.$database);}
