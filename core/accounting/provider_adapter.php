<?php
/**
 * core/accounting/provider_adapter.php
 *
 * Provider-neutral accounting backend contract — per spec §7.2 + §26.
 *
 * Every Jaz / QBO / Xero / CoreFlux-Native implementation MUST implement
 * `AccountingProviderAdapter` so the rest of the system (Accounting
 * Command Service, GL screens, AI Tool Gateway) never speaks directly
 * to a vendor. The spec's TS Promise signatures translate to
 * synchronous PHP method signatures returning canonical PHP arrays
 * (matching the rest of the CoreFlux codebase).
 *
 * Canonical shapes returned by reads:
 *   CanonicalChartOfAccounts: [
 *     'as_of'    => 'YYYY-MM-DD',
 *     'accounts' => [['code'=>'1000','name'=>'Cash','type'=>'asset','currency'=>'USD','active'=>true], ...],
 *   ]
 *   CanonicalTrialBalance: [
 *     'as_of' => 'YYYY-MM-DD', 'currency'=>'USD',
 *     'lines' => [['account_code'=>'1000','account_name'=>'Cash','debit_cents'=>123,'credit_cents'=>0], ...],
 *     'total_debit_cents' => int, 'total_credit_cents' => int,
 *   ]
 *   CanonicalGLResult: [
 *     'from'=>'YYYY-MM-DD','to'=>'YYYY-MM-DD',
 *     'lines'=>[['posted_at'=>'…','account_code'=>'…','account_name'=>'…','memo'=>'…','debit_cents'=>0,'credit_cents'=>0,'reference'=>'…','provider_txn_id'=>'…'], ...],
 *   ]
 *   ProviderResult: ['provider_object_type'=>'bill','provider_object_id'=>'jaz_123','idempotency_key'=>'…','status'=>'posted'|'draft']
 *
 * Errors:
 *   Adapters MUST throw \RuntimeException with normalised messages so
 *   the Command Service can persist a stable error_code/error_message
 *   on the outbox row. Adapters that are not yet wired throw
 *   `AccountingAdapterNotReadyException` (subclass of RuntimeException)
 *   so callers can distinguish "this provider is gated behind partner
 *   diligence" from a transient transport error.
 */
declare(strict_types=1);

class AccountingAdapterNotReadyException extends \RuntimeException {}
class AccountingAdapterValidationException extends \RuntimeException {}

abstract class AccountingProviderAdapter
{
    /**
     * Identifier used in DB rows (`provider` column). Matches the
     * accounting_provider_connections.provider ENUM.
     */
    abstract public function providerKey(): string;

    /**
     * Light-touch credential probe. Returns:
     *   ['ok' => bool, 'status' => 'active'|'expired'|'revoked'|'failed',
     *    'scope' => ['permissions'=>[...], 'shadow_user'=>?string],
     *    'org' => ['id'=>?string, 'name'=>?string, 'base_currency'=>?string],
     *    'error' => ?string]
     * MUST NOT throw on auth failure — returns ok=false with a
     * normalised message so the UI can surface it.
     */
    abstract public function validateConnection(int $tenantId, int $subTenantId): array;

    // -------- Read APIs (Phase 1) --------
    abstract public function getChartOfAccounts(int $tenantId, int $subTenantId, array $filters = []): array;
    abstract public function getTrialBalance(int $tenantId, int $subTenantId, array $filters): array;
    abstract public function getGeneralLedger(int $tenantId, int $subTenantId, array $filters): array;
    abstract public function getProfitAndLoss(int $tenantId, int $subTenantId, array $filters): array;
    abstract public function getBalanceSheet(int $tenantId, int $subTenantId, array $filters): array;
    abstract public function getArAging(int $tenantId, int $subTenantId, array $filters): array;
    abstract public function getApAging(int $tenantId, int $subTenantId, array $filters): array;

    // -------- Write APIs (Phase 3+ — drafts then post) --------
    abstract public function createDraftBill(int $tenantId, int $subTenantId, array $bill, string $idempotencyKey): array;
    abstract public function createDraftInvoice(int $tenantId, int $subTenantId, array $invoice, string $idempotencyKey): array;
    abstract public function createDraftJournal(int $tenantId, int $subTenantId, array $journal, string $idempotencyKey): array;
    abstract public function postObject(int $tenantId, int $subTenantId, string $providerObjectType, string $providerObjectId): array;

    // -------- Generic fetch + error normalisation --------
    abstract public function getObject(int $tenantId, int $subTenantId, string $providerObjectType, string $providerObjectId): array;
    abstract public function normalizeProviderError(\Throwable $e): array;
}

/**
 * Factory — returns the adapter wired for a given provider. New
 * providers register themselves here. Keeps consumers (command
 * service, API endpoints) provider-agnostic.
 */
function accountingProviderAdapterFor(string $provider): AccountingProviderAdapter
{
    switch ($provider) {
        case 'jaz':
            require_once __DIR__ . '/jaz_adapter.php';
            return new JazAccountingAdapter();
        // Future: 'coreflux_native', 'qbo', 'xero'. We deliberately do
        // NOT silently fall back when an unknown provider is asked for —
        // the caller has to wire a new adapter explicitly.
        default:
            throw new \InvalidArgumentException("No adapter registered for provider={$provider}");
    }
}
