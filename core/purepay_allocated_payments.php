<?php
/** Original-bill allocation contract. No accounting entries or provider-bill creation. */
declare(strict_types=1);
require_once __DIR__.'/purepay_bill_identity.php';
require_once __DIR__.'/integrations/identity.php';

/** Match the provider's sorted-key, ASCII JSON hash, independently of credentials. */
function purepayAllocationJson(array $value): string
{
    $sort=static function(mixed $item)use(&$sort):mixed {
        if (!is_array($item)) return $item;
        if (!array_is_list($item)) ksort($item,SORT_STRING);
        foreach($item as $key=>$child)$item[$key]=$sort($child);
        return $item;
    };
    return json_encode($sort($value),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}

function purepayAllocationHash(array $value): string { return hash('sha256',purepayAllocationJson($value)); }

function purepayAllocationParent(PDO $pdo,int $tenant,int $payment,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM purepay_allocated_payments WHERE tenant_id=? AND core_payment_id=?'.($lock?' FOR UPDATE':''));
    $q->execute([$tenant,$payment]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function purepayAllocationChildren(PDO $pdo,int $tenant,int $payment,bool $lock=false): array
{
    $q=$pdo->prepare('SELECT * FROM purepay_payment_allocations WHERE tenant_id=? AND core_payment_id=? ORDER BY core_bill_id'.($lock?' FOR UPDATE':''));
    $q->execute([$tenant,$payment]);return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Inspect the authoritative AP payment and every reserved original bill under locks. */
function purepayAllocationSource(PDO $pdo,int $tenant,int $payment,array $item,string $date): array
{
    if (!$pdo->inTransaction()) throw new LogicException('AP allocation source needs its transaction');
    purepayBillDate($date);
    $instance=integrationInstanceId();
    if (!$instance) throw new PurePayApiException('Configure the permanent Core integration identity before dispatch.');
    $q=$pdo->prepare('SELECT * FROM ap_payments WHERE tenant_id=? AND id=? FOR UPDATE');
    $q->execute([$tenant,$payment]);$p=$q->fetch(PDO::FETCH_ASSOC);
    if (!$p || !in_array($p['status'],['draft','queued','sent'],true) || $p['currency']!=='USD'
        || purepayMoneyCents($p['unallocated_amount'])!==0 || (int)($p['entity_id']??0)<=0
        || !is_int($item['amount_cents']??null) || $item['amount_cents']<=0
        || $item['amount_cents']>9999999999999 || purepayMoneyCents($p['amount'])!==$item['amount_cents'] || ($item['currency']??null)!=='USD'
        || $p['pay_date']!==$date
        || ($item['external_ref']??null)!=='ap_payment:'.$payment
        || trim((string)($item['recipient_name']??''))!==$p['vendor_name']) {
        throw new PurePayApiException('Review the fully allocated USD AP payment before dispatch.');
    }
    $connection=purepayFinancialConnection($pdo,$tenant,true);
    $binding=purepayFinancialRequire($pdo,$tenant,$connection);
    if ($connection['key_scope']!=='read_write' || $binding['provider_key_scope']!=='write') throw new PurePayApiException('Dispatch requires a reviewed read-write financial connection.');
    $q=$pdo->prepare('SELECT id FROM purepay_payment_links WHERE tenant_id=? AND (core_payment_id=? OR source_ref=?) FOR UPDATE');
    $q->execute([$tenant,$payment,'ap_payment:'.$payment]);
    if ($q->fetchColumn()!==false) throw new PurePayApiException('This AP payment has a historical provider attempt. Reconcile its original receipt; it cannot be adopted into a new dispatch.');
    $q=$pdo->prepare('SELECT bill_id,amount_applied FROM ap_payment_allocations WHERE payment_id=? ORDER BY bill_id,id FOR UPDATE');
    $q->execute([$payment]);$amounts=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC)as$a){
        $cents=purepayMoneyCents($a['amount_applied']);
        if($cents===null || $cents<=0)throw new PurePayApiException('An AP allocation amount is invalid.');
        $id=(int)$a['bill_id'];$amounts[$id]=($amounts[$id]??0)+$cents;
    }
    if (!$amounts || count($amounts)>100 || array_sum($amounts)!==$item['amount_cents']) throw new PurePayApiException('AP bill allocations do not equal this payment.');
    $children=[];
    foreach($amounts as $bill=>$amount){
        $local=purepayBillLocal($pdo,$tenant,$bill,true);
        $mapped=purepayBillMapping($pdo,$tenant,$bill,true);
        if (!$mapped) throw new PurePayApiException('Review and link each original supplier bill before dispatch.');
        $snapshot=json_decode($mapped['review_json'],true,512,JSON_THROW_ON_ERROR);
        if ($snapshot['core']!==$local || $snapshot['connection_review_hash']!==$binding['binding_hash']
            || $snapshot['organization']['id']!==$binding['remote_organization_id'] || $snapshot['origin']!==purepayApiBase()
            || $local['entity_id']!==(int)$p['entity_id'] || $local['vendor_name']!==$p['vendor_name']
            || $local['vendor_email']!==strtolower(trim((string)($item['recipient_email']??'')))
            || ($item['recipient_ref']??null)!=='ap_vendor:'.$local['vendor_id']) {
            throw new PurePayApiException('An original bill or vendor review changed; review the allocations again.');
        }
        $q=$pdo->prepare('SELECT amount_due,pwp_status,status FROM ap_bills WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$tenant,$bill]);$balance=$q->fetch(PDO::FETCH_ASSOC);
        if (!in_array($balance['status'],['approved','partially_paid'],true) || $balance['pwp_status']==='awaiting_ar') throw new PurePayApiException('An allocated bill is not released for payment.');
        $q=$pdo->prepare('SELECT a.amount_applied FROM ap_payment_allocations a JOIN ap_payments p ON p.id=a.payment_id
            WHERE a.bill_id=? AND p.tenant_id=? AND p.status IN ("draft","queued","sent") FOR UPDATE');$q->execute([$bill,$tenant]);$held=0;
        foreach($q->fetchAll(PDO::FETCH_COLUMN)as$value){$c=purepayMoneyCents($value);if($c===null || $c<=0)throw new PurePayApiException('A competing bill reservation is invalid.');$held+=$c;}
        $due=purepayMoneyCents($balance['amount_due']);
        if($due===null || $held>$due)throw new PurePayApiException('Competing allocations exceed the original bill balance.');
        $remote=$snapshot['provider'];unset($remote['bill_id'],$remote['status']);
        $request=['organization_id'=>$binding['remote_organization_id'],'core_instance_id'=>$instance,
            'core_tenant_id'=>$tenant,'core_payment_id'=>$payment,'core_bill_id'=>$bill,'amount_cents'=>$amount,'bill'=>$remote];
        $rid=purepayAllocationHash(['bill_id'=>$mapped['purepay_bill_id'],'organization_id'=>$binding['remote_organization_id'],
            'core_instance_id'=>$instance,'core_tenant_id'=>$tenant,'core_payment_id'=>$payment,'core_bill_id'=>$bill]);
        $children[]=['core_bill_id'=>$bill,'provider_bill_id'=>$mapped['purepay_bill_id'],'provider_vendor_id'=>$mapped['purepay_vendor_id'],
            'amount_cents'=>$amount,'reservation_id'=>$rid,'request'=>$request,'review_hash'=>$mapped['review_hash']];
    }
    return ['instance_id'=>$instance,'origin_hash'=>hash('sha256',purepayApiBase()),'organization_id'=>$binding['remote_organization_id'],
        'connection_fingerprint'=>purepayConnectionFingerprint($tenant,purepayFinancialKey($connection)),
        'item'=>$item,'effective_date'=>$date,'allocations'=>$children];
}

/** Commit parent and children before any provider mutation; replay never changes them. */
function purepayAllocationPrepare(PDO $pdo,int $tenant,int $payment,array $item,string $date): array
{
    if ($pdo->inTransaction() || !(bool)$pdo->getAttribute(PDO::ATTR_AUTOCOMMIT)) throw new LogicException('Dispatch preparation needs its own durable transaction.');
    $pdo->beginTransaction();
    try {
        $source=purepayAllocationSource($pdo,$tenant,$payment,$item,$date);
        $existing=purepayAllocationParent($pdo,$tenant,$payment,true);
        if ($existing) {
            if (!hash_equals($existing['request_hash'],purepayAllocationHash($source))) throw new PurePayApiException('This payment already has a different immutable dispatch request.');
            $pdo->commit();return $existing;
        }
        $pdo->commit();
        // Review GETs are outside SQL locks; confirmation below detects changes.
        foreach($source['allocations']as$allocation){
            $review=purepayBillReview($pdo,$tenant,$allocation['core_bill_id'],$allocation['provider_bill_id']);
            if(!hash_equals($allocation['review_hash'],$review['review_hash']))throw new PurePayApiException('The original supplier bill changed before reservation. Review it again.');
        }
        $pdo->beginTransaction();
        $current=purepayAllocationSource($pdo,$tenant,$payment,$item,$date);
        if($current!==$source)throw new PurePayApiException('The AP allocations changed while checking the supplier bills.');
        $existing=purepayAllocationParent($pdo,$tenant,$payment,true);
        $hash=purepayAllocationHash($source);
        if ($existing) {
            if(!hash_equals($existing['request_hash'],$hash))throw new PurePayApiException('A different dispatch claimed this payment.');
            $pdo->commit();return $existing;
        }
        $pdo->prepare('INSERT INTO purepay_allocated_payments(tenant_id,core_payment_id,amount_cents,instance_id,origin_hash,organization_id,connection_fingerprint,request_hash,request_json)
            VALUES(?,?,?,?,?,?,?,?,?)')->execute([$tenant,$payment,$item['amount_cents'],$source['instance_id'],$source['origin_hash'],$source['organization_id'],
                $source['connection_fingerprint'],$hash,purepayAllocationJson($source)]);
        foreach($source['allocations']as$a)$pdo->prepare('INSERT INTO purepay_payment_allocations
            (tenant_id,core_payment_id,core_bill_id,origin_hash,organization_id,provider_bill_id,provider_vendor_id,reservation_id,amount_cents,request_hash,request_json)
            VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$tenant,$payment,$a['core_bill_id'],$source['origin_hash'],$source['organization_id'],$a['provider_bill_id'],
                $a['provider_vendor_id'],$a['reservation_id'],$a['amount_cents'],purepayAllocationHash($a['request']),purepayAllocationJson($a['request'])]);
        $result=purepayAllocationParent($pdo,$tenant,$payment);
        $pdo->commit();return $result;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Validate the complete original reservation envelope before trusting any money. */
function purepayAllocationEvidence(array $child,array $remote): array
{
    $request=json_decode($child['request_json'],true,512,JSON_THROW_ON_ERROR);
    foreach(['contract_version'=>'2026-10-25.1','reservation_id'=>$child['reservation_id'],
        'organization_id'=>$child['organization_id'],'bill_id'=>$child['provider_bill_id'],'request_hash'=>$child['request_hash']]as$key=>$expected){
        if(($remote[$key]??null)!==$expected)throw new PurePayApiException('The provider reservation does not match its original allocation.');
    }
    if(!is_array($remote['request']??null) || !hash_equals($child['request_hash'],purepayAllocationHash($remote['request']))
        || purepayAllocationJson($remote['request'])!==purepayAllocationJson($request))throw new PurePayApiException('The provider reservation request changed.');
    $state=$remote['state']??null;
    if(!in_array($state,['held','released','executing','submitted','needs_review','completed'],true))throw new PurePayApiException('The provider reservation state is unknown.');
    $evidence=null;$payment=$remote['payment']??null;
    if ($payment!==null) {
        if(!is_array($payment) || ($payment['vendor_id']??null)!==$child['provider_vendor_id'])throw new PurePayApiException('The payment vendor differs from its reserved bill.');
        $evidence=purepayPaymentEvidence($payment,(int)$child['amount_cents'],$child['provider_bill_id'],(string)($child['provider_payment_id']??''));
        if(!$evidence['ok'])throw new PurePayApiException('The allocation payment needs review: '.$evidence['reason']);
    }
    if(in_array($state,['held','released'],true) && ($payment!==null || !empty($child['provider_payment_id'])))throw new PurePayApiException('A consumed payment cannot become an unexecuted reservation.');
    if($state==='completed' && (!$evidence || !in_array($evidence['status'],['settled','returned','failed','cancelled'],true)))throw new PurePayApiException('A completed reservation needs terminal payment evidence.');
    if($evidence && purepayPaymentStatus($evidence['status'])==='settled'
        && (empty($payment['moov_transfer_id']) || ($payment['moov_status']??null)!=='completed'))
        throw new PurePayApiException('A settled allocation needs confirmed delivery of its original transfer.');
    return ['provider_state'=>$state,'evidence'=>$evidence,'payment_status'=>$evidence?purepayPaymentStatus($evidence['status']):'unknown'];
}

/** Verify the complete child set against the immutable parent, not only its sum. */
function purepayAllocationChildrenMatch(array $parent,array $children): bool
{
    $saved=json_decode((string)$parent['request_json'],true);
    if(!is_array($saved) || !hash_equals($parent['request_hash'],purepayAllocationHash($saved))
        || !is_array($saved['allocations']??null) || count($saved['allocations'])!==count($children))return false;
    $expected=[];
    foreach($saved['allocations']as$a)$expected[(int)$a['core_bill_id']]=$a;
    if(count($expected)!==count($children))return false;
    foreach($children as$c){
        $id=(int)$c['core_bill_id'];$a=$expected[$id]??null;
        if(!$a || (int)$c['tenant_id']!==(int)$parent['tenant_id'] || (int)$c['core_payment_id']!==(int)$parent['core_payment_id']
            || $c['origin_hash']!==$parent['origin_hash'] || $c['organization_id']!==$parent['organization_id']
            || (int)$c['amount_cents']!==$a['amount_cents'] || $c['provider_bill_id']!==$a['provider_bill_id']
            || $c['provider_vendor_id']!==$a['provider_vendor_id'] || $c['reservation_id']!==$a['reservation_id']
            || !hash_equals($c['request_hash'],purepayAllocationHash($a['request']))
            || $c['request_json']!==purepayAllocationJson($a['request']))return false;
        unset($expected[$id]);
    }
    return !$expected;
}

/** Only all matching settled children can settle a multi-bill parent. */
function purepayAllocationAggregate(array $parent,array $children): string
{
    if(!$children || !purepayAllocationChildrenMatch($parent,$children))return 'needs_review';
    $total=0;$settled=true;$released=true;$prepared=true;
    foreach($children as$c){
        $total+=(int)$c['amount_cents'];
        if(!empty($c['last_error']))return 'needs_review';
        $state=$c['provider_state'];$status=$c['payment_status'];
        if($state==='needs_review' || in_array($status,['failed','returned','cancelled'],true))return 'needs_review';
        $settled=$settled && $state==='completed' && $status==='settled' && !empty($c['receipt_json']);
        $released=$released && $state==='released' && empty($c['provider_payment_id']);
        $prepared=$prepared && in_array($state,['unreserved','held'],true);
    }
    if($total!==(int)$parent['amount_cents'])return 'needs_review';
    return $settled?'settled':($released?'cancelled':($prepared?'prepared':'submitted'));
}

/** Persist one allocation result atomically with parent aggregation and receipt ownership. */
function purepayAllocationSave(PDO $pdo,int $tenant,int $payment,int $bill,array $remote): string
{
    if($pdo->inTransaction())throw new LogicException('Provider evidence needs its own durable transaction.');
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT id FROM ap_payments WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$tenant,$payment]);
        if(!$q->fetchColumn())throw new PurePayApiException('The AP payment was not found.');
        $binding=purepayFinancialRequire($pdo,$tenant);
        $parent=purepayAllocationParent($pdo,$tenant,$payment,true);
        $connection=purepayFinancialConnection($pdo,$tenant,true);
        if(!$parent || $parent['organization_id']!==$binding['remote_organization_id']
            || !hash_equals($parent['connection_fingerprint'],purepayConnectionFingerprint($tenant,purepayFinancialKey($connection))))throw new PurePayApiException('Review the changed financial connection before reconciling this dispatch.');
        $children=purepayAllocationChildren($pdo,$tenant,$payment,true);$child=null;
        if(!purepayAllocationChildrenMatch($parent,$children))throw new PurePayApiException('The original allocation records changed.');
        foreach($children as$row)if((int)$row['core_bill_id']===$bill)$child=$row;
        if(!$child)throw new PurePayApiException('The original payment allocation was not found.');
        $verified=purepayAllocationEvidence($child,$remote);$e=$verified['evidence'];$providerPayment=$e['payment_id']??null;
        $receiptHash=purepayAllocationHash(['reservation_id'=>$child['reservation_id'],'request_hash'=>$child['request_hash'],
            'state'=>$verified['provider_state'],'payment'=>$remote['payment']??null]);
        $pdo->prepare('INSERT IGNORE INTO purepay_allocation_receipts(tenant_id,core_payment_id,core_bill_id,receipt_hash,reservation_json)
            VALUES(?,?,?,?,?)')->execute([$tenant,$payment,$bill,$receiptHash,purepayAllocationJson($remote)]);
        if($providerPayment!==null){
            $q=$pdo->prepare('SELECT id FROM purepay_payment_links WHERE tenant_id=? AND purepay_payment_id=? FOR UPDATE');$q->execute([$tenant,$providerPayment]);
            if($q->fetchColumn()!==false)throw new PurePayApiException('This receipt already belongs to a historical Core payment.');
        }
        $status=$verified['payment_status'];$error=null;
        if(in_array($child['payment_status'],['settled','returned','failed','cancelled'],true)
            && !in_array($status,['settled','returned','failed','cancelled'],true)) {
            // A delayed pre-settlement response cannot replace the terminal receipt.
            $pdo->commit();return $parent['status'];
        }
        if($child['payment_status']==='settled' && $status!=='settled')$error='A settled allocation changed. Review accounting before changing the parent payment.';
        if(in_array($child['payment_status'],['returned','failed','cancelled'],true) && $status!==$child['payment_status'])$error='A terminal allocation changed. Review the original payment.';
        $pdo->prepare('UPDATE purepay_payment_allocations SET provider_state=?,provider_payment_id=COALESCE(?,provider_payment_id),payment_status=?,
            reservation_json=?,receipt_json=COALESCE(?,receipt_json),last_error=?,updated_at=NOW() WHERE tenant_id=? AND core_payment_id=? AND core_bill_id=?')
            ->execute([$verified['provider_state'],$providerPayment,$status,purepayAllocationJson($remote),
                $e?purepayAllocationJson(['version'=>1,'source'=>'reservation','payment'=>$e]):null,$error,$tenant,$payment,$bill]);
        $status=purepayAllocationAggregate($parent,purepayAllocationChildren($pdo,$tenant,$payment,true));
        $pdo->prepare('UPDATE purepay_allocated_payments SET status=?,last_error=?,updated_at=NOW() WHERE tenant_id=? AND core_payment_id=?')
            ->execute([$status,$status==='needs_review'?'Reconcile every original bill allocation before changing this payment.':null,$tenant,$payment]);
        $pdo->commit();return $status;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function purepayAllocationHold(PDO $pdo,int $tenant,int $payment,int $bill): void
{
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT id FROM ap_payments WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$tenant,$payment]);
        $parent=purepayAllocationParent($pdo,$tenant,$payment,true);
        if($parent){
            $pdo->prepare('UPDATE purepay_payment_allocations SET last_error="Original reservation needs reconciliation",updated_at=NOW()
                WHERE tenant_id=? AND core_payment_id=? AND core_bill_id=?')->execute([$tenant,$payment,$bill]);
            $pdo->prepare('UPDATE purepay_allocated_payments SET status="needs_review",last_error="Reconcile the original bill allocations",updated_at=NOW()
                WHERE tenant_id=? AND core_payment_id=?')->execute([$tenant,$payment]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Every call checks the committed AP source; only the provider owns execute-once. */
function purepayAllocationCall(PDO $pdo,int $tenant,int $payment,int $bill,string $action): string
{
    if($pdo->inTransaction() || !in_array($action,['reserve','execute','reconcile','release'],true))throw new LogicException('Invalid allocation operation');
    $parent=purepayAllocationParent($pdo,$tenant,$payment);
    if(!$parent)throw new PurePayApiException('Prepare the original allocation before dispatch.');
    $saved=json_decode($parent['request_json'],true,512,JSON_THROW_ON_ERROR);
    $binding=purepayFinancialRequire($pdo,$tenant,null,true);
    $pdo->beginTransaction();
    try {
        if(in_array($action,['reserve','execute'],true)) {
            $current=purepayAllocationSource($pdo,$tenant,$payment,$saved['item'],$saved['effective_date']);
            if(!hash_equals($parent['request_hash'],purepayAllocationHash($current)))throw new PurePayApiException('The original AP source changed before provider access.');
        } else {
            $q=$pdo->prepare('SELECT id FROM ap_payments WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$tenant,$payment]);
            if(!$q->fetchColumn())throw new PurePayApiException('The original AP payment was not found.');
            $binding=purepayFinancialRequire($pdo,$tenant);
            $connection=purepayFinancialConnection($pdo,$tenant,true);
            if($parent['organization_id']!==$binding['remote_organization_id']
                || $parent['connection_fingerprint']!==purepayConnectionFingerprint($tenant,purepayFinancialKey($connection)))throw new PurePayApiException('Reconfirm the original company connection before reconciliation.');
        }
        $parent=purepayAllocationParent($pdo,$tenant,$payment,true);
        if($action==='execute' && $saved['effective_date']>date('Y-m-d'))throw new PurePayApiException('This payment is scheduled for a future date.');
        $children=purepayAllocationChildren($pdo,$tenant,$payment,true);
        if(!purepayAllocationChildrenMatch($parent,$children))throw new PurePayApiException('The original allocation records changed.');
        $child=null;foreach($children as$row)if((int)$row['core_bill_id']===$bill)$child=$row;
        if(!$child)throw new PurePayApiException('Unknown original bill allocation.');
        if($action==='execute') {
            foreach($children as$row)
                if(!in_array($row['provider_state'],['held','executing','submitted','completed'],true) || !empty($row['last_error']))
                    throw new PurePayApiException('Reserve and reconcile every bill before executing any allocation.');
        }
        $key=purepayFinancialKey(purepayFinancialConnection($pdo,$tenant,true));
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $path='/bills/'.rawurlencode($child['provider_bill_id']).'/reservations';
    $body=$action==='reserve'?json_decode($child['request_json'],true,512,JSON_THROW_ON_ERROR):[];
    if($action!=='reserve')$path.='/'.$child['reservation_id'].'/'.$action;
    try {
        // Provider claim IDs, not the optional response cache, make retry safe.
        $remote=purepayCall($key,'POST',$path,$body,25,'core_'.$action.'_'.$child['reservation_id']);
        return purepayAllocationSave($pdo,$tenant,$payment,$bill,$remote);
    }catch(Throwable $e){purepayAllocationHold($pdo,$tenant,$payment,$bill);throw $e;}
}

function purepayAllocationDispatch(PDO $pdo,int $tenant,int $payment,array $item,string $date): array
{
    purepayAllocationPrepare($pdo,$tenant,$payment,$item,$date);
    foreach(purepayAllocationChildren($pdo,$tenant,$payment)as$child)
        purepayAllocationCall($pdo,$tenant,$payment,(int)$child['core_bill_id'],'reserve');
    foreach(purepayAllocationChildren($pdo,$tenant,$payment)as$child)
        purepayAllocationCall($pdo,$tenant,$payment,(int)$child['core_bill_id'],'execute');
    $parent=purepayAllocationParent($pdo,$tenant,$payment);
    return ['external_ref'=>'ap_payment:'.$payment,'rail_external_ref'=>'purepay:allocation:'.$payment,
        'status'=>$parent['status']==='settled'?'settled':($parent['status']==='submitted'?'submitted':'unknown')];
}

/** AP guard runs with the caller's payment lock and never makes provider calls. */
function purepayAllocationAssertAction(PDO $pdo,int $tenant,array $payment,string $action): bool
{
    if(!$pdo->inTransaction() || !in_array($action,['allocate','void','clear'],true))throw new LogicException('Allocation action requires the AP transaction.');
    $parent=purepayAllocationParent($pdo,$tenant,(int)$payment['id'],true);
    if(!$parent)return false;
    if($action==='allocate')throw new DomainException('This payment already reserves its original supplier bills.');
    $binding=purepayFinancialRequire($pdo,$tenant);
    $connection=purepayFinancialConnection($pdo,$tenant,true);
    if($parent['organization_id']!==$binding['remote_organization_id']
        || $parent['connection_fingerprint']!==purepayConnectionFingerprint($tenant,purepayFinancialKey($connection))
        || (int)$parent['amount_cents']!==purepayMoneyCents($payment['amount']) || $payment['currency']!=='USD')throw new DomainException('The original dispatch or company needs review.');
    $children=purepayAllocationChildren($pdo,$tenant,(int)$payment['id'],true);
    $saved=json_decode($parent['request_json'],true,512,JSON_THROW_ON_ERROR);
    $current=purepayAllocationSource($pdo,$tenant,(int)$payment['id'],$saved['item'],$saved['effective_date']);
    if(!hash_equals($parent['request_hash'],purepayAllocationHash($current)))throw new DomainException('The current AP allocations differ from the original dispatch.');
    foreach($children as$c){
        if(!$c['reservation_json'])throw new DomainException('An allocation has no confirmed provider reservation.');
        $verified=purepayAllocationEvidence($c,json_decode($c['reservation_json'],true,512,JSON_THROW_ON_ERROR));
        if($verified['provider_state']!==$c['provider_state'] || $verified['payment_status']!==$c['payment_status'])throw new DomainException('The saved allocation evidence disagrees.');
        if($c['provider_payment_id']){
            $q=$pdo->prepare('SELECT id FROM purepay_payment_links WHERE tenant_id=? AND purepay_payment_id=? FOR UPDATE');$q->execute([$tenant,$c['provider_payment_id']]);
            if($q->fetchColumn()!==false)throw new DomainException('The allocation receipt conflicts with a historical payment.');
        }
    }
    $aggregate=purepayAllocationAggregate($parent,$children);
    if($aggregate!==$parent['status'] || ($action==='clear' && $aggregate!=='settled') || ($action==='void' && $aggregate!=='cancelled'))
        throw new DomainException('Reconcile every original supplier bill before changing this payment.');
    return true;
}
