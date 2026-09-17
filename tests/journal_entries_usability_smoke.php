<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$ui = (string) file_get_contents($root . '/modules/accounting/ui/JournalEntries.jsx');
$module = (string) file_get_contents($root . '/modules/accounting/ui/AccountingModule.jsx');

$checks = [
    'journal navigation uses bookmarkable canonical routes' =>
        str_contains($module, "{ to: 'journal-entries', label: 'Journal entries'")
        && str_contains($ui, 'navigate(`/modules/accounting/journal-entries/${id}`)')
        && str_contains($ui, "navigate('/modules/accounting/journal-entries/new')"),
    'legacy journal route redirects to the canonical list' =>
        str_contains($module, '<Route path="journal"  element={<Navigate to="../journal-entries" replace />} />'),
    'journal list exposes account date and status filters' =>
        str_contains($ui, 'data-testid="accounting-journal-filters"')
        && str_contains($ui, 'aria-label="Journal entries from date"')
        && str_contains($ui, 'aria-label="Journal entry status"'),
    'journal list uses API totals and exposes pagination' =>
        str_contains($ui, 'const total = Number(data?.total ?? rows.length)')
        && str_contains($ui, 'data-testid="accounting-journal-pagination"')
        && str_contains($ui, "qs.set('per_page', String(perPage))"),
    'journal rows support keyboard opening' =>
        str_contains($ui, "event.key === 'Enter' || event.key === ' '")
        && str_contains($ui, 'aria-label={`Open ${r.je_number}`}'),
    'reversal reason has an accessible required label' =>
        str_contains($ui, 'aria-label="Reason for reversal"')
        && str_contains($ui, 'data-testid="accounting-journal-reverse-reason"'),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    if ($passed) {
        echo "PASS: {$label}\n";
    } else {
        fwrite(STDERR, "FAIL: {$label}\n");
        $failed++;
    }
}

exit($failed === 0 ? 0 : 1);
