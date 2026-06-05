<?php
/**
 * core/accounting/jaz_payload_mapper.php
 *
 * CoreFlux row → Jaz API payload translator. The bridge between
 * Slice 3's "enqueue the raw row" and Jaz actually accepting a draft.
 *
 * Three translation surfaces:
 *   - mapBillToJaz($tenantId, $subTenantId, $row, $provider='jaz')
 *   - mapInvoiceToJaz(...)
 *   - mapJournalToJaz(...)
 *
 * Each resolves CoreFlux foreign keys (vendor_id, customer_id,
 * account_id) to Jaz resource ids via the existing
 * accounting_destination_links table. If a referenced entity isn't
 * linked yet (operator hasn't synced their CoC / vendor list to Jaz),
 * we throw AccountingAdapterValidationException with a clear
 * "vendor #42 not linked to jaz" message — that lands cleanly on the
 * outbox row as `provider_validation`, telling the operator exactly
 * what's missing.
 *
 * This file is provider-aware ('jaz') by design — when a second
 * adapter lands, fork the file or add a $provider switch. Each
 * provider's payload shape is distinct enough that a single generic
 * mapper would be more confusing than helpful.
 */
declare(strict_types=1);

require_once __DIR__ . '/provider_adapter.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/account_mapping_service.php';

/**
 * Resolve a CoreFlux object → Jaz resourceId via the destination
 * links table. Returns the resourceId or throws Validation.
 *
 * For `coreflux_object_type='account'` specifically, falls back to
 * `accounting_account_mappings` (the per-entity mapping grid the
 * operator manages in JazIntegrationSettings.jsx). Without this
 * fallback the very first JE push always fails because no
 * destination_links row exists yet — the mappings table is the
 * operator-declared source of truth for "this CoreFlux account
 * corresponds to that Jaz resource".
 *
 * When we resolve via the mapping fallback we also write the
 * destination_links row so future lookups stay fast and the
 * canonical link history stays accurate.
 */
function _accLookupJazResourceId(int $tenantId, int $subTenantId, string $corefluxObjectType, int $corefluxObjectId): string
{
    if ($corefluxObjectId <= 0) {
        throw new AccountingAdapterValidationException("missing {$corefluxObjectType} reference");
    }
    $stmt = getDB()->prepare(
        "SELECT provider_object_id FROM accounting_destination_links
          WHERE tenant_id = :t AND sub_tenant_id = :st AND provider = 'jaz'
            AND coreflux_object_type = :cot AND coreflux_object_id = :coi
            AND sync_status IN ('pending','posted')
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['t' => $tenantId, 'st' => $subTenantId,
                    'cot' => $corefluxObjectType, 'coi' => $corefluxObjectId]);
    $rid = (string) ($stmt->fetchColumn() ?: '');
    if ($rid !== '') return $rid;

    // ── account-type fallback: consult accounting_account_mappings ──
    // The operator-managed mapping grid is the source of truth for
    // CoreFlux↔Jaz GL account linkage. If a mapping row exists, use
    // its provider_account_id and backfill destination_links so
    // future pushes hit the fast path.
    if ($corefluxObjectType === 'account') {
        $map = accountingAccountMappingLookup($tenantId, $subTenantId, 'jaz', $corefluxObjectId);
        $providerId = $map['provider_account_id'] ?? null;
        if ($providerId !== null && $providerId !== '') {
            // Opportunistic backfill — failure here (e.g. concurrent
            // insert, transient DB issue) must NOT break the resolver.
            // The mapping table is the source of truth; destination_links
            // is just a fast path.
            try {
                getDB()->prepare(
                    "INSERT IGNORE INTO accounting_destination_links
                       (tenant_id, sub_tenant_id, provider,
                        coreflux_object_type, coreflux_object_id,
                        provider_object_type, provider_object_id,
                        sync_status, idempotency_key)
                     VALUES (:t, :st, 'jaz',
                             'account', :coi,
                             'account', :poi,
                             'pending', :ik)"
                )->execute([
                    't'   => $tenantId,
                    'st'  => $subTenantId,
                    'coi' => $corefluxObjectId,
                    'poi' => (string) $providerId,
                    'ik'  => 'mapping-fallback:account:' . $corefluxObjectId,
                ]);
            } catch (\Throwable $_) { /* fast-path optimization only */ }
            return (string) $providerId;
        }
    }

    throw new AccountingAdapterValidationException(
        "{$corefluxObjectType} #{$corefluxObjectId} is not linked to Jaz — sync the {$corefluxObjectType} catalog to Jaz first"
    );
}

function _accCents($n): int
{
    if (is_int($n)) return $n;
    if (is_string($n) && str_contains($n, '.')) $n = (float) $n;
    if (is_numeric($n)) return (int) round(((float) $n) * 100);
    return 0;
}

/** Jaz wants amounts as decimal numbers. Inverse of _accCents. */
function _accAmount($cents): float
{
    return round(((int) $cents) / 100, 2);
}

/**
 * Map an AP bill row → Jaz POST /bills payload.
 *
 * Expected $row fields (best-effort, missing → throws Validation):
 *   id, vendor_id, internal_ref, bill_date, due_date, currency,
 *   total_amount, notes, lines[] = {description, quantity,
 *   unit_amount, line_total, account_id|gl_account_id}
 */
function mapBillToJaz(int $tenantId, int $subTenantId, array $row): array
{
    $vendorId = (int) ($row['vendor_id'] ?? $row['contact_id'] ?? 0);
    if ($vendorId <= 0) {
        throw new AccountingAdapterValidationException('bill missing vendor_id');
    }
    $contactRid = _accLookupJazResourceId($tenantId, $subTenantId, 'vendor', $vendorId);

    $lines = $row['lines'] ?? $row['lineItems'] ?? [];
    if (!is_array($lines) || empty($lines)) {
        throw new AccountingAdapterValidationException('bill has no line items');
    }
    $jazLines = [];
    foreach ($lines as $idx => $ln) {
        $acctId = (int) ($ln['account_id'] ?? $ln['gl_account_id'] ?? 0);
        if ($acctId <= 0) {
            throw new AccountingAdapterValidationException("bill line #{$idx} missing account_id");
        }
        $jazLines[] = [
            'description'       => (string) ($ln['description'] ?? $ln['memo'] ?? ''),
            'quantity'          => (float) ($ln['quantity'] ?? 1),
            'unitAmount'        => _accAmount(_accCents($ln['unit_amount'] ?? $ln['line_total'] ?? 0)),
            'accountResourceId' => _accLookupJazResourceId($tenantId, $subTenantId, 'account', $acctId),
            'taxRateResourceId' => isset($ln['tax_rate_id'])
                ? _accLookupJazResourceId($tenantId, $subTenantId, 'tax_rate', (int) $ln['tax_rate_id'])
                : null,
        ];
    }

    return [
        'reference'         => (string) ($row['internal_ref'] ?? ('CF-BILL-' . $row['id'])),
        'contactResourceId' => $contactRid,
        'billDate'          => (string) ($row['bill_date'] ?? date('Y-m-d')),
        'dueDate'           => (string) ($row['due_date']  ?? $row['bill_date'] ?? date('Y-m-d')),
        'currency'          => (string) ($row['currency']  ?? 'USD'),
        'notes'             => (string) ($row['notes']     ?? $row['memo'] ?? ''),
        'lineItems'         => $jazLines,
    ];
}

/**
 * Map an AR invoice row → Jaz POST /invoices payload.
 */
function mapInvoiceToJaz(int $tenantId, int $subTenantId, array $row): array
{
    $customerId = (int) ($row['customer_id'] ?? $row['contact_id'] ?? 0);
    if ($customerId <= 0) {
        throw new AccountingAdapterValidationException('invoice missing customer_id');
    }
    $contactRid = _accLookupJazResourceId($tenantId, $subTenantId, 'customer', $customerId);

    $lines = $row['lines'] ?? $row['lineItems'] ?? [];
    if (!is_array($lines) || empty($lines)) {
        throw new AccountingAdapterValidationException('invoice has no line items');
    }
    $jazLines = [];
    foreach ($lines as $idx => $ln) {
        $acctId = (int) ($ln['account_id'] ?? $ln['gl_account_id'] ?? $ln['revenue_account_id'] ?? 0);
        if ($acctId <= 0) {
            throw new AccountingAdapterValidationException("invoice line #{$idx} missing account_id");
        }
        $jazLines[] = [
            'description'       => (string) ($ln['description'] ?? $ln['memo'] ?? ''),
            'quantity'          => (float) ($ln['quantity'] ?? 1),
            'unitAmount'        => _accAmount(_accCents($ln['unit_amount'] ?? $ln['line_total'] ?? 0)),
            'accountResourceId' => _accLookupJazResourceId($tenantId, $subTenantId, 'account', $acctId),
            'taxRateResourceId' => isset($ln['tax_rate_id'])
                ? _accLookupJazResourceId($tenantId, $subTenantId, 'tax_rate', (int) $ln['tax_rate_id'])
                : null,
        ];
    }

    return [
        'reference'         => (string) ($row['invoice_number'] ?? ('CF-INV-' . $row['id'])),
        'contactResourceId' => $contactRid,
        'invoiceDate'       => (string) ($row['invoice_date'] ?? date('Y-m-d')),
        'dueDate'           => (string) ($row['due_date']     ?? $row['invoice_date'] ?? date('Y-m-d')),
        'currency'          => (string) ($row['currency']     ?? 'USD'),
        'notes'             => (string) ($row['notes']        ?? $row['memo'] ?? ''),
        'lineItems'         => $jazLines,
    ];
}

/**
 * Map a journal entry → Jaz POST /journals payload.
 */
function mapJournalToJaz(int $tenantId, int $subTenantId, array $row): array
{
    $lines = $row['lines'] ?? [];
    if (!is_array($lines) || count($lines) < 2) {
        throw new AccountingAdapterValidationException('journal entry needs ≥2 lines');
    }
    $jazLines = []; $totalDr = 0; $totalCr = 0;
    foreach ($lines as $idx => $ln) {
        $acctId = (int) ($ln['account_id'] ?? $ln['gl_account_id'] ?? 0);
        if ($acctId <= 0) {
            throw new AccountingAdapterValidationException("journal line #{$idx} missing account_id");
        }
        $debit  = _accCents($ln['debit']  ?? $ln['debit_amount']  ?? 0);
        $credit = _accCents($ln['credit'] ?? $ln['credit_amount'] ?? 0);
        $totalDr += $debit;
        $totalCr += $credit;
        $jazLines[] = [
            'accountResourceId' => _accLookupJazResourceId($tenantId, $subTenantId, 'account', $acctId),
            'description'       => (string) ($ln['description'] ?? $ln['memo'] ?? ''),
            'debit'             => _accAmount($debit),
            'credit'            => _accAmount($credit),
        ];
    }
    if ($totalDr !== $totalCr) {
        throw new AccountingAdapterValidationException(
            sprintf('journal entry unbalanced: debits=%.2f vs credits=%.2f',
                    $totalDr / 100, $totalCr / 100)
        );
    }
    return [
        'reference'   => (string) ($row['je_number']    ?? ('CF-JE-' . $row['id'])),
        'narration'   => (string) ($row['memo']         ?? $row['narration'] ?? ''),
        'postingDate' => (string) ($row['posting_date'] ?? date('Y-m-d')),
        'currency'    => (string) ($row['currency']     ?? 'USD'),
        'lines'       => $jazLines,
    ];
}

/**
 * Front door — dispatches by coreflux_object_type. Returns the Jaz
 * payload OR throws Validation. The Command Service calls this
 * during execute() right before the adapter HTTP call.
 */
function mapCorefluxRowToJaz(string $corefluxObjectType, int $tenantId, int $subTenantId, array $row): array
{
    switch ($corefluxObjectType) {
        case 'bill':    return mapBillToJaz($tenantId, $subTenantId, $row);
        case 'invoice': return mapInvoiceToJaz($tenantId, $subTenantId, $row);
        case 'journal': return mapJournalToJaz($tenantId, $subTenantId, $row);
        default:
            throw new AccountingAdapterValidationException("no Jaz mapper for object type '{$corefluxObjectType}'");
    }
}
