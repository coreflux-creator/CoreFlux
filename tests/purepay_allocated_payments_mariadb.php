<?php
/** Original-bill consumer: real SQL transactions and synthetic provider responses only. */
declare(strict_types=1);
$ci=getenv('GITHUB_ACTIONS')==='true' && getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1' && in_array('--confirm-disposable-fixture',$argv,true) && (string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if(!$ci&&!$local)throw new RuntimeException('Explicit disposable fixture required');
putenv('COREFLUX_DISABLE_DATABASE=1');putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));
putenv('COREFLUX_INTEGRATION_INSTANCE_ID=00000000-0000-4000-8000-000000000001');
require_once __DIR__.'/../core/purepay_allocated_payments.php';
require_once __DIR__.'/../core/migrate.php';
require_once __DIR__.'/../modules/ap/lib/ap.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$ci?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$database='coreflux_allocations_'.getmypid();$pdo->exec('CREATE DATABASE '.$database);$pdo->exec('USE '.$database);
$checks=0;$check=static function(bool $ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$fails=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}};
$key='fixture-allocation-key';$calls=[];$reservations=[];$executeCount=0;$lose=null;$patch=[];$interleave=null;
$remoteBills=[];
$vendor=['id'=>'vendor-a','organization_id'=>'org-a','display_name'=>'Synthetic supplier','email'=>'supplier@example.test'];
$context=['contract_version'=>'2026-10-25.1','organization'=>['id'=>'org-a','name'=>'Synthetic company'],'key_scope'=>'write','execution_authority'=>false];
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$json)use($pdo,&$calls,&$remoteBills,&$reservations,&$executeCount,&$lose,&$patch,&$interleave,$vendor,$context){
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
            if($reservations[$rid]['state']!=='held' && $reservations[$rid]['state']!=='released')throw new RuntimeException('Provider refuses consumed reservation release');
            $reservations[$rid]['state']='released';
        }
        if($lose===$action){$lose=null;throw new PurePayApiException('Synthetic response loss');}
        $result=array_replace($reservations[$rid],$patch);
    }else throw new RuntimeException('Unexpected provider operation '.$method.' '.$path);
    return ['status'=>200,'body'=>json_encode($result,JSON_THROW_ON_ERROR)];
};
try{
    foreach(['146_purepay_rail.sql','163_purepay_attempt_binding.sql','175_purepay_payment_receipt_owner.sql','176_purepay_financial_company.sql','177_purepay_bill_identity.sql','178_purepay_allocated_payments.sql','178_purepay_allocated_payments.sql']as$file)
        foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file))as$sql)$pdo->exec($sql);
    foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../modules/ap/migrations/001_init.sql'))as$sql)
        if(preg_match('/CREATE TABLE IF NOT EXISTS (ap_bills|ap_vendors_index|ap_payments|ap_payment_allocations)\s*\(/',$sql))$pdo->exec($sql);
    $pdo->exec('ALTER TABLE ap_bills ADD entity_id BIGINT, ADD pwp_status VARCHAR(40) NULL');
    $pdo->exec('ALTER TABLE ap_payments ADD entity_id BIGINT, ADD disbursement_rail VARCHAR(40) NULL, ADD rail_external_ref VARCHAR(160), ADD rail_status VARCHAR(40), ADD rail_originated_at DATETIME NULL');
    $pdo->exec('ALTER TABLE ap_vendors_index ADD remit_to_email VARCHAR(255),ADD contact_email VARCHAR(255)');
    $pdo->exec('CREATE TABLE accounting_entities(id BIGINT PRIMARY KEY,tenant_id BIGINT,legal_name VARCHAR(255))');
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
    $item=$seed(1);$date='2026-10-21';$before=count($calls);
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
    $before=$executeCount;$replay=purepayAllocationDispatch($pdo,1,1,$item,$date);
    $check($executeCount===$before && $replay['rail_external_ref']==='purepay:allocation:1','dispatch replay retains original bills and payment IDs');
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
    $pdo->exec('UPDATE purepay_connections SET key_scope="read_only" WHERE tenant_id=1');$before=$executeCount;$callsBefore=count($calls);
    $check($fails(fn()=>purepayAllocationCall($pdo,1,4,40,'execute')) && $executeCount===$before
        && !array_filter(array_slice($calls,$callsBefore),static fn($call)=>$call[0]==='POST'),'read-only connection cannot execute');
    echo "PASS allocated payment MariaDB checks: $checks\n";
}finally{unset($GLOBALS['__purepay_transport']);$pdo->exec('DROP DATABASE '.$database);}
