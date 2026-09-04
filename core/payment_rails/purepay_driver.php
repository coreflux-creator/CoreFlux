<?php
/** Pure//Pay vendor-payment rail. Uses the provider's Moov-backed wallet path. */

declare(strict_types=1);

require_once __DIR__ . '/../payment_rails.php';

class PurePayRailDriver implements PaymentRailsDriver
{
    public function name(): string { return 'purepay'; }
    public function isConfigured(): bool
    {
        return (bool) (defined('COREFLUX_DATA_KEY') || getenv('COREFLUX_DATA_KEY'));
    }

    public function isConfiguredForTenant(int $tenantId): bool
    {
        require_once __DIR__ . '/../purepay_service.php';
        $conn = purepayGetConnection($tenantId);
        return $conn && ($conn['status'] ?? '') === 'active' && ($conn['api_key'] ?? '') !== '';
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

        $batchId = (string) ($opts['batch_id'] ?? ('purepay_' . date('YmdHis') . '_' . bin2hex(random_bytes(3))));
        $results = [];
        foreach ($items as $index => $item) {
            try {
                $results[] = $this->originateOne($tenantId, (string) $conn['api_key'], $item, $opts, $index);
            } catch (\Throwable $e) {
                $this->recordFailure($tenantId, (string) ($item['external_ref'] ?? ''), $e);
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
        if ((int) $item['amount_cents'] <= 0) {
            throw new PaymentRailsOriginateException("Pure//Pay item {$index}: amount must be positive");
        }

        $sourceRef = (string) $item['external_ref'];
        $recipientName = trim((string) ($item['recipient_full_name'] ?? $item['recipient_name']));
        $recipientEmail = strtolower(trim((string) $item['recipient_email']));
        $fingerprint = hash('sha256', json_encode([
            $tenantId, $sourceRef, (string) $item['recipient_ref'], $recipientName,
            $recipientEmail, (int) $item['amount_cents'], 'wallet',
        ], JSON_UNESCAPED_SLASHES));
        $link = $this->claimLink($tenantId, $sourceRef, (int) $item['amount_cents'], $fingerprint);
        if (!hash_equals((string) $link['request_fingerprint'], $fingerprint)) {
            throw new PaymentRailsOriginateException('Pure//Pay idempotency conflict: this CoreFlux payment was previously sent with different details');
        }

        $billId = (string) ($link['purepay_bill_id'] ?? '');
        $paymentId = (string) ($link['purepay_payment_id'] ?? '');
        $rawStatus = strtolower(trim((string) ($link['status'] ?? '')));
        $existingStatus = $this->normalizeStatus($rawStatus);
        $safePrePayStates = ['creating', 'bill_created', 'ready_to_pay', 'pushed_unverified', 'failed'];
        if ($paymentId !== '' || ($billId !== '' && !in_array($rawStatus, $safePrePayStates, true))) {
            return [
                'external_ref' => $sourceRef,
                'status' => $existingStatus,
                'rail_external_ref' => $billId !== '' ? 'purepay:bill:' . $billId : 'purepay:payment:' . $paymentId,
            ];
        }
        if ($billId === '' && !in_array($rawStatus, $safePrePayStates, true)) {
            $e = new PurePayApiException('Pure//Pay may have accepted this payment, but CoreFlux has no remote id; review the provider dashboard before retrying');
            $e->outcomeUncertain = true;
            throw $e;
        }
        if ($billId === '' && $rawStatus === 'failed') {
            $this->updateLink($tenantId, $sourceRef, ['status'=>'creating','last_error'=>null,'last_error_json'=>null]);
        } elseif ($billId === '' && $rawStatus === 'creating' && empty($link['__fresh'])) {
            $e = new PurePayApiException('Pure//Pay origination is already in progress or stopped before its remote result was recorded; review the provider dashboard before retrying');
            $e->outcomeUncertain = true;
            throw $e;
        } elseif ($billId === '' && $rawStatus !== 'creating') {
            throw new PaymentRailsOriginateException('Pure//Pay origination is in an uncertain state; review the provider dashboard before retrying');
        }

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
            $created = purepayCreateBill($apiKey, $billPayload);
            $billId = purepayResourceId($created, ['bill']);
            if ($billId === '') throw new PurePayApiException('Pure//Pay created a bill but did not return its id');
            $this->updateLink($tenantId, $sourceRef, [
                'purepay_bill_id' => $billId,
                'status' => 'bill_created',
                'response_json' => json_encode($created, JSON_UNESCAPED_SLASHES),
            ]);
        }

        // Charter primitive #5: re-read the provider after the create before paying.
        $remoteBill = $this->findRemoteResource(purepayCollection(purepayListBills($apiKey), ['bills']), $billId);
        if (!$remoteBill) {
            $this->updateLink($tenantId, $sourceRef, ['status' => 'pushed_unverified']);
            throw new PaymentRailsOriginateException('Pure//Pay bill was created but could not be verified from GET /bills; payment was not released');
        }

        // The provider does not publish an idempotency key. Reconcile the bill
        // against GET /payments immediately before every release so a retry
        // after a prior response-loss cannot pay the same bill twice.
        $paymentsBefore = purepayCollection(purepayListPayments($apiKey), ['payments']);
        $alreadyPaid = $this->findPayment($paymentsBefore, '', $billId);
        if ($alreadyPaid) {
            $paymentId = (string) ($alreadyPaid['id'] ?? $alreadyPaid['payment_id'] ?? '');
            $status = (string) ($alreadyPaid['pay_status'] ?? $alreadyPaid['status'] ?? 'submitted');
            $this->updateLink($tenantId, $sourceRef, [
                'purepay_payment_id' => $paymentId !== '' ? $paymentId : null,
                'status' => $status,
                'response_json' => json_encode($alreadyPaid, JSON_UNESCAPED_SLASHES),
                'last_synced_at' => date('Y-m-d H:i:s'),
                'last_error' => null,
                'last_error_json' => null,
            ]);
            return [
                'external_ref' => $sourceRef,
                'status' => $this->normalizeStatus($status),
                'rail_external_ref' => 'purepay:bill:' . $billId,
            ];
        }

        $paid = purepayPayBill($apiKey, $billId, ['pay_via' => 'wallet']);
        $paymentId = purepayResourceId($paid, ['payment', 'bill']);
        $status = (string) (purepayResource($paid, ['payment', 'bill'])['pay_status']
            ?? purepayResource($paid, ['payment', 'bill'])['status'] ?? 'submitted');

        // Verify the payment exists in the provider's own list. The API does not
        // publish a per-payment GET, so the list endpoint is the source of truth.
        $payments = purepayCollection(purepayListPayments($apiKey), ['payments']);
        $remotePayment = $this->findPayment($payments, $paymentId, $billId);
        if ($remotePayment) {
            $paymentId = (string) ($remotePayment['id'] ?? $remotePayment['payment_id'] ?? $paymentId);
            $status = (string) ($remotePayment['pay_status'] ?? $remotePayment['status'] ?? $status);
        } else {
            $status = 'posted_unverified';
        }
        $this->updateLink($tenantId, $sourceRef, [
            'purepay_payment_id' => $paymentId !== '' ? $paymentId : null,
            'status' => $status,
            'response_json' => json_encode($paid, JSON_UNESCAPED_SLASHES),
            'last_synced_at' => date('Y-m-d H:i:s'),
            'last_error' => null,
            'last_error_json' => null,
        ]);
        return [
            'external_ref' => $sourceRef,
            'status' => $this->normalizeStatus($status),
            'rail_external_ref' => 'purepay:bill:' . $billId,
        ];
    }

    private function ensureVendor(int $tenantId, string $apiKey, array $item): array
    {
        $coreRef = (string) $item['recipient_ref'];
        $stmt = getDB()->prepare('SELECT purepay_vendor_id, verification_status FROM purepay_vendor_mappings WHERE tenant_id=:t AND core_vendor_ref=:r LIMIT 1');
        $stmt->execute(['t' => $tenantId, 'r' => $coreRef]);
        $mapped = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($mapped) return ['id' => (string) $mapped['purepay_vendor_id']];

        $name = trim((string) ($item['recipient_full_name'] ?? $item['recipient_name']));
        $email = strtolower(trim((string) $item['recipient_email']));
        $vendors = purepayCollection(purepayListVendors($apiKey), ['vendors']);
        $remote = null;
        foreach ($vendors as $candidate) {
            if (!is_array($candidate)) continue;
            $candidateEmail = strtolower(trim((string) ($candidate['email'] ?? '')));
            $candidateName = trim((string) ($candidate['legal_name'] ?? $candidate['display_name'] ?? $candidate['name'] ?? ''));
            if (($candidateEmail !== '' && $candidateEmail === $email) || strcasecmp($candidateName, $name) === 0) {
                $remote = $candidate;
                break;
            }
        }
        $verification = 'verified';
        if (!$remote) {
            $created = purepayCreateVendor($apiKey, [
                'legal_name' => $name,
                'email' => $email,
                'relationship' => 'vendor',
            ]);
            $vendorId = purepayResourceId($created, ['vendor']);
            if ($vendorId === '') throw new PurePayApiException('Pure//Pay created a vendor but did not return its id');
            $after = purepayCollection(purepayListVendors($apiKey), ['vendors']);
            $remote = $this->findRemoteResource($after, $vendorId);
            if (!$remote) $verification = 'pushed_unverified';
        } else {
            $vendorId = (string) ($remote['id'] ?? $remote['vendor_id'] ?? '');
        }
        if ($vendorId === '') throw new PurePayApiException('Pure//Pay vendor id is missing');
        getDB()->prepare(
            'INSERT INTO purepay_vendor_mappings
                (tenant_id, core_vendor_ref, purepay_vendor_id, vendor_name, vendor_email, verification_status)
             VALUES (:t,:r,:v,:n,:e,:s)
             ON DUPLICATE KEY UPDATE purepay_vendor_id=VALUES(purepay_vendor_id),
                vendor_name=VALUES(vendor_name), vendor_email=VALUES(vendor_email),
                verification_status=VALUES(verification_status), updated_at=NOW()'
        )->execute(['t'=>$tenantId,'r'=>$coreRef,'v'=>$vendorId,'n'=>$name,'e'=>$email,'s'=>$verification]);
        return ['id' => $vendorId, 'verification_status' => $verification];
    }

    private function claimLink(int $tenantId, string $sourceRef, int $amountCents, string $fingerprint): array
    {
        $corePaymentId = preg_match('/^ap_payment:(\d+)$/', $sourceRef, $m) ? (int) $m[1] : null;
        $insert = getDB()->prepare(
            'INSERT IGNORE INTO purepay_payment_links
                (tenant_id, source_ref, core_payment_id, amount_cents, status, request_fingerprint)
             VALUES (:t,:s,:p,:a,"creating",:f)'
        );
        $insert->execute(['t'=>$tenantId,'s'=>$sourceRef,'p'=>$corePaymentId,'a'=>$amountCents,'f'=>$fingerprint]);
        $fresh = $insert->rowCount() > 0;
        $stmt = getDB()->prepare('SELECT * FROM purepay_payment_links WHERE tenant_id=:t AND source_ref=:s LIMIT 1');
        $stmt->execute(['t'=>$tenantId,'s'=>$sourceRef]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) throw new PaymentRailsOriginateException('Could not create Pure//Pay durable operation');
        $row['__fresh'] = $fresh;
        return $row;
    }

    private function updateLink(int $tenantId, string $sourceRef, array $fields): void
    {
        $allowed = ['purepay_vendor_id','purepay_bill_id','purepay_payment_id','status','response_json','last_error','last_error_json','last_synced_at'];
        $sets = []; $params = ['t'=>$tenantId,'s'=>$sourceRef];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;
            $sets[] = "{$key}=:{$key}"; $params[$key] = $value;
        }
        if (!$sets) return;
        getDB()->prepare('UPDATE purepay_payment_links SET ' . implode(',', $sets) . ', updated_at=NOW() WHERE tenant_id=:t AND source_ref=:s')
            ->execute($params);
    }

    private function recordFailure(int $tenantId, string $sourceRef, \Throwable $error): void
    {
        if ($sourceRef === '') return;
        $status = ($error instanceof PurePayApiException && $error->outcomeUncertain) ? 'needs_review' : 'failed';
        $raw = $error instanceof PurePayApiException ? $error->raw : null;
        try {
            $this->updateLink($tenantId, $sourceRef, [
                'status' => $status,
                'last_error' => substr($error->getMessage(), 0, 500),
                'last_error_json' => $raw ? json_encode($raw, JSON_UNESCAPED_SLASHES) : null,
            ]);
        } catch (\Throwable $_) {}
    }

    private function findRemoteResource(array $rows, string $id): ?array
    {
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $remoteId = (string) ($row['id'] ?? $row['vendor_id'] ?? $row['bill_id'] ?? $row['payment_id'] ?? '');
            if ($remoteId !== '' && hash_equals($id, $remoteId)) return $row;
        }
        return null;
    }

    private function findPayment(array $rows, string $paymentId, string $billId): ?array
    {
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $rid = (string) ($row['id'] ?? $row['payment_id'] ?? '');
            $rbill = (string) ($row['bill_id'] ?? $row['payable_id'] ?? '');
            if (($paymentId !== '' && $rid === $paymentId) || ($billId !== '' && $rbill === $billId)) return $row;
        }
        return null;
    }

    public function getStatus(string $railExternalRef): string
    {
        require_once __DIR__ . '/../purepay_service.php';
        if (!preg_match('/^purepay:(bill|payment):(.+)$/', $railExternalRef, $m)) return 'unknown';
        $column = $m[1] === 'bill' ? 'purepay_bill_id' : 'purepay_payment_id';
        try {
            $stmt = getDB()->prepare("SELECT * FROM purepay_payment_links WHERE {$column}=:id ORDER BY id DESC LIMIT 1");
            $stmt->execute(['id' => $m[2]]);
            $link = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$link) return 'unknown';
            $conn = purepayGetConnection((int) $link['tenant_id']);
            if (!$conn || ($conn['status'] ?? '') !== 'active') return $this->normalizeStatus((string) $link['status']);
            $rows = purepayCollection(purepayListPayments((string) $conn['api_key']), ['payments']);
            $remote = $this->findPayment($rows, (string) ($link['purepay_payment_id'] ?? ''), (string) ($link['purepay_bill_id'] ?? ''));
            if (!$remote) return $this->normalizeStatus((string) $link['status']);
            $status = (string) ($remote['pay_status'] ?? $remote['status'] ?? 'unknown');
            $this->updateLink((int) $link['tenant_id'], (string) $link['source_ref'], [
                'status'=>$status, 'last_synced_at'=>date('Y-m-d H:i:s'),
                'purepay_payment_id'=>(string) ($remote['id'] ?? $remote['payment_id'] ?? $link['purepay_payment_id'] ?? ''),
            ]);
            return $this->normalizeStatus($status);
        } catch (\Throwable $_) { return 'unknown'; }
    }

    private function normalizeStatus(string $status): string
    {
        $s = strtolower(trim($status));
        return match ($s) {
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
                'Public API does not document idempotency or pricing',
            ],
        ];
    }
}
