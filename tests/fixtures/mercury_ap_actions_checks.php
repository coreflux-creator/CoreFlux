<?php
/** Runs only inside the disposable, real-MariaDB allocation fixture. No Mercury transport. */
function exerciseMercuryApActions(PDO $pdo, PDO $other, callable $seed, callable $check, callable $fails, callable $guard, callable $dbError): void
{
    $seed(301,1); $draft = mpCreateFromApPayment(1,301,8); $id = (int)$draft['id'];
    foreach (['allocate','void','clear'] as $action) {
        $check($fails(fn()=>$guard(301,$action)), 'original Mercury draft blocks AP '.$action.' before cancellation');
    }
    $pdo->exec('UPDATE ap_payments SET rail_external_ref=NULL,rail_status="failed" WHERE id=301');
    $check($fails(fn()=>$guard(301,'void')) && $fails(fn()=>$guard(301,'clear')),
        'missing Mercury reverse reference and a failed UI label cannot release or settle an original claim');
    $pdo->prepare('UPDATE ap_payments SET rail_external_ref=? WHERE id=301')->execute(['pi:'.$id]);
    mpSubmitForApproval(1,$id,8); mpCancel(1,$id,9,'Synthetic pre-approval cancellation');
    $check($guard(301,'void')===true && $fails(fn()=>$guard(301,'clear')) && $fails(fn()=>$guard(301,'allocate')),
        'audited pre-approval cancellation permits only explicit AP void, never settlement or allocation changes');
    $pdo->prepare('UPDATE payment_instructions SET source_module="ap_payment",source_ref=? WHERE id=?')->execute(['ap_payment:301',$id]);
    $pdo->prepare('UPDATE ap_payments SET rail_external_ref=? WHERE id=301')->execute(['mercury:instruction:'.$id]);
    $check($guard(301,'void')===true,'historical Mercury source spelling and canonical AP reference use the same cancellation proof');
    foreach ([['amount','126','125'],['currency','CAD','USD'],['vendor_name','Wrong supplier','Synthetic supplier']] as [$column,$bad,$original]) {
        $pdo->prepare('UPDATE ap_payments SET '.$column.'=? WHERE id=301')->execute([$bad]);
        $check($fails(fn()=>$guard(301,'void')), 'cancelled Mercury claim rejects changed AP '.$column);
        $pdo->prepare('UPDATE ap_payments SET '.$column.'=? WHERE id=301')->execute([$original]);
    }
    $pdo->prepare('UPDATE payment_instructions SET payout_mercury_txn_id="original-bank-transfer" WHERE id=?')->execute([$id]);
    $check($fails(fn()=>$guard(301,'void')), 'cancelled label cannot release a Mercury instruction with a saved bank transfer');
    $pdo->prepare('UPDATE payment_instructions SET payout_mercury_txn_id=NULL WHERE id=?')->execute([$id]);
    $pdo->prepare('UPDATE payment_instruction_audit SET from_state="Approved" WHERE instruction_id=? AND to_state="Cancelled"')->execute([$id]);
    $check($fails(fn()=>$guard(301,'void')), 'approval in cancellation history remains a hold even if current approval fields are empty');
    $pdo->prepare('UPDATE payment_instruction_audit SET from_state="PendingApproval" WHERE instruction_id=? AND to_state="Cancelled"')->execute([$id]);
    $pdo->prepare('UPDATE payment_instruction_audit SET actor_user_id=NULL WHERE instruction_id=? AND to_state="Cancelled"')->execute([$id]);
    $check($fails(fn()=>$guard(301,'void')), 'cancellation without an authenticated audit actor cannot free AP');
    $pdo->prepare('UPDATE payment_instruction_audit SET actor_user_id=9 WHERE instruction_id=? AND to_state="Cancelled"')->execute([$id]);
    $pdo->prepare('UPDATE payment_instructions SET tenant_id=2 WHERE id=?')->execute([$id]);
    $check($fails(fn()=>$guard(301,'void')), 'foreign-company Mercury instruction cannot authorize a local AP cancellation');
    $pdo->prepare('UPDATE payment_instructions SET tenant_id=1 WHERE id=?')->execute([$id]);
    $seed(302,1); $second = mpCreateFromApPayment(1,302,8); $secondId = (int)$second['id'];
    $check($fails(fn()=>apAllocatePayment(302,['allocations'=>[['bill_id'=>3020,'amount'=>1]]],8))
        && (string)$pdo->query('SELECT amount_applied FROM ap_payment_allocations WHERE payment_id=302')->fetchColumn()==='125.00',
        'actual allocation entry point preserves the original Mercury bill amounts');
    foreach (['Failed','Returned','Settled','Reconciled','Cancelled'] as $state) {
        $pdo->prepare('UPDATE payment_instructions SET state=? WHERE id=?')->execute([$state,$secondId]);
        $check($fails(fn()=>$guard(302,'void')) && $fails(fn()=>$guard(302,'clear')),
            'Mercury '.$state.' label without original cancellation or verified bank evidence stays held');
    }
    $pdo->prepare('UPDATE payment_instructions SET state="Draft" WHERE id=?')->execute([$secondId]);
    $pdo->beginTransaction();
    try {
        $q=$pdo->query('SELECT * FROM ap_payments WHERE id=301 FOR UPDATE');
        $check(apAssertProviderPaymentAction($pdo,1,$q->fetch(PDO::FETCH_ASSOC),'void')===true,'AP cancellation proof stays inside its caller transaction');
        $check($dbError(function()use($other,$id){$q=$other->prepare('UPDATE payment_instructions SET state_reason="competing update" WHERE id=?');$q->execute([$id]);})===1205,
            'second SQL session cannot change the original instruction during AP cancellation verification');
    } finally { $pdo->rollBack(); }
    $pdo->prepare('UPDATE payment_instructions SET source_ref="ap_payment:301" WHERE id=?')->execute([$secondId]);
    $check($fails(fn()=>$guard(301,'void')), 'ambiguous historical Mercury instructions cannot free an AP reservation');
}
