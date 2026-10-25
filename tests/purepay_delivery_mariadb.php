<?php
/** Real driver/state tests, isolated MariaDB and fake provider IO. Never uses app DB settings. */
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
foreach(['146_purepay_rail.sql','163_purepay_attempt_binding.sql']as$file)foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file))as$sql)$pdo->exec($sql);
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
$calls=[];$bills=[];$payments=[];$vendors=[$vendor];$mode='ok';$interleave=null;$preexisting=null;$detailOverride=[];
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$json)use(&$calls,&$bills,&$payments,&$vendors,&$mode,&$interleave,&$preexisting,&$detailOverride,$vendor,$pdo,$check){
    $path=parse_url($url,PHP_URL_PATH);$body=$json===null?[]:json_decode($json,true);$calls[]=[$method,$path,$body,$headers];
    if($method==='POST')$check(!$pdo->inTransaction(),'each provider mutation follows a committed durable claim');
    if($interleave!==null){$callback=$interleave;$interleave=null;$callback($method,$path);}
    if($method==='GET' && $path==='/api/v1/vendors'){
        parse_str(parse_url($url,PHP_URL_QUERY)??'', $query);
        $rows=$mode==='vendor_repeated_page'
            ? array_map(static fn($i)=>['id'=>'unrelated-vendor-'.$i],range(1,200))
            : array_slice($vendors,(int)($query['skip']??0),(int)($query['limit']??200));
        return ['status'=>200,'body'=>json_encode(['vendors'=>$rows])];
    }
    if($method==='GET' && str_starts_with($path,'/api/v1/vendors/')){
        foreach($vendors as $row)if($row['id']===basename($path))return ['status'=>200,'body'=>json_encode(['vendor'=>$row])];
        return ['status'=>404,'body'=>'{"detail":"Vendor not found"}'];
    }
    if($method==='POST' && $path==='/api/v1/vendors'){
        $new=['id'=>'created-vendor','display_name'=>$body['display_name'],'email'=>$body['email']];$vendors[]=$new;
        if($mode==='vendor_timeout'){ $e=new PurePayApiException('Synthetic lost vendor response');$e->outcomeUncertain=true;throw $e; }
        return ['status'=>200,'body'=>json_encode($new)];
    }
    if($method==='POST' && $path==='/api/v1/bills'){
        $id='bill-'.(count($bills)+1);$bills[$id]=$body+['id'=>$id];
        if($preexisting!==null){$payment='existing-'.count($payments);$payments[$payment]=$preexisting+['id'=>$payment,'bill_id'=>$id];}
        if($mode==='bill_timeout'){ $e=new PurePayApiException('Synthetic lost bill response');$e->outcomeUncertain=true;throw $e; }
        return ['status'=>200,'body'=>json_encode(['bill'=>$bills[$id]])];
    }
    if($method==='GET' && preg_match('~^/api/v1/bills/([^/]+)/payments$~',$path,$match)){
        if($mode==='scoped_failure')return ['status'=>503,'body'=>'{"detail":"Unavailable"}'];
        if(!in_array($mode,['scoped_payments','scoped_incomplete'],true))return ['status'=>404,'body'=>'{"detail":"Not Found"}'];
        $bill=rawurldecode($match[1]);$rows=[];
        foreach($payments as$row)if(($row['bill_id']??$row['payable_id']??'')===$bill)$rows[]=[
            'id'=>$row['id'],'bill_id'=>$bill,'status'=>$row['pay_status']??$row['status']??null,'amount'=>$row['amount']??null,'currency'=>$row['currency']??null];
        return ['status'=>200,'body'=>json_encode(['contract_version'=>'2026-10-25.1','organization_id'=>'org-a','bill_id'=>$bill,
            'complete'=>$mode==='scoped_payments','total'=>count($rows),'items'=>$rows,
            'consistency'=>'rechecked_read_not_transactional_snapshot','observed_at'=>'2026-10-25T02:40:00+00:00'])];
    }
    if($method==='GET' && str_starts_with($path,'/api/v1/bills/'))return ['status'=>200,'body'=>json_encode(['bill'=>$bills[basename($path)]])];
    if($method==='GET' && $path==='/api/v1/payments'){
        if($mode==='payment_list_capped')return ['status'=>200,'body'=>json_encode(array_map(static fn($i)=>['id'=>'unrelated-payment-'.$i,'payable_id'=>'unrelated-bill-'.$i],range(1,1000)))];
        if($mode==='payment_list_incomplete')return ['status'=>200,'body'=>'{"payments":[],"has_more":true}'];
        return ['status'=>200,'body'=>json_encode(['payments'=>array_values($payments)])];
    }
    if($method==='POST' && preg_match('~/bills/([^/]+)/pay$~',$path,$m)){
        $id='payment-'.(count($payments)+1);$payments[$id]=['id'=>$id,'bill_id'=>$m[1],'pay_status'=>'processing','amount'=>'125.00','currency'=>'USD'];
        if($mode==='payment_timeout'){ $e=new PurePayApiException('Synthetic lost payment response');$e->outcomeUncertain=true;throw $e; }
        return ['status'=>200,'body'=>json_encode(['payment'=>$payments[$id]])];
    }
    if($method==='GET' && str_starts_with($path,'/api/v1/payments/')){
        if($mode==='detail_failure')return ['status'=>503,'body'=>'{"detail":"Unavailable"}'];
        if($mode==='detail_missing')return ['status'=>404,'body'=>'{"detail":"Not Found"}'];
        if(in_array($mode,['settled_then_get_failure','settled_then_stale_success'],true)) {
            $racedPayment=$payments[basename($path)];
            $racedPayment['payment_id']=$racedPayment['id'];$racedPayment['pay_status']='settled';
            $outcome=purepayWebhookProcess(1,'raced-settlement','payment.settled',['data'=>$racedPayment],purepayConnectionFingerprint(1,'fixture-company-a'));
            $check(($outcome['status']??'')==='settled','concurrent signed result includes verified reservation evidence');
        }
        if($mode==='settled_then_get_failure'){
            throw new PurePayApiException('Synthetic detail read failed after webhook');
        }
        if($mode==='wrong_payment')return ['status'=>200,'body'=>json_encode(['payment'=>['id'=>'different-payment','bill_id'=>$payments[basename($path)]['bill_id'],'pay_status'=>'settled']])];
        if($mode==='wrong_bill')return ['status'=>200,'body'=>json_encode(['payment'=>array_replace($payments[basename($path)],['bill_id'=>'different-bill'])])];
        return ['status'=>200,'body'=>json_encode(['payment'=>array_replace($payments[basename($path)],$detailOverride)])];
    }
    throw new RuntimeException('Unexpected fixture route '.$method.' '.$path);
};
$postCount=static function()use(&$calls){return count(array_filter($calls,static fn($call)=>$call[0]==='POST'));};
$link=static function($ref,$tenant=1)use($pdo){$s=$pdo->prepare('SELECT * FROM purepay_payment_links WHERE tenant_id=? AND source_ref=?');$s->execute([$tenant,$ref]);return $s->fetch(PDO::FETCH_ASSOC);};
$send=static fn($value,$options=null)=>$driver->originate([$value],$options??$opts);
$pdo->beginTransaction();
$check($fails(fn()=>$send($item)) && !$link($item['external_ref']) && $postCount()===0,'caller transaction cannot strand an uncommitted attempt after provider IO');$pdo->rollBack();
$check($fails(fn()=>$send(array_replace($item,['amount_cents'=>12.5]))) && $postCount()===0,'fractional cents rejected before a claim');
$check($fails(fn()=>$send($item,array_replace($opts,['effective_date'=>'2026-02-30']))) && $postCount()===0,'invalid effective date rejected before a claim');
$interleave=static function()use($send,$item,$link,$check,$fails){
    $check($fails(fn()=>apVoidPayment(1,101,'Synthetic concurrent void')),'void after the committed claim cannot release its reservation');
    $check(tryPurePayDuplicate($send,$item),'competing request is refused');
    $check($link($item['external_ref'])['status']==='creating','competing request cannot overwrite the first attempt state');
};
function tryPurePayDuplicate(callable $send,array $item):bool{try{$send($item);return false;}catch(PaymentRailsOriginateException $e){return true;}}
$result=$send($item);
$check($result['items'][0]['status']==='submitted' && $postCount()===2,'first valid attempt creates one bill and pays once');
$before=count($calls);$original=$link($item['external_ref']);
$check($send($item)['items'][0]['rail_external_ref']===$result['items'][0]['rail_external_ref'] && count($calls)===$before,'confirmed replay returns its existing reference without any provider IO');
foreach([['amount_cents'=>13000],['recipient_email'=>'changed@example.test'],['invoice_number'=>'changed']]as$change){
    $check($fails(fn()=>$send(array_replace($item,$change))) && $link($item['external_ref'])===$original,'changed request cannot alter the original payment or its status');
}
$check($fails(fn()=>$send($item,array_replace($opts,['effective_date'=>'2026-10-22']))) && $link($item['external_ref'])===$original,'effective-date drift is part of request identity');
$payCount=static function()use(&$calls){return count(array_filter($calls,static fn($c)=>$c[0]==='POST' && str_ends_with($c[1],'/pay')));};
foreach(['payment_list_capped','payment_list_incomplete'] as $i=>$listMode){
    $mode=$listMode;$blocked=array_replace($item,['external_ref'=>'ap_payment:'.(120+$i)]);$before=$payCount();
    $check($fails(fn()=>$send($blocked)) && $payCount()===$before && $link($blocked['external_ref'])['status']==='needs_review',
        $listMode.' blocks payout and retains its durable reconciliation hold');
    $before=count($calls);
    $check($fails(fn()=>$send($blocked)) && count($calls)===$before,'incomplete-list retry cannot repeat any provider write');
    $check($driver->getStatusForTenant(1,'purepay:bill:'.$link($blocked['external_ref'])['purepay_bill_id'])==='unknown'
        && $link($blocked['external_ref'])['status']==='needs_review','incomplete status poll cannot erase the hold');
}
$mode='vendor_repeated_page';$blocked=array_replace($item,['external_ref'=>'ap_payment:122','recipient_ref'=>'vendor:repeated-page']);$before=$postCount();
$check($fails(fn()=>$send($blocked)) && $postCount()===$before,'unverifiable vendor pagination blocks vendor, bill and payment creation');
$mode='ok';$laterVendor=['id'=>'vendor-late-page','display_name'=>'Later Vendor','email'=>'late@example.test'];
$vendors=array_merge(array_map(static fn($i)=>['id'=>'other-vendor-'.$i,'display_name'=>'Unrelated','email'=>'other@example.test'],range(1,200)),[$laterVendor,$vendor]);
$lateVendor=array_replace($item,['external_ref'=>'ap_payment:123','recipient_ref'=>'vendor:late-page','recipient_name'=>'Later Vendor','recipient_email'=>'late@example.test']);$before=$postCount();
$check($send($lateVendor)['items'][0]['status']==='submitted' && $postCount()===$before+2,'vendor on the second page is linked without another vendor creation');
$vendors=[$vendor];
$mode='bill_timeout';$lostBill=array_replace($item,['external_ref'=>'ap_payment:102']);
$check($fails(fn()=>$send($lostBill)) && $link($lostBill['external_ref'])['status']==='needs_review','bill write with a lost response stays on hold');
$before=count($calls);$check($fails(fn()=>$send($lostBill)) && count($calls)===$before,'lost bill response cannot repeat vendor, bill or payment IO');
$mode='payment_timeout';$lostPayment=array_replace($item,['external_ref'=>'ap_payment:103']);
$check($fails(fn()=>$send($lostPayment)) && $link($lostPayment['external_ref'])['status']==='needs_review','lost payment response stays on hold');
$before=count($calls);$check($fails(fn()=>$send($lostPayment)) && count($calls)===$before,'lost payment response cannot trigger another payment POST');
$mode='ok';$billRef='purepay:bill:'.$link($lostPayment['external_ref'])['purepay_bill_id'];
$check($driver->getStatusForTenant(1,$billRef)==='submitted','scoped read can reconcile a payment whose response was lost');
$before=count($calls);$check($send($lostPayment)['items'][0]['status']==='submitted' && count($calls)===$before,'reconciled attempt returns the confirmed record without paying again');
$before=count($calls);$check($driver->getStatus($billRef)==='unknown' && count($calls)===$before,'unscoped status lookup cannot select another company');
$check($driver->getStatusForTenant(2,$billRef)==='unknown' && count($calls)===$before,'other company cannot poll the first company payment');
$mode='settled_then_get_failure';$late=array_replace($item,['external_ref'=>'ap_payment:105']);
$check($fails(fn()=>$send($late)) && $link($late['external_ref'])['status']==='settled','late failure cannot downgrade a confirmed settlement');
$before=count($calls);$invalid=array_replace($late,['recipient_email'=>'bad']);
$check($fails(fn()=>$send($invalid)) && $link($late['external_ref'])['status']==='settled' && count($calls)===$before,'invalid duplicate cannot damage settled state');
$mode='settled_then_stale_success';$raced=array_replace($item,['external_ref'=>'ap_payment:109']);
$check($send($raced)['items'][0]['status']==='settled' && $link($raced['external_ref'])['status']==='settled','late successful processing response cannot downgrade a settlement');
$raceRef='purepay:bill:'.$link($raced['external_ref'])['purepay_bill_id'];
$mode='wrong_payment';$wrong=array_replace($item,['external_ref'=>'ap_payment:110']);
$check($fails(fn()=>$send($wrong)) && $link($wrong['external_ref'])['status']==='needs_review','payment detail with a different identity cannot confirm an attempt');
$check($driver->getStatusForTenant(1,$raceRef)==='unknown' && $link($raced['external_ref'])['status']==='settled','polling rejects a different payment identity');
$mode='wrong_bill';$wrong=array_replace($item,['external_ref'=>'ap_payment:111']);
$check($fails(fn()=>$send($wrong)) && $link($wrong['external_ref'])['status']==='needs_review','payment detail for a different bill cannot confirm an attempt');
$check($driver->getStatusForTenant(1,$raceRef)==='unknown' && $link($raced['external_ref'])['status']==='settled','polling rejects a different bill identity');
$mode='ok';$payments[$link($raced['external_ref'])['purepay_payment_id']]['pay_status']='failed';
$check($driver->getStatusForTenant(1,$raceRef)==='unknown' && $link($raced['external_ref'])['status']==='settled_failed_review','provider failure after settlement stays in manual review');
$mode='ok';$pdo->prepare('UPDATE purepay_connections SET api_key_ct=? WHERE tenant_id=1')->execute([encryptField('rotated-fixture-key')]);
$before=count($calls);$check($driver->getStatusForTenant(1,$billRef)==='unknown' && count($calls)===$before,'connection rotation cannot poll old organization identities');
$check($fails(fn()=>$send($item)) && count($calls)===$before,'connection rotation cannot replay an old payment request');
$fresh=array_replace($item,['external_ref'=>'ap_payment:106']);
$check($fails(fn()=>$send($fresh)) && count($calls)===$before,'old vendor mapping cannot silently retarget after rotation');
$pdo->prepare('UPDATE purepay_connections SET api_key_ct=? WHERE tenant_id=1')->execute([encryptField($key)]);
$pdo->exec('UPDATE purepay_vendor_mappings SET connection_fingerprint=NULL WHERE tenant_id=2');
$before=count($calls);$check($fails(fn()=>$send(array_replace($item,['external_ref'=>'ap_payment:201']),array_replace($opts,['tenant_id'=>2]))) && count($calls)===$before,'historical unbound vendor mapping needs review before release');
$mode='vendor_timeout';$vendors=[];$newVendor=array_replace($item,['external_ref'=>'ap_payment:107','recipient_ref'=>'vendor:new']);
$before=$postCount();$check($fails(fn()=>$send($newVendor)) && $postCount()===$before+1,'lost vendor-create response is a single durable attempt');
$nextVendor=array_replace($newVendor,['external_ref'=>'ap_payment:108']);$before=$postCount();
$check($fails(fn()=>$send($nextVendor)) && $postCount()===$before,'another payment cannot bypass an uncertain vendor creation');
$check($pdo->query('SELECT state FROM purepay_vendor_attempts WHERE core_vendor_ref="vendor:new"')->fetchColumn()==='needs_review','vendor attempt retains reconciliation state');
// A lost response must hold the actual AP reservation, not only the adapter row.
$mode='ok';
$held=static function(callable $action,string $label)use($check):void{
    $message='';try{$action();}catch(DomainException $e){$message=$e->getMessage();}
    $check(str_contains($message,'PurePay'),$label);
};
foreach([102,103]as$id){
    $before=$link('ap_payment:'.$id);
    $held(fn()=>apVoidPayment(1,$id,'Synthetic lost-response void'), 'lost response cannot void AP payment '.$id);
    $held(fn()=>apAllocatePayment($id,['allocations'=>[['bill_id'=>$id,'amount'=>1]]]),'lost response cannot reallocate AP payment '.$id);
    $held(fn()=>apClearPayment(1,$id,'2026-10-21',null,null),'lost response cannot clear AP payment '.$id);
    $check($link('ap_payment:'.$id)===$before
        && $pdo->query('SELECT status FROM ap_payments WHERE id='.$id)->fetchColumn()==='sent'
        && $pdo->query('SELECT SUM(amount_applied) FROM ap_payment_allocations WHERE payment_id='.$id)->fetchColumn()==='125.00',
        'blocked AP action preserves the provider receipt and bill reservation');
}
$pdo->exec('UPDATE ap_payments SET rail_status="failed",rail_external_ref="synthetic-cached-reference" WHERE id=102');
$held(fn()=>apVoidPayment(1,102,'Synthetic stale UI failure'),
    'cached AP failure status cannot release an uncertain provider attempt');
$before=count($calls);
apVoidPayment(1,140,'Synthetic void before claim');
$check($fails(fn()=>$send(array_replace($item,['external_ref'=>'ap_payment:140'])))
    && !$link('ap_payment:140') && count($calls)===$before,
    'void winning before the claim prevents any provider request');
foreach([
    ['external_ref'=>'ap_payment:141','amount_cents'=>13000],
    ['external_ref'=>'ap_payment:142','recipient_name'=>'Different Vendor'],
    ['external_ref'=>'ap_payment:99999'],
    ['external_ref'=>'payroll_line:143'],
]as$change){
    $check($fails(fn()=>$send(array_replace($item,$change))) && count($calls)===$before,
        'changed or missing AP source is rejected before provider IO');
}
$pdo->exec('UPDATE ap_payments SET unallocated_amount=1 WHERE id=143');
$check($fails(fn()=>$send(array_replace($item,['external_ref'=>'ap_payment:143']))) && count($calls)===$before,
    'unallocated AP source cannot acquire a provider claim');
$pdo->exec('UPDATE ap_bills SET amount_due=100 WHERE id=141');
$check($fails(fn()=>$send(array_replace($item,['external_ref'=>'ap_payment:141']))) && count($calls)===$before,
    'a reduced bill balance cannot dispatch an excess reservation');
$pdo->exec('UPDATE ap_bills SET pwp_status="awaiting_ar" WHERE id=142');
$check($fails(fn()=>$send(array_replace($item,['external_ref'=>'ap_payment:142']))) && count($calls)===$before,
    'pay-when-paid change is rechecked in the durable claim');
// Provider detail reconciliation supplies the terminal receipt missing from the lost POST.
foreach($payments as&$paymentRow)if($paymentRow['bill_id']===$link('ap_payment:103')['purepay_bill_id'])$paymentRow['pay_status']='failed';
unset($paymentRow);
$check($driver->getStatusForTenant(1,'purepay:bill:'.$link('ap_payment:103')['purepay_bill_id'])==='failed',
    'current provider detail reconciles the lost payment response');
$pdo->exec('UPDATE ap_payments SET amount=126 WHERE id=103');
$held(fn()=>apVoidPayment(1,103,'Synthetic amount drift'),'terminal receipt cannot release a changed AP amount');
$pdo->exec('UPDATE ap_payments SET amount=125 WHERE id=103');
$pdo->prepare('UPDATE purepay_connections SET api_key_ct=? WHERE tenant_id=1')->execute([encryptField('rotated-after-failure')]);
$held(fn()=>apVoidPayment(1,103,'Synthetic changed connection'),'terminal receipt remains bound to its original company connection');
$pdo->prepare('UPDATE purepay_connections SET api_key_ct=? WHERE tenant_id=1')->execute([encryptField($key)]);
apVoidPayment(1,103,'Synthetic verified failure');
$check($pdo->query('SELECT status FROM ap_payments WHERE id=103')->fetchColumn()==='void'
    && $pdo->query('SELECT amount_due FROM ap_bills WHERE id=103')->fetchColumn()==='125.00',
    'verified failure permits void while preserving the unpaid bill balance');
$before=count($calls);
$check($fails(fn()=>$send($lostPayment)) && count($calls)===$before,
    'a voided failed attempt cannot dispatch another payment on retry');
$check((int)$pdo->query('SELECT COUNT(*) FROM accounting_journal_entries')->fetchColumn()===0,
    'provider hold and cancellation checks never post a journal');
// Settlement can authorize the normal clear flow, but never ordinary cancellation.
$pdo->exec('UPDATE ap_bills SET amount_due=125 WHERE id=141'); $vendors=[$vendor];
$settlementItem=array_replace($item,['external_ref'=>'ap_payment:141']); $send($settlementItem);
$settlementLink=$link('ap_payment:141');
$payments[$settlementLink['purepay_payment_id']]['pay_status']='settled';
$check($driver->getStatusForTenant(1,'purepay:bill:'.$settlementLink['purepay_bill_id'])==='settled',
    'current provider detail establishes a bound settlement receipt');
$pdo->beginTransaction();
$settlementRow=$pdo->query('SELECT * FROM ap_payments WHERE tenant_id=1 AND id=141 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
$check(apAssertProviderPaymentAction($pdo,1,$settlementRow,'clear'),
    'verified unchanged settlement permits the existing ledger-clear checks');
$pdo->rollBack();
$held(fn()=>apVoidPayment(1,141,'Synthetic settled cancellation'),'settlement cannot be cancelled to release the AP reservation');
$payments[$settlementLink['purepay_payment_id']]['pay_status']='returned';
$driver->getStatusForTenant(1,'purepay:bill:'.$settlementLink['purepay_bill_id']);
$pdo->exec('UPDATE ap_payments SET status="cleared" WHERE id=141');
$ledgerBlock='';try{apVoidPayment(1,141,'Synthetic return after clearance');}catch(DomainException $e){$ledgerBlock=$e->getMessage();}
$check(str_contains($ledgerBlock,'Cleared payments need a linked accounting and bank correction'),
    'a returned payment with ledger activity still requires a linked correction');
$seedAp(144);$mode='scoped_payments';$before=count($calls);
$send(array_replace($item,['external_ref'=>'ap_payment:144']));$scoped=$link('ap_payment:144');
$newCalls=array_slice($calls,$before);
$check(count(array_filter($newCalls,static fn($c)=>str_ends_with($c[1],'/payments')))===1
    && !in_array('/api/v1/payments',array_column($newCalls,1),true),'driver uses complete bill history without scanning unrelated provider payments');
$pdo->exec('UPDATE purepay_payment_links SET purepay_payment_id=NULL WHERE source_ref="ap_payment:144"');
$check($driver->getStatusForTenant(1,'purepay:bill:'.$scoped['purepay_bill_id'])==='submitted'
    && $link('ap_payment:144')['purepay_payment_id']===$scoped['purepay_payment_id'],'bill-only polling recovers the original payment through scoped history');
$pdo->exec('UPDATE purepay_payment_links SET purepay_payment_id=NULL WHERE source_ref="ap_payment:144"');
$scopedBefore=$link('ap_payment:144');
foreach(['scoped_incomplete','scoped_failure']as$failedMode){$mode=$failedMode;
    $check($driver->getStatusForTenant(1,'purepay:bill:'.$scoped['purepay_bill_id'])==='unknown'
        && $link('ap_payment:144')['status']===$scopedBefore['status']
        && $link('ap_payment:144')['response_json']===$scopedBefore['response_json']
        && !purepayStoredPaymentEvidence($link('ap_payment:144')),'failed or incomplete scoped history preserves the receipt but holds cached success for review');
}
$mode='ok';
// Identity alone is not evidence that the entire reserved obligation was paid.
$badMoney=[['amount'=>'40.00'],['amount'=>null],['amount'=>'125.001'],['currency'=>null],['currency'=>'CAD'],
    ['amount_cents'=>125],['allocated'=>40],['allocations'=>[['amount'=>40]]],['payment_id'=>'conflicting-alias'],['pay_status'=>'settled','status'=>'failed']];
foreach($badMoney as$i=>$change){
    $id=150+$i;$seedAp($id);$candidate=array_replace($item,['external_ref'=>'ap_payment:'.$id]);
    $previousPayments=$payments;
    $preexisting=array_replace(['pay_status'=>'settled','amount'=>'125.00','currency'=>'USD'],$change);$before=$payCount();
    $check($fails(fn()=>$send($candidate)) && $payCount()===$before && $link($candidate['external_ref'])['status']==='needs_review',
        'existing partial or conflicting payment cannot settle the reservation or trigger a new payout');
    $before=count($calls);
    $check($fails(fn()=>$send($candidate)) && count($calls)===$before,'held existing payment cannot be reposted on retry');
    $payments=$previousPayments;
}
$seedAp(160);$preexisting=['pay_status'=>'settled','amount'=>'125.00','currency'=>'USD'];$before=$payCount();
$check($send(array_replace($item,['external_ref'=>'ap_payment:160']))['items'][0]['status']==='settled' && $payCount()===$before,
    'matching full preexisting payment is adopted without another payout');
$preexisting=null;
foreach($badMoney as$i=>$change){
    $id=170+$i;$seedAp($id);$candidate=array_replace($item,['external_ref'=>'ap_payment:'.$id]);$detailOverride=$change;$before=$payCount();
    $check($fails(fn()=>$send($candidate)) && $payCount()===$before+1 && $link($candidate['external_ref'])['status']==='needs_review',
        'POST acknowledgement cannot substitute for full matching payment detail');
    $before=count($calls);
    $check($fails(fn()=>$send($candidate)) && count($calls)===$before,'unverified payment detail preserves one durable payout attempt');
}
$detailOverride=[];
$seedAp(180);$candidate=array_replace($item,['external_ref'=>'ap_payment:180']);$send($candidate);$moneyLink=$link($candidate['external_ref']);
$moneyId=$moneyLink['purepay_payment_id'];$moneyRef='purepay:bill:'.$moneyLink['purepay_bill_id'];
$payments[$moneyId]['pay_status']='settled';$validPayment=$payments[$moneyId];
foreach($badMoney as$change){
    $payments[$moneyId]=$validPayment;
    $check($driver->getStatusForTenant(1,$moneyRef)==='settled','fresh matching detail can establish or restore evidence');
    $payments[$moneyId]=array_replace($validPayment,$change);$priorReceipt=$link($candidate['external_ref'])['response_json'];
    $check($driver->getStatusForTenant(1,$moneyRef)==='unknown' && $link($candidate['external_ref'])['response_json']===$priorReceipt
        && !purepayStoredPaymentEvidence($link($candidate['external_ref'])),'mismatched current detail holds even a previously valid settled receipt');
    $held(fn()=>apClearPayment(1,180,'2026-10-21',null,null),'held money evidence cannot clear the full Core reservation');
    $held(fn()=>purepayApplyPaymentStatus(1,180,'settled',$pdo),'terminal projection cannot bypass held evidence');
    $before=count($calls);$check($fails(fn()=>$send($candidate)) && count($calls)===$before,'a held cached settlement cannot replay as successful');
}
$payments[$moneyId]=$validPayment;
foreach(['detail_failure','detail_missing']as$failedMode){
    $mode='ok';$driver->getStatusForTenant(1,$moneyRef);$mode=$failedMode;
    $check($driver->getStatusForTenant(1,$moneyRef)==='unknown' && !purepayStoredPaymentEvidence($link($candidate['external_ref'])),
        'missing or unreadable current detail cannot fall back to cached settlement');
    $held(fn()=>apClearPayment(1,180,'2026-10-21',null,null),'unavailable evidence holds ledger clearance');
}
$mode='ok';$driver->getStatusForTenant(1,$moneyRef);
$pdo->exec('UPDATE purepay_payment_links SET response_json=NULL WHERE source_ref="ap_payment:180"');
$held(fn()=>apClearPayment(1,180,'2026-10-21',null,null),'legacy status-only settlement requires current money evidence');
$driver->getStatusForTenant(1,$moneyRef);
// Bill-only lookup must validate the same money as payment detail.
$mode='scoped_payments';$payments[$moneyId]['amount']='40.00';
$pdo->exec('UPDATE purepay_payment_links SET purepay_payment_id=NULL WHERE source_ref="ap_payment:180"');
$check($driver->getStatusForTenant(1,$moneyRef)==='unknown' && $link('ap_payment:180')['purepay_payment_id']===null,
    'bill history cannot attach a partial payment as full settlement');
$payments[$moneyId]=$validPayment;$driver->getStatusForTenant(1,$moneyRef);$mode='ok';
// Signed terminal callbacks with mismatched money must not release live AP reservations.
$seedAp(181);$send(array_replace($item,['external_ref'=>'ap_payment:181']));$moneyHook=$link('ap_payment:181');
$hookData=['bill_id'=>$moneyHook['purepay_bill_id'],'payment_id'=>$moneyHook['purepay_payment_id'],
    'pay_status'=>'failed','amount'=>'125.00','currency'=>'USD'];
foreach([['amount'=>'40.00'],['amount'=>null],['currency'=>null],['currency'=>'CAD'],['allocated'=>40]]as$change){
    $outcome=purepayWebhookProcess(1,'bad-money','payment.failed',['data'=>array_replace($hookData,$change)],purepayConnectionFingerprint(1,$key));
    $check($outcome['outcome']==='needs_review' && !purepayStoredPaymentEvidence($link('ap_payment:181'))
        && $pdo->query('SELECT status FROM ap_payments WHERE id=181')->fetchColumn()==='sent',
        'signed failure with missing or mismatched money cannot release its AP reservation');
    $held(fn()=>apVoidPayment(1,181,'Synthetic unmatched failure'),'unverified callback cannot authorize cancellation');
}
$outcome=purepayWebhookProcess(1,'good-money','payment.failed',['data'=>$hookData],purepayConnectionFingerprint(1,$key));
$check($outcome['outcome']==='updated' && $pdo->query('SELECT status FROM ap_payments WHERE id=181')->fetchColumn()==='failed',
    'corrected signed evidence can release exactly the matched reservation');
$check((int)$pdo->query('SELECT COUNT(*) FROM accounting_journal_entries')->fetchColumn()===0,'money reconciliation tests never post a journal');
foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/163_purepay_attempt_binding.sql'))as$sql)$pdo->exec($sql);
$check($link($item['external_ref'])['connection_fingerprint']===purepayConnectionFingerprint(1,$key),'migration replay preserves original connection bindings');
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
purepayWebhookRecordOutcome(1,'review-event',['outcome'=>'updated']);
$event=$pdo->query('SELECT processed_at,processing_error FROM purepay_webhook_events WHERE tenant_id=1 AND event_id="review-event"')->fetch(PDO::FETCH_ASSOC);
$check($event['processed_at']!==null && $event['processing_error']===null,'successful reconciliation marks the event processed');
unset($GLOBALS['__purepay_transport']);
echo "PurePay delivery MariaDB: {$passed} passed\n";
