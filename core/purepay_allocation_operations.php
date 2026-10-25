<?php
/** Audited operator recovery of an existing, immutable original-bill dispatch. */
declare(strict_types=1);
require_once __DIR__.'/purepay_allocated_payments.php';

/** No provider calls. Caller owns the AP lock and one consistent review transaction. */
function purepayAllocationReviewLocked(PDO $pdo,int $tenant,int $payment,int $actor,bool $canOperate): array
{
    if(!$pdo->inTransaction())throw new LogicException('Payment review requires a transaction.');
    $q=$pdo->prepare('SELECT * FROM ap_payments WHERE tenant_id=? AND id=? FOR UPDATE');
    $q->execute([$tenant,$payment]);$ap=$q->fetch(PDO::FETCH_ASSOC);
    if(!$ap)throw new DomainException('Payment not found.');
    $parent=purepayAllocationParent($pdo,$tenant,$payment,true);
    if(!$parent)throw new DomainException('This payment has no original-bill delivery record.');
    $children=purepayAllocationChildren($pdo,$tenant,$payment,true);
    $valid=purepayAllocationChildrenMatch($parent,$children);
    $connectionValid=false;$sourceValid=false;$reason='Review the original company connection.';
    try {
        $connection=purepayFinancialConnection($pdo,$tenant,true);
        $binding=purepayFinancialRequire($pdo,$tenant,$connection);
        $connectionValid=$parent['organization_id']===$binding['remote_organization_id']
            && $parent['origin_hash']===hash('sha256',purepayApiBase())
            && hash_equals($parent['connection_fingerprint'],purepayConnectionFingerprint($tenant,purepayFinancialKey($connection)))
            && $connection['key_scope']==='read_write' && $binding['provider_key_scope']==='write';
        if($connectionValid && $valid){
            $saved=json_decode($parent['request_json'],true,512,JSON_THROW_ON_ERROR);
            $source=purepayAllocationSource($pdo,$tenant,$payment,$saved['item'],$saved['effective_date']);
            $sourceValid=hash_equals($parent['request_hash'],purepayAllocationHash($source));
            $reason=$sourceValid?'':'The current payment differs from its original delivery request.';
        }
    }catch(PurePayApiException $e){$reason=$e->getMessage();}
    $cancelled=!empty($parent['release_requested']);
    $canResume=$sourceValid && $ap['status']==='sent' && !$cancelled;
    $canRelease=$connectionValid && $valid && in_array($ap['status'],['draft','queued','sent'],true);
    $hasWork=false;$bills=[];
    foreach($children as$c){
        $hasWork=$hasWork || in_array($c['provider_state'],['unreserved','held'],true);
        if(!empty($c['terminal_review']) || in_array($c['provider_state'],['released','needs_review'],true)
            || in_array($c['payment_status'],['failed','returned','cancelled'],true)
            || (!empty($c['last_error']) && $c['provider_state']!=='unreserved'))$canResume=false;
        if(!in_array($c['provider_state'],['unreserved','held','released'],true)
            || !empty($c['provider_payment_id']) || !empty($c['terminal_review']))$canRelease=false;
        $request=json_decode($c['request_json'],true,512,JSON_THROW_ON_ERROR);
        $bills[]=['bill_id'=>(int)$c['core_bill_id'],'invoice_number'=>$request['bill']['invoice_number'],
            'vendor_name'=>$request['bill']['vendor_name'],'amount_cents'=>(int)$c['amount_cents'],
            'state'=>$c['provider_state'],'payment_status'=>$c['payment_status'],'checked_at'=>$c['updated_at'],
            'needs_review'=>!empty($c['last_error']) || !empty($c['terminal_review']) || $c['provider_state']==='needs_review'];
    }
    $canResume=$canResume && $hasWork;
    if($sourceValid && $saved['effective_date']>date('Y-m-d')){
        $canResume=false;$reason='This payment is scheduled for a future date.';
    }
    if($cancelled)$reason='Cancellation is permanent. Refresh the results or finish cancelling the unexecuted bills.';
    elseif(!$valid)$reason='The original delivery records need review.';
    elseif($sourceValid && !$canResume && !$canRelease)$reason='Refresh the original results. A payment already attempted cannot be cancelled here.';
    if((int)($ap['created_by_user_id']??0)>0 && (int)$ap['created_by_user_id']===$actor){
        $canResume=false;$reason='Another authorized reviewer must resume a payment you created.';
    }
    $reviewHash=purepayAllocationHash(['parent'=>$parent,'children'=>$children,'payment'=>$ap,
        'connection_valid'=>$connectionValid,'source_valid'=>$sourceValid]);
    $q=$pdo->prepare('SELECT operation_id,phase,actor_id,action,reason,result_json,recorded_at FROM purepay_allocation_operations
        WHERE tenant_id=? AND core_payment_id=? ORDER BY recorded_at DESC,operation_id,phase LIMIT 30');
    $q->execute([$tenant,$payment]);$history=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($history as&$event){$event['result']=json_decode($event['result_json'],true);unset($event['result_json']);}unset($event);
    return ['tenant_id'=>$tenant,'payment_id'=>$payment,'company_name'=>$connectionValid?$binding['remote_organization_name']:'Original company needs review',
        'vendor_name'=>$ap['vendor_name'],'amount_cents'=>(int)$parent['amount_cents'],
        'status'=>purepayAllocationAggregate($parent,$children),'release_requested'=>$cancelled,
        'review_hash'=>$reviewHash,'bills'=>$bills,'history'=>$history,'reason'=>$reason,
        'can_resume'=>$canOperate && $canResume,'can_release'=>$canOperate && $canRelease,
        'can_reconcile'=>$canOperate && $connectionValid && $valid];
}

function purepayAllocationReview(PDO $pdo,int $tenant,int $payment,int $actor,bool $canOperate): array
{
    if($pdo->inTransaction())throw new LogicException('Payment review needs its own transaction.');
    $pdo->beginTransaction();
    try{$result=purepayAllocationReviewLocked($pdo,$tenant,$payment,$actor,$canOperate);$pdo->commit();return $result;}
    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Critical audit is mandatory, unlike the general best-effort activity logger. */
function purepayAllocationOperationEvent(PDO $pdo,string $operation,string $phase,int $tenant,int $payment,
    int $actor,string $action,string $reason,string $hash,array $result): void
{
    $pdo->prepare('INSERT INTO purepay_allocation_operations
        (operation_id,phase,tenant_id,core_payment_id,actor_id,action,reason,review_hash,result_json) VALUES(?,?,?,?,?,?,?,?,?)')
        ->execute([$operation,$phase,$tenant,$payment,$actor,$action,$reason,$hash,purepayAllocationJson($result)]);
}

/** Authorize the displayed evidence, commit the intent, then contact only original claims. */
function purepayAllocationOperate(PDO $pdo,int $tenant,int $payment,int $actor,bool $canOperate,
    string $action,string $hash,string $reason): array
{
    if($pdo->inTransaction() || !(bool)$pdo->getAttribute(PDO::ATTR_AUTOCOMMIT))throw new LogicException('Recovery needs a durable transaction.');
    $reason=trim($reason);
    if(!$canOperate || $actor<=0)throw new DomainException('An authorized AP reviewer is required.');
    if(!in_array($action,['resume','release','reconcile'],true) || !preg_match('/^[a-f0-9]{64}$/D',$hash)
        || $reason==='' || mb_strlen($reason)>500)throw new DomainException('Review the payment and give a reason of up to 500 characters.');
    $operation=bin2hex(random_bytes(16));$pdo->beginTransaction();
    try {
        $review=purepayAllocationReviewLocked($pdo,$tenant,$payment,$actor,true);
        if(!hash_equals($review['review_hash'],$hash))throw new DomainException('The payment changed. Review its latest results before continuing.');
        if(!$review['can_'.$action])throw new DomainException($review['reason']?:'This action is unavailable for the original payment.');
        purepayAllocationOperationEvent($pdo,$operation,'requested',$tenant,$payment,$actor,$action,$reason,$hash,
            ['status'=>$review['status'],'release_requested'=>$review['release_requested']]);
        if($action==='release')$pdo->prepare('UPDATE purepay_allocated_payments SET release_requested=1,status="needs_review",updated_at=NOW()
            WHERE tenant_id=? AND core_payment_id=?')->execute([$tenant,$payment]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $failed=false;
    if($action==='reconcile'){
        $failed=purepayAllocationReconcile($pdo,$tenant,$payment)==='unknown';
    }elseif($action==='release'){
        foreach($review['bills']as$bill){
            try{purepayAllocationCall($pdo,$tenant,$payment,$bill['bill_id'],'release');}
            catch(Throwable $e){$failed=true;}
        }
    }else{
        $parent=purepayAllocationParent($pdo,$tenant,$payment);
        $saved=json_decode($parent['request_json'],true,512,JSON_THROW_ON_ERROR);
        try{purepayAllocationDispatch($pdo,$tenant,$payment,$saved['item'],$saved['effective_date']);}
        catch(Throwable $e){$failed=true;}
    }
    $parent=purepayAllocationParent($pdo,$tenant,$payment);
    $phase=$failed || $parent['status']==='needs_review'?'needs_review':'completed';
    purepayAllocationOperationEvent($pdo,$operation,$phase,$tenant,$payment,$actor,$action,$reason,$hash,
        ['status'=>$parent['status'],'release_requested'=>(bool)$parent['release_requested']]);
    $result=purepayAllocationReview($pdo,$tenant,$payment,$actor,true);
    $result['operation']=['id'=>$operation,'outcome'=>$phase];
    return $result;
}
