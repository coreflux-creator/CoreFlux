<?php
/**
 * core/accounting/jaz_adapter.php — Jaz.ai destination adapter.
 *
 * Slice 1 scope (per user confirmation): SKELETON ONLY. Per spec §2,
 * Jaz's endpoint-level API contracts are NOT publicly documented, so
 * live HTTP calls are gated behind Phase 0 partner diligence. This
 * class implements the AccountingProviderAdapter surface so the rest
 * of the system (Command Service, API endpoints, UI) can be built and
 * tested end-to-end against a deterministic stub; the moment the
 * partner contract lands, the only file that changes is THIS one.
 *
 * Reads return empty canonical shapes with a `not_implemented_yet`
 * marker so the UI can render "Connect Jaz to populate" states
 * without blowing up. Writes throw AccountingAdapterNotReadyException
 * so the Command Service marks the outbox row failed → dead_letter
 * after max_attempts (correct behaviour until Jaz endpoints are wired).
 *
 * Credential resolution is real (encrypted at rest, decrypted on read,
 * never logged) — the security spine in spec §11 is implemented
 * end-to-end in Slice 1 so Phase 0 diligence only adds the actual
 * HTTP call sites.
 */
declare(strict_types=1);

require_once __DIR__ . '/provider_adapter.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../encryption.php';

class JazAccountingAdapter extends AccountingProviderAdapter
{
    public function providerKey(): string
    {
        return 'jaz';
    }

    /**
     * Returns the decrypted Jaz API key for an entity, or null if no
     * active connection exists. Never logs or echoes the key.
     */
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
     * Probe the stored credential. Slice 1: validates we CAN decrypt
     * the stored secret and that it looks like a Jaz key (non-empty,
     * ≥ 24 chars matching Jaz's publicly visible key shape). Returns
     * 'pending_diligence' status until the live /me-style probe is
     * wired in Phase 0.
     */
    public function validateConnection(int $tenantId, int $subTenantId): array
    {
        $key = $this->resolveCredential($tenantId, $subTenantId);
        if ($key === null) {
            return [
                'ok' => false, 'status' => 'failed',
                'scope' => null, 'org' => null,
                'error' => 'no credential stored for this entity',
            ];
        }
        if (strlen($key) < 24) {
            return [
                'ok' => false, 'status' => 'failed',
                'scope' => null, 'org' => null,
                'error' => 'credential too short to be a valid Jaz key',
            ];
        }
        // Phase 0 placeholder. When the Jaz `/v1/me` (or equivalent)
        // endpoint is documented, swap this block for a real probe and
        // flip the status to 'active' on a 2xx.
        return [
            'ok' => true,
            'status' => 'pending_diligence',
            'scope' => [
                'permissions' => ['unknown_until_partner_diligence'],
                'shadow_user' => null,
            ],
            'org' => [
                'id' => null, 'name' => null, 'base_currency' => null,
            ],
            'error' => null,
            'not_implemented_yet' => true,
        ];
    }

    private function emptyReadResult(string $kind, array $filters): array
    {
        return [
            'as_of'                => $filters['asOf'] ?? $filters['to'] ?? date('Y-m-d'),
            'from'                 => $filters['from'] ?? null,
            'to'                   => $filters['to'] ?? null,
            'currency'             => 'USD',
            'accounts'             => [],
            'lines'                => [],
            'total_debit_cents'    => 0,
            'total_credit_cents'   => 0,
            'provider'             => 'jaz',
            'report_type'          => $kind,
            'not_implemented_yet'  => true,
            'message'              => 'Jaz live HTTP calls land in Phase 0 — see CoreFlux_Jaz_AI_Integration_Specification §2.',
        ];
    }

    public function getChartOfAccounts(int $tenantId, int $subTenantId, array $filters = []): array
    { return $this->emptyReadResult('chart_of_accounts', $filters); }

    public function getTrialBalance(int $tenantId, int $subTenantId, array $filters): array
    { return $this->emptyReadResult('trial_balance', $filters); }

    public function getGeneralLedger(int $tenantId, int $subTenantId, array $filters): array
    { return $this->emptyReadResult('general_ledger', $filters); }

    public function getProfitAndLoss(int $tenantId, int $subTenantId, array $filters): array
    { return $this->emptyReadResult('pnl', $filters); }

    public function getBalanceSheet(int $tenantId, int $subTenantId, array $filters): array
    { return $this->emptyReadResult('balance_sheet', $filters); }

    public function getArAging(int $tenantId, int $subTenantId, array $filters): array
    { return $this->emptyReadResult('ar_aging', $filters); }

    public function getApAging(int $tenantId, int $subTenantId, array $filters): array
    { return $this->emptyReadResult('ap_aging', $filters); }

    // Writes — gated behind partner contract. The Command Service
    // catches AccountingAdapterNotReadyException, marks the outbox row
    // failed, and (after max_attempts) dead-letters. No silent success.
    public function createDraftBill(int $tenantId, int $subTenantId, array $bill, string $idempotencyKey): array
    { throw new AccountingAdapterNotReadyException('Jaz createDraftBill is gated behind Phase 0 partner diligence.'); }

    public function createDraftInvoice(int $tenantId, int $subTenantId, array $invoice, string $idempotencyKey): array
    { throw new AccountingAdapterNotReadyException('Jaz createDraftInvoice is gated behind Phase 0 partner diligence.'); }

    public function createDraftJournal(int $tenantId, int $subTenantId, array $journal, string $idempotencyKey): array
    { throw new AccountingAdapterNotReadyException('Jaz createDraftJournal is gated behind Phase 0 partner diligence.'); }

    public function postObject(int $tenantId, int $subTenantId, string $providerObjectType, string $providerObjectId): array
    { throw new AccountingAdapterNotReadyException('Jaz postObject is gated behind Phase 0 partner diligence.'); }

    public function getObject(int $tenantId, int $subTenantId, string $providerObjectType, string $providerObjectId): array
    {
        return [
            'provider_object_type' => $providerObjectType,
            'provider_object_id'   => $providerObjectId,
            'not_implemented_yet'  => true,
        ];
    }

    /**
     * Normalise any Throwable to a stable {code,message} pair so the
     * outbox stores a recoverable error_code rather than a stack trace.
     * Real Jaz error mapping (HTTP 401/403/422/429/5xx) lands when the
     * adapter goes live.
     */
    public function normalizeProviderError(\Throwable $e): array
    {
        if ($e instanceof AccountingAdapterNotReadyException) {
            return ['code' => 'adapter_not_ready', 'message' => $e->getMessage()];
        }
        if ($e instanceof AccountingAdapterValidationException) {
            return ['code' => 'provider_validation', 'message' => substr($e->getMessage(), 0, 240)];
        }
        return ['code' => 'provider_error', 'message' => substr($e->getMessage(), 0, 240)];
    }
}
