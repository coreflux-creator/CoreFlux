<?php
/** Pure//Pay vendor-payment rail. Uses the provider's Moov-backed wallet path. */

declare(strict_types=1);

require_once __DIR__ . '/../payment_rails.php';
require_once __DIR__ . '/../purepay_payment_evidence.php';

class PurePayRailDriver implements PaymentRailsDriver
{
    public function name(): string { return 'purepay'; }
    public function isConfigured(): bool
    {
        require_once __DIR__ . '/../purepay_service.php';
        return purepayServiceConfigured();
    }

    public function isConfiguredForTenant(int $tenantId): bool
    {
        require_once __DIR__ . '/../purepay_service.php';
        $conn = purepayGetConnection($tenantId);
        try { purepayFinancialRequire(getDB(),$tenantId,$conn); } catch (Throwable $e) { return false; }
        return $conn && ($conn['status'] ?? '') === 'active'
            && ($conn['key_scope'] ?? '') === 'read_write' && ($conn['api_key'] ?? '') !== '';
    }

    public function originate(array $items, array $opts): array
    {
        require_once __DIR__ . '/../purepay_service.php';
        if (!$items) throw new PaymentRailsOriginateException('Pure//Pay originate requires at least one item');
        $tenantId = (int) ($opts['tenant_id'] ?? 0);
        if ($tenantId <= 0) throw new PaymentRailsOriginateException('Pure//Pay rail requires tenant_id');
        $conn = purepayGetConnection($tenantId);
        if (!$conn || ($conn['status'] ?? '') !== 'active' || ($conn['api_key'] ?? '') === '') {
            throw new PaymentRailsNotConfiguredException(
                'Pure//Pay is not connected for this tenant. Add a Pure//Pay API key under Admin → Integrations → Pure//Pay.'
            );
        }
        if (($conn['key_scope'] ?? '') !== 'read_write') {
            throw new PaymentRailsNotConfiguredException('Pure//Pay payouts require a read-write API key');
        }

        purepayFinancialRequire(getDB(),$tenantId,$conn);
        $batchId = (string) ($opts['batch_id'] ?? ('purepay_' . date('YmdHis') . '_' . bin2hex(random_bytes(3))));
        $results = [];
        foreach ($items as $index => $item) {
            try {
                $results[] = $this->originateOne($tenantId, (string) $conn['api_key'], $item, $opts, $index);
            } catch (\Throwable $e) {
                // Only the request that acquired the durable claim may record a failure.
                // Validation errors and competing retries must not change another attempt.
                $results[] = [
                    'external_ref' => (string) ($item['external_ref'] ?? ''),
                    'status' => 'failed',
                    'rail_external_ref' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }
        $ok = count(array_filter($results, static fn(array $r): bool => ($r['status'] ?? '') !== 'failed'));
        if ($ok === 0) {
            $first = (string) ($results[0]['error'] ?? 'all Pure//Pay items failed');
            throw new PaymentRailsOriginateException($first);
        }
        return [
            'batch_id' => $batchId,
            'status' => $ok === 0 ? 'failed' : 'submitted',
            'items' => $results,
            'payload' => ['provider' => 'purepay', 'pay_via' => 'wallet'],
        ];
    }

    private function originateOne(int $tenantId, string $apiKey, array $item, array $opts, int $index): array
    {
        require_once __DIR__.'/../purepay_allocated_payments.php';
        $source=(string)($item['external_ref']??'');
        if(!preg_match('/^ap_payment:([1-9][0-9]*)$/D',$source,$match))
            throw new PaymentRailsOriginateException('PurePay dispatch requires an original AP payment.');
        $date=(string)($item['effective_date']??$opts['effective_date']??date('Y-m-d'));
        purepayBillDate($date);
        if($date>date('Y-m-d'))throw new PaymentRailsOriginateException('This payment is scheduled for a future date.');
        return purepayAllocationDispatch(getDB(),$tenantId,(int)$match[1],$item,$date);
    }

    private function updateLink(int $tenantId, string $sourceRef, array $fields): string
    {
        $pdo=getDB();
        if ($pdo->inTransaction()) throw new PaymentRailsOriginateException('Provider result requires its own durable transaction');
        $pdo->beginTransaction();
        try {
        purepayFinancialRequire($pdo,$tenantId);
        $read=$pdo->prepare('SELECT * FROM purepay_payment_links WHERE tenant_id=:t AND source_ref=:s FOR UPDATE');
        $read->execute(['t'=>$tenantId,'s'=>$sourceRef]);$current=$read->fetch(PDO::FETCH_ASSOC);
        if (!$current) throw new PaymentRailsOriginateException('Pure//Pay attempt was not found');
        $connection=purepayFinancialConnection($pdo,$tenantId,true);
        if (!hash_equals((string)$current['connection_fingerprint'],purepayConnectionFingerprint($tenantId,purepayFinancialKey($connection)))) throw new PaymentRailsOriginateException('The company connection changed while reading this payment.');
        foreach (['purepay_vendor_id','purepay_bill_id','purepay_payment_id'] as $identity) {
            if (!empty($current[$identity]) && isset($fields[$identity]) && $fields[$identity]!==$current[$identity]) {
                throw new PaymentRailsOriginateException('Pure//Pay result conflicts with the recorded provider identity');
            }
        }
        $paymentId = (string)($fields['purepay_payment_id'] ?? $current['purepay_payment_id'] ?? '');
        if ($paymentId !== '') {
            if(purepayAllocationConflictsWithLegacy($pdo,$tenantId,$current,$paymentId))
                throw new PaymentRailsOriginateException('This receipt belongs to an original-bill allocation. Reconcile the historical attempt separately.');
            $owner = $pdo->prepare('SELECT source_ref FROM purepay_payment_links
                WHERE tenant_id=:t AND purepay_payment_id=:p AND source_ref<>:s LIMIT 1');
            $owner->execute(['t'=>$tenantId, 'p'=>$paymentId, 's'=>$sourceRef]);
            if ($owner->fetchColumn() !== false) {
                throw new PaymentRailsOriginateException('This PurePay payment already belongs to another Core payment. Reconcile both attempts; no receipt was reassigned.');
            }
            // The database unique key arbitrates concurrent first bindings.
        }
        $status=(string)($fields['status']??$current['status']);
        $prior=$this->normalizeStatus((string)$current['status']);$next=$this->normalizeStatus($status);
        if ($current['status']==='settled_failed_review' || ($prior==='returned' && $next!=='returned')
            || ($prior==='cancelled' && !in_array($next,['returned','settled'],true))) $status=(string)$current['status'];
        elseif ($prior==='settled' && !in_array($next,['settled','returned'],true)) $status=$next==='failed'?'settled_failed_review':(string)$current['status'];
        // A late processing response must not replace a concurrent terminal receipt.
        if ($this->normalizeStatus($status) !== $next) unset($fields['response_json'], $fields['last_error'], $fields['last_error_json']);
        $fields['status']=$status;
        $allowed = ['purepay_vendor_id','purepay_bill_id','purepay_payment_id','status','response_json','last_error','last_error_json','last_synced_at'];
        $sets = []; $params = ['t'=>$tenantId,'s'=>$sourceRef];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;
            $sets[] = "{$key}=:{$key}"; $params[$key] = $value;
        }
        $pdo->prepare('UPDATE purepay_payment_links SET ' . implode(',', $sets) . ', updated_at=NOW() WHERE tenant_id=:t AND source_ref=:s')
            ->execute($params);
        $pdo->commit();
        return $status;
        } catch (\Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
    }

    private function recordFailure(int $tenantId, string $sourceRef, \Throwable $error): void
    {
        if ($sourceRef === '') return;
        try {
            // A preceding write may have succeeded even when a subsequent GET
            // fails. Keep the whole attempt on hold; never downgrade settlement.
            getDB()->prepare('UPDATE purepay_payment_links SET status="needs_review",last_error=:e,last_error_json=NULL,updated_at=NOW()
                WHERE tenant_id=:t AND source_ref=:s AND status IN ("creating","bill_created","ready_to_pay","pushed_unverified","posted_unverified")')
                ->execute(['t'=>$tenantId,'s'=>$sourceRef,'e'=>'Provider attempt interrupted. Reconcile its vendor, bill and payment before another payout.']);
        } catch (\Throwable $_) {}
    }

    private function findPayment(array $rows, string $paymentId, string $billId): ?array
    {
        $matches=[];
        foreach ($rows as $row) {
            if (!is_array($row) || (!isset($row['bill_id']) && !isset($row['payable_id']))) {
                throw new PaymentRailsOriginateException('Pure//Pay history has an unidentified bill; reconcile it before another payout');
            }
            // Conflicting aliases must be examined, not hide a payment from the preflight.
            if (($paymentId !== '' && in_array($paymentId, [$row['id']??null, $row['payment_id']??null], true))
                || ($billId !== '' && in_array($billId, [$row['bill_id']??null, $row['payable_id']??null], true))) $matches[]=$row;
        }
        if (count($matches)>1) throw new PaymentRailsOriginateException('Multiple Pure//Pay payments match this bill; reconcile them before changing its status');
        return $matches[0]??null;
    }

    public function getStatus(string $railExternalRef): string
    {
        // The interface has no tenant argument. Refuse a global provider-ID lookup.
        return 'unknown';
    }

    private function confirmedStatus(int $tenantId, string $sourceRef): string
    {
        $stmt = getDB()->prepare('SELECT * FROM purepay_payment_links WHERE tenant_id=:t AND source_ref=:s');
        $stmt->execute(['t'=>$tenantId, 's'=>$sourceRef]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        return $link && purepayStoredPaymentEvidence($link) && purepayPaymentEvidenceOwned(getDB(), $tenantId, $link)
            ? $this->normalizeStatus((string)$link['status']) : 'unknown';
    }

    private function readPaymentDetail(string $apiKey, string $billId, string $paymentId): ?array
    {
        if ($paymentId === '') {
            $candidate = $this->findPayment(purepayCompleteBillPayments($apiKey, $billId), '', $billId);
            if ($candidate === null) return null;
            $identity = purepayPaymentIdentity($candidate, $billId);
            if (!$identity['ok']) throw new PaymentRailsOriginateException('PurePay history has conflicting payment identity');
            $paymentId = $identity['payment_id'];
        }
        $detail = purepayResource(purepayGetPayment($apiKey, $paymentId), ['payment']);
        $identity = purepayPaymentIdentity($detail, $billId, $paymentId);
        if (!$identity['ok']) throw new PaymentRailsOriginateException('PurePay detail does not confirm the original payment and bill');
        return $detail;
    }

    public function getStatusForTenant(int $tenantId,string $railExternalRef): string
    {
        require_once __DIR__ . '/../purepay_service.php';
        if ($tenantId<=0) return 'unknown';
        if(preg_match('/^purepay:allocation:([1-9][0-9]*)$/D',$railExternalRef,$allocation)){
            require_once __DIR__.'/../purepay_allocated_payments.php';
            return purepayAllocationReconcile(getDB(),$tenantId,(int)$allocation[1]);
        }
        if (!preg_match('/^purepay:(bill|payment):(.+)$/', $railExternalRef, $m)) return 'unknown';
        $column = $m[1] === 'bill' ? 'purepay_bill_id' : 'purepay_payment_id';
        $link = null;
        try {
            $stmt = getDB()->prepare("SELECT * FROM purepay_payment_links WHERE tenant_id=:t AND {$column}=:id ORDER BY id DESC LIMIT 2");
            $stmt->execute(['t'=>$tenantId,'id' => $m[2]]);
            $link = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$link || $stmt->fetch(\PDO::FETCH_ASSOC)) return 'unknown';
            if (($link['status'] ?? '') === 'settled_failed_review') return 'unknown';
            $conn = purepayGetConnection((int) $link['tenant_id']);
            if (!$conn || ($conn['status'] ?? '') !== 'active') return 'unknown';
            purepayFinancialRequire(getDB(),$tenantId,$conn,true);
            if (!hash_equals((string)($link['connection_fingerprint']??''),purepayConnectionFingerprint($tenantId,(string)$conn['api_key']))) return 'unknown';
            $paymentId = (string) ($link['purepay_payment_id'] ?? '');
            $remote = $this->readPaymentDetail((string)$conn['api_key'], (string)($link['purepay_bill_id'] ?? ''), $paymentId);
            if (!$remote) {
                purepayHoldPaymentEvidence(getDB(), $tenantId, (string)$link['source_ref'], 'payment_evidence_missing');
                return 'unknown';
            }
            $evidence = purepayPaymentEvidence($remote, (int)$link['amount_cents'], (string)($link['purepay_bill_id'] ?? ''), $paymentId);
            if (!$evidence['ok']) {
                purepayHoldPaymentEvidence(getDB(), $tenantId, (string)$link['source_ref'], $evidence['reason']);
                return 'unknown';
            }
            $status = $evidence['status'];
            $status=$this->updateLink((int) $link['tenant_id'], (string) $link['source_ref'], [
                'status'=>$status, 'last_synced_at'=>date('Y-m-d H:i:s'),
                'purepay_payment_id'=>$evidence['payment_id'],
                'response_json'=>purepayPaymentReceipt($evidence, 'detail'),
                'last_error'=>null, 'last_error_json'=>null,
            ]);
            return $this->confirmedStatus($tenantId, (string)$link['source_ref']);
        } catch (\Throwable $e) {
            // Missing, ambiguous, or unreadable evidence cannot replay a cached terminal label.
            if ($link) {
                try { purepayHoldPaymentEvidence(getDB(), $tenantId, (string)$link['source_ref'], 'payment_evidence_unavailable'); }
                catch (\Throwable $_) { /* Return uncertainty even if the review marker could not be saved. */ }
            }
            return 'unknown';
        }
    }

    private function normalizeStatus(string $status): string
    {
        return purepayPaymentStatus($status);
    }

    public function metadata(): array
    {
        return [
            'cost_per_item_dollars' => null,
            'cost_pct' => null,
            'pricing_note' => 'Pricing is not published; use your Pure//Pay agreement.',
            'settlement_business_days' => ['min' => null, 'max' => null],
            'settlement_note' => 'Subject to ACH processing and cut-off times.',
            'supports_same_day_ach' => false,
            'supports_rtp' => false,
            'needs_pre_approval' => false,
            'needs_funding_link' => true,
            'fallback_to' => null,
            'supported_modules' => ['ap'],
            'pros' => [
                'Vendor, bill, payment, and wallet lifecycle through one B2B workspace',
                'Signed settlement and failure webhooks',
                'Provider vault keeps vendor bank details out of the CoreFlux request',
            ],
            'cons' => [
                'Vendor payout onboarding must be completed in Pure//Pay',
                'API keys grant full access to the Pure//Pay organization',
                'Pricing is not published; use your Pure//Pay agreement',
            ],
        ];
    }
}
