<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$ui = (string) file_get_contents($root . '/modules/accounting/ui/JournalEntries.jsx');
$detail = (string) file_get_contents($root . '/modules/accounting/ui/JournalEntryDetail.jsx');
$editor = (string) file_get_contents($root . '/modules/accounting/ui/JournalEntryCreate.jsx');
$api = (string) file_get_contents($root . '/modules/accounting/api/journal_entries.php');
$module = (string) file_get_contents($root . '/modules/accounting/ui/AccountingModule.jsx');
$manifest = (string) file_get_contents($root . '/modules/accounting/manifest.php');
$accounting = (string) file_get_contents($root . '/modules/accounting/lib/accounting.php');
$standardReports = (string) file_get_contents($root . '/modules/accounting/api/standard_reports.php');
$export = (string) file_get_contents($root . '/modules/accounting/api/export.php');

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
        str_contains($detail, 'aria-label="Reason for reversal"')
        && str_contains($detail, 'data-testid="accounting-journal-reverse-reason"'),
    'draft lifecycle exposes edit post and delete actions' =>
        str_contains($detail, 'data-testid="accounting-je-edit-draft"')
        && str_contains($detail, 'data-testid="accounting-je-post-draft"')
        && str_contains($detail, 'data-testid="accounting-je-delete-draft"'),
    'draft editor updates in place and can post after saving' =>
        str_contains($editor, 'api.patch(`/modules/accounting/api/journal_entries.php?id=${id}`')
        && str_contains($editor, 'action=post_draft&id=${id}'),
    'draft editing and copying preserve accounting context' =>
        str_contains($editor, 'counterparty_entity_id: line.counterparty_entity_id || null')
        && str_contains($editor, 'dims: parseDims(line.dim_json)')
        && str_contains($editor, "{ entity_id: sourceEntry.entity_id }"),
    'draft mutation endpoints are permission gated' =>
        str_contains($api, "'accounting.je.edit_draft'")
        && str_contains($api, "'accounting.je.void'")
        && str_contains($api, "'accounting.je.post'"),
    'manual draft actions cannot bypass AI approval workflows' =>
        str_contains($accounting, 'function accountingDraftRequiresApproval')
        && str_contains($accounting, 'accountingAssertManualDraftLifecycle($existing)')
        && substr_count($accounting, 'accountingAssertManualDraftLifecycle($row)') >= 2
        && str_contains($detail, 'data-testid="accounting-je-approval-workflow-note"')
        && str_contains($ui, 'isManualDraft(r)')
        && str_contains($editor, 'System-generated drafts must be reviewed in AI Agents.'),
    'posted corrections stay reversal based' =>
        str_contains($detail, 'Copy as draft')
        && str_contains($detail, 'Reverse entry')
        && str_contains($detail, 'Posted entries cannot be deleted'),
    'draft changes are auditable and concurrent deletion is guarded' =>
        str_contains($manifest, "'accounting.je.draft_updated'")
        && str_contains($accounting, "if (\$delete->rowCount() !== 1)"),
    'unposted work queues contain drafts rather than reversals or deleted drafts' =>
        str_contains($standardReports, "status = 'draft'")
        && substr_count($export, "'forced_options' => ['status' => 'draft']") >= 2,
    'accounting navigation exposes workflow order and close' =>
        str_contains($module, "{ to: 'bookkeeping', label: 'Overview'")
        && str_contains($module, "{ to: 'close', label: 'Close'")
        && str_contains($module, 'Tools <ChevronDown'),
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
