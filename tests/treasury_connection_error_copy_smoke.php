<?php
declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/modules/treasury/ui/TreasuryOverview.jsx');

$checks = [
    'Plaid item-not-found errors have a user-facing explanation' =>
        str_contains($source, "detail.includes('ITEM_NOT_FOUND')")
        && str_contains($source, 'Connection no longer available. Reconnect or disconnect it.'),
    'connection identifiers are masked in the default view' =>
        str_contains($source, 'maskedConnectionId(r.item_id)')
        && str_contains($source, 'Connection …${String(itemId).slice(-6)}'),
    'raw provider errors are not printed directly in the status table' =>
        !str_contains($source, '<div className="muted" style={{ fontSize: 11 }}>{r.last_error_message}</div>'),
    'irrecoverable connections are grouped into one cleanup action' =>
        str_contains($source, 'const staleGroups = Object.values')
        && str_contains($source, 'treasury-plaid-health-remove-stale-')
        && str_contains($source, 'Remove ${group.items.length} stale'),
    'disconnected connections move into collapsed history' =>
        str_contains($source, "row.status !== 'disconnected'")
        && str_contains($source, 'treasury-connected-institutions-history')
        && str_contains($source, 'Connection history ({historyRows.length})'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
