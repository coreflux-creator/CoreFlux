<?php
/** Original-bill consumer: real SQL transactions and synthetic provider responses only. */
declare(strict_types=1);
$ci=getenv('GITHUB_ACTIONS')==='true' && getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1' && in_array('--confirm-disposable-fixture',$argv,true) && (string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if(!$ci&&!$local)throw new RuntimeException('Explicit disposable fixture required');
putenv('COREFLUX_DISABLE_DATABASE=1');putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));
putenv('COREFLUX_INTEGRATION_INSTANCE_ID=00000000-0000-4000-8000-000000000001');
require_once __DIR__.'/../core/purepay_allocated_payments.php';
require_once __DIR__.'/../core/purepay_allocation_operations.php';
require_once __DIR__.'/../core/migrate.php';
require_once __DIR__.'/../modules/ap/lib/ap.php';
require_once __DIR__.'/../core/payment_rails/purepay_driver.php';
require_once __DIR__.'/../core/payment_rails/originate_helpers.php';
require_once __DIR__.'/../core/purepay_webhooks.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$ci?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$database='coreflux_allocations_'.getmypid();$pdo->exec('CREATE DATABASE '.$database);$pdo->exec('USE '.$database);
$checks=0;$check=static function(bool $ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$fails=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}};
$key='fixture-allocation-key';$calls=[];$reservations=[];$executeCount=0;$lose=null;$patch=[];$interleave=null;
$remoteBills=[];$reservationInterleave=null;
$vendor=['id'=>'vendor-a','organization_id'=>'org-a','display_name'=>'Synthetic supplier','email'=>'supplier@example.test'];
$context=['contract_version'=>'2026-10-25.1','organization'=>['id'=>'org-a','name'=>'Synthetic company'],'key_scope'=>'write','execution_authority'=>false];
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$json)use($pdo,&$calls,&$remoteBills,&$reservations,&$executeCount,&$lose,&$patch,&$interleave,&$reservationInterleave,$vendor,$context){
    if($pdo->inTransaction())throw new RuntimeException('Provider IO inside SQL transaction');
    $path=parse_url($url,PHP_URL_PATH);$body=$json?json_decode($json,true):[];$calls[]=[$method,$path];
    if($interleave){$fn=$interleave;$interleave=null;$fn();}
    if($path==='/api/v1/context')$result=$context;
    elseif($path==='/api/v1/vendors/vendor-a')$result=$vendor;
    elseif($method==='GET' && isset($remoteBills[basename($path)]))$result=$remoteBills[basename($path)];
    elseif($method==='POST' && preg_match('~^/api/v1/bills/([^/]+)/reservations(?:/([a-f0-9]{64})/(execute|release|reconcile))?$~',$path,$m)){
        $bill=$m[1];$action=$m[3]??'reserve';
        $rid=$m[2]??purepayAllocationHash(['bill_id'=>$bill,'organization_id'=>$body['organization_id'],'core_instance_id'=>$body['core_instance_id'],
            'core_tenant_id'=>$body['core_tenant_id'],'core_payment_id'=>$body['core_payment_id'],'core_bill_id'=>$body['core_bill_id']]);
        if($reservationInterleave){$fn=$reservationInterleave;$reservationInterleave=null;$fn($action);}
        if($action==='reserve'){
            if(!isset($reservations[$rid]))$reservations[$rid]=['contract_version'=>'2026-10-25.1','organization_id'=>'org-a','bill_id'=>$bill,
                'reservation_id'=>$rid,'request'=>$body,'request_hash'=>purepayAllocationHash($body),'state'=>'held','payment'=>null];
            elseif($reservations[$rid]['request']!==$body)throw new RuntimeException('Provider immutable request conflict');
        } elseif($action==='execute' && $reservations[$rid]['state']==='held'){
            $executeCount++;$amount=$reservations[$rid]['request']['amount_cents'];
            $reservations[$rid]['state']='completed';
            $reservations[$rid]['payment']=['id'=>'payment-'.$rid,'payable_id'=>$bill,'vendor_id'=>'vendor-a','status'=>'settled',
                'amount'=>intdiv($amount,100).'.'.str_pad((string)($amount%100),2,'0',STR_PAD_LEFT),'currency'=>'USD','gross_cents'=>$amount,
                'moov_transfer_id'=>'transfer-'.$rid,'moov_status'=>'completed'];
        } elseif($action==='release'){
            if(!isset($reservations[$rid]))$reservations[$rid]=['contract_version'=>'2026-10-25.1','organization_id'=>'org-a','bill_id'=>$bill,
                'reservation_id'=>$rid,'request'=>$body,'request_hash'=>purepayAllocationHash($body),'state'=>'released','payment'=>null];
            if($reservations[$rid]['request']!==$body)throw new RuntimeException('Cancellation must keep the original request');
            if($reservations[$rid]['state']!=='held' && $reservations[$rid]['state']!=='released')throw new RuntimeException('Provider refuses consumed reservation release');
            $reservations[$rid]['state']='released';
        }
        if($lose===$action){$lose=null;throw new PurePayApiException('Synthetic response loss');}
        $result=array_replace($reservations[$rid],$patch);
    }else throw new RuntimeException('Unexpected provider operation '.$method.' '.$path);
    return ['status'=>200,'body'=>json_encode($result,JSON_THROW_ON_ERROR)];
};
try{
    // Independently generated with Python json.dumps(sort_keys=True, separators=(',', ':')).
    $check(purepayAllocationHash(['z'=>"caf\u{00e9} / \u{5408}\u{540c} \u{1f600}",
        'a'=>[['vendor_name'=>'A&B / partners','amount_cents'=>12500]],'core_instance_id'=>'00000000-0000-4000-8000-000000000001'])
        ==='8971700621b26d8eeb9dcc40ed5c5ec455c4c45272409666ab4572c34305ccc7','PHP request digest matches the provider Python Unicode and slash contract');
    foreach(['146_purepay_rail.sql','163_purepay_attempt_binding.sql','175_purepay_payment_receipt_owner.sql','176_purepay_financial_company.sql','177_purepay_bill_identity.sql','178_purepay_allocated_payments.sql','178_purepay_allocated_payments.sql','179_purepay_allocation_operations.sql','179_purepay_allocation_operations.sql']as$file)
        foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file))as$sql)$pdo->exec($sql);
    foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../modules/ap/migrations/001_init.sql'))as$sql)
        if(preg_match('/CREATE TABLE IF NOT EXISTS (ap_bills|ap_vendors_index|ap_payments|ap_payment_allocations)\s*\(/',$sql))$pdo->exec($sql);
    $pdo->exec('ALTER TABLE ap_bills ADD entity_id BIGINT, ADD pwp_status VARCHAR(40) NULL');
    $pdo->exec('ALTER TABLE ap_payments ADD entity_id BIGINT, ADD disbursement_rail VARCHAR(40) NULL, ADD rail_external_ref VARCHAR(160), ADD rail_status VARCHAR(40), ADD rail_originated_at DATETIME NULL');
    $pdo->exec('ALTER TABLE ap_vendors_index ADD remit_to_email VARCHAR(255),ADD contact_email VARCHAR(255)');
    $pdo->exec('CREATE TABLE accounting_entities(id BIGINT PRIMARY KEY,tenant_id BIGINT,legal_name VARCHAR(255))');
    $pdo->exec('CREATE TABLE accounting_journal_entries(id BIGINT,tenant_id BIGINT,status VARCHAR(40))');
    $pdo->exec('INSERT INTO accounting_entities VALUES(11,1,"Synthetic company LLC")');
    $pdo->exec('INSERT INTO ap_vendors_index(id,tenant_id,vendor_name,remit_to_email) VALUES(10,1,"Synthetic supplier","supplier@example.test")');
    purepayStoreConnection(1,$key,'Synthetic company',7,'read_write');$r=purepayFinancialReview($pdo,1);purepayFinancialConfirm($pdo,1,7,$r['review_hash'],'org-a');
    $seed=static function(int $payment,int $count=2)use($pdo,&$remoteBills):array{
        $pdo->prepare('INSERT INTO ap_payments(id,tenant_id,entity_id,vendor_name,pay_date,amount,unallocated_amount,currency,status,disbursement_rail)
            VALUES(?,1,11,"Synthetic supplier","2026-10-21",125,0,"USD","sent","purepay")')->execute([$payment]);
        for($i=0;$i<$count;$i++){
            $bill=$payment*10+$i;$provider='bill-'.$bill;
            $pdo->prepare('INSERT INTO ap_bills(id,tenant_id,entity_id,bill_number,internal_ref,vendor_name,received_at,bill_date,due_date,total,amount_due,currency,status)
                VALUES(?,1,11,?,?,"Synthetic supplier","2026-10-01","2026-10-01","2026-10-31",125,125,"USD","approved")')->execute([$bill,'INV-'.$bill,'AP-'.$bill]);
            $pdo->prepare('INSERT INTO ap_payment_allocations(payment_id,bill_id,amount_applied) VALUES(?,?,?)')->execute([$payment,$bill,$count===1?125:($i===0?50:75)]);
            $remoteBills[$provider]=['id'=>$provider,'organization_id'=>'org-a','vendor_id'=>'vendor-a','vendor_name'=>'Synthetic supplier','invoice_number'=>'INV-'.$bill,
                'bill_date'=>'2026-10-01','due_date'=>'2026-10-31','amount'=>125,'currency'=>'USD','status'=>'ready_to_pay'];
            $review=purepayBillReview($pdo,1,$bill,$provider);purepayBillConfirm($pdo,1,7,$bill,$provider,$review['review_hash'],static function(){});
        }
        return ['external_ref'=>'ap_payment:'.$payment,'recipient_name'=>'Synthetic supplier','recipient_email'=>'supplier@example.test',
            'recipient_ref'=>'ap_vendor:10','amount_cents'=>12500,'currency'=>'USD'];
    };
    $guard=static function(int $payment,string $action)use($pdo){
        $pdo->beginTransaction();try{$q=$pdo->prepare('SELECT * FROM ap_payments WHERE id=? FOR UPDATE');$q->execute([$payment]);
            $result=apAssertProviderPaymentAction($pdo,1,$q->fetch(PDO::FETCH_ASSOC),$action);$pdo->commit();return $result;
        }catch(Throwable $e){$pdo->rollBack();throw $e;}
    };
    $date='2026-10-21';$sourceCheck=$seed(15,1);$before=count($calls);
    foreach([
        ['UPDATE ap_payments SET currency="CAD" WHERE id=15','UPDATE ap_payments SET currency="USD" WHERE id=15'],
        ['UPDATE ap_payments SET status="void" WHERE id=15','UPDATE ap_payments SET status="sent" WHERE id=15'],
        ['UPDATE ap_payments SET amount=126 WHERE id=15','UPDATE ap_payments SET amount=125 WHERE id=15'],
        ['UPDATE ap_payments SET unallocated_amount=1 WHERE id=15','UPDATE ap_payments SET unallocated_amount=0 WHERE id=15'],
        ['UPDATE ap_payments SET pay_date="2026-10-22" WHERE id=15','UPDATE ap_payments SET pay_date="2026-10-21" WHERE id=15'],
        ['UPDATE ap_bills SET amount_due=100 WHERE id=150','UPDATE ap_bills SET amount_due=125 WHERE id=150'],
        ['UPDATE ap_bills SET pwp_status="awaiting_ar" WHERE id=150','UPDATE ap_bills SET pwp_status=NULL WHERE id=150'],
    ]as[$change,$restore]){
        $pdo->exec($change);$check($fails(fn()=>purepayAllocationPrepare($pdo,1,15,$sourceCheck,$date)) && count($calls)===$before
            && purepayAllocationParent($pdo,1,15)===null,'changed AP authority, money, date or released balance blocks all provider IO');$pdo->exec($restore);
    }
    foreach([['amount_cents'=>12500.5],['currency'=>'CAD'],['recipient_email'=>'changed@example.test']]as$change)
        $check($fails(fn()=>purepayAllocationPrepare($pdo,1,15,array_replace($sourceCheck,$change),$date)) && count($calls)===$before,'invalid request money or remit identity cannot reserve provider bills');
    $item=$seed(1);$before=count($calls);
    $pdo->beginTransaction();$check($fails(fn()=>purepayAllocationPrepare($pdo,1,1,$item,$date)) && count($calls)===$before,'caller transaction cannot issue provider IO');$pdo->rollBack();
    $parent=purepayAllocationPrepare($pdo,1,1,$item,$date);$children=purepayAllocationChildren($pdo,1,1);
    $check(count($children)===2 && array_sum(array_column($children,'amount_cents'))===12500,'two original bills become two exact child allocations');
    $check(count($reservations)===0 && $executeCount===0,'preparation commits identities without financial provider writes');
    $check($fails(fn()=>$guard(1,'void')) && $fails(fn()=>$guard(1,'allocate')) && $fails(fn()=>$guard(1,'clear')),'prepared children hold all AP actions');
    $check(purepayAllocationPrepare($pdo,1,1,$item,$date)===$parent,'identical prepare preserves the original parent');
    $check($fails(fn()=>purepayAllocationPrepare($pdo,1,1,array_replace($item,['recipient_email'=>'changed@example.test']),$date)),'changed remit identity cannot reuse the dispatch');
    $check($fails(fn()=>purepayAllocationCall($pdo,1,1,10,'execute')) && $executeCount===0,'cannot execute before every original bill is reserved');
    purepayAllocationCall($pdo,1,1,10,'reserve');purepayAllocationCall($pdo,1,1,11,'reserve');
    purepayAllocationCall($pdo,1,1,10,'execute');
    $check(purepayAllocationParent($pdo,1,1)['status']==='submitted' && $fails(fn()=>$guard(1,'clear')),'one settled bill cannot settle the multi-bill parent');
    purepayAllocationCall($pdo,1,1,11,'execute');
    $check(purepayAllocationParent($pdo,1,1)['status']==='settled' && $guard(1,'clear'),'all matching original bill settlements authorize normal AP clear');
    $check($fails(fn()=>$guard(1,'void')),'a settled parent cannot be cancelled');
    $driver=new PurePayRailDriver();$before=$executeCount;$replay=$driver->originate([$item],['tenant_id'=>1,'effective_date'=>$date]);
    $check($executeCount===$before && $replay['items'][0]['rail_external_ref']==='purepay:allocation:1','public dispatch replay retains original bills and payment IDs');
    $check((int)$pdo->query('SELECT COUNT(*) FROM purepay_allocation_receipts WHERE core_payment_id=1')->fetchColumn()===4,'distinct held and completed receipts remain immutable history');
    $c=purepayAllocationChildren($pdo,1,1)[0];$old=$reservations[$c['reservation_id']];
    $reservations[$c['reservation_id']]['payment']['status']='returned';$reservations[$c['reservation_id']]['payment']['moov_status']='reversed';
    purepayAllocationCall($pdo,1,1,10,'reconcile');
    $check(purepayAllocationParent($pdo,1,1)['status']==='needs_review' && $fails(fn()=>$guard(1,'clear')),'return after settlement holds the parent without reversing accounting');
    $check((int)$pdo->query('SELECT COUNT(*) FROM purepay_allocation_receipts WHERE core_payment_id=1')->fetchColumn()===5,'the original settlement receipt survives a later return');
    $item2=$seed(2);purepayAllocationPrepare($pdo,1,2,$item2,$date);$lose='reserve';
    $check($fails(fn()=>purepayAllocationCall($pdo,1,2,20,'reserve')) && purepayAllocationParent($pdo,1,2)['status']==='needs_review','lost reserve response keeps durable Core claims');
    purepayAllocationCall($pdo,1,2,20,'reserve');purepayAllocationCall($pdo,1,2,21,'reserve');$lose='execute';$before=$executeCount;
    $check($fails(fn()=>purepayAllocationCall($pdo,1,2,20,'execute')) && $executeCount===$before+1,'lost execution response records only one provider attempt');
    purepayAllocationCall($pdo,1,2,20,'reconcile');
    purepayAllocationCall($pdo,1,2,20,'execute');
    $check($executeCount===$before+1,'reconciliation and execution retry cannot make a second provider payment');
    $c=purepayAllocationChildren($pdo,1,2)[1];$reservations[$c['reservation_id']]['state']='needs_review';
    $check(purepayAllocationCall($pdo,1,2,21,'reconcile')==='needs_review' && $fails(fn()=>$guard(2,'void')),'mixed settlement and uncertainty keep every local reservation held');
    $item3=$seed(3);purepayAllocationPrepare($pdo,1,3,$item3,$date);
    foreach([30,31]as$b){purepayAllocationCall($pdo,1,3,$b,'reserve');purepayAllocationCall($pdo,1,3,$b,'release');}
    $check($guard(3,'void') && purepayAllocationParent($pdo,1,3)['status']==='cancelled','only entirely unexecuted released claims authorize cancellation');
    $item4=$seed(4,1);purepayAllocationPrepare($pdo,1,4,$item4,$date);$patch=['organization_id'=>'foreign'];
    $check($fails(fn()=>purepayAllocationCall($pdo,1,4,40,'reserve')) && $fails(fn()=>$guard(4,'void')),'wrong company evidence cannot release Core bills');$patch=[];
    purepayAllocationCall($pdo,1,4,40,'reserve');
    $pdo->exec('UPDATE ap_payment_allocations SET amount_applied=100 WHERE payment_id=4');$before=$executeCount;
    $check($fails(fn()=>purepayAllocationCall($pdo,1,4,40,'execute')) && $executeCount===$before,'changed AP allocations block execution before provider IO');
    $check(purepayAllocationCall($pdo,1,4,40,'reconcile')==='prepared','original receipt reconciliation still works when local allocations changed');
    $pdo->exec('UPDATE ap_payment_allocations SET amount_applied=125 WHERE payment_id=4');
    $check($fails(fn()=>purepayAllocationPrepare($pdo,2,4,$item4,$date)),'foreign Core company cannot prepare another company payment');
    $item5=$seed(5,1);$interleave=static fn()=>$pdo->exec('UPDATE ap_payment_allocations SET amount_applied=100 WHERE payment_id=5');
    $check($fails(fn()=>purepayAllocationPrepare($pdo,1,5,$item5,$date)) && purepayAllocationParent($pdo,1,5)===null,'source change during provider reads rolls back the entire new parent');
    $item6=$seed(6,1);$pdo->prepare('INSERT INTO purepay_payment_links(tenant_id,source_ref,core_payment_id,amount_cents,status,request_fingerprint) VALUES(1,"ap_payment:6",6,12500,"needs_review",?)')->execute([str_repeat('a',64)]);
    $check($fails(fn()=>purepayAllocationPrepare($pdo,1,6,$item6,$date)),'historical attempts cannot be rebound into a new canonical dispatch');

    $item7=$seed(7);$built=paymentRailsBuildItem($item7+['effective_date'=>$date,'sec_code'=>'ccd','description'=>'AP-PAY','account_type'=>'checking'],true);
    $built['recipient_name']='Short rail name'; // Full remit identity survives bank-format truncation.
    $before=$executeCount;$reply=$driver->originate([$built],['tenant_id'=>1,'effective_date'=>date('Y-m-d',strtotime('+1 day'))]);
    $check($executeCount===$before+2 && $reply['items'][0]['status']==='settled' && $reply['items'][0]['rail_external_ref']==='purepay:allocation:7',
        'public driver uses per-payment AP date and full vendor identity for two original bills');
    $before=$executeCount;$rail=$driver->getStatusForTenant(1,'purepay:allocation:7');purepayApplyPaymentStatus(1,7,$rail,$pdo);
    $check($rail==='settled' && $executeCount===$before && $pdo->query('SELECT status FROM ap_payments WHERE id=7')->fetchColumn()==='sent'
        && $pdo->query('SELECT rail_status FROM ap_payments WHERE id=7')->fetchColumn()==='settled','public status reconciliation projects evidence without paying or clearing AP');
    $children7=purepayAllocationChildren($pdo,1,7);$child7=$children7[0];$payment7=$reservations[$child7['reservation_id']]['payment'];
    $before=count($calls);$check($driver->getStatusForTenant(2,'purepay:allocation:7')==='unknown' && count($calls)===$before,'foreign company cannot reconcile another allocation');
    $callback7=$payment7+['payment_id'=>$payment7['id']];$badCallback=array_replace($callback7,['amount'=>'1.00']);
    $out=purepayWebhookProcess(1,'bad-allocation-money','payment.settled',['data'=>$badCallback],purepayConnectionFingerprint(1,$key));
    $check($out['outcome']==='needs_review' && $fails(fn()=>$guard(7,'clear')),'signed callback cannot clear an allocation with conflicting money');
    $before=$executeCount;$out=purepayWebhookProcess(1,'good-allocation-money','payment.settled',['data'=>$callback7],purepayConnectionFingerprint(1,$key));
    $check($out['outcome']==='updated' && $out['status']==='settled' && $executeCount===$before,'signed callback reconciles original receipts without another payout');
    $returned=array_replace($payment7,['status'=>'returned','moov_status'=>'reversed']);$reservations[$child7['reservation_id']]['payment']=$returned;
    $out=purepayWebhookProcess(1,'returned-allocation','payment.returned',['data'=>$returned+['payment_id'=>$payment7['id']]],purepayConnectionFingerprint(1,$key));
    $check($out['outcome']==='needs_review' && $pdo->query('SELECT status FROM ap_payments WHERE id=7')->fetchColumn()==='sent','late allocation return holds the entire payment without automatic accounting reversal');
    $reservations[$child7['reservation_id']]['payment']=$payment7;
    $check($driver->getStatusForTenant(1,'purepay:allocation:7')==='unknown' && $driver->getStatusForTenant(1,'purepay:allocation:7')==='unknown',
        'repeated settlement cannot erase a terminal allocation conflict');

    $item8=$seed(8);purepayAllocationPrepare($pdo,1,8,$item8,$date);$before=$executeCount;
    $pdo->exec('UPDATE purepay_payment_allocations SET provider_bill_id="bill-81" WHERE core_payment_id=8 AND core_bill_id=80');
    $check($fails(fn()=>purepayAllocationCall($pdo,1,8,80,'reserve')) && $executeCount===$before,'equal parent totals cannot hide a changed original child identity');
    $pdo->exec('UPDATE purepay_payment_allocations SET provider_bill_id="bill-80" WHERE core_payment_id=8 AND core_bill_id=80');
    $item9=$seed(9,1);purepayAllocationPrepare($pdo,1,9,$item9,$date);purepayAllocationCall($pdo,1,9,90,'reserve');
    $patch=['payment'=>array_replace($payment7,['payable_id'=>'bill-90','amount'=>'125.00','gross_cents'=>12500])];
    $check($fails(fn()=>purepayAllocationCall($pdo,1,9,90,'execute')) && purepayAllocationChildren($pdo,1,9)[0]['provider_payment_id']===null,
        'one provider payment cannot discharge a second original bill allocation');$patch=[];
    $legacyEvidence=purepayPaymentEvidence($payment7,5000,'bill-70',$payment7['id']);
    $pdo->prepare('INSERT INTO purepay_payment_links(tenant_id,source_ref,purepay_bill_id,purepay_payment_id,amount_cents,status,request_fingerprint,response_json)
        VALUES(1,"legacy-overlap","bill-70",?,5000,"settled",?,?)')->execute([$payment7['id'],str_repeat('c',64),purepayPaymentReceipt($legacyEvidence,'detail')]);
    $q=$pdo->query('SELECT * FROM purepay_payment_links WHERE source_ref="legacy-overlap"');
    $check(!purepayPaymentEvidenceOwned($pdo,1,$q->fetch(PDO::FETCH_ASSOC)),'historical receipt checks cannot reuse a new allocation receipt');
    $pdo->exec('DELETE FROM purepay_payment_links WHERE source_ref="legacy-overlap"');
    $item10=$seed(10,1);purepayAllocationPrepare($pdo,1,10,$item10,$date);
    $other=new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database.';charset=utf8mb4','root',$ci?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $other->exec('SET SESSION innodb_lock_wait_timeout=1');
    $dbError=static function(callable $fn):int{try{$fn();return 0;}catch(PDOException $e){return (int)($e->errorInfo[1]??0);}};
    $pdo->beginTransaction();$pdo->exec('UPDATE purepay_payment_allocations SET provider_payment_id="allocation-race" WHERE core_payment_id=9');
    $check($dbError(fn()=>$other->exec('UPDATE purepay_payment_allocations SET provider_payment_id="allocation-race" WHERE core_payment_id=10'))===1205,
        'a second SQL session cannot bind an uncommitted allocation receipt');$pdo->commit();
    $check($dbError(fn()=>$other->exec('UPDATE purepay_payment_allocations SET provider_payment_id="allocation-race" WHERE core_payment_id=10'))===1062,
        'committed allocation receipt remains exclusive across SQL sessions');
    $other->exec('UPDATE purepay_payment_allocations SET provider_payment_id="ALLOCATION-RACE" WHERE core_payment_id=10');
    $check($other->query('SELECT provider_payment_id FROM purepay_payment_allocations WHERE core_payment_id=10')->fetchColumn()==='ALLOCATION-RACE',
        'allocation receipt identity remains case sensitive');
    $check(!array_filter($calls,static fn($call)=>$call[0]==='POST' && in_array($call[1],['/api/v1/bills','/api/v1/vendors'],true))
        && (int)$pdo->query('SELECT COUNT(*) FROM accounting_journal_entries')->fetchColumn()===0,'dispatch preserves original provider records and never posts journals');
    $operatorItem=$seed(20);purepayAllocationPrepare($pdo,1,20,$operatorItem,$date);
    $before=count($calls);$review=purepayAllocationReview($pdo,1,20,8,true);
    $check($review['can_resume'] && $review['can_release'] && count($review['bills'])===2 && count($calls)===$before,
        'operator review reads the complete saved bill set without provider IO');
    $check(!str_contains(json_encode($review),$key) && !str_contains(json_encode($review),'connection_fingerprint'),
        'operator review does not expose credentials or connection fingerprints');
    $readOnly=purepayAllocationReview($pdo,1,20,8,false);
    $check(!$readOnly['can_resume'] && !$readOnly['can_release'] && !$readOnly['can_reconcile']
        && $fails(fn()=>purepayAllocationOperate($pdo,1,20,8,false,'release',$review['review_hash'],'No authority')),
        'view permission cannot perform recovery actions');
    $check($fails(fn()=>purepayAllocationReview($pdo,2,20,8,true)) && count($calls)===$before,
        'review and operations are scoped to the payment company');
    $pdo->exec('UPDATE ap_payments SET created_by_user_id=8 WHERE id=20');
    $creator=purepayAllocationReview($pdo,1,20,8,true);
    $check(!$creator['can_resume'] && $fails(fn()=>purepayAllocationOperate($pdo,1,20,8,true,'resume',$creator['review_hash'],'Own payment')),
        'creator cannot resume their own payment even through the direct operation function');
    $pdo->exec('UPDATE ap_payments SET created_by_user_id=7 WHERE id=20');
    $check($fails(fn()=>purepayAllocationOperate($pdo,1,20,8,true,'release',$review['review_hash'],'Stale review'))
        && (int)$pdo->query('SELECT COUNT(*) FROM purepay_allocation_operations')->fetchColumn()===0 && count($calls)===$before,
        'stale displayed evidence cannot authorize an operation or provider call');
    $review=purepayAllocationReview($pdo,1,20,8,true);$before=$executeCount;
    $out=purepayAllocationOperate($pdo,1,20,8,true,'release',$review['review_hash'],'Duplicate draft detected');
    $check($out['status']==='cancelled' && $out['release_requested'] && !$out['can_resume'] && !$out['can_release'] && $executeCount===$before
        && count($out['history'])===2 && $guard(20,'void'),'cancelling never-reserved bills records every original release and an immutable audit');
    $check($pdo->query('SELECT rail_external_ref FROM ap_payments WHERE id=20')->fetchColumn()==='purepay:allocation:20',
        'the AP payment retains its original rail claim after cancellation');
    $check($fails(fn()=>purepayAllocationCall($pdo,1,20,200,'reserve')) && $fails(fn()=>purepayAllocationCall($pdo,1,20,200,'execute'))
        && $executeCount===$before,'permanent cancellation blocks both a reserve retry and execution');
    $child=purepayAllocationChildren($pdo,1,20)[0];$delayed=$reservations[$child['reservation_id']];$delayed['state']='held';
    purepayAllocationSave($pdo,1,20,200,$delayed);
    $check(purepayAllocationParent($pdo,1,20)['status']==='cancelled' && $guard(20,'void'),
        'a late held receipt cannot reopen a cancelled original request');
    $contradiction=$delayed;$contradiction['state']='executing';purepayAllocationSave($pdo,1,20,200,$contradiction);
    purepayAllocationSave($pdo,1,20,200,$reservations[$child['reservation_id']]);
    $check(purepayAllocationParent($pdo,1,20)['status']==='needs_review' && $fails(fn()=>$guard(20,'void')),
        'execution reported after cancellation creates a sticky conflict that later release cannot erase');
    $operatorItem=$seed(21);purepayAllocationPrepare($pdo,1,21,$operatorItem,$date);$review=purepayAllocationReview($pdo,1,21,8,true);$lose='release';
    $out=purepayAllocationOperate($pdo,1,21,8,true,'release',$review['review_hash'],'Stop before payment');
    $check($out['status']==='needs_review' && $out['release_requested'] && !$out['can_resume'] && $fails(fn()=>$guard(21,'void')),
        'lost cancellation response retains the cancellation intent and AP hold');
    $out=purepayAllocationOperate($pdo,1,21,8,true,'release',$out['review_hash'],'Finish original cancellation');
    $check($out['status']==='cancelled' && count($out['history'])===4 && $guard(21,'void'),
        'cancellation retry confirms the original releases with separate audit events');
    $operatorItem=$seed(22,1);purepayAllocationPrepare($pdo,1,22,$operatorItem,$date);$review=purepayAllocationReview($pdo,1,22,8,true);
    $pdo->exec("CREATE TRIGGER reject_recovery_audit BEFORE INSERT ON purepay_allocation_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
    $before=count($calls);
    $check($fails(fn()=>purepayAllocationOperate($pdo,1,22,8,true,'release',$review['review_hash'],'Audit required'))
        && !purepayAllocationParent($pdo,1,22)['release_requested'] && count($calls)===$before,
        'audit failure rolls back cancellation and prevents every provider call');
    $pdo->exec('DROP TRIGGER reject_recovery_audit');
    $reservationInterleave=static function($action)use($pdo,$check){
        $check($action==='reserve','race occurs after local authorization and before the delayed reserve');
        $review=purepayAllocationReview($pdo,1,22,8,true);
        purepayAllocationOperate($pdo,1,22,8,true,'release',$review['review_hash'],'Cancel while reservation is in flight');
    };
    $before=$executeCount;purepayAllocationCall($pdo,1,22,220,'reserve');
    $check(purepayAllocationParent($pdo,1,22)['status']==='cancelled' && $executeCount===$before && $guard(22,'void'),
        'cancellation that wins the in-flight reserve race cannot later execute');
    $operatorItem=$seed(23);purepayAllocationPrepare($pdo,1,23,$operatorItem,$date);$review=purepayAllocationReview($pdo,1,23,8,true);$before=$executeCount;
    $out=purepayAllocationOperate($pdo,1,23,8,true,'resume',$review['review_hash'],'Approved independent review');
    $check($out['status']==='settled' && $executeCount===$before+2 && count($out['history'])===2
        && !$out['can_resume'] && !$out['can_release'],'authorized resume uses both immutable original allocations exactly once');
    $out=purepayAllocationOperate($pdo,1,23,8,true,'reconcile',$out['review_hash'],'Confirm receipt');
    $check($out['status']==='settled' && $executeCount===$before+2 && count($out['history'])===4,
        'audited refresh reconciles saved claims without resending money');
    $operatorItem=$seed(24,1);purepayAllocationPrepare($pdo,1,24,$operatorItem,$date);purepayAllocationCall($pdo,1,24,240,'reserve');
    $review=purepayAllocationReview($pdo,1,24,8,true);$lose='execute';$before=$executeCount;
    $out=purepayAllocationOperate($pdo,1,24,8,true,'resume',$review['review_hash'],'Continue approved original request');
    $check($out['status']==='needs_review' && !$out['can_resume'] && $executeCount===$before+1,
        'uncertain execution requires reconciliation before another resume');
    $out=purepayAllocationOperate($pdo,1,24,8,true,'reconcile',$out['review_hash'],'Recover lost result');
    $check($out['status']==='settled' && $executeCount===$before+1,'lost execution is recovered using the original receipt without a second attempt');
    $operatorItem=$seed(25,1);purepayAllocationPrepare($pdo,1,25,$operatorItem,$date);purepayAllocationCall($pdo,1,25,250,'reserve');
    $review=purepayAllocationReview($pdo,1,25,8,true);$child=purepayAllocationChildren($pdo,1,25)[0];
    $reservationInterleave=static function($action)use($pdo,$check,$child,&$reservations){
        $check($action==='release' && (bool)purepayAllocationParent($pdo,1,25)['release_requested']
            && (int)$pdo->query('SELECT COUNT(*) FROM purepay_allocation_operations WHERE core_payment_id=25 AND phase="requested"')->fetchColumn()===1,
            'the cancellation flag and operator intent are durable before provider cancellation');
        // A previously authorized execution wins the provider compare-and-set.
        $reservations[$child['reservation_id']]['state']='executing';
    };
    $out=purepayAllocationOperate($pdo,1,25,8,true,'release',$review['review_hash'],'Cancel racing execution');
    $check($out['status']==='needs_review' && $out['release_requested'] && !$out['can_resume'] && $fails(fn()=>$guard(25,'void')),
        'execution that wins the provider race keeps Core held instead of voiding or freeing money');
    $out=purepayAllocationOperate($pdo,1,25,8,true,'reconcile',$out['review_hash'],'Inspect original in-flight attempt');
    $check($out['status']==='needs_review' && !$out['can_release'] && !$out['can_resume'],
        'reconciliation of an executing cancelled request preserves the hold');
    // Both actual Mercury AP paths must contend on the same row as PurePay.
    // Only draft/approval records are created; no Mercury worker or adapter is invoked.
    require_once __DIR__.'/../core/mercury_payments.php';
    require_once __DIR__.'/../core/payment_rails/mercury_driver.php';
    $mercuryCalls=0;
    $GLOBALS['__mercury_transport']=static function()use(&$mercuryCalls){
        $mercuryCalls++;throw new RuntimeException('Mercury network is prohibited in this fixture');
    };
    foreach(['048_mercury_foundation.sql','049_mercury_recipients.sql','050_mercury_payments.sql'] as $file)
        foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file)) as $sql)
            if(preg_match('/CREATE TABLE IF NOT EXISTS /',$sql))$pdo->exec($sql);
    $pdo->exec('ALTER TABLE payment_instructions ADD is_internal_transfer TINYINT NOT NULL DEFAULT 0');
    $pdo->exec('INSERT INTO mercury_recipients(id,tenant_id,kind,name,status) VALUES(1,1,"vendor","Synthetic supplier","active")');
    $pdo->prepare('INSERT INTO mercury_connections(tenant_id,api_token_ct,api_token_last4,status) VALUES(1,?,"ture","active")')
        ->execute([encryptField('synthetic-not-a-credential')]);
    $pdo->prepare('INSERT INTO mercury_recipient_bank_methods(tenant_id,recipient_id,routing_number_ct,account_number_ct,account_number_last4,is_default)
        VALUES(1,1,?,?,"5678",1)')->execute([encryptField('021000021'),encryptField('12345678')]);
    $mercuryData=static fn(int $id):array=>['source_module'=>'ap','source_ref'=>'ap_payment:'.$id,'recipient_id'=>1,'amount_cents'=>12500,'currency'=>'USD'];
    $mercuryCount=static function(int $id)use($pdo):int{
        $q=$pdo->prepare('SELECT COUNT(*) FROM payment_instructions WHERE tenant_id=1 AND source_ref IN (?,?)');
        $q->execute([(string)$id,'ap_payment:'.$id]);return (int)$q->fetchColumn();
    };
    $mercuryItem=$seed(101,1);$before=count($calls);$pdo->beginTransaction();
    $draft=mpCreateFromApPayment(1,101,8);
    $check($pdo->inTransaction() && $draft['state']==='Draft'
        && $pdo->query('SELECT rail_external_ref FROM ap_payments WHERE id=101')->fetchColumn()==='pi:'.$draft['id'],
        'direct Mercury draft and AP reference share their caller transaction and require human approval');
    $check($dbError(fn()=>purepayAllocationPrepare($other,1,101,$mercuryItem,$date))===1205 && count($calls)===$before,
        'another SQL session cannot prepare PurePay while Mercury owns the AP row');
    $pdo->commit();
    $check($fails(fn()=>purepayAllocationPrepare($pdo,1,101,$mercuryItem,$date)) && count($calls)===$before && $mercuryCount(101)===1,
        'committed Mercury draft excludes PurePay before any provider read or write');
    $check($fails(fn()=>mpCreateFromApPayment(1,101,8)) && $mercuryCount(101)===1,
        'repeated direct draft does not create another instruction');
    $pdo->exec('UPDATE ap_payments SET rail_external_ref=NULL WHERE id=101');
    $check($fails(fn()=>purepayAllocationPrepare($pdo,1,101,$mercuryItem,$date))
        && $fails(fn()=>mpCreate(1,$mercuryData(101),8)) && $mercuryCount(101)===1,
        'a missing reverse link cannot erase the original Mercury claim in either path');
    $mercuryItem=$seed(102,1);purepayAllocationPrepare($pdo,1,102,$mercuryItem,$date);$before=count($calls);
    $check($fails(fn()=>mpCreateFromApPayment(1,102,8)) && $fails(fn()=>mpCreate(1,$mercuryData(102),8))
        && $mercuryCount(102)===0 && count($calls)===$before,'PurePay that claims first excludes both Mercury paths');
    $pdo->exec('UPDATE ap_payments SET rail_external_ref=NULL WHERE id=102');
    $check($fails(fn()=>mpCreate(1,$mercuryData(102),8)) && $mercuryCount(102)===0,
        'a missing PurePay reverse link still blocks Mercury via its durable original allocation');
    $mercuryItem=$seed(103,1);
    $pdo->exec("CREATE TRIGGER fail_mercury_reference BEFORE UPDATE ON ap_payments FOR EACH ROW
        BEGIN IF NEW.id=103 AND NEW.disbursement_rail='mercury' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic reference failure'; END IF; END");
    $check($fails(fn()=>mpCreateFromApPayment(1,103,8)) && !$pdo->inTransaction() && $mercuryCount(103)===0
        && !$pdo->query('SELECT rail_external_ref FROM ap_payments WHERE id=103')->fetchColumn(),
        'reference persistence failure rolls back the entire direct Mercury draft');
    $pdo->exec('DROP TRIGGER fail_mercury_reference');
    purepayAllocationPrepare($pdo,1,103,$mercuryItem,$date);
    $check(purepayAllocationParent($pdo,1,103)!==null,'rolled-back Mercury draft leaves no phantom provider claim');
    $mercuryItem=$seed(104,1);$pdo->beginTransaction();
    $pdo->query('SELECT COUNT(*) FROM payment_instructions')->fetchColumn();
    $primary=$pdo;$GLOBALS['pdo']=$other;
    try{$draft=mpCreate(1,$mercuryData(104),8);}finally{$GLOBALS['pdo']=$primary;}
    $before=count($calls);
    $check($fails(fn()=>purepayAllocationSource($pdo,1,104,$mercuryItem,$date)) && count($calls)===$before,
        'an older transaction snapshot sees the competing committed Mercury AP claim');
    $pdo->rollBack();
    $mercuryItem=$seed(105,1);$pdo->beginTransaction();
    mpCreate(1,$mercuryData(105),8);$check($pdo->inTransaction(),'batch-style Mercury creation preserves its caller transaction');
    $pdo->rollBack();
    $check($mercuryCount(105)===0 && !$pdo->query('SELECT rail_external_ref FROM ap_payments WHERE id=105')->fetchColumn(),
        'caller rollback removes both batch-style Mercury draft and AP reverse link');
    foreach([['amount_cents'=>12501],['amount_cents'=>12500.5],['currency'=>'CAD'],['source_ref'=>'ap_payment:0105']]as$change)
        $check($fails(fn()=>mpCreate(1,array_replace($mercuryData(105),$change),8)) && $mercuryCount(105)===0,
            'changed money, currency or noncanonical AP identity cannot create a Mercury draft');
    $check($fails(fn()=>mpCreate(2,$mercuryData(105),8)) && $fails(fn()=>mpCreate(1,$mercuryData(105),null)),
        'foreign company and missing operator cannot claim an AP payment');
    $pdo->exec('UPDATE ap_payments SET created_by_user_id=8 WHERE id=105');
    $check($fails(fn()=>mpCreate(1,$mercuryData(105),8)) && $mercuryCount(105)===0,
        'payment creator cannot bypass independent release through the Mercury batch path');
    $pdo->exec('UPDATE ap_payments SET created_by_user_id=NULL WHERE id=105');
    $pdo->exec('UPDATE ap_bills SET pwp_status="awaiting_ar" WHERE id=1050');
    $check($fails(fn()=>mpCreate(1,$mercuryData(105),8)) && $mercuryCount(105)===0,
        'Mercury rechecks the original supplier bill release gate inside the AP claim');
    $pdo->exec('UPDATE ap_bills SET pwp_status=NULL WHERE id=1050');
    $data=$mercuryData(105);$data['source_module']='ap_payment';mpCreate(1,$data,8);
    $check($mercuryCount(105)===1 && $pdo->query('SELECT source_module FROM payment_instructions WHERE source_ref="ap_payment:105"')->fetchColumn()==='ap',
        'legacy ap_payment source spelling is normalized and cannot bypass the AP claim');
    $pdo->exec('UPDATE payment_instructions SET source_module="ap_payment",state="Failed" WHERE source_ref="ap_payment:105"');
    $pdo->exec('UPDATE ap_payments SET rail_external_ref=NULL WHERE id=105');
    $check($fails(fn()=>purepayAllocationPrepare($pdo,1,105,$mercuryItem,$date)) && $fails(fn()=>mpCreate(1,$mercuryData(105),8)),
        'historical batch source spelling and a failed instruction retain the original claim for reconciliation');
    $mercuryItem=$seed(106,1);
    $mercuryItem['recipient_name']='Synthetic supplier with a longer legal name';
    $pdo->exec('UPDATE ap_payments SET vendor_name="Synthetic supplier with a longer legal name" WHERE id=106');
    $pdo->exec('UPDATE ap_bills SET vendor_name="Synthetic supplier with a longer legal name" WHERE id=1060');
    $pdo->exec('UPDATE mercury_recipients SET name="Synthetic supplier with a longer legal name" WHERE id=1');
    $railItem=paymentRailsBuildItem($mercuryItem+['routing'=>'021000021','account'=>'12345678',
        'account_type'=>'checking','sec_code'=>'ccd','description'=>'AP-PAY']);
    $before=count($calls);$beforeExecution=$executeCount;
    $result=paymentRailsDispatch('ap',['tenant_id'=>1,'actor_user_id'=>8,'disbursement_rail'=>'mercury'],[],[$railItem]);
    $check($result['rail']==='mercury' && $result['items'][0]['status']!=='failed' && $mercuryCount(106)===1
        && $pdo->query('SELECT rail_external_ref FROM ap_payments WHERE id=106')->fetchColumn()===$result['items'][0]['rail_external_ref'],
        'actual shared dispatch creates one Mercury draft with the full vendor name and matching AP reference');
    $check(count($calls)===$before && $executeCount===$beforeExecution
        && $fails(fn()=>purepayAllocationPrepare($pdo,1,106,$mercuryItem,$date)),
        'shared Mercury dispatch excludes PurePay without provider or funding execution');
    $result=paymentRailsDispatch('ap',['tenant_id'=>1,'actor_user_id'=>8,'disbursement_rail'=>'mercury'],[],[$railItem]);
    $check($result['items'][0]['status']==='failed' && $mercuryCount(106)===1,
        'repeated actual Mercury dispatch returns a conflict without creating a second instruction');
    $pdo->exec('UPDATE mercury_recipients SET name="Synthetic supplier" WHERE id=1');
    $mercuryItem=$seed(107,1);$before=count($calls);
    $interleave=static function(){mpCreateFromApPayment(1,107,8);};
    $check($fails(fn()=>purepayAllocationPrepare($pdo,1,107,$mercuryItem,$date))
        && $mercuryCount(107)===1 && purepayAllocationParent($pdo,1,107)===null
        && !array_filter(array_slice($calls,$before),static fn($call)=>$call[0]==='POST'),
        'Mercury that wins during PurePay bill review prevents the later reservation commit and provider writes');
    $check($mercuryCalls===0,'all Mercury concurrency checks complete without any external provider calls');
    require_once __DIR__.'/fixtures/ap_bank_dispatches_checks.php';
    exerciseApBankDispatches($pdo,$other,$seed,$check,$fails,$guard,$date);
    require_once __DIR__.'/fixtures/mercury_ap_actions_checks.php';
    exerciseMercuryApActions($pdo,$other,$seed,$check,$fails,$guard,$dbError);
    $check($mercuryCalls===0,'Mercury AP lifecycle verification performs no external bank operations');
    $pdo->exec('UPDATE purepay_connections SET key_scope="read_only" WHERE tenant_id=1');$before=$executeCount;$callsBefore=count($calls);
    $check($fails(fn()=>purepayAllocationCall($pdo,1,4,40,'execute')) && $executeCount===$before
        && !array_filter(array_slice($calls,$callsBefore),static fn($call)=>$call[0]==='POST'),'read-only connection cannot execute');
    echo "PASS allocated payment MariaDB checks: $checks\n";
}finally{unset($GLOBALS['__purepay_transport'],$GLOBALS['__mercury_transport']);$pdo->exec('DROP DATABASE '.$database);}
