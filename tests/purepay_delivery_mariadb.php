<?php
/** Historical receipts remain reconcilable after new dispatch moves to original-bill allocations. */
declare(strict_types=1);
$inCi=getenv('GITHUB_ACTIONS')==='true' && getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1' && in_array('--confirm-disposable-fixture',$argv,true) && (string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if(!$inCi&&!$local)throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/purepay_service.php';
require_once __DIR__.'/../core/payment_rails/purepay_driver.php';
require_once __DIR__.'/../core/purepay_webhooks.php';
require_once __DIR__.'/../core/migrate.php';
require_once __DIR__.'/../modules/ap/lib/ap.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$inCi?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db='coreflux_purepay_'.($inCi?'ci':gmdate('YmdHis').'_'.getmypid());
$pdo->exec('CREATE DATABASE '.$db);$pdo->exec('USE '.$db);
foreach(['146_purepay_rail.sql','163_purepay_attempt_binding.sql','175_purepay_payment_receipt_owner.sql','176_purepay_financial_company.sql']as$file)foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file))as$sql)$pdo->exec($sql);
foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../modules/ap/migrations/001_init.sql'))as$sql) {
    if(preg_match('/CREATE TABLE IF NOT EXISTS (ap_bills|ap_payments|ap_payment_allocations)\s*\(/',$sql))$pdo->exec($sql);
}
$pdo->exec('ALTER TABLE ap_payments ADD entity_id BIGINT NULL, ADD disbursement_rail VARCHAR(40) NULL,
    ADD rail_external_ref VARCHAR(160) NULL, ADD rail_status VARCHAR(40) NULL, ADD rail_originated_at DATETIME NULL');
$pdo->exec('ALTER TABLE ap_bills ADD entity_id BIGINT NULL, ADD opening_cutover_id BIGINT NULL, ADD amount_credited DECIMAL(12,2) NOT NULL DEFAULT 0, ADD pwp_status VARCHAR(40) NULL');
// Empty ledger tables allow native cancellation/refresh checks; this fixture never posts money.
$pdo->exec('CREATE TABLE accounting_subledger_links (tenant_id BIGINT,source_module VARCHAR(40),source_record_id VARCHAR(160))');
$pdo->exec('CREATE TABLE accounting_events (tenant_id BIGINT,source_module VARCHAR(40),source_record_id VARCHAR(160),status VARCHAR(40))');
$pdo->exec('CREATE TABLE accounting_journal_entries (id BIGINT,tenant_id BIGINT,status VARCHAR(40))');
$pdo->exec('CREATE TABLE placement_economic_obligations (tenant_id BIGINT,ap_bill_id BIGINT,status VARCHAR(40))');
$seedAp=static function(int $id,int $tenant=1,string $name='Synthetic Vendor')use($pdo):void {
    $pdo->prepare('INSERT INTO ap_bills(id,tenant_id,entity_id,bill_number,internal_ref,vendor_name,received_at,bill_date,due_date,total,amount_due,status)
        VALUES (?,?,11,?,?,?,"2026-10-01","2026-10-01","2026-10-31",125,125,"approved")')->execute([$id,$tenant,'SYN-'.$id,'SYN-'.$id,$name]);
    $pdo->prepare('INSERT INTO ap_payments(id,tenant_id,entity_id,vendor_name,pay_date,amount,unallocated_amount,status,disbursement_rail)
        VALUES (?,?,11,?,"2026-10-21",125,0,"sent","purepay")')->execute([$id,$tenant,$name]);
    $pdo->prepare('INSERT INTO ap_payment_allocations(payment_id,bill_id,amount_applied) VALUES (?,?,125)')->execute([$id,$id]);
};
foreach(array_merge(range(101,123),[140,141,142,143])as$id)$seedAp($id,1,$id===123?'Later Vendor':'Synthetic Vendor');
$seedAp(201,2);
$GLOBALS['__cf_request_tenant_id']=1;
$passed=0;$check=static function(bool $ok,string $label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS {$label}\n";};
$fails=static function(callable $f):bool{try{$f();return false;}catch(Throwable $e){return true;}};
$key='fixture-company-a';$otherKey='fixture-company-b';
$connection=$pdo->prepare('INSERT INTO purepay_connections(tenant_id,api_key_ct,api_key_last4,key_scope,status) VALUES (?,?,?,"read_write","active")');
foreach([1=>$key,2=>$otherKey]as$tenant=>$credential)$connection->execute([$tenant,encryptField($credential),substr($credential,-4)]);
$map=$pdo->prepare('INSERT INTO purepay_vendor_mappings(tenant_id,core_vendor_ref,purepay_vendor_id,vendor_name,vendor_email,verification_status,connection_fingerprint) VALUES (?,?,?,"Synthetic Vendor","vendor@example.test","verified",?)');
$map->execute([1,'vendor:1','vendor-1',purepayConnectionFingerprint(1,$key)]);
$map->execute([2,'vendor:1','vendor-1',purepayConnectionFingerprint(2,$otherKey)]);
$vendor=['id'=>'vendor-1','display_name'=>'Synthetic Vendor','email'=>'vendor@example.test'];
$item=['external_ref'=>'ap_payment:101','recipient_name'=>'Synthetic Vendor','recipient_ref'=>'vendor:1','recipient_email'=>'vendor@example.test','amount_cents'=>12500,'currency'=>'USD','invoice_number'=>'SYNTH-101'];
$opts=['tenant_id'=>1,'effective_date'=>'2026-10-21'];
$driver=new PurePayRailDriver();
$calls=[];$bills=[];$payments=[];$vendors=[$vendor];$mode='ok';$interleave=null;$preexisting=null;$detailOverride=[];$reusedBillId=null;
// Existing records are fixture inputs, not requests to create replacement bills.
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$json)use(&$calls,&$payments,&$mode,&$detailOverride,$pdo){
    $path=parse_url($url,PHP_URL_PATH);$calls[]=[$method,$path,[], $headers];
    if($method!=='GET' || $pdo->inTransaction())throw new RuntimeException('Historical reconciliation cannot mutate the provider or hold SQL locks');
    if($path==='/api/v1/context'){
        $tenant=str_contains($headers[0],'fixture-company-b')?2:1;
        $result=['contract_version'=>'2026-10-25.1','organization'=>['id'=>'org-'.$tenant,'name'=>'Synthetic '.$tenant],'key_scope'=>'write','execution_authority'=>false];
    } elseif(preg_match('~^/api/v1/bills/([^/]+)/payments$~',$path,$m)){
        if($mode==='scoped_failure')return ['status'=>503,'body'=>'{"detail":"Synthetic unavailable history"}'];
        $rows=[];foreach($payments as$p)if($p['bill_id']===$m[1])$rows[]=['id'=>$p['id'],'bill_id'=>$m[1],'status'=>$p['pay_status'],'amount'=>$p['amount'],'currency'=>$p['currency']];
        $result=['contract_version'=>'2026-10-25.1','organization_id'=>'org-1','bill_id'=>$m[1],'complete'=>$mode!=='scoped_incomplete',
            'total'=>count($rows),'items'=>$rows,'consistency'=>'rechecked_read_not_transactional_snapshot','observed_at'=>'2026-10-25T00:00:00Z'];
    } elseif(str_starts_with($path,'/api/v1/payments/')){
        if($mode==='detail_failure')return ['status'=>503,'body'=>'{"detail":"Synthetic unavailable detail"}'];
        if($mode==='detail_missing')return ['status'=>404,'body'=>'{"detail":"Synthetic missing detail"}'];
        $result=['payment'=>array_replace($payments[basename($path)]??[],$detailOverride)];
    } else throw new RuntimeException('Unexpected historical provider route '.$path);
    return ['status'=>200,'body'=>json_encode($result)];
};
foreach([1,2]as$tenant){$review=purepayFinancialReview($pdo,$tenant);purepayFinancialConfirm($pdo,$tenant,7,$review['review_hash'],$review['organization']['id']);}
$seedLegacy=static function(int $id,?string $bill=null,bool $hasReceipt=true)use($pdo,$key,&$payments):void{
    $bill=$bill??'historic-bill-'.$id;$payment='historic-payment-'.$id;
    $payments[$payment]=['id'=>$payment,'bill_id'=>$bill,'pay_status'=>'processing','amount'=>'125.00','currency'=>'USD'];
    $e=purepayPaymentEvidence($payments[$payment],12500,$bill,$payment);
    $pdo->prepare('INSERT INTO purepay_payment_links(tenant_id,core_payment_id,source_ref,purepay_bill_id,purepay_payment_id,amount_cents,status,request_fingerprint,connection_fingerprint,response_json)
        VALUES(1,?,?,?,?,12500,?,?,?,?)')->execute([$id,'ap_payment:'.$id,$bill,$hasReceipt?$payment:null,$hasReceipt?'processing':'needs_review',str_repeat('a',64),
            purepayConnectionFingerprint(1,$key),$hasReceipt?purepayPaymentReceipt($e,'detail'):null]);
};
$link=static function($ref,$tenant=1)use($pdo){$s=$pdo->prepare('SELECT * FROM purepay_payment_links WHERE tenant_id=? AND source_ref=?');$s->execute([$tenant,$ref]);return $s->fetch(PDO::FETCH_ASSOC);};
$send=static fn($value,$options=null)=>$driver->originate([$value],$options??$opts);
$payCount=static function()use(&$calls){return count(array_filter($calls,static fn($c)=>$c[0]==='POST'));};
$held=static function(callable $action,string $label)use($check):void{
    $message='';try{$action();}catch(DomainException $e){$message=$e->getMessage();}$check(str_contains($message,'PurePay'),$label);
};
$seedLegacy(101);$seedLegacy(103);$lostPayment=array_replace($item,['external_ref'=>'ap_payment:103']);
$calls=[];$before=count($calls);
$check($fails(fn()=>$send($item)) && count($calls)===$before,'historical dispatch cannot create or pay a substitute bill');
$billRef='purepay:bill:'.$link($item['external_ref'])['purepay_bill_id'];
$check($driver->getStatus($billRef)==='unknown' && $driver->getStatusForTenant(2,$billRef)==='unknown' && count($calls)===$before,'unscoped and foreign-company reads cannot select this receipt');
$check($driver->getStatusForTenant(1,$billRef)==='submitted','historical processing detail remains reconcilable');
$moneyId=$link($item['external_ref'])['purepay_payment_id'];$moneyRef=$billRef;
$payments[$moneyId]['pay_status']='settled';$validPayment=$payments[$moneyId];
$check($driver->getStatusForTenant(1,$moneyRef)==='settled','current historical detail establishes settlement');
$pdo->beginTransaction();$paymentRow=$pdo->query('SELECT * FROM ap_payments WHERE id=101 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
$check(apAssertProviderPaymentAction($pdo,1,$paymentRow,'clear'),'historical settlement retains normal ledger-clear checks');$pdo->rollBack();
$held(fn()=>apVoidPayment(1,101,'Synthetic settlement void'),'historical settled payment cannot be cancelled');
$payments[$moneyId]['pay_status']='processing';
$check($driver->getStatusForTenant(1,$moneyRef)==='settled' && purepayStoredPaymentEvidence($link($item['external_ref'])),'late processing response cannot downgrade historical settlement');
$badMoney=[['amount'=>'40.00'],['amount'=>null],['amount'=>'125.001'],['currency'=>null],['currency'=>'CAD'],
    ['amount_cents'=>125],['allocated'=>40],['partial'=>true],['allocations'=>[['amount'=>40]]],['payment_id'=>'conflicting-alias'],['pay_status'=>'settled','status'=>'failed'],['bill_id'=>'wrong']];
foreach($badMoney as$change){
    $payments[$moneyId]=$validPayment;$detailOverride=[];$driver->getStatusForTenant(1,$moneyRef);$priorReceipt=$link($item['external_ref'])['response_json'];
    $detailOverride=$change;
    $check($driver->getStatusForTenant(1,$moneyRef)==='unknown' && $link($item['external_ref'])['response_json']===$priorReceipt
        && !purepayStoredPaymentEvidence($link($item['external_ref'])),'mismatched historical money preserves the receipt and holds cached success');
    $held(fn()=>apClearPayment(1,101,'2026-10-21',null,null),'held historical evidence cannot clear AP');
    $held(fn()=>purepayApplyPaymentStatus(1,101,'settled',$pdo),'held historical evidence cannot project settlement');
}
$detailOverride=[];
foreach(['detail_failure','detail_missing']as$failure){$mode='ok';$driver->getStatusForTenant(1,$moneyRef);$mode=$failure;
    $check($driver->getStatusForTenant(1,$moneyRef)==='unknown','missing detail cannot use cached historical success');}
$mode='ok';$payments[$moneyId]=$validPayment+['gross_cents'=>12500,'net_cents'=>12000,'amount_cents'=>12000];
$check($driver->getStatusForTenant(1,$moneyRef)==='settled','consistent net transfer cents remain distinct from gross money');
$pdo->exec('UPDATE purepay_payment_links SET purepay_payment_id=NULL WHERE core_payment_id=101');$payments[$moneyId]=$validPayment;$detailOverride=['partial'=>true];$before=count($calls);
$check($driver->getStatusForTenant(1,$moneyRef)==='unknown' && $link($item['external_ref'])['purepay_payment_id']===null
    && in_array('/api/v1/payments/'.$moneyId,array_column(array_slice($calls,$before),1),true),'compact history cannot hide partial full detail');
$detailOverride=[];foreach(['scoped_failure','scoped_incomplete']as$failure){$mode=$failure;
    $check($driver->getStatusForTenant(1,$moneyRef)==='unknown' && $link($item['external_ref'])['purepay_payment_id']===null,'unverified history cannot adopt a payment');}
$mode='ok';$check($driver->getStatusForTenant(1,$moneyRef)==='settled','complete bill history recovers historical payment identity');
$pdo->prepare('UPDATE purepay_connections SET api_key_ct=? WHERE tenant_id=1')->execute([encryptField('rotated-fixture-key')]);$before=count($calls);
$check($driver->getStatusForTenant(1,$moneyRef)==='unknown' && count($calls)===$before,'rotation cannot infer ownership of historical money');
$held(fn()=>apClearPayment(1,101,'2026-10-21',null,null),'rotated connection holds historical clearance');
$pdo->prepare('UPDATE purepay_connections SET api_key_ct=? WHERE tenant_id=1')->execute([encryptField($key)]);$driver->getStatusForTenant(1,$moneyRef);
$hookData=['bill_id'=>'historic-bill-103','payment_id'=>'historic-payment-103','pay_status'=>'failed','amount'=>'125.00','currency'=>'USD'];
foreach([['amount'=>'40.00'],['currency'=>null],['allocated'=>40]]as$change){
    $out=purepayWebhookProcess(1,'bad-money','payment.failed',['data'=>array_replace($hookData,$change)],purepayConnectionFingerprint(1,$key));
    $check($out['outcome']==='needs_review' && $pdo->query('SELECT status FROM ap_payments WHERE id=103')->fetchColumn()==='sent','mismatched signed money cannot release historical AP');
    $held(fn()=>apVoidPayment(1,103,'Synthetic bad callback'),'unverified failure cannot cancel historical AP');
}
$out=purepayWebhookProcess(1,'good-money','payment.failed',['data'=>$hookData],purepayConnectionFingerprint(1,$key));
$check($out['outcome']==='updated' && $pdo->query('SELECT status FROM ap_payments WHERE id=103')->fetchColumn()==='failed','verified failure retains historical AP release behavior');
$pdo->exec('UPDATE ap_payments SET amount=126 WHERE id=103');$held(fn()=>apVoidPayment(1,103,'Changed amount'),'changed AP amount cannot use historical failure');
$pdo->exec('UPDATE ap_payments SET amount=125 WHERE id=103');apVoidPayment(1,103,'Reconciled synthetic failure');
$check($pdo->query('SELECT status FROM ap_payments WHERE id=103')->fetchColumn()==='void','verified historical failure supports explicit cancellation');
$check((int)$pdo->query('SELECT COUNT(*) FROM accounting_journal_entries')->fetchColumn()===0,'historical reconciliation never posts a journal');

$pdo->exec('UPDATE purepay_payment_links SET core_payment_id=NULL');
$hookBill=$link($item['external_ref'])['purepay_bill_id'];$hookPayment=$link($item['external_ref'])['purepay_payment_id'];
$hook=static fn($data,$event='payment.settled',$fingerprint=null)=>purepayWebhookProcess(1,'synthetic-event',$event,['data'=>$data+['pay_status'=>$event==='payment.settled'?'settled':'failed','amount'=>'125.00','currency'=>'USD']],$fingerprint??purepayConnectionFingerprint(1,$key));
$prior=$link($item['external_ref']);
foreach ([['pay_status'=>'processing'], ['pay_status'=>'created'], ['pay_status'=>null], ['pay_status'=>'failed','status'=>'processing']] as $badResult) {
    $check($hook($badResult+['bill_id'=>$hookBill,'payment_id'=>$hookPayment], 'payment.failed')['reason']==='payment_status_conflict'
        && $link($item['external_ref'])===$prior, 'inconsistent failure callback cannot release or change an in-flight attempt');
}
$check($hook(['bill_id'=>$hookBill,'payment_id'=>$hookPayment,'pay_status'=>'processing','mercury_request_id'=>'submitted-only'])['reason']==='payment_status_conflict'
    && $link($item['external_ref'])===$prior, 'Mercury request acknowledgement cannot establish settlement');
$check($hook(['bill_id'=>$hookBill])['reason']==='provider_identity_missing' && $link($item['external_ref'])===$prior,
    'terminal callback requires the actual payment as well as bill identity');
$check($hook(['bill_id'=>$hookBill,'payment_id'=>'wrong-payment'])['reason']==='provider_identity_conflict' && $link($item['external_ref'])===$prior,'signed callback cannot attach another payment to a known bill');
$otherBill=$link($lostPayment['external_ref'])['purepay_bill_id'];
$check($hook(['bill_id'=>$otherBill,'payment_id'=>$hookPayment])['reason']==='ambiguous_provider_identity','callback naming two different attempts requires reconciliation');
$check($hook(['bill_id'=>$hookBill,'payment_id'=>$hookPayment],'payment.settled',purepayConnectionFingerprint(1,'different-key'))['reason']==='connection_changed','callback verified before a connection change cannot update attempts');
$check($hook(['bill_id'=>$hookBill,'payment_id'=>$hookPayment])['status']==='settled' && !$pdo->inTransaction(),'matching signed callback commits settlement atomically');
$check($hook(['bill_id'=>$hookBill,'payment_id'=>$hookPayment],'payment.failed')['status']==='settled_failed_review','failure callback after settlement remains in review');
$check($hook(['bill_id'=>$hookBill,'payment_id'=>$hookPayment])['reason']==='terminal_result_requires_review','old settlement callback cannot erase unresolved failure review');
$pdo->prepare('UPDATE purepay_payment_links SET connection_fingerprint=NULL WHERE source_ref=?')->execute([$lostPayment['external_ref']]);
$check($hook(['bill_id'=>$otherBill,'payment_id'=>$link($lostPayment['external_ref'])['purepay_payment_id']])['reason']==='unverified_organization','callback cannot bind a historical payment to an inferred organization');
$reviewPayload=['id'=>'review-event','type'=>'payment.failed','data'=>['bill_id'=>$hookBill,'payment_id'=>$hookPayment,'pay_status'=>'processing']];
$check(purepayWebhookRecord(1,'review-event','payment.failed',true,null,time(),$reviewPayload,json_encode($reviewPayload)), 'verified event is persisted before projection');
purepayWebhookRecordOutcome(1,'review-event',$hook($reviewPayload['data'],'payment.failed'));
$event=$pdo->query('SELECT processed_at,processing_error FROM purepay_webhook_events WHERE tenant_id=1 AND event_id="review-event"')->fetch(PDO::FETCH_ASSOC);
$check($event['processed_at']===null && $event['processing_error']==='payment_status_conflict','inconsistent payment evidence remains visible for review rather than marked successful');
$check(purepayWebhookRecord(1,'review-event','payment.failed',true,null,time(),$reviewPayload,json_encode($reviewPayload)), 'held event remains available for reconciliation');
try {
    purepayWebhookRecord(1,'review-event','payment.failed',true,null,time(),$reviewPayload,json_encode($reviewPayload).' ');
    throw new RuntimeException('Conflicting event replay was accepted');
} catch (DomainException $expectedConflict) {
    $check(true,'same event ID cannot replace its originally signed content');
}
purepayWebhookRecordOutcome(1,'review-event',['outcome'=>'updated']);
$event=$pdo->query('SELECT processed_at,processing_error FROM purepay_webhook_events WHERE tenant_id=1 AND event_id="review-event"')->fetch(PDO::FETCH_ASSOC);
$check($event['processed_at']!==null && $event['processing_error']===null,'successful reconciliation marks the event processed');
$check(!purepayWebhookRecord(1,'review-event','payment.failed',true,null,time(),$reviewPayload,json_encode($reviewPayload)), 'completed identical replay is acknowledged without projection');
$pdo->prepare('UPDATE purepay_payment_links SET status="submitted" WHERE source_ref=?')->execute([$item['external_ref']]);
$confirmedRail=['bill_id'=>$hookBill,'payment_id'=>$hookPayment,'qbo_billpayment_id'=>'ledger-copy','moov_transfer_id'=>'moov-confirmed','moov_status'=>'completed'];
$check($hook($confirmedRail)['status']==='settled','rail-confirmed settlement remains valid with a QuickBooks bookkeeping reference');
$returned=$confirmedRail; $returned['pay_status']='returned'; $returned['moov_status']='reversed';
$check($hook($returned,'payment.returned')['status']==='settled_failed_review','a late return preserves the settled obligation for review');
$pdo->prepare('UPDATE purepay_payment_links SET status="submitted" WHERE source_ref=?')->execute([$item['external_ref']]);
$cancelled=$confirmedRail; $cancelled['pay_status']='cancelled'; $cancelled['moov_status']='canceled';
$check($hook($cancelled,'payment.cancelled')['status']==='cancelled','cancellation before settlement projects its distinct state');

// A provider may return its existing bill. Its payment cannot settle a second
// Core payment merely because the amount, bill and provider detail all match.
$seedAp(190);$ownerItem=array_replace($item,['external_ref'=>'ap_payment:190']);$seedLegacy(190);
$owner=$link('ap_payment:190');$ownerId=$owner['purepay_payment_id'];
$payments[$ownerId]['pay_status']='settled';
$check($driver->getStatusForTenant(1,'purepay:payment:'.$ownerId)==='settled','first receipt owner retains verified settlement');
$owner=$link('ap_payment:190');$seedAp(191);$secondItem=array_replace($item,['external_ref'=>'ap_payment:191']);$seedLegacy(191,$owner['purepay_bill_id'],false);unset($payments['historic-payment-191']);
$reusedBillId=$owner['purepay_bill_id'];$before=$payCount();
$check($fails(fn()=>$send($secondItem)) && $payCount()===$before,'a second Core payment cannot adopt an already owned provider payment or originate another');
$check($driver->getStatusForTenant(1,'purepay:payment:'.$ownerId)==='settled','owner stays readable without adopting the held duplicate');
$second=$link('ap_payment:191');
$check($second['purepay_payment_id']===null && $second['status']==='needs_review'
    && $link('ap_payment:190')===$owner,'conflict preserves the original receipt and leaves the second attempt held');
$held(fn()=>apVoidPayment(1,191,'Synthetic ownership conflict'),'receipt ownership conflict cannot release the second bill reservation');
$held(fn()=>apClearPayment(1,191,'2026-10-21',null,null),'receipt ownership conflict cannot clear the second AP payment');
$reusedBillId=null;

// Simulate pre-migration duplicate evidence in this disposable fixture. Both
// legacy receipts must be refused, and migration must preserve them for review.
$pdo->exec('ALTER TABLE purepay_payment_links DROP INDEX uq_pppl_payment_owner');
$pdo->prepare('UPDATE purepay_payment_links SET purepay_payment_id=?,status="settled",response_json=?,last_synced_at=NOW(),last_error=NULL,last_error_json=NULL WHERE tenant_id=1 AND source_ref="ap_payment:191"')
    ->execute([$ownerId,$owner['response_json']]);
$check(!purepayPaymentEvidenceOwned($pdo,1,$link('ap_payment:190')) && !purepayPaymentEvidenceOwned($pdo,1,$link('ap_payment:191')),
    'legacy duplicate receipts do not choose either owner');
$before=count($calls);
$check($fails(fn()=>$send($ownerItem)) && $fails(fn()=>$send($secondItem)) && count($calls)===$before,
    'legacy duplicate receipt cannot replay either Core attempt as successful');
foreach([190,191]as$id){
    $held(fn()=>apClearPayment(1,$id,'2026-10-21',null,null),'legacy shared settlement cannot clear either AP reservation');
}
$failedEvidence=purepayPaymentEvidence(['id'=>$ownerId,'bill_id'=>$owner['purepay_bill_id'],'status'=>'failed','amount'=>'125.00','currency'=>'USD'],12500,$owner['purepay_bill_id'],$ownerId);
$pdo->prepare('UPDATE purepay_payment_links SET status="failed",response_json=? WHERE tenant_id=1 AND source_ref IN ("ap_payment:190","ap_payment:191")')
    ->execute([purepayPaymentReceipt($failedEvidence,'detail')]);
foreach([190,191]as$id){
    $held(fn()=>apVoidPayment(1,$id,'Synthetic shared failure'),'legacy shared failure cannot release either AP reservation');
}
$pdo->prepare('UPDATE purepay_payment_links SET status="settled",response_json=? WHERE tenant_id=1 AND source_ref IN ("ap_payment:190","ap_payment:191")')
    ->execute([$owner['response_json']]);
$migration=coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/175_purepay_payment_receipt_owner.sql'));
$applyOwnerMigration=static function()use($pdo,$migration):void{foreach($migration as$sql)$pdo->exec($sql);};
$databaseError=static function(callable $action):int{try{$action();return 0;}catch(PDOException $error){return (int)($error->errorInfo[1]??0);}};
$legacyRows=$pdo->query('SELECT * FROM purepay_payment_links ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$check($databaseError($applyOwnerMigration)===1062 && $pdo->query('SELECT * FROM purepay_payment_links ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$legacyRows,
    'migration stops on historical duplicate receipts without rewriting evidence');
// This is fixture repair only, not automatic production reconciliation.
$pdo->exec('UPDATE purepay_payment_links SET purepay_payment_id=NULL,status="needs_review" WHERE tenant_id=1 AND source_ref="ap_payment:191"');
$applyOwnerMigration();$applyOwnerMigration();
$check(purepayPaymentEvidenceOwned($pdo,1,$owner),'migration is replayable after an explicit fixture conflict repair');
$check($databaseError(fn()=>$pdo->prepare('UPDATE purepay_payment_links SET purepay_payment_id=? WHERE tenant_id=1 AND source_ref="ap_payment:191"')->execute([$ownerId]))===1062,
    'database rejects a second owner even when application validation is bypassed');

// Two independent database sessions cannot race the same first receipt binding.
$otherPdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$db.';charset=utf8mb4','root',$inCi?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$otherPdo->exec('SET SESSION innodb_lock_wait_timeout=1');
$pdo->prepare('INSERT INTO purepay_payment_links(tenant_id,source_ref,amount_cents,status,request_fingerprint) VALUES(1,"race-owner-a",12500,"creating",?)')
    ->execute([str_repeat('a',64)]);
$pdo->prepare('INSERT INTO purepay_payment_links(tenant_id,source_ref,amount_cents,status,request_fingerprint) VALUES(1,"race-owner-b",12500,"creating",?)')
    ->execute([str_repeat('b',64)]);
$pdo->beginTransaction();
try{
    $pdo->exec('UPDATE purepay_payment_links SET purepay_payment_id="race-receipt" WHERE tenant_id=1 AND source_ref="race-owner-a"');
    $check($databaseError(fn()=>$otherPdo->exec('UPDATE purepay_payment_links SET purepay_payment_id="race-receipt" WHERE tenant_id=1 AND source_ref="race-owner-b"'))===1205,
        'second session cannot bind a provider receipt while its first owner is committing');
    $pdo->commit();
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
$check($databaseError(fn()=>$otherPdo->exec('UPDATE purepay_payment_links SET purepay_payment_id="race-receipt" WHERE tenant_id=1 AND source_ref="race-owner-b"'))===1062,
    'committed owner remains exclusive across sessions');
$otherPdo->exec('UPDATE purepay_payment_links SET purepay_payment_id="RACE-RECEIPT" WHERE tenant_id=1 AND source_ref="race-owner-b"');
$check($otherPdo->query('SELECT purepay_payment_id FROM purepay_payment_links WHERE source_ref="race-owner-b"')->fetchColumn()==='RACE-RECEIPT',
    'provider receipt identity remains case sensitive');
$pdo->prepare('INSERT INTO purepay_payment_links(tenant_id,source_ref,purepay_payment_id,amount_cents,status,request_fingerprint) VALUES(2,"separate-company","race-receipt",12500,"creating",?)')
    ->execute([str_repeat('c',64)]);
$check(purepayPaymentEvidenceOwned($pdo,2,['source_ref'=>'separate-company','purepay_payment_id'=>'race-receipt']),
    'receipt namespace remains isolated by Core company');
$check((int)$pdo->query('SELECT COUNT(*) FROM accounting_journal_entries')->fetchColumn()===0,'receipt ownership checks never post a journal');
unset($GLOBALS['__purepay_transport']);
$check($payCount()===0,'historical reconciliation never issues a provider mutation');
$pdo->exec('DROP DATABASE '.$db);
echo "PurePay historical delivery MariaDB: {$passed} passed\n";
