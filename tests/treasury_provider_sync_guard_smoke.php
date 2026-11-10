<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/treasury/provider_statement_sync.php';

$failures = 0;
$assert = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures++;
};

$route = (string) file_get_contents(__DIR__ . '/../api/plaid_sync_transactions.php');
$assert('Plaid sync routes existing rows through protected update guard',
    str_contains($route, 'treasuryProviderSyncExistingLine('));
$assert('deposit and liability upserts also guard matched rows during races',
    substr_count($route, 'IF(match_status = "unmatched", VALUES(amount), amount)') === 2);
$assert('sync results surface protected changes for review',
    str_contains($route, "'review_required' => 0") && str_contains($route, '_plaidNoteProtectedChange(')
    && str_contains($route, 'treasuryProviderIgnoreRemovedLine('));

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo '[SKIP] sqlite driver unavailable' . PHP_EOL;
    exit($failures > 0 ? 1 : 0);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE accounting_bank_statement_lines (
    id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, bank_account_id INTEGER NOT NULL,
    fitid TEXT, match_status TEXT, posted_date TEXT, description TEXT, amount NUMERIC, bank_reference TEXT
)');
$pdo->exec('CREATE TABLE treasury_liability_statement_lines (
    id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, liability_account_id INTEGER NOT NULL,
    fitid TEXT, match_status TEXT, posted_date TEXT, description TEXT, amount NUMERIC,
    merchant_name TEXT, category TEXT, bank_reference TEXT
)');
$pdo->exec("INSERT INTO accounting_bank_statement_lines VALUES
    (1, 9, 7, 'bank-a', 'unmatched', '2026-10-01', 'Receipt', 10, NULL),
    (2, 9, 7, 'bank-b', 'matched', '2026-10-01', 'Receipt', 10, NULL),
    (3, 9, 7, 'bank-c', 'ignored', '2026-10-01', 'Receipt', 10, NULL),
    (4, 10, 7, 'bank-b', 'unmatched', '2026-10-01', 'Other tenant', 10, NULL)");
$pdo->exec("INSERT INTO treasury_liability_statement_lines VALUES
    (5, 9, 11, 'card-a', 'unmatched', '2026-10-01', 'Card purchase', -10, 'Shop', 'OTHER', NULL),
    (6, 9, 11, 'card-b', 'matched', '2026-10-01', 'Card purchase', -10, 'Shop', 'OTHER', NULL)");

$bankFacts = [
    'posted_date' => '2026-10-02', 'description' => 'Receipt corrected',
    'amount' => 12, 'bank_reference' => 'R-2',
];
$updated = treasuryProviderSyncExistingLine($pdo, 9, 'deposit', 7, 1, 'bank-a', $bankFacts);
$assert('unmatched bank line accepts provider correction',
    $updated['found'] && $updated['updated'] && !$updated['review_required']
    && (float) $pdo->query('SELECT amount FROM accounting_bank_statement_lines WHERE id = 1')->fetchColumn() === 12.0);
$replayed = treasuryProviderSyncExistingLine($pdo, 9, 'deposit', 7, null, 'bank-a', $bankFacts);
$assert('identical replay does not create a review item',
    $replayed['found'] && !$replayed['updated'] && !$replayed['review_required']);

$protected = treasuryProviderSyncExistingLine($pdo, 9, 'deposit', 7, 2, 'bank-b', $bankFacts);
$ignored = treasuryProviderSyncExistingLine($pdo, 9, 'deposit', 7, 3, 'bank-c', $bankFacts);
$assert('matched and ignored bank lines remain unchanged for review',
    $protected['review_required'] && $ignored['review_required']
    && (int) $pdo->query('SELECT SUM(amount) FROM accounting_bank_statement_lines WHERE id IN (2,3)')->fetchColumn() === 20);
$removedOpen = treasuryProviderIgnoreRemovedLine($pdo, 9, 'deposit', 7, 1, 'bank-a');
$removedMatched = treasuryProviderIgnoreRemovedLine($pdo, 9, 'deposit', 7, 2, 'bank-b');
$assert('provider removal ignores only an unmatched bank line',
    $removedOpen['ignored'] && $removedMatched['review_required']
    && (string) $pdo->query('SELECT match_status FROM accounting_bank_statement_lines WHERE id = 1')->fetchColumn() === 'ignored'
    && (string) $pdo->query('SELECT match_status FROM accounting_bank_statement_lines WHERE id = 2')->fetchColumn() === 'matched');
$assert('provider identity lookup is tenant scoped',
    !treasuryProviderSyncExistingLine($pdo, 9, 'deposit', 7, null, 'missing', $bankFacts)['found']
    && (string) $pdo->query('SELECT description FROM accounting_bank_statement_lines WHERE id = 4')->fetchColumn() === 'Other tenant');

$cardFacts = [
    'posted_date' => '2026-10-02', 'description' => 'Card purchase corrected',
    'amount' => -12, 'merchant_name' => 'Shop', 'category' => 'SOFTWARE', 'bank_reference' => 'C-2',
];
$cardUpdated = treasuryProviderSyncExistingLine($pdo, 9, 'liability', 11, null, 'card-a', $cardFacts);
$cardProtected = treasuryProviderSyncExistingLine($pdo, 9, 'liability', 11, null, 'card-b', $cardFacts);
$assert('unmatched liability updates, matched liability remains intact',
    $cardUpdated['updated'] && $cardProtected['review_required']
    && (float) $pdo->query('SELECT amount FROM treasury_liability_statement_lines WHERE id = 6')->fetchColumn() === -10.0);
$removedCard = treasuryProviderIgnoreRemovedLine($pdo, 9, 'liability', 11, null, 'card-b');
$assert('provider removal preserves matched liability',
    $removedCard['review_required']
    && (string) $pdo->query('SELECT match_status FROM treasury_liability_statement_lines WHERE id = 6')->fetchColumn() === 'matched');

exit($failures > 0 ? 1 : 0);
