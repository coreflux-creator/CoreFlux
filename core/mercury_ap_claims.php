<?php
/** AP lifecycle checks against the original Mercury instruction, without provider I/O. */
declare(strict_types=1);
require_once __DIR__.'/ap_bank_dispatches.php';

function mercuryApAssertAction(PDO $pdo, int $tenant, array $payment, string $action): ?bool
{
    if (!$pdo->inTransaction()) throw new LogicException('Mercury AP checks require the locked payment transaction');
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $exists = $pdo->query($mysql
        ? "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='payment_instructions'"
        : "SELECT 1 FROM sqlite_master WHERE type='table' AND name='payment_instructions'")->fetchColumn();
    $attempts = [];
    if ($exists) {
        $q = $pdo->prepare('SELECT * FROM payment_instructions WHERE tenant_id=?
            AND source_module IN ("ap","ap_payment") AND source_ref IN (?,?) ORDER BY id LIMIT 2'.($mysql ? ' FOR UPDATE' : ''));
        $q->execute([$tenant, (string)$payment['id'], 'ap_payment:'.$payment['id']]);
        $attempts = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$attempts) {
        if (($payment['disbursement_rail'] ?? '') === 'mercury'
            && (!empty($payment['rail_external_ref']) || !empty($payment['rail_status']) || !empty($payment['rail_originated_at']))) {
            throw new DomainException('The original Mercury instruction is missing. Reconcile it before changing this payment.');
        }
        return null;
    }
    $hold = 'This payment retains its original Mercury instruction. Review it in Treasury before changing its allocations, cancelling or clearing it.';
    if (count($attempts) !== 1) throw new DomainException($hold);
    $instruction = $attempts[0];
    // A UI status, including Failed, is not proof that neither leg moved money.
    // Only a cancellation before approval can release AP without bank evidence.
    if ($action !== 'void' || $instruction['state'] !== 'Cancelled'
        || ($payment['disbursement_rail'] ?? '') !== 'mercury'
        || !in_array($payment['rail_external_ref'] ?? '', ['pi:'.$instruction['id'], 'mercury:instruction:'.$instruction['id']], true)
        || (string)$instruction['currency'] !== 'USD' || ($payment['currency'] ?? '') !== 'USD'
        || apBankCents($payment['amount']) !== (int)$instruction['amount_cents']
        || (int)($instruction['is_internal_transfer'] ?? 0) !== 0) throw new DomainException($hold);
    foreach (['approved_by_user_id','approved_at','funding_mercury_txn_id','funding_mercury_status',
        'funding_initiated_at','funding_settled_at','funding_last_polled_at','payout_mercury_txn_id',
        'payout_mercury_status','payout_initiated_at','payout_settled_at','payout_last_polled_at'] as $field) {
        if (!empty($instruction[$field])) throw new DomainException($hold);
    }
    $q = $pdo->prepare('SELECT name,kind FROM mercury_recipients WHERE tenant_id=? AND id=?'.($mysql ? ' FOR UPDATE' : ''));
    $q->execute([$tenant, $instruction['recipient_id']]);
    $recipient = $q->fetch(PDO::FETCH_ASSOC);
    if (!$recipient || $recipient['kind'] !== 'vendor' || $recipient['name'] !== $payment['vendor_name']) throw new DomainException($hold);
    $q = $pdo->prepare('SELECT from_state,to_state,actor_user_id FROM payment_instruction_audit
        WHERE tenant_id=? AND instruction_id=? ORDER BY id'.($mysql ? ' FOR UPDATE' : ''));
    $q->execute([$tenant, $instruction['id']]);
    $history = $q->fetchAll(PDO::FETCH_ASSOC);
    $state = 'Draft';
    foreach ($history as $event) {
        if ($event['from_state'] !== $state || !in_array($event['to_state'], ['Draft','PendingApproval','Cancelled'], true)
            || $state === 'Cancelled') throw new DomainException($hold);
        $state = $event['to_state'];
    }
    $last = $history ? $history[count($history)-1] : null;
    if ($state !== 'Cancelled' || !$last || (int)$last['actor_user_id'] <= 0) throw new DomainException($hold);
    return true;
}
