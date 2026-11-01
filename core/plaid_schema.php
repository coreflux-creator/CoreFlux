<?php
/** Plaid schema prerequisites; web requests must never apply migrations. */
declare(strict_types=1);

final class PlaidSchemaException extends RuntimeException {}

function plaidMissingColumns(PDO $pdo, string $table, array $columns): array
{
    $stmt = $pdo->prepare(
        'SELECT column_name FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = :table'
    );
    $stmt->execute(['table' => $table]);
    $present = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
    return array_values(array_filter($columns, static fn(string $column): bool => !isset($present[$column])));
}

function plaidRequireBalanceSchema(PDO $pdo): void
{
    if (plaidMissingColumns($pdo, 'plaid_accounts', [
        'current_balance_cents', 'available_balance_cents', 'limit_balance_cents',
        'iso_currency_code', 'balance_as_of',
    ])) {
        throw new PlaidSchemaException('Plaid balance schema is not installed; apply core migration 010');
    }
}

function plaidRequireLiabilityLinkSchema(PDO $pdo): void
{
    if (plaidMissingColumns($pdo, 'treasury_liability_accounts', ['plaid_account_id'])) {
        throw new PlaidSchemaException('Plaid liability schema is not installed; apply Treasury migration 002');
    }
}
