<?php
declare(strict_types=1);

const CORE_ACCOUNTING_CSV_ENTITIES = [
    'ap_vendors', 'accounting_accounts', 'billing_items',
    'ap_bills', 'billing_invoices', 'ap_payments', 'billing_payments',
];

function coreAccountingCsvEntities(): array
{
    return CORE_ACCOUNTING_CSV_ENTITIES;
}

function coreAccountingCsvEntityAllowed(string $entity): bool
{
    return getenv('COREFLUX_ENV') !== 'coreaccounting'
        || in_array($entity, CORE_ACCOUNTING_CSV_ENTITIES, true);
}
