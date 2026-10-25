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
        foreach (['external_ref', 'recipient_name', 'recipient_email', 'recipient_ref', 'amount_cents'] as $key) {
            if (!isset($item[$key]) || trim((string) $item[$key]) === '') {
                throw new PaymentRailsOriginateException("Pure//Pay item {$index}: {$key} is required");
            }
        }
        if (!filter_var((string) $item['recipient_email'], FILTER_VALIDATE_EMAIL)) {
            throw new PaymentRailsOriginateException("Pure//Pay item {$index}: vendor remit email is invalid");
        }
        if (!is_int($item['amount_cents']) || $item['amount_cents'] <= 0 || $item['amount_cents'] > 9999999999999) {
            throw new PaymentRailsOriginateException("Pure//Pay item {$index}: amount must be positive");
        }
        if (strtoupper((string) ($item['currency'] ?? '')) !== 'USD') {
            throw new PaymentRailsOriginateException("Pure//Pay item {$index}: only USD payouts are supported");
        }

        $sourceRef = (string) $item['external_ref'];
        if (strlen($sourceRef)>160 || preg_match('/[\x00-\x1f\x7f]/',$sourceRef)) throw new PaymentRailsOriginateException('Pure//Pay source reference is invalid');
        $effectiveDate=(string)($opts['effective_date']??date('Y-m-d'));
        $parsedDate=DateTimeImmutable::createFromFormat('!Y-m-d',$effectiveDate);
        if (!$parsedDate || $parsedDate->format('Y-m-d')!==$effectiveDate) throw new PaymentRailsOriginateException('Pure//Pay effective date must be a valid calendar date');
        $opts['effective_date']=$effectiveDate;
        $recipientName = trim((string) ($item['recipient_full_name'] ?? $item['recipient_name']));
        $recipientEmail = strtolower(trim((string) $item['recipient_email']));
        $fingerprint = hash('sha256', json_encode([
            'contract'=>2,'provider'=>purepayApiBase(),'credential_digest'=>hash('sha256',$apiKey),
            $tenantId, $sourceRef, (string) $item['recipient_ref'], $recipientName,
            $recipientEmail, (int) $item['amount_cents'], 'wallet',
            'effective_date'=>$effectiveDate,'invoice_number'=>substr((string)($item['invoice_number']??$sourceRef),0,100),
        ], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $link = $this->claimLink($tenantId, $sourceRef, (int) $item['amount_cents'], $fingerprint, $apiKey, $recipientName);
        if (!hash_equals((string) $link['request_fingerprint'], $fingerprint)) {
            throw new PaymentRailsOriginateException('Pure//Pay idempotency conflict: this CoreFlux payment was previously sent with different details');
        }

        $billId = (string) ($link['purepay_bill_id'] ?? '');
        $paymentId = (string) ($link['purepay_payment_id'] ?? '');
        $rawStatus = strtolower(trim((string) ($link['status'] ?? '')));
        $existingStatus = $this->normalizeStatus($rawStatus);
        if (!$link['__fresh']) {
            // An existing row is never authority for another provider POST. The
            // original claim may still be executing or its response may be lost.
            $confirmedStates=['submitted','processing','in_transit','initiated','posted','sent','settled','paid','completed','cleared','returned','reversed','cancelled','canceled','void','voided'];
            if (($billId==='' && $paymentId==='') || !in_array($rawStatus,$confirmedStates,true) || !purepayStoredPaymentEvidence($link)
                || !purepayPaymentEvidenceOwned(getDB(), $tenantId, $link)) {
                throw new PaymentRailsOriginateException('This Pure//Pay attempt already exists. Reconcile its bill and payment before another payout; no request was repeated.');
            }
            return [
                'external_ref' => $sourceRef,
                'status' => $existingStatus,
                'rail_external_ref' => $billId !== '' ? 'purepay:bill:' . $billId : 'purepay:payment:' . $paymentId,
            ];
        }
        try {
            $active=purepayGetConnection($tenantId);
            if (!$active || !hash_equals($apiKey,(string)$active['api_key'])) throw new PaymentRailsOriginateException('The PurePay key changed before provider access.');
            purepayFinancialRequire(getDB(),$tenantId,$active,true);
            return $this->originateClaimed($tenantId,$apiKey,$item,$opts,$link,$sourceRef);
        } catch (\Throwable $e) {
            $this->recordFailure($tenantId,$sourceRef,$e);
            throw $e;
        }
    }

    private function originateClaimed(int $tenantId,string $apiKey,array $item,array $opts,array $link,string $sourceRef): array
    {
        $billId=(string)($link['purepay_bill_id']??'');
        $paymentId=(string)($link['purepay_payment_id']??'');
        $vendorId = (string) ($link['purepay_vendor_id'] ?? '');
        if ($vendorId === '') {
            $vendor = $this->ensureVendor($tenantId, $apiKey, $item);
            $vendorId = $vendor['id'];
            getDB()->prepare('UPDATE purepay_payment_links SET purepay_vendor_id=:v, updated_at=NOW() WHERE tenant_id=:t AND source_ref=:s')
                ->execute(['v' => $vendorId, 't' => $tenantId, 's' => $sourceRef]);
        }

        if ($billId === '') {
            $billPayload = [
                'vendor_id' => $vendorId,
                'amount' => round(((int) $item['amount_cents']) / 100, 2),
                'bill_date' => (string) ($opts['effective_date'] ?? date('Y-m-d')),
                'due_date' => (string) ($opts['effective_date'] ?? date('Y-m-d')),
                'invoice_number' => substr((string) ($item['invoice_number'] ?? $sourceRef), 0, 100),
            ];
            $created = purepayCreateBill($apiKey, $billPayload, purepayIdempotencyKey($tenantId, 'bill', $sourceRef));
            $billId = purepayResourceId($created, ['bill']);
            if ($billId === '') throw new PurePayApiException('Pure//Pay created a bill but did not return its id');
            $this->updateLink($tenantId, $sourceRef, [
                'purepay_bill_id' => $billId,
                'status' => 'bill_created',
                'response_json' => json_encode($created, JSON_UNESCAPED_SLASHES),
            ]);
        }

        // Charter primitive #5: re-read the provider after the create before paying.
        $remoteBill = purepayResource(purepayGetBill($apiKey, $billId), ['bill']);
        if (purepayResourceId($remoteBill, ['bill']) !== $billId) {
            $this->updateLink($tenantId, $sourceRef, ['status' => 'pushed_unverified']);
            throw new PaymentRailsOriginateException('Pure//Pay bill was created but could not be verified from GET /bills; payment was not released');
        }

        // Check existing payments before this first attempt. The durable Core
        // claim, not provider cache behavior, prevents another payment POST.
        $paymentsBefore = purepayCompleteBillPayments($apiKey, $billId);
        $alreadyPaid = $this->findPayment($paymentsBefore, '', $billId);
        if ($alreadyPaid) {
            $identity = purepayPaymentIdentity($alreadyPaid, $billId, $paymentId);
            if (!$identity['ok']) throw new PaymentRailsOriginateException('Pure//Pay existing payment needs reconciliation: '.$identity['reason']);
            $paymentId = $identity['payment_id'];
            $remote = $this->readPaymentDetail($apiKey, $billId, $paymentId);
            $evidence = purepayPaymentEvidence($remote ?? [], (int)$link['amount_cents'], $billId, $paymentId);
            if (!$evidence['ok']) throw new PaymentRailsOriginateException('Pure//Pay existing payment needs reconciliation: '.$evidence['reason']);
            $paymentId = $evidence['payment_id'];
            $status = $evidence['status'];
            $status = $this->updateLink($tenantId, $sourceRef, [
                'purepay_payment_id' => $paymentId !== '' ? $paymentId : null,
                'status' => $status,
                'response_json' => purepayPaymentReceipt($evidence, 'detail'),
                'last_synced_at' => date('Y-m-d H:i:s'),
                'last_error' => null,
                'last_error_json' => null,
            ]);
            return [
                'external_ref' => $sourceRef,
                'status' => $this->confirmedStatus($tenantId, $sourceRef),
                'rail_external_ref' => 'purepay:bill:' . $billId,
            ];
        }

        // Pin the durable Core obligation. Omitting amount lets a provider-side
        // payment plan or changed bill balance choose a different payout.
        $paid = purepayPayBill($apiKey, $billId, [
            'pay_via' => 'wallet',
            'amount' => ((int) $link['amount_cents']) / 100,
        ], purepayIdempotencyKey($tenantId, 'pay', $sourceRef));
        $paymentResource = is_array($paid['payment'] ?? null) ? $paid['payment'] : $paid;
        $paymentId = (string) ($paymentResource['payment_id'] ?? $paymentResource['id'] ?? '');
        $receipt = json_encode($paid, JSON_UNESCAPED_SLASHES);

        // History locates an omitted payment ID; only the full detail can prove
        // money and allocation semantics omitted from that compact projection.
        $remotePayment = $this->readPaymentDetail($apiKey, $billId, $paymentId);
        if ($remotePayment) {
            $evidence = purepayPaymentEvidence($remotePayment, (int)$link['amount_cents'], $billId, $paymentId);
            if (!$evidence['ok']) throw new PaymentRailsOriginateException('Pure//Pay payment detail needs reconciliation: '.$evidence['reason']);
            $receipt = purepayPaymentReceipt($evidence, 'detail');
            $paymentId = $evidence['payment_id'];
            $status = $evidence['status'];
        } else {
            $status = 'posted_unverified';
        }
        $status = $this->updateLink($tenantId, $sourceRef, [
            'purepay_payment_id' => $paymentId !== '' ? $paymentId : null,
            'status' => $status,
            'response_json' => $receipt,
            'last_synced_at' => date('Y-m-d H:i:s'),
            'last_error' => null,
            'last_error_json' => null,
        ]);
        return [
            'external_ref' => $sourceRef,
            'status' => $remotePayment ? $this->confirmedStatus($tenantId, $sourceRef) : 'pending',
            'rail_external_ref' => 'purepay:bill:' . $billId,
        ];
    }

    private function ensureVendor(int $tenantId, string $apiKey, array $item): array
    {
        $coreRef = (string) $item['recipient_ref'];
        if ($coreRef==='' || strlen($coreRef)>120 || preg_match('/[\x00-\x1f\x7f]/',$coreRef)) throw new PaymentRailsOriginateException('Invalid Core vendor reference');
        $connectionFingerprint=purepayConnectionFingerprint($tenantId,$apiKey);
        $stmt = getDB()->prepare('SELECT purepay_vendor_id, verification_status,connection_fingerprint FROM purepay_vendor_mappings WHERE tenant_id=:t AND core_vendor_ref=:r LIMIT 1');
        $stmt->execute(['t' => $tenantId, 'r' => $coreRef]);
        $mapped = $stmt->fetch(\PDO::FETCH_ASSOC);

        $name = trim((string) ($item['recipient_full_name'] ?? $item['recipient_name']));
        $email = strtolower(trim((string) $item['recipient_email']));
        if ($mapped) {
            if ($mapped['verification_status']!=='verified' || !hash_equals($connectionFingerprint,(string)$mapped['connection_fingerprint'])) {
                throw new PaymentRailsOriginateException('Review this vendor mapping against the connected Pure//Pay organization before paying');
            }
            $vendorId = (string) $mapped['purepay_vendor_id'];
            $remote = purepayResource(purepayGetVendor($apiKey, $vendorId), ['vendor']);
            $this->assertVendorIdentity($remote, $vendorId, $name, $email);
            return ['id' => $vendorId];
        }
        $vendors = purepayCompleteCollection($apiKey, 'vendors');
        $matches=[];
        foreach ($vendors as $candidate) {
            if (!is_array($candidate)) continue;
            $candidateEmail = strtolower(trim((string) ($candidate['email'] ?? '')));
            $candidateName = trim((string) ($candidate['display_name'] ?? $candidate['legal_name'] ?? $candidate['name'] ?? ''));
            if ($candidateEmail === $email && strcasecmp($candidateName, $name) === 0) {
                $matches[] = $candidate;
            }
        }
        if (count($matches)>1) throw new PaymentRailsOriginateException('Multiple Pure//Pay vendors match this identity. Review the intended vendor before paying.');
        $remote=$matches[0]??null;
        $vendorAttemptFingerprint=hash('sha256',json_encode([$connectionFingerprint,$coreRef,$name,$email],JSON_THROW_ON_ERROR));
        $vendorClaim=getDB()->prepare('INSERT IGNORE INTO purepay_vendor_attempts (tenant_id,core_vendor_ref,connection_fingerprint,request_fingerprint) VALUES (:t,:r,:c,:f)');
        $vendorClaim->execute(['t'=>$tenantId,'r'=>$coreRef,'c'=>$connectionFingerprint,'f'=>$vendorAttemptFingerprint]);
        if ($vendorClaim->rowCount()!==1) throw new PaymentRailsOriginateException('This vendor already has an integration attempt. Reconcile it before another vendor creation or payment.');
        try {
        if (!$remote) {
            $created = purepayCreateVendor($apiKey, [
                'display_name' => $name,
                'email' => $email,
                'relationship' => 'vendor',
            ], purepayIdempotencyKey($tenantId, 'vendor', $coreRef));
            $vendorId = purepayResourceId($created, ['vendor']);
            if ($vendorId === '') throw new PurePayApiException('Pure//Pay created a vendor but did not return its id');
        } else {
            $vendorId = (string) ($remote['id'] ?? $remote['vendor_id'] ?? '');
        }
        if ($vendorId === '') throw new PurePayApiException('Pure//Pay vendor id is missing');
        $remote = purepayResource(purepayGetVendor($apiKey, $vendorId), ['vendor']);
        $this->assertVendorIdentity($remote, $vendorId, $name, $email);
        getDB()->prepare(
            'INSERT INTO purepay_vendor_mappings
                (tenant_id, core_vendor_ref, purepay_vendor_id, vendor_name, vendor_email, verification_status,connection_fingerprint)
             VALUES (:t,:r,:v,:n,:e,:s,:c)'
        )->execute(['t'=>$tenantId,'r'=>$coreRef,'v'=>$vendorId,'n'=>$name,'e'=>$email,'s'=>'verified','c'=>$connectionFingerprint]);
        getDB()->prepare('UPDATE purepay_vendor_attempts SET state="verified",remote_vendor_id=:v,finished_at=NOW() WHERE tenant_id=:t AND core_vendor_ref=:r AND state="creating"')
            ->execute(['t'=>$tenantId,'r'=>$coreRef,'v'=>$vendorId]);
        return ['id' => $vendorId, 'verification_status' => 'verified'];
        } catch(\Throwable $e) {
            getDB()->prepare('UPDATE purepay_vendor_attempts SET state="needs_review",finished_at=NOW() WHERE tenant_id=:t AND core_vendor_ref=:r AND state="creating"')->execute(['t'=>$tenantId,'r'=>$coreRef]);
            throw $e;
        }
    }

    private function assertVendorIdentity(array $remote, string $vendorId, string $name, string $email): void
    {
        $actualId = purepayResourceId($remote, ['vendor']);
        $actualName = trim((string) ($remote['display_name'] ?? $remote['legal_name'] ?? $remote['name'] ?? ''));
        $actualEmail = strtolower(trim((string) ($remote['email'] ?? '')));
        if ($actualId !== $vendorId || strcasecmp($actualName, $name) !== 0 || $actualEmail !== $email) {
            throw new PaymentRailsOriginateException(
                'Pure//Pay vendor identity differs from the AP vendor. Review the name and remit email in both systems before paying.'
            );
        }
    }

    private function claimLink(int $tenantId, string $sourceRef, int $amountCents, string $fingerprint, string $apiKey, string $recipientName): array
    {
        $pdo=getDB();
        if (!$pdo || $pdo->inTransaction() || !(bool)$pdo->getAttribute(PDO::ATTR_AUTOCOMMIT)) throw new PaymentRailsOriginateException('Pure//Pay requires a durable claim outside a caller transaction');
        if (!preg_match('/^ap_payment:([1-9]\d*)$/D', $sourceRef, $m)) {
            throw new PaymentRailsOriginateException('PurePay requires a canonical AP payment reference');
        }
        $corePaymentId=(int)$m[1];
        $pdo->beginTransaction();
        try {
        // Serialize with void/allocation before committing an irrevocable provider attempt.
        $paymentStmt=$pdo->prepare('SELECT * FROM ap_payments WHERE tenant_id=:t AND id=:id FOR UPDATE');
        $paymentStmt->execute(['t'=>$tenantId,'id'=>$corePaymentId]); $payment=$paymentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) throw new PaymentRailsOriginateException('The tenant-owned AP payment was not found');
        $connection=$pdo->prepare('SELECT api_key_ct,status,key_scope FROM purepay_connections WHERE tenant_id=:t FOR UPDATE');
        $connection->execute(['t'=>$tenantId]);$current=$connection->fetch(\PDO::FETCH_ASSOC);
        if (!$current || $current['status']!=='active' || $current['key_scope']!=='read_write'
            || !hash_equals($apiKey,(string)decryptField($current['api_key_ct']))) throw new PaymentRailsOriginateException('The Pure//Pay connection changed before this attempt');
        purepayFinancialRequire($pdo,$tenantId);
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO purepay_payment_links
                (tenant_id, source_ref, core_payment_id, amount_cents, status, request_fingerprint,connection_fingerprint)
             VALUES (:t,:s,:p,:a,"creating",:f,:c)'
        );
        $insert->execute(['t'=>$tenantId,'s'=>$sourceRef,'p'=>$corePaymentId,'a'=>$amountCents,'f'=>$fingerprint,'c'=>purepayConnectionFingerprint($tenantId,$apiKey)]);
        $fresh = $insert->rowCount() > 0;
        $stmt = $pdo->prepare('SELECT * FROM purepay_payment_links WHERE tenant_id=:t AND source_ref=:s LIMIT 1 FOR UPDATE');
        $stmt->execute(['t'=>$tenantId,'s'=>$sourceRef]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) throw new PaymentRailsOriginateException('Could not create Pure//Pay durable operation');
        if ($fresh) {
            if (!in_array($payment['status'],['draft','queued','sent'],true)
                || (int)round((float)$payment['amount']*100)!==$amountCents
                || (float)$payment['unallocated_amount']>0.005
                || strtoupper((string)$payment['currency'])!=='USD'
                || trim((string)$payment['vendor_name'])!==$recipientName) {
                throw new PaymentRailsOriginateException('The AP payment changed or is not fully allocated; review it before dispatch');
            }
            $allocations=$pdo->prepare('SELECT a.amount_applied,b.id,b.tenant_id,b.vendor_name,b.currency,b.status,b.entity_id,b.amount_due,b.pwp_status
                FROM ap_payment_allocations a LEFT JOIN ap_bills b ON b.id=a.bill_id
                WHERE a.payment_id=:p ORDER BY a.bill_id,a.id FOR UPDATE');
            $allocations->execute(['p'=>$corePaymentId]); $total=0; $billBalances=[];
            foreach ($allocations->fetchAll(PDO::FETCH_ASSOC) as $allocation) {
                if ((int)($allocation['tenant_id']??0)!==$tenantId
                    || $allocation['vendor_name']!==$payment['vendor_name']
                    || strtoupper((string)$allocation['currency'])!=='USD'
                    || !in_array($allocation['status'],['approved','partially_paid'],true)
                    || ($allocation['pwp_status']??'')==='awaiting_ar'
                    || (int)($payment['entity_id']??0)<=0
                    || (int)($allocation['entity_id']??0)!==(int)$payment['entity_id']
                    || (float)$allocation['amount_applied']<=0) {
                    throw new PaymentRailsOriginateException('The AP bill reservations no longer match this payment');
                }
                $total+=(int)round((float)$allocation['amount_applied']*100);
                $billBalances[(int)$allocation['id']]=(int)round((float)$allocation['amount_due']*100);
            }
            if ($total!==$amountCents) throw new PaymentRailsOriginateException('The AP payment has no complete bill reservation');
            $reserved=$pdo->prepare('SELECT a.amount_applied FROM ap_payment_allocations a
                JOIN ap_payments p ON p.id=a.payment_id WHERE a.bill_id=:b AND p.tenant_id=:t
                AND p.status IN ("draft","queued","sent") FOR UPDATE');
            foreach ($billBalances as $billId=>$dueCents) {
                $reserved->execute(['b'=>$billId,'t'=>$tenantId]);
                $held=array_sum(array_map(static fn($amount)=>(int)round((float)$amount*100),$reserved->fetchAll(PDO::FETCH_COLUMN)));
                if ($held>$dueCents) throw new PaymentRailsOriginateException('The AP bill has competing or excess reservations; reconcile it before dispatch');
            }
        }
        $row['__fresh'] = $fresh;
        $pdo->commit();
        return $row;
        } catch (\Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
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
