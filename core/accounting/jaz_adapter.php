<?php
/**
 * core/accounting/jaz_adapter.php — Jaz.ai live destination adapter.
 *
 * Slice 2 — Phase 1 (live reads) + Phase 3 (live drafted writes).
 *
 * Every method now makes a real Jaz HTTP call via jazCall(). Responses
 * are normalised into the canonical CoreFlux shapes the rest of the
 * platform consumes — when QBO/Xero adapters land later, only the
 * shape-mapping code in THIS file differs; consumers don't change.
 *
 * Spec endpoint mappings (user-confirmed):
 *   validateConnection   → GET  /organization
 *   getChartOfAccounts   → GET  /chart-of-accounts
 *   getTrialBalance      → POST /reports/trial-balance
 *   getGeneralLedger     → POST /reports/general-ledger
 *   getProfitAndLoss     → POST /reports/profit-and-loss
 *   getBalanceSheet      → POST /reports/balance-sheet
 *   getArAging           → POST /reports/ar-report
 *   getApAging           → POST /reports/ap-report
 *   createDraftBill      → POST /bills     (saveAsDraft: true)
 *   createDraftInvoice   → POST /invoices  (saveAsDraft: true)
 *   createDraftJournal   → POST /journals
 *   postObject           → POST /{type}/{id}/convert-to-active  (for draft → active)
 *   getObject            → GET  /{type}/{id}
 *
 * Auth: Authorization: Bearer <api_key>. Credential resolution unchanged
 * from Slice 1 — decrypted on each call, never logged.
 */
declare(strict_types=1);

require_once __DIR__ . '/provider_adapter.php';
require_once __DIR__ . '/jaz_http.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../encryption.php';

class JazAccountingAdapter extends AccountingProviderAdapter
{
    public function providerKey(): string { return 'jaz'; }

    protected function resolveCredential(int $tenantId, int $subTenantId): ?string
    {
        try {
            $stmt = getDB()->prepare(
                "SELECT credential_secret_ct, connection_status
                   FROM accounting_provider_connections
                  WHERE tenant_id = :t AND sub_tenant_id = :st AND provider = 'jaz'
                  LIMIT 1"
            );
            $stmt->execute(['t' => $tenantId, 'st' => $subTenantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || $row['connection_status'] === 'revoked') return null;
            $ct = $row['credential_secret_ct'];
            if (!$ct) return null;
            $plain = decryptField($ct);
            return is_string($plain) && $plain !== '' ? $plain : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Probe with a real GET /organization. On 2xx, captures the org's
     * resource id + name + base currency for storage on the connection
     * row (auto-fetched per user-confirmed default). On non-2xx,
     * returns ok=false with a normalised error.
     */
    public function validateConnection(int $tenantId, int $subTenantId): array
    {
        $key = $this->resolveCredential($tenantId, $subTenantId);
        if ($key === null) {
            return ['ok' => false, 'status' => 'failed', 'scope' => null, 'org' => null,
                    'error' => 'no credential stored for this entity'];
        }
        try {
            $resp = jazCall($key, 'GET', 'organization');
        } catch (JazApiException $e) {
            $status = $e->httpStatus === 401 || $e->httpStatus === 403 ? 'failed' : 'failed';
            return ['ok' => false, 'status' => $status, 'scope' => null, 'org' => null,
                    'error' => substr($e->getMessage(), 0, 240)];
        }
        // Persist the resolved org id + currency so reads/writes don't
        // need to re-fetch every call. We update the row directly here
        // (validateConnection is the canonical place to do it; spec
        // §15.1 also lists scope-summary capture as part of validate).
        $orgRoot   = is_array($resp['organization'] ?? null) ? $resp['organization'] : $resp;
        $orgId     = (string) ($orgRoot['resourceId'] ?? $orgRoot['id']     ?? '');
        $orgName   = (string) ($orgRoot['name']       ?? $orgRoot['legalName'] ?? '');
        $baseCcy   = (string) ($orgRoot['baseCurrency']['code'] ?? $orgRoot['currency'] ?? 'USD');
        try {
            getDB()->prepare(
                "UPDATE accounting_provider_connections
                    SET provider_org_id = COALESCE(NULLIF(:org, ''), provider_org_id),
                        base_currency   = COALESCE(NULLIF(:bc, ''), base_currency)
                  WHERE tenant_id = :t AND sub_tenant_id = :st AND provider = 'jaz'"
            )->execute(['org' => $orgId, 'bc' => $baseCcy, 't' => $tenantId, 'st' => $subTenantId]);
        } catch (\Throwable $e) { /* non-fatal */ }

        return [
            'ok' => true, 'status' => 'active',
            'scope' => ['permissions' => ['organization.read'], 'shadow_user' => null],
            'org'   => ['id' => $orgId, 'name' => $orgName, 'base_currency' => $baseCcy],
            'error' => null,
        ];
    }

    /** Decrypt + return key OR throw — used by read/write methods. */
    private function keyOrThrow(int $tenantId, int $subTenantId): string
    {
        $k = $this->resolveCredential($tenantId, $subTenantId);
        if ($k === null) throw new AccountingAdapterValidationException('Jaz credential not configured for this entity');
        return $k;
    }

    // ============================================================ READS
    public function getChartOfAccounts(int $tenantId, int $subTenantId, array $filters = []): array
    {
        $key = $this->keyOrThrow($tenantId, $subTenantId);
        // Paginate: Jaz uses ?page=N&pageSize=M on list endpoints.
        $accounts = []; $page = 1; $pageSize = 200; $maxPages = 20;
        while ($page <= $maxPages) {
            $resp = jazCall($key, 'GET', 'chart-of-accounts', [], ['page' => $page, 'pageSize' => $pageSize]);
            $rows = $resp['data'] ?? $resp['items'] ?? $resp['results'] ?? [];
            if (!is_array($rows)) $rows = [];
            foreach ($rows as $r) {
                $accounts[] = $this->normalizeCoaRow($r);
            }
            $hasMore = (bool) ($resp['hasMore'] ?? $resp['hasNextPage']
                          ?? (count($rows) === $pageSize));
            if (!$hasMore || count($rows) < $pageSize) break;
            $page++;
        }
        return [
            'as_of'    => date('Y-m-d'),
            'accounts' => $accounts,
            'provider' => 'jaz',
        ];
    }

    public function getTrialBalance(int $tenantId, int $subTenantId, array $filters): array
    {
        $key  = $this->keyOrThrow($tenantId, $subTenantId);
        $asOf = $filters['asOf'] ?? $filters['to'] ?? date('Y-m-d');
        $resp = jazCall($key, 'POST', 'reports/trial-balance', ['endDate' => $asOf]);
        $lines = []; $totalDr = 0; $totalCr = 0;
        foreach (($resp['lines'] ?? $resp['rows'] ?? []) as $row) {
            $dr = $this->amountToCents($row['debit'] ?? $row['debitAmount'] ?? 0);
            $cr = $this->amountToCents($row['credit'] ?? $row['creditAmount'] ?? 0);
            $lines[] = [
                'account_code' => (string) ($row['accountCode'] ?? $row['code'] ?? ''),
                'account_name' => (string) ($row['accountName'] ?? $row['name'] ?? ''),
                'debit_cents'  => $dr,
                'credit_cents' => $cr,
            ];
            $totalDr += $dr; $totalCr += $cr;
        }
        return [
            'as_of' => $asOf, 'currency' => $resp['currency'] ?? 'USD',
            'lines' => $lines,
            'total_debit_cents'  => $totalDr,
            'total_credit_cents' => $totalCr,
            'provider' => 'jaz',
        ];
    }

    public function getGeneralLedger(int $tenantId, int $subTenantId, array $filters): array
    {
        $key  = $this->keyOrThrow($tenantId, $subTenantId);
        $body = [
            'startDate' => $filters['from'] ?? null,
            'endDate'   => $filters['to']   ?? null,
        ];
        if (!empty($filters['account'])) $body['accountResourceId'] = $filters['account'];
        $resp = jazCall($key, 'POST', 'reports/general-ledger', array_filter($body));
        $lines = [];
        foreach (($resp['lines'] ?? $resp['rows'] ?? []) as $row) {
            $lines[] = [
                'posted_at'        => (string) ($row['postedDate'] ?? $row['date'] ?? ''),
                'account_code'     => (string) ($row['accountCode'] ?? ''),
                'account_name'     => (string) ($row['accountName'] ?? ''),
                'memo'             => (string) ($row['memo']        ?? $row['narration'] ?? ''),
                'debit_cents'      => $this->amountToCents($row['debit']  ?? $row['debitAmount']  ?? 0),
                'credit_cents'     => $this->amountToCents($row['credit'] ?? $row['creditAmount'] ?? 0),
                'reference'        => (string) ($row['reference']        ?? ''),
                'provider_txn_id'  => (string) ($row['transactionResourceId'] ?? $row['txnId'] ?? ''),
            ];
        }
        return [
            'from' => $body['startDate'], 'to' => $body['endDate'],
            'currency' => $resp['currency'] ?? 'USD',
            'lines' => $lines, 'provider' => 'jaz',
        ];
    }

    public function getProfitAndLoss(int $tenantId, int $subTenantId, array $filters): array
    {
        $key  = $this->keyOrThrow($tenantId, $subTenantId);
        $resp = jazCall($key, 'POST', 'reports/profit-and-loss', array_filter([
            'startDate' => $filters['from'] ?? null,
            'endDate'   => $filters['to']   ?? null,
        ]));
        return ['report_type' => 'pnl', 'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null, 'jaz_payload' => $resp, 'provider' => 'jaz'];
    }

    public function getBalanceSheet(int $tenantId, int $subTenantId, array $filters): array
    {
        $key  = $this->keyOrThrow($tenantId, $subTenantId);
        $resp = jazCall($key, 'POST', 'reports/balance-sheet', array_filter([
            'asOfDate' => $filters['asOf'] ?? $filters['to'] ?? date('Y-m-d'),
        ]));
        return ['report_type' => 'balance_sheet', 'as_of' => $filters['asOf'] ?? null,
                'jaz_payload' => $resp, 'provider' => 'jaz'];
    }

    public function getArAging(int $tenantId, int $subTenantId, array $filters): array
    {
        $key  = $this->keyOrThrow($tenantId, $subTenantId);
        $resp = jazCall($key, 'POST', 'reports/ar-report', array_filter([
            'asOfDate' => $filters['asOf'] ?? $filters['to'] ?? date('Y-m-d'),
        ]));
        return ['report_type' => 'ar_aging', 'jaz_payload' => $resp, 'provider' => 'jaz'];
    }

    public function getApAging(int $tenantId, int $subTenantId, array $filters): array
    {
        $key  = $this->keyOrThrow($tenantId, $subTenantId);
        $resp = jazCall($key, 'POST', 'reports/ap-report', array_filter([
            'asOfDate' => $filters['asOf'] ?? $filters['to'] ?? date('Y-m-d'),
        ]));
        return ['report_type' => 'ap_aging', 'jaz_payload' => $resp, 'provider' => 'jaz'];
    }

    // ============================================================ WRITES
    public function createDraftBill(int $tenantId, int $subTenantId, array $bill, string $idempotencyKey): array
    {
        $key = $this->keyOrThrow($tenantId, $subTenantId);
        // The Command Service idempotency key dedupes on our side. Jaz
        // accepts an Idempotency-Key header on POSTs that retry-safely;
        // we set saveAsDraft=true per spec §15.3 (drafts then post).
        $payload = array_merge([
            'saveAsDraft' => true,
            'submitForApproval' => false,
        ], $bill);
        $payload['__idempotencyKey'] = $idempotencyKey; // not sent
        unset($payload['__idempotencyKey']);
        $resp = jazCall($key, 'POST', 'bills', $payload);
        return $this->wrapWriteResult('bill', $resp, $idempotencyKey, 'draft');
    }

    public function createDraftInvoice(int $tenantId, int $subTenantId, array $invoice, string $idempotencyKey): array
    {
        $key = $this->keyOrThrow($tenantId, $subTenantId);
        $payload = array_merge([
            'saveAsDraft' => true,
            'submitForApproval' => false,
        ], $invoice);
        $resp = jazCall($key, 'POST', 'invoices', $payload);
        return $this->wrapWriteResult('invoice', $resp, $idempotencyKey, 'draft');
    }

    public function createDraftJournal(int $tenantId, int $subTenantId, array $journal, string $idempotencyKey): array
    {
        $key = $this->keyOrThrow($tenantId, $subTenantId);
        $payload = array_merge([
            'saveAsDraft' => true,
        ], $journal);
        $resp = jazCall($key, 'POST', 'journals', $payload);
        return $this->wrapWriteResult('journal', $resp, $idempotencyKey, 'draft');
    }

    /**
     * Convert a Jaz draft to active. Spec §15.3 calls this
     * "approve_command → execute_command → posted". For drafts created
     * via createDraft*, Jaz exposes a `/draft/convert-to-active` bulk
     * endpoint — we wrap the single-id case so the Command Service
     * doesn't need to know Jaz's bulk semantics.
     */
    public function postObject(int $tenantId, int $subTenantId, string $providerObjectType, string $providerObjectId): array
    {
        $key = $this->keyOrThrow($tenantId, $subTenantId);
        if ($providerObjectId === '') {
            throw new AccountingAdapterValidationException('provider_object_id required to post object');
        }
        $resp = jazCall($key, 'POST', 'draft/convert-to-active', [
            'resourceIds' => [$providerObjectId],
            'businessTransactionType' => strtoupper($providerObjectType),
        ]);
        return $this->wrapWriteResult($providerObjectType, [
            'resourceId' => $providerObjectId,
            'status'     => 'ACTIVE',
            'jobId'      => $resp['jobId'] ?? null,
        ], 'post:' . $providerObjectId, 'posted');
    }

    public function getObject(int $tenantId, int $subTenantId, string $providerObjectType, string $providerObjectId): array
    {
        $key  = $this->keyOrThrow($tenantId, $subTenantId);
        $path = $this->pluralPath($providerObjectType) . '/' . rawurlencode($providerObjectId);
        $resp = jazCall($key, 'GET', $path);
        return [
            'provider_object_type' => $providerObjectType,
            'provider_object_id'   => $providerObjectId,
            'jaz_payload'          => $resp,
        ];
    }

    public function normalizeProviderError(\Throwable $e): array
    {
        if ($e instanceof AccountingAdapterNotReadyException) {
            return ['code' => 'adapter_not_ready', 'message' => $e->getMessage()];
        }
        if ($e instanceof AccountingAdapterValidationException) {
            return ['code' => 'provider_validation', 'message' => substr($e->getMessage(), 0, 240)];
        }
        if ($e instanceof JazApiException) {
            $code = 'provider_error';
            switch ($e->httpStatus) {
                case 401: $code = 'auth_invalid';      break;
                case 403: $code = 'auth_forbidden';    break;
                case 404: $code = 'not_found';         break;
                case 409: $code = 'conflict';          break;
                case 422: $code = 'provider_validation'; break;
                case 429: $code = 'rate_limited';      break;
                case 500: case 502: case 503: case 504: $code = 'provider_unavailable'; break;
            }
            return ['code' => $code, 'message' => substr($e->getMessage(), 0, 240)];
        }
        return ['code' => 'provider_error', 'message' => substr($e->getMessage(), 0, 240)];
    }

    // ============================================================ helpers
    private function normalizeCoaRow(array $r): array
    {
        $type = strtolower((string) ($r['accountType']      ?? $r['type']           ?? ''));
        $code = (string)       ($r['accountCode']      ?? $r['code']           ?? '');
        $name = (string)       ($r['accountName']      ?? $r['name']           ?? '');
        $ccy  = (string)       ($r['currency']['code'] ?? $r['currency']       ?? 'USD');
        return [
            'code'             => $code,
            'name'             => $name,
            'type'             => $type,
            'currency'         => $ccy,
            'active'           => ($r['isActive'] ?? $r['active'] ?? true) ? true : false,
            'jaz_resource_id'  => (string) ($r['resourceId'] ?? $r['id'] ?? ''),
        ];
    }

    /** Convert a Jaz amount (number with decimals) to integer cents. */
    private function amountToCents($n): int
    {
        if (is_int($n)) return $n; // assume already cents
        if (is_string($n)) $n = (float) $n;
        if (!is_numeric($n)) return 0;
        return (int) round(((float) $n) * 100);
    }

    /** Build a stable response wrapper the Command Service consumes. */
    private function wrapWriteResult(string $kind, array $resp, string $idem, string $status): array
    {
        $root = $resp[$kind] ?? $resp;
        if (!is_array($root)) $root = $resp;
        return [
            'provider_object_type' => $kind,
            'provider_object_id'   => (string) ($root['resourceId'] ?? $root['id'] ?? ''),
            'idempotency_key'      => $idem,
            'status'               => $status,
            'jaz_payload'          => $root,
        ];
    }

    /** Map canonical object type to Jaz REST path segment. */
    private function pluralPath(string $type): string
    {
        switch (strtolower($type)) {
            case 'bill':         return 'bills';
            case 'invoice':      return 'invoices';
            case 'journal':      return 'journals';
            case 'contact':      return 'contacts';
            case 'item':         return 'items';
            case 'account':
            case 'chart_of_account': return 'chart-of-accounts';
            default:             return $type;
        }
    }
}
