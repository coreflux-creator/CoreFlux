<?php
/** Actual mapping transactions; synthetic bills/credentials and mocked GETs only. */
declare(strict_types=1);
$ci=getenv('GITHUB_ACTIONS')==='true' && getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1' && in_array('--confirm-disposable-fixture',$argv,true) && (string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if (!$ci&&!$local) throw new RuntimeException('Explicit disposable fixture required');
putenv('COREFLUX_DISABLE_DATABASE=1');putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));
require_once __DIR__.'/../core/purepay_bill_identity.php';
require_once __DIR__.'/../core/migrate.php';
$password=$ci?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$password,$options);
$database='coreflux_bill_identity_'.getmypid();$pdo->exec('CREATE DATABASE '.$database);$pdo->exec('USE '.$database);
$checks=0;$check=static function(bool $ok,string $label)use(&$checks):void {if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS '.$label."\n";};
$fails=static function(callable $fn):bool {try{$fn();return false;}catch(Throwable $e){return true;}};
$key='fixture-bill-key';$rotated='fixture-bill-key-rotated';
$context=['contract_version'=>'2026-10-25.1','organization'=>['id'=>'org-a','name'=>'Synthetic Alpha'],'key_scope'=>'read','execution_authority'=>false];
$remote=['id'=>'bill-a','organization_id'=>'org-a','vendor_id'=>'vendor-a','vendor_name'=>'Synthetic supplier',
    'invoice_number'=>'SUP-42','amount'=>'125.00','currency'=>'USD','bill_date'=>'2026-10-01','due_date'=>'2026-10-31','status'=>'ready_to_pay'];
$vendor=['id'=>'vendor-a','organization_id'=>'org-a','display_name'=>'Synthetic supplier','email'=>'supplier@example.test',
    'bank_account'=>'not-for-review','tax_identifier'=>'not-for-review'];
$calls=[];$interleave=null;$failure=false;
$GLOBALS['__purepay_transport']=static function($method,$url)use(&$remote,&$vendor,&$context,&$calls,&$interleave,&$failure,$pdo):array {
    if($method!=='GET' || $pdo->inTransaction()) throw new RuntimeException('Read-only provider IO must be outside transactions');
    $path=parse_url($url,PHP_URL_PATH);$calls[]=$path;
    $body=match($path){'/api/v1/context'=>$context,'/api/v1/bills/bill-a','/api/v1/bills/bill-b','/api/v1/bills/bill-race'=>$remote,
        '/api/v1/vendors/vendor-a'=>$vendor,default=>throw new RuntimeException('Unexpected provider route')};
    if($interleave!==null){$fn=$interleave;$interleave=null;$fn();}
    if($failure) return ['status'=>500,'body'=>json_encode(['detail'=>'private provider error'])];
    return ['status'=>200,'body'=>json_encode($body)];
};
$audits=[];$audit=static function(array $meta)use(&$audits){$audits[]=$meta;};
$review=static fn(int $id=1,string $provider='bill-a',int $tenant=1)=>purepayBillReview($pdo,$tenant,$id,$provider);
$confirm=static fn(array $r,int $id=1,string $provider='bill-a',?callable $a=null)=>purepayBillConfirm($pdo,1,7,$id,$provider,$r['review_hash'],$a??$audit);
$connect=static function(string $token)use($pdo):void{
    purepayStoreConnection(1,$token,'Synthetic company',7,'read_only');$r=purepayFinancialReview($pdo,1);
    purepayFinancialConfirm($pdo,1,7,$r['review_hash'],$r['organization']['id']);
};
try {
    foreach(['146_purepay_rail.sql','163_purepay_attempt_binding.sql','176_purepay_financial_company.sql','177_purepay_bill_identity.sql','177_purepay_bill_identity.sql']as$file)
        foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file))as$sql)$pdo->exec($sql);
    foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../modules/ap/migrations/001_init.sql'))as$sql)
        if(preg_match('/CREATE TABLE IF NOT EXISTS (ap_bills|ap_vendors_index|ap_payments|ap_payment_allocations)\s*\(/',$sql))$pdo->exec($sql);
    $pdo->exec('ALTER TABLE ap_bills ADD COLUMN entity_id BIGINT UNSIGNED NULL');
    $pdo->exec('ALTER TABLE ap_vendors_index ADD COLUMN remit_to_email VARCHAR(255), ADD COLUMN contact_email VARCHAR(255)');
    $pdo->exec('CREATE TABLE accounting_entities(id BIGINT UNSIGNED PRIMARY KEY,tenant_id BIGINT UNSIGNED,legal_name VARCHAR(255))');
    $pdo->exec('INSERT INTO accounting_entities VALUES(11,1,"Synthetic Alpha LLC"),(22,2,"Synthetic Beta LLC")');
    $pdo->exec('INSERT INTO ap_vendors_index(id,tenant_id,vendor_name,remit_to_email) VALUES(10,1,"Synthetic supplier","supplier@example.test"),(20,2,"Synthetic supplier","supplier@example.test")');
    $insert=static function(int $id,int $tenant=1,int $entity=11)use($pdo):void {
        $pdo->prepare('INSERT INTO ap_bills(id,tenant_id,entity_id,bill_number,internal_ref,vendor_name,received_at,bill_date,due_date,currency,total,amount_due,status)
            VALUES(?,?,?,"SUP-42",?,"Synthetic supplier","2026-10-01","2026-10-01","2026-10-31","USD",125,125,"approved")')
            ->execute([$id,$tenant,$entity,'AP-'.$id]);
    };
    $insert(1);$insert(2);$insert(3);$insert(4,2,22);$insert(5,1,22);$insert(6);
    $check($fails(fn()=>$review()),'unconfirmed financial company cannot read provider bills');
    $connect($key);$r=$review();
    $check($r['snapshot']['core']['vendor_id']===10 && $r['snapshot']['provider']['vendor_id']==='vendor-a'
        && !$r['payment_authority'] && !$r['reservation_created'],'review presents original bill and stable vendor identities without payment authority');
    $check(!str_contains(json_encode($r),'not-for-review'),'bank and tax values never enter the displayed or saved snapshot');
    $check((int)$pdo->query('SELECT COUNT(*) FROM purepay_bill_mappings')->fetchColumn()===0,'preview creates no bill link');
    $check($fails(fn()=>$review(4,'bill-a',1)) && $fails(fn()=>$review(1,'bill-a',2)),'Core company boundary blocks foreign bills');
    $check($fails(fn()=>$review(5)),'foreign legal entity cannot be linked');
    $before=count($calls);$check($fails(fn()=>$review(1,'../bills/x')) && count($calls)===$before,'invalid provider ID rejected before IO');
    $baseline=$remote;
    foreach(['currency'=>null,'amount'=>'125.001','invoice_number'=>'SUP-43','bill_date'=>'2026-02-30','due_date'=>'2026-11-01',
        'organization_id'=>'org-foreign','id'=>'other-id','bill_id'=>'other-alias','vendor_name'=>'Another supplier','status'=>'void']as$field=>$bad){
        $remote=array_replace($baseline,[$field=>$bad]);$check($fails(fn()=>$review()),'reject mismatched or missing '.$field);$remote=$baseline;
    }
    $vendor['organization_id']='org-foreign';$check($fails(fn()=>$review()),'provider vendor must belong to the reviewed company');$vendor['organization_id']='org-a';
    $vendor['email']='changed@example.test';$check($fails(fn()=>$review()),'different vendor remit email blocks identity confirmation');$vendor['email']='supplier@example.test';
    $pdo->exec('UPDATE ap_bills SET status="pending_approval" WHERE id=1');$check($fails(fn()=>$review()),'unapproved Core bill is not linkable');$pdo->exec('UPDATE ap_bills SET status="approved" WHERE id=1');
    $interleave=static fn()=>$pdo->exec('UPDATE ap_bills SET total=126 WHERE id=1');
    $check($fails(fn()=>$review()),'Core change during provider reads invalidates the review');$pdo->exec('UPDATE ap_bills SET total=125 WHERE id=1');
    $r=$review();$remote['status']='processing';
    $check($fails(fn()=>$confirm($r)),'provider state change after display requires fresh review');$remote=$baseline;
    $r=$review();$check($fails(fn()=>purepayBillConfirm($pdo,1,0,1,'bill-a',$r['review_hash'],$audit)),'confirmation requires an identified administrator');
    $badAudit=static function(){throw new RuntimeException('Audit unavailable');};
    $check($fails(fn()=>$confirm($r,1,'bill-a',$badAudit)) && (int)$pdo->query('SELECT COUNT(*) FROM purepay_bill_mappings')->fetchColumn()===0
        && (int)$pdo->query('SELECT COUNT(*) FROM purepay_bill_mapping_reviews')->fetchColumn()===0,'audit failure rolls back identity and history together');
    $confirm($r);$original=purepayBillMapping($pdo,1,1);$confirm($r);
    $check(purepayBillMapping($pdo,1,1)===$original && count($audits)===1
        && (int)$pdo->query('SELECT COUNT(*) FROM purepay_bill_mapping_reviews')->fetchColumn()===1,'identical confirmation replay preserves the original reviewer and one history record');
    $remote['status']='processing';$confirm($review());$remote=$baseline;$confirm($review());
    $check(count($audits)===3 && purepayBillMapping($pdo,1,1)['review_hash']===$original['review_hash']
        && (int)$pdo->query('SELECT COUNT(*) FROM purepay_bill_mapping_reviews')->fetchColumn()===2,
        'returning to an earlier snapshot records a new confirmation audit without duplicating snapshot history');
    $check($fails(fn()=>$confirm($review(2),2)) && purepayBillMapping($pdo,1,2)===null,'one provider bill cannot represent two Core bills');
    $remote['id']='bill-b';$check($fails(fn()=>$review(1,'bill-b')),'saved Core bill cannot be retargeted to another provider bill');$remote=$baseline;
    $before=count($calls);$summary=purepayBillSummary($pdo,1,1);
    $check($summary['linked'] && $summary['local_review_current'] && !$summary['provider_rechecked'] && count($calls)===$before,'saved identity summary does not claim fresh provider evidence');
    $pdo->exec('UPDATE ap_bills SET entity_id=22 WHERE id=1');
    $check(!purepayBillSummary($pdo,1,1)['local_review_current'] && $fails(fn()=>$review()),'entity changes invalidate local review without erasing the saved identity');$pdo->exec('UPDATE ap_bills SET entity_id=11 WHERE id=1');
    $r=$review();$connect($rotated);
    $check(!purepayBillSummary($pdo,1,1)['local_review_current'] && $fails(fn()=>$confirm($r)),'rotation invalidates displayed review while preserving the bill identity');
    $confirm($review());$next=purepayBillMapping($pdo,1,1);
    $check($next['purepay_bill_id']===$original['purepay_bill_id'] && $next['purepay_vendor_id']===$original['purepay_vendor_id']
        && (int)$pdo->query('SELECT COUNT(*) FROM purepay_bill_mapping_reviews')->fetchColumn()===3,'same-company rotation records a new review without replacing original identities or history');
    purepayRevokeConnection(1);$check(purepayBillSummary($pdo,1,1)['linked'] && $fails(fn()=>$review()),'disconnect retains the permanent bill claim and prevents new provider reads');$connect($rotated);
    $failure=true;try{$review();throw new RuntimeException('Expected failure');}catch(PurePayApiException $e){$check(!str_contains($e->getMessage(),'private provider error'),'provider error payload is not exposed');}$failure=false;

    // Independent SQL sessions contend on the actual unique provider bill claim.
    $other=new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database.';charset=utf8mb4','root',$password,$options);
    $other->beginTransaction();
    $other->prepare('INSERT INTO purepay_bill_mappings SELECT tenant_id,6,core_vendor_id,entity_id,origin_hash,organization_id,"bill-race",purepay_vendor_id,review_hash,review_json,reviewed_by,reviewed_at
        FROM purepay_bill_mappings WHERE tenant_id=1 AND core_bill_id=1')->execute();
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=1');$remote['id']='bill-race';$r3=$review(3,'bill-race');
    $check($fails(fn()=>$confirm($r3,3,'bill-race')) && !$pdo->inTransaction(),'concurrent claim waits then rolls back without a second mapping');
    $other->commit();$check($fails(fn()=>$confirm($review(3,'bill-race'),3,'bill-race')),'committed competing claim stays exclusive');
    $check((int)$pdo->query('SELECT COUNT(*) FROM purepay_payment_links')->fetchColumn()===0
        && (int)$pdo->query('SELECT COUNT(*) FROM ap_payments')->fetchColumn()===0
        && (int)$pdo->query('SELECT COUNT(*) FROM ap_payment_allocations')->fetchColumn()===0
        && (int)$pdo->query('SELECT COUNT(*) FROM ap_bills WHERE amount_due<>125 OR amount_paid<>0')->fetchColumn()===0,
        'linking changes no payment attempts, allocations, balances or financial records');
    echo "PurePay bill identity: {$checks} passed\n";
}finally{
    if(isset($other)&&$other->inTransaction())$other->rollBack();
    if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP DATABASE '.$database);
}
