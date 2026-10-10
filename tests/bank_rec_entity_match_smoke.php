<?php
/** Bank reconciliation must never suggest or accept cross-entity journals. */
declare(strict_types=1);

$queries = [];
function scopedQuery(string $sql, array $params = []): array
{
    $GLOBALS['queries'][] = ['sql' => $sql, 'params' => $params];
    return [];
}

require_once __DIR__ . '/../modules/accounting/lib/bank_rec.php';

$passed = 0;
$check = static function (bool $condition, string $label) use (&$passed): void {
    if (!$condition) throw new RuntimeException($label);
    $passed++;
};

$check(bankRecAutoSuggestMatches(7, ['amount' => 0, 'posted_date' => '2026-10-10'], 12) === []
    && count($queries) === 0, 'zero amount has no candidate query');

bankRecAutoSuggestMatches(7, ['amount' => 25, 'posted_date' => '2026-10-10'], 12);
$deposit = $queries[0] ?? [];
$check(str_contains((string) ($deposit['sql'] ?? ''), 'je.entity_id = ba.entity_id')
    && str_contains((string) ($deposit['sql'] ?? ''), 'ba.entity_id IS NOT NULL'),
    'suggestions require the bank and journal to have the same known legal entity');
$check(str_contains((string) $deposit['sql'], 'l.debit = :abs_amt AND l.credit = 0')
    && ($deposit['params']['bank_account_id'] ?? null) === 12,
    'deposit suggestions retain the bank and debit restrictions');

bankRecAutoSuggestMatches(7, ['amount' => -25, 'posted_date' => '2026-10-10'], 12);
$withdrawal = $queries[1] ?? [];
$check(str_contains((string) ($withdrawal['sql'] ?? ''), 'je.entity_id = ba.entity_id')
    && str_contains((string) ($withdrawal['sql'] ?? ''), 'l.credit = :abs_amt AND l.debit = 0'),
    'withdrawal suggestions retain entity and credit restrictions');

$source = (string) file_get_contents(__DIR__ . '/../modules/accounting/lib/bank_rec.php');
$matchStart = strpos($source, 'function bankRecMatchLine(');
$matchEnd = strpos($source, 'function bankRecUnmatchLine(', $matchStart ?: 0);
$match = $matchStart === false ? '' : substr($source, $matchStart,
    $matchEnd === false ? null : $matchEnd - $matchStart);
$check(str_contains($match, "empty(\$line['bank_entity_id']) || empty(\$je['entity_id'])")
    && str_contains($match, "(int) \$line['bank_entity_id'] !== (int) \$je['entity_id']"),
    'final match refuses unknown or different legal entities');

echo "bank rec entity match smoke: {$passed} passed\n";
