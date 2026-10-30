<?php
/** Original AP bank instructions and results. No automatic financial retry. */
declare(strict_types=1);
require_once __DIR__.'/payment_rails.php';
require_once __DIR__.'/encryption.php';

function apBankSchemaAvailable(PDO $pdo): bool
{
    $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ? "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('ap_bank_dispatches','ap_bank_payment_claims')"
        : "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('ap_bank_dispatches','ap_bank_payment_claims')";
    return (int)$pdo->query($sql)->fetchColumn()===2;
}

function apBankClaim(PDO $pdo,int $tenant,int $payment,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM ap_bank_payment_claims WHERE tenant_id=? AND payment_id=?'.($lock?' FOR UPDATE':''));
    $q->execute([$tenant,$payment]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function apBankJson(array $value): string
{
    $sort=static function(mixed $v)use(&$sort):mixed{
        if(!is_array($v))return $v;
        if(!array_is_list($v))ksort($v,SORT_STRING);
        foreach($v as $k=>$child)$v[$k]=$sort($child);
        return $v;
    };
    return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}

function apBankCents(mixed $value): int
{
    if(!preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,2}))?$/D',(string)$value,$m))
        throw new PaymentRailsOriginateException('Invalid AP money amount');
    return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
}

/** The caller holds this AP row, the common lock for all supported payment services. */
function apBankSource(PDO $pdo,int $tenant,array $p,array $item,int $actor): array
{
    $id=(int)$p['id'];
    if(!in_array($p['status'],['draft','queued','sent'],true) || !empty($p['rail_external_ref'])
        || $p['currency']!=='USD' || (int)($p['entity_id']??0)<=0 || apBankCents($p['unallocated_amount'])!==0
        || !is_int($item['amount_cents']??null) || $item['amount_cents']<=0
        || apBankCents($p['amount'])!==$item['amount_cents'] || ($item['currency']??'USD')!=='USD'
        || trim((string)($item['recipient_full_name']??$item['recipient_name']??''))!==$p['vendor_name']
        || $actor<=0 || (int)($p['created_by_user_id']??0)===$actor) {
        throw new PaymentRailsOriginateException('Review the unclaimed, fully allocated USD payment with an independent operator');
    }
    $q=$pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id=? AND id=? FOR UPDATE');
    $q->execute([$tenant,$p['entity_id']]);
    if(!$q->fetchColumn())throw new PaymentRailsOriginateException('The payment legal entity must belong to this company');
    foreach(['purepay_allocated_payments','purepay_payment_links','payment_instructions'] as $table){
        $q=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);
        if(!$q->fetchColumn())continue;
        [$sql,$params]=match($table){
            'purepay_allocated_payments'=>['SELECT core_payment_id FROM purepay_allocated_payments WHERE tenant_id=? AND core_payment_id=? FOR UPDATE',[$tenant,$id]],
            'purepay_payment_links'=>['SELECT id FROM purepay_payment_links WHERE tenant_id=? AND (core_payment_id=? OR source_ref=?) FOR UPDATE',[$tenant,$id,'ap_payment:'.$id]],
            default=>['SELECT id FROM payment_instructions WHERE tenant_id=? AND source_module IN ("ap","ap_payment") AND source_ref IN (?,?) FOR UPDATE',[$tenant,(string)$id,'ap_payment:'.$id]],
        };
        $q=$pdo->prepare($sql);$q->execute($params);
        if($q->fetchColumn()!==false)throw new PaymentRailsOriginateException('This payment already has an original provider delivery; review it before changing service');
    }
    $q=$pdo->prepare('SELECT a.bill_id,a.amount_applied,b.tenant_id,b.entity_id,b.vendor_name,b.currency,b.status,b.pwp_status
        FROM ap_payment_allocations a JOIN ap_bills b ON b.id=a.bill_id WHERE a.payment_id=? ORDER BY b.id,a.id FOR UPDATE');
    $q->execute([$id]);$allocations=[];$total=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $b){
        $cents=apBankCents($b['amount_applied']);
        if($cents<=0 || (int)$b['tenant_id']!==$tenant || (int)$b['entity_id']!==(int)$p['entity_id']
            || $b['vendor_name']!==$p['vendor_name'] || $b['currency']!=='USD'
            || !in_array($b['status'],['approved','partially_paid'],true) || $b['pwp_status']==='awaiting_ar')
            throw new PaymentRailsOriginateException('An original supplier bill is not released for payment');
        $total+=$cents;$allocations[]=['bill_id'=>(int)$b['bill_id'],'amount_cents'=>$cents];
    }
    if($total!==$item['amount_cents'])throw new PaymentRailsOriginateException('Original bill allocations must equal the payment');
    return ['payment_id'=>$id,'amount_cents'=>$total,'vendor_name'=>$p['vendor_name'],'currency'=>$p['currency'],
        'entity_id'=>(int)($p['entity_id']??0),'pay_date'=>$p['pay_date'],'allocations'=>$allocations];
}

/** Only this exact packet can recover a saved response; it can never call the driver again. */
function apBankDispatch(PDO $pdo,int $tenant,string $rail,array $items,array $opts,callable $originate): array
{
    if($pdo->inTransaction() || !(bool)$pdo->getAttribute(PDO::ATTR_AUTOCOMMIT))throw new LogicException('Bank dispatch requires its own durable transaction');
    if($tenant<=0 || !in_array($rail,['nacha','plaid_transfer'],true) || !$items || count($items)>500
        || (int)($opts['tenant_id']??0)!==$tenant || (int)($opts['user_id']??0)<=0)
        throw new PaymentRailsOriginateException('Company, operator and original AP payments are required');
    if(!apBankSchemaAvailable($pdo))throw new PaymentRailsOriginateException('Apply the bank-dispatch migration before preparing payments');
    $ordered=[];
    foreach($items as $item){
        if(!preg_match('/^ap_payment:([1-9][0-9]*)$/D',(string)($item['external_ref']??''),$m)
            || (string)(int)$m[1]!==$m[1] || isset($ordered[(int)$m[1]]))throw new PaymentRailsOriginateException('Use unique canonical AP payment identities');
        $ordered[(int)$m[1]]=$item;
    }
    ksort($ordered,SORT_NUMERIC);$items=array_values($ordered);
    $packet=['tenant_id'=>$tenant,'rail'=>$rail,'items'=>$items,'options'=>$opts];
    $hash=fieldHash(apBankJson($packet));$dispatch=bin2hex(random_bytes(16));$committed=false;
    $pdo->beginTransaction();
    try{
        $payments=[];$claims=[];
        foreach($ordered as $id=>$item){
            $q=$pdo->prepare('SELECT * FROM ap_payments WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$tenant,$id]);
            $payments[$id]=$q->fetch(PDO::FETCH_ASSOC);
            if(!$payments[$id])throw new PaymentRailsOriginateException('An AP payment is unavailable in this company');
            $claim=apBankClaim($pdo,$tenant,$id,true);if($claim)$claims[$id]=$claim;
        }
        if($claims){
            $ids=array_unique(array_column($claims,'dispatch_id'));
            if(count($claims)!==count($items) || count($ids)!==1)throw new PaymentRailsOriginateException('Some payments belong to a different original batch');
            $q=$pdo->prepare('SELECT * FROM ap_bank_dispatches WHERE tenant_id=? AND dispatch_id=? FOR UPDATE');$q->execute([$tenant,$ids[0]]);$saved=$q->fetch(PDO::FETCH_ASSOC);
            if(!$saved || !hash_equals($saved['packet_hash'],$hash))throw new PaymentRailsOriginateException('The original bank instruction cannot be changed; open its saved delivery');
            if(!$saved['result_ct'])throw new PaymentRailsOriginateException('The original bank request needs reconciliation; it will not be sent again');
            $result=json_decode((string)decryptField($saved['result_ct']),true,512,JSON_THROW_ON_ERROR);
            $pdo->commit();return $result;
        }
        $sources=[];
        foreach($ordered as $id=>$item)$sources[]=apBankSource($pdo,$tenant,$payments[$id],$item,(int)$opts['user_id']);
        $pdo->prepare('INSERT INTO ap_bank_dispatches(tenant_id,dispatch_id,rail,actor_id,packet_hash,request_ct) VALUES(?,?,?,?,?,?)')
            ->execute([$tenant,$dispatch,$rail,$opts['user_id'],$hash,encryptField(apBankJson($packet+['sources'=>$sources]))]);
        foreach($ordered as $id=>$item){
            $pdo->prepare('INSERT INTO ap_bank_payment_claims(tenant_id,payment_id,dispatch_id,rail,amount_cents) VALUES(?,?,?,?,?)')
                ->execute([$tenant,$id,$dispatch,$rail,$item['amount_cents']]);
            $pdo->prepare('UPDATE ap_payments SET disbursement_rail=?,rail_external_ref=?,rail_status="unknown",status="sent",
                sent_at=COALESCE(sent_at,NOW()),sent_by_user_id=COALESCE(sent_by_user_id,?) WHERE tenant_id=? AND id=?')
                ->execute([$rail,'core:ap:'.$dispatch.':'.$id,$opts['user_id'],$tenant,$id]);
        }
        $opts['batch_id']='ap_'.$dispatch;
        // NACHA is a pure local formatter. Its original bytes and claims become
        // durable together before any file is returned to the browser.
        if($rail==='nacha'){
            $result=apBankRecordLocked($pdo,$tenant,$dispatch,$rail,$ordered,$originate($items,$opts));
            $pdo->commit();return $result;
        }
        $pdo->commit();$committed=true;
        // A provider request may be accepted even when its response is lost.
        $raw=$originate($items,$opts);
        $pdo->beginTransaction();
        $result=apBankRecordLocked($pdo,$tenant,$dispatch,$rail,$ordered,$raw);
        $pdo->commit();return $result;
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if($committed){
            $pdo->prepare('UPDATE ap_bank_dispatches SET state="needs_review" WHERE tenant_id=? AND dispatch_id=? AND state="attempted"')->execute([$tenant,$dispatch]);
            throw new PaymentRailsOriginateException('The original bank request needs reconciliation. Its payments remain reserved; do not send them again.',0,$e);
        }
        throw $e;
    }
}

/** Validate complete result identity and save without letting an old API response rewrite it later. */
function apBankRecordLocked(PDO $pdo,int $tenant,string $dispatch,string $rail,array $ordered,array $result): array
{
    if(!$pdo->inTransaction())throw new LogicException('Bank result needs its transaction');
    $byRef=[];
    foreach($result['items']??[] as $r){
        $ref=(string)($r['external_ref']??'');
        if(isset($byRef[$ref]))throw new PaymentRailsOriginateException('Duplicate bank result identity');
        $byRef[$ref]=$r;
    }
    if(count($byRef)!==count($ordered))throw new PaymentRailsOriginateException('Incomplete bank result');
    $review=false;$normalized=[];
    foreach($ordered as $id=>$item){
        $r=$byRef['ap_payment:'.$id]??null;
        if(!$r)throw new PaymentRailsOriginateException('Bank result does not match the original AP payment');
        $q=$pdo->prepare('SELECT rail_external_ref FROM ap_payments WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$tenant,$id]);
        $marker='core:ap:'.$dispatch.':'.$id;
        if($q->fetchColumn()!==$marker)throw new PaymentRailsOriginateException('The original AP claim changed before its result was saved');
        $claim=apBankClaim($pdo,$tenant,$id,true);
        if(!$claim || $claim['dispatch_id']!==$dispatch || $claim['rail']!==$rail || (int)$claim['amount_cents']!==$item['amount_cents'])
            throw new PaymentRailsOriginateException('Bank receipt ownership does not match the original claim');
        $ref=$r['rail_external_ref']??null;$status=(string)($r['status']??'unknown');
        if(!is_string($ref) || $ref==='' || strlen($ref)>160
            || !in_array($status,['queued','pending','submitted','posted','settled','failed','returned','cancelled'],true)){
            $review=true;$ref=null;$status='unknown';
        }
        if(in_array($status,['failed','returned','cancelled'],true))$review=true;
        $pdo->prepare('UPDATE ap_bank_payment_claims SET provider_ref=?,receipt_owner=?,result_status=?
            WHERE tenant_id=? AND payment_id=? AND dispatch_id=?')
            ->execute([$ref,$rail==='plaid_transfer'?$ref:null,$status,$tenant,$id,$dispatch]);
        $pdo->prepare('UPDATE ap_payments SET rail_external_ref=?,rail_status=?,rail_originated_at=NOW() WHERE tenant_id=? AND id=?')
            ->execute([$ref??$marker,$status,$tenant,$id]);
        $normalized[]=['external_ref'=>'ap_payment:'.$id,'rail_external_ref'=>$ref??$marker,'status'=>$status,
            'error'=>$status==='unknown'?'Original bank request needs reconciliation':null];
    }
    if($rail==='nacha' && (!is_string($result['payload']['content']??null) || $result['payload']['content']===''))
        throw new PaymentRailsOriginateException('The original bank file is missing');
    $result['items']=$normalized;$result['rail']=$rail;$result['ap_dispatch_id']=$dispatch;$result['ap_dispatch_recorded']=true;
    if($review)$result['status']='unknown';
    $pdo->prepare('UPDATE ap_bank_dispatches SET result_ct=?,state=?,recorded_at=NOW() WHERE tenant_id=? AND dispatch_id=?')
        ->execute([encryptField(apBankJson($result)),$review?'needs_review':'recorded',$tenant,$dispatch]);
    return $result;
}

/** Saved evidence only. Callers enforce view permission and separate bank-file download permission. */
function apBankDelivery(PDO $pdo,int $tenant,int $payment,bool $download=false): array
{
    $q=$pdo->prepare('SELECT d.* FROM ap_bank_payment_claims c JOIN ap_bank_dispatches d
        ON d.tenant_id=c.tenant_id AND d.dispatch_id=c.dispatch_id WHERE c.tenant_id=? AND c.payment_id=?');
    $q->execute([$tenant,$payment]);$d=$q->fetch(PDO::FETCH_ASSOC);
    if(!$d)throw new DomainException('No original bank delivery found in this company');
    $q=$pdo->prepare('SELECT payment_id,amount_cents,provider_ref,result_status FROM ap_bank_payment_claims WHERE tenant_id=? AND dispatch_id=? ORDER BY payment_id');
    $q->execute([$tenant,$d['dispatch_id']]);
    $payments=$q->fetchAll(PDO::FETCH_ASSOC);
    $request=json_decode((string)decryptField($d['request_ct']),true,512,JSON_THROW_ON_ERROR);
    $sources=array_column($request['sources'],null,'payment_id');
    foreach($payments as &$p)$p['vendor_name']=$sources[$p['payment_id']]['vendor_name']??'Original supplier';
    unset($p);
    $view=['tenant_id'=>$tenant,'dispatch_id'=>$d['dispatch_id'],'rail'=>$d['rail'],'state'=>$d['state'],
        'created_at'=>$d['created_at'],'recorded_at'=>$d['recorded_at'],'payments'=>$payments,
        'can_download'=>$d['rail']==='nacha' && $d['state']==='recorded' && !empty($d['result_ct'])];
    if($download){
        if(!$view['can_download'])throw new DomainException('No original bank file is available for this delivery');
        $result=json_decode((string)decryptField($d['result_ct']),true,512,JSON_THROW_ON_ERROR);
        $view['nacha_file_b64']=base64_encode($result['payload']['content']);
        $view['nacha_filename']=$result['payload']['filename'];
        $view['file_sha256']=hash('sha256',$result['payload']['content']);
    }
    return $view;
}

/** Unknown bank outcomes must not free their bill allocations or become accounting settlement. */
function apBankAssertAction(PDO $pdo,int $tenant,array $payment,string $action): ?bool
{
    if(!apBankSchemaAvailable($pdo))return null;
    $claim=apBankClaim($pdo,$tenant,(int)$payment['id'],true);
    if(!$claim)return null;
    $q=$pdo->prepare('SELECT state FROM ap_bank_dispatches WHERE tenant_id=? AND dispatch_id=? FOR UPDATE');$q->execute([$tenant,$claim['dispatch_id']]);
    $state=$q->fetchColumn();
    if($action==='clear' && $claim['rail']==='nacha' && $state==='recorded' && $claim['provider_ref']
        && $payment['disbursement_rail']==='nacha' && $payment['rail_external_ref']===$claim['provider_ref']
        && apBankCents($payment['amount'])===(int)$claim['amount_cents'] && $payment['currency']==='USD')return false;
    throw new DomainException('This payment retains its original bank instruction. Reconcile that delivery before changing its allocations, cancelling or clearing it.');
}
