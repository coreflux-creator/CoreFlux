<?php
/** Real encrypted captures/locks/audit rollback with only synthetic HTTP and records. */
declare(strict_types=1);
$ci=getenv('GITHUB_ACTIONS')==='true'&&getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1'&&in_array('--confirm-disposable-fixture',$argv,true)&&(string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if(!$ci&&!$local)throw new RuntimeException('Explicit disposable fixture required');
putenv('COREFLUX_DISABLE_DATABASE=1');putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));
require_once __DIR__.'/../core/integrations/purepay_financial_observations.php';
require_once __DIR__.'/../core/migrate.php';
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
    foreach(['146_purepay_rail.sql','176_purepay_financial_company.sql','183_purepay_financial_observations.sql','183_purepay_financial_observations.sql'] as $name)
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
    $check((int)$pdo->query('SELECT COUNT(*) FROM purepay_payment_links')->fetchColumn()===0,'capture creates no payment links');
    $check(!in_array('accounting_journal_entries',$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),true),'capture needs no posting or payroll tables');
    echo "Financial observations MariaDB: $passed passed\n";
}finally{unset($GLOBALS['__purepay_transport']);if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP DATABASE '.$database);}
