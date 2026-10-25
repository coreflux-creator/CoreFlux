<?php
/** Payment results must prove the full reserved obligation, not merely name its bill. */
declare(strict_types=1);

/** Exact decimal-to-cents conversion. Never round sub-cent values or assume missing money. */
function purepayMoneyCents(mixed $value): ?int
{
    if (is_float($value)) {
        if (!is_finite($value)) return null;
        $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
    } elseif (is_int($value)) {
        $value = (string)$value;
    }
    if (!is_string($value) || !preg_match('/^(0|[1-9]\d{0,10})(?:\.(\d{1,2}))?$/D', $value, $parts)) return null;
    return (int)$parts[1] * 100 + (int)str_pad($parts[2] ?? '', 2, '0');
}

function purepayPaymentStatus(string $status): string
{
    return match (strtolower(trim($status))) {
        'settled', 'paid', 'completed', 'cleared' => 'settled',
        'returned', 'reversed' => 'returned',
        'cancelled', 'canceled', 'void', 'voided' => 'cancelled',
        'failed', 'payment_failed', 'declined' => 'failed',
        'posted', 'sent' => 'posted',
        'submitted', 'processing', 'in_transit', 'initiated' => 'submitted',
        'creating', 'bill_created', 'ready_to_pay', 'pending', 'pushed_unverified', 'posted_unverified', 'needs_review' => 'pending',
        default => 'unknown',
    };
}

function purepayPaymentIdentity(array $data, string $billId, string $paymentId = ''): array
{
    $bad = static fn(string $reason): array => ['ok'=>false, 'reason'=>$reason];
    if ($billId === '') return $bad('reservation_invalid');
    $ids = [];
    foreach (['payment'=>['id','payment_id'], 'bill'=>['bill_id','payable_id']] as $kind=>$fields) {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) continue;
            $value = $data[$field];
            if (!is_string($value) || $value === '' || strlen($value)>200 || preg_match('/[\x00-\x20\x7f]/', $value)) {
                return $bad('provider_identity_invalid');
            }
            if (isset($ids[$kind]) && $ids[$kind] !== $value) return $bad('provider_identity_conflict');
            $ids[$kind] = $value;
        }
        if (!isset($ids[$kind])) return $bad('provider_identity_missing');
    }
    if ($ids['bill'] !== $billId || ($paymentId !== '' && $ids['payment'] !== $paymentId)) return $bad('provider_identity_conflict');
    return ['ok'=>true, 'payment_id'=>$ids['payment'], 'bill_id'=>$ids['bill']];
}

/** Only a single-bill payment with explicit gross money can discharge this reservation. */
function purepayPaymentEvidence(array $data, int $expectedCents, string $billId, string $paymentId = ''): array
{
    $bad = static fn(string $reason): array => ['ok'=>false, 'reason'=>$reason];
    if ($expectedCents <= 0 || $expectedCents > 9999999999999) return $bad('reservation_invalid');
    $identity = purepayPaymentIdentity($data, $billId, $paymentId);
    if (!$identity['ok']) return $identity;
    if (!array_key_exists('amount', $data) || $data['amount'] === null) return $bad('payment_amount_missing');
    $amount = purepayMoneyCents($data['amount']);
    if ($amount === null || $amount <= 0) return $bad('payment_amount_invalid');
    if ($amount !== $expectedCents) return $bad('payment_amount_mismatch');
    if (!array_key_exists('currency', $data) || $data['currency'] === null) return $bad('payment_currency_missing');
    if (!is_string($data['currency']) || !preg_match('/^[A-Z]{3}$/D', $data['currency'])) return $bad('payment_currency_invalid');
    if ($data['currency'] !== 'USD') return $bad('payment_currency_mismatch');
    if (array_key_exists('gross_cents', $data)
        && (!is_int($data['gross_cents']) || $data['gross_cents'] !== $expectedCents)) return $bad('payment_amount_conflict');
    // Unlike Core's request amount_cents, PurePay's stored amount_cents is the
    // NET transfer (including scheduled/retried wallet payouts). Keep its units
    // and meaning separate from the gross bill obligation.
    if (array_key_exists('net_cents', $data)
        && (!is_int($data['net_cents']) || $data['net_cents'] <= 0 || $data['net_cents'] > $expectedCents)) {
        return $bad('payment_transfer_amount_invalid');
    }
    if (array_key_exists('amount_cents', $data)
        && (!is_int($data['amount_cents']) || !isset($data['net_cents']) || $data['amount_cents'] !== $data['net_cents'])) {
        return $bad('payment_transfer_amount_conflict');
    }
    // PurePay's `allocated` is an adjustment/overpayment amount, not money applied
    // to this bill. Never mistake it (or a multi-bill total) for allocation proof.
    if (array_key_exists('allocated', $data) && purepayMoneyCents($data['allocated']) !== 0) return $bad('payment_allocation_mismatch');
    if (!empty($data['allocation']) || ($data['partial'] ?? false) !== false) return $bad('payment_allocation_ambiguous');
    // The current rail has one payable per payment. Multi-bill totals are not bill allocation proof.
    foreach (['allocated_amount','allocations','bill_allocations','payment_allocations'] as $field) {
        if (array_key_exists($field, $data)) return $bad('payment_allocation_ambiguous');
    }
    $status = null;
    foreach (['pay_status','status'] as $field) {
        if (!array_key_exists($field, $data)) continue;
        if (!is_string($data[$field]) || purepayPaymentStatus($data[$field]) === 'unknown') return $bad('payment_status_invalid');
        if ($status !== null && purepayPaymentStatus($status) !== purepayPaymentStatus($data[$field])) return $bad('payment_status_conflict');
        $status = strtolower(trim($data[$field]));
    }
    if ($status === null) return $bad('payment_status_missing');
    if (!empty($data['qbo_billpayment_id']) || !empty($data['mercury_request_id'])) return $bad('payment_delivery_unconfirmed');
    if (purepayPaymentStatus($status) === 'settled' && ($data['ok'] ?? null) === false) return $bad('payment_status_conflict');
    return ['ok'=>true, 'payment_id'=>$identity['payment_id'], 'bill_id'=>$identity['bill_id'], 'status'=>$status,
        'amount_cents'=>$amount, 'currency'=>'USD'];
}

/** Persist only validated evidence; source is assigned by Core after the authenticated read/callback. */
function purepayPaymentReceipt(array $evidence, string $source): string
{
    if (($evidence['ok'] ?? false) !== true || !in_array($source, ['detail','signed_webhook'], true)) {
        throw new InvalidArgumentException('A verified PurePay result and evidence source are required');
    }
    return json_encode(['payment_evidence'=>['version'=>'2026-10-25.1', 'source'=>$source, 'payment'=>[
        'id'=>$evidence['payment_id'], 'bill_id'=>$evidence['bill_id'], 'status'=>$evidence['status'],
        'amount'=>intdiv($evidence['amount_cents'],100).'.'.str_pad((string)($evidence['amount_cents']%100),2,'0',STR_PAD_LEFT),
        'currency'=>$evidence['currency'],
    ]]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** Old status-only rows cannot authorize a retry result, cancellation, or ledger clearance. */
function purepayStoredPaymentEvidence(array $link): bool
{
    $review = json_decode((string)($link['last_error_json'] ?? ''), true);
    if (is_array($review) && isset($review['payment_evidence_review'])) return false;
    $receipt = json_decode((string)($link['response_json'] ?? ''), true);
    $receipt = is_array($receipt) ? ($receipt['payment_evidence'] ?? null) : null;
    if (!is_array($receipt) || ($receipt['version'] ?? '') !== '2026-10-25.1'
        || !in_array($receipt['source'] ?? null, ['detail','signed_webhook'], true)
        || !is_array($receipt['payment'] ?? null) || empty($link['purepay_payment_id'])) return false;
    $evidence = purepayPaymentEvidence($receipt['payment'], (int)($link['amount_cents'] ?? 0),
        (string)($link['purepay_bill_id'] ?? ''), (string)$link['purepay_payment_id']);
    return $evidence['ok'] && purepayPaymentStatus($evidence['status']) === purepayPaymentStatus((string)($link['status'] ?? ''));
}

/** Preserve the original receipt and reservation, but prevent a stale result from authorizing action. */
function purepayHoldPaymentEvidence(PDO $pdo, int $tenantId, string $sourceRef, string $reason): void
{
    $pdo->prepare('UPDATE purepay_payment_links SET last_error=:e,last_error_json=:j,updated_at=NOW()
        WHERE tenant_id=:t AND source_ref=:s')->execute([
        'e'=>'PurePay payment evidence needs reconciliation before changing this reservation.',
        'j'=>json_encode(['payment_evidence_review'=>$reason], JSON_THROW_ON_ERROR), 't'=>$tenantId, 's'=>$sourceRef,
    ]);
}
