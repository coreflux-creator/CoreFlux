<?php
/** Legal-entity input contracts for the shared accounting core. */
declare(strict_types=1);

require_once __DIR__ . '/../core/accounting/entity_setup.php';

$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$reject = static function (callable $action, string $message) use ($assert): void {
    try {
        $action();
    } catch (InvalidArgumentException $error) {
        $assert(str_contains($error->getMessage(), $message), $message);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
};
$valid = [
    'code' => ' main ', 'legal_name' => ' Test Company, Inc. ', 'country' => 'us',
    'base_currency' => 'usd', 'entity_type' => 'corporation',
    'accounting_basis' => 'accrual', 'fiscal_year_start_month' => 1,
];
$profile = accountingNewEntityProfile($valid);
$assert($profile['code'] === 'MAIN' && $profile['legal_name'] === 'Test Company, Inc.', 'normalized identity');
$assert($profile['country'] === 'US' && $profile['base_currency'] === 'USD', 'normalized locale');
$assert($profile['accounting_basis'] === 'accrual' && $profile['fiscal_year_start_month'] === 1, 'supported books');
$assert($profile['parent_entity_id'] === null, 'optional parent defaults to null');
$assert(accountingFirstFiscalYear('2026') === 2026, 'explicit first fiscal year');
$reject(static fn() => accountingFirstFiscalYear('26'), 'four-digit year');
$reject(static fn() => accountingFirstFiscalYear(2200), 'from 1900 to 2199');

$reject(static fn() => accountingNewEntityProfile(array_diff_key($valid, ['country' => true])), 'country is required');
$reject(static fn() => accountingNewEntityProfile($valid + ['tenant_id' => 5]), 'Unsupported entity field');
$reject(static fn() => accountingNewEntityProfile(array_replace($valid, ['code' => 'bad code'])), 'Entity code');
$reject(static fn() => accountingNewEntityProfile(array_replace($valid, ['legal_name' => ' '])), 'Legal name');
$reject(static fn() => accountingNewEntityProfile(array_replace($valid, ['country' => 'USA'])), 'Country');
$reject(static fn() => accountingNewEntityProfile(array_replace($valid, ['base_currency' => 'US'])), 'Base currency');
$reject(static fn() => accountingNewEntityProfile(array_replace($valid, ['country' => 'CA'])), 'Only US/USD');
$reject(static fn() => accountingNewEntityProfile(array_replace($valid, ['base_currency' => 'CAD'])), 'Only US/USD');
$reject(static fn() => accountingNewEntityProfile(array_replace($valid, ['entity_type' => 'unknown'])), 'entity type');
$reject(static fn() => accountingNewEntityProfile(array_replace($valid, ['accounting_basis' => 'cash'])), 'Only accrual');
$reject(static fn() => accountingNewEntityProfile(array_replace($valid, ['fiscal_year_start_month' => 4])), 'Only a January');
$reject(static fn() => accountingNewEntityProfile(array_replace($valid, ['parent_entity_id' => '1 OR 1=1'])), 'positive integer');
$reject(static fn() => accountingNewEntityProfile(array_replace($valid, ['legal_name' => ['bad']])), 'must be text');

$current = $profile;
$assert(accountingReviewEntityProfileUpdate($current, ['legal_name' => 'Correct Name']) === ['legal_name' => 'Correct Name'], 'legal-name correction');
$assert(accountingReviewEntityProfileUpdate($current, ['entity_type' => 'llc']) === ['entity_type' => 'llc'], 'legal-type correction');
$assert(accountingReviewEntityProfileUpdate($current, ['legal_name' => $current['legal_name']]) === [], 'unchanged request is idempotent');
$reject(static fn() => accountingReviewEntityProfileUpdate($current, ['base_currency' => 'EUR']), 'fixed after creation');
$reject(static fn() => accountingReviewEntityProfileUpdate($current, ['fiscal_year_start_month' => 4]), 'fixed after creation');
$reject(static fn() => accountingReviewEntityProfileUpdate($current, ['active' => 0]), 'fixed after creation');
$reject(static fn() => accountingReviewEntityProfileUpdate($current, ['legal_name' => '']), 'Legal name');
$reject(static fn() => accountingReviewEntityProfileUpdate($current, []), 'Choose a field');

echo "Accounting entity profile: {$checks} checks passed.\n";
