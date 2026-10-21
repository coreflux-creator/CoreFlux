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
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$inCi?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db='coreflux_purepay_'.($inCi?'ci':gmdate('YmdHis').'_'.getmypid());
$pdo->exec('CREATE DATABASE '.$db);$pdo->exec('USE '.$db);
foreach(['146_purepay_rail.sql','163_purepay_attempt_binding.sql']as$file)foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file))as$sql)$pdo->exec($sql);
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
$calls=[];$bills=[];$payments=[];$vendors=[$vendor];$mode='ok';$interleave=null;
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$json)use(&$calls,&$bills,&$payments,&$vendors,&$mode,&$interleave,$vendor,$pdo,$check){
    $path=parse_url($url,PHP_URL_PATH);$body=$json===null?[]:json_decode($json,true);$calls[]=[$method,$path,$body,$headers];
    if($method==='POST')$check(!$pdo->inTransaction(),'each provider mutation follows a committed durable claim');
    if($interleave!==null){$callback=$interleave;$interleave=null;$callback($method,$path);}
    if($method==='GET' && $path==='/api/v1/vendors')return ['status'=>200,'body'=>json_encode(['vendors'=>$vendors])];
    if($method==='GET' && str_starts_with($path,'/api/v1/vendors/'))return ['status'=>200,'body'=>json_encode(['vendor'=>$vendor])];
    if($method==='POST' && $path==='/api/v1/vendors'){
        $new=['id'=>'created-vendor','display_name'=>$body['display_name'],'email'=>$body['email']];$vendors[]=$new;
        if($mode==='vendor_timeout'){ $e=new PurePayApiException('Synthetic lost vendor response');$e->outcomeUncertain=true;throw $e; }
        return ['status'=>200,'body'=>json_encode($new)];
    }
    if($method==='POST' && $path==='/api/v1/bills'){
        $id='bill-'.(count($bills)+1);$bills[$id]=$body+['id'=>$id];
        if($mode==='bill_timeout'){ $e=new PurePayApiException('Synthetic lost bill response');$e->outcomeUncertain=true;throw $e; }
        return ['status'=>200,'body'=>json_encode(['bill'=>$bills[$id]])];
    }
    if($method==='GET' && str_starts_with($path,'/api/v1/bills/'))return ['status'=>200,'body'=>json_encode(['bill'=>$bills[basename($path)]])];
    if($method==='GET' && $path==='/api/v1/payments')return ['status'=>200,'body'=>json_encode(['payments'=>array_values($payments)])];
    if($method==='POST' && preg_match('~/bills/([^/]+)/pay$~',$path,$m)){
        $id='payment-'.(count($payments)+1);$payments[$id]=['id'=>$id,'bill_id'=>$m[1],'pay_status'=>'processing'];
        if($mode==='payment_timeout'){ $e=new PurePayApiException('Synthetic lost payment response');$e->outcomeUncertain=true;throw $e; }
        return ['status'=>200,'body'=>json_encode(['payment'=>$payments[$id]])];
    }
    if($method==='GET' && str_starts_with($path,'/api/v1/payments/')){
        if($mode==='settled_then_get_failure'){
            $pdo->exec('UPDATE purepay_payment_links SET status="settled" WHERE source_ref="ap_payment:105" AND tenant_id=1');
            throw new PurePayApiException('Synthetic detail read failed after webhook');
        }
        if($mode==='settled_then_stale_success')$pdo->exec('UPDATE purepay_payment_links SET status="settled" WHERE source_ref="ap_payment:109" AND tenant_id=1');
        if($mode==='wrong_payment')return ['status'=>200,'body'=>json_encode(['payment'=>['id'=>'different-payment','bill_id'=>$payments[basename($path)]['bill_id'],'pay_status'=>'settled']])];
        if($mode==='wrong_bill')return ['status'=>200,'body'=>json_encode(['payment'=>array_replace($payments[basename($path)],['bill_id'=>'different-bill'])])];
        return ['status'=>200,'body'=>json_encode(['payment'=>$payments[basename($path)]])];
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
$interleave=static function()use($send,$item,$link,$check){$check($fails=tryPurePayDuplicate($send,$item),'competing request is refused');$check($link($item['external_ref'])['status']==='creating','competing request cannot overwrite the first attempt state');};
function tryPurePayDuplicate(callable $send,array $item):bool{try{$send($item);return false;}catch(PaymentRailsOriginateException $e){return true;}}
$result=$send($item);
$check($result['items'][0]['status']==='submitted' && $postCount()===2,'first valid attempt creates one bill and pays once');
$before=count($calls);$original=$link($item['external_ref']);
$check($send($item)['items'][0]['rail_external_ref']===$result['items'][0]['rail_external_ref'] && count($calls)===$before,'confirmed replay returns its existing reference without any provider IO');
foreach([['amount_cents'=>13000],['recipient_email'=>'changed@example.test'],['invoice_number'=>'changed']]as$change){
    $check($fails(fn()=>$send(array_replace($item,$change))) && $link($item['external_ref'])===$original,'changed request cannot alter the original payment or its status');
}
$check($fails(fn()=>$send($item,array_replace($opts,['effective_date'=>'2026-10-22']))) && $link($item['external_ref'])===$original,'effective-date drift is part of request identity');
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
foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/163_purepay_attempt_binding.sql'))as$sql)$pdo->exec($sql);
$check($link($item['external_ref'])['connection_fingerprint']===purepayConnectionFingerprint(1,$key),'migration replay preserves original connection bindings');
$pdo->exec('UPDATE purepay_payment_links SET core_payment_id=NULL');
$hookBill=$link($item['external_ref'])['purepay_bill_id'];$hookPayment=$link($item['external_ref'])['purepay_payment_id'];
$hook=static fn($data,$event='payment.settled',$fingerprint=null)=>purepayWebhookProcess(1,'synthetic-event',$event,['data'=>$data],$fingerprint??purepayConnectionFingerprint(1,$key));
$prior=$link($item['external_ref']);
$check($hook(['bill_id'=>$hookBill,'payment_id'=>'wrong-payment'])['reason']==='provider_identity_conflict' && $link($item['external_ref'])===$prior,'signed callback cannot attach another payment to a known bill');
$otherBill=$link($lostPayment['external_ref'])['purepay_bill_id'];
$check($hook(['bill_id'=>$otherBill,'payment_id'=>$hookPayment])['reason']==='ambiguous_provider_identity','callback naming two different attempts requires reconciliation');
$check($hook(['bill_id'=>$hookBill,'payment_id'=>$hookPayment],'payment.settled',purepayConnectionFingerprint(1,'different-key'))['reason']==='connection_changed','callback verified before a connection change cannot update attempts');
$check($hook(['bill_id'=>$hookBill,'payment_id'=>$hookPayment])['status']==='settled' && !$pdo->inTransaction(),'matching signed callback commits settlement atomically');
$check($hook(['bill_id'=>$hookBill,'payment_id'=>$hookPayment],'payment.failed')['status']==='settled_failed_review','failure callback after settlement remains in review');
$check($hook(['bill_id'=>$hookBill,'payment_id'=>$hookPayment])['reason']==='terminal_result_requires_review','old settlement callback cannot erase unresolved failure review');
$pdo->prepare('UPDATE purepay_payment_links SET connection_fingerprint=NULL WHERE source_ref=?')->execute([$lostPayment['external_ref']]);
$check($hook(['bill_id'=>$otherBill])['reason']==='unverified_organization','callback cannot bind a historical payment to an inferred organization');
unset($GLOBALS['__purepay_transport']);
echo "PurePay delivery MariaDB: {$passed} passed\n";
