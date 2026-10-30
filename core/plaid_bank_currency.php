<?php
/** Currency guard shared by Plaid Link and orphan recovery. */
declare(strict_types=1);

function plaidBankMirrorCurrency(array $account, array $entity): string
{
    $balances = is_array($account['balances'] ?? null) ? $account['balances'] : [];
    $raw = $balances['iso_currency_code'] ?? $account['iso_currency_code'] ?? null;
    $currency = strtoupper(trim((string) $raw));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new RuntimeException('Connected bank account has no supported ISO currency');
    }

    $entityCurrency = strtoupper(trim((string) ($entity['base_currency'] ?? '')));
    if ($currency !== $entityCurrency) {
        throw new RuntimeException(
            "Connected bank currency {$currency} does not match the legal entity currency {$entityCurrency}"
        );
    }
    return $currency;
}
