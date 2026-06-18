<?php
/**
 * Smoke — /api/admin/qbo/drift_badges.php + QboDriftBadge wiring into
 * BillsList + InvoicesList.
 *
 * Locks:
 *   - endpoint accepts type=bill|invoice, batches by id, returns
 *     keyed map, gracefully handles missing tables
 *   - selects most-severe drift row when multiple open ones exist
 *   - QboDriftBadge component + useQboDriftBadges hook live in
 *     dashboard/src/components/
 *   - BillsList and InvoicesList both import the helpers and render
 *     the chip next to the status cell
 *
 * Run: php -d zend.assertions=1 /app/tests/qbo_drift_badges_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nQBO drift badges smoke\n";
echo "======================\n\n";

// ────────────────────── 1. Endpoint shape ──
echo "── endpoint ──\n";
$ep = '/app/api/admin/qbo/drift_badges.php';
check('endpoint exists', file_exists($ep));
$src = (string) file_get_contents($ep);
check('calls api_require_auth',            str_contains($src, 'api_require_auth()'));
check("type allowlist enforces 'bill' | 'invoice'",
    str_contains($src, "['bill', 'invoice']"));
check('caps batch size at 500',            str_contains($src, 'array_slice($ids, 0, 500)'));
check('uses qbo_inbound_bills for bill type',     str_contains($src, "'bill' ? 'qbo_inbound_bills'"));
check('uses qbo_inbound_invoices for invoice type', str_contains($src, "'qbo_inbound_invoices'"));
check('joins to qbo_sync_drift for open drift rows',
    str_contains($src, "FROM qbo_sync_drift") && str_contains($src, "status = 'open'"));
check('selects most-severe drift when multiples exist',
    preg_match('/rank.*critical.*?warn.*?info/s', $src) === 1);
check('try/catch on shadow query (missing migration = soft fail)',
    substr_count($src, 'catch (\\Throwable $_)') >= 2);
check('response returns items map + generated_at',
    str_contains($src, "'items'") && str_contains($src, "'generated_at'"));

// ────────────────────── 2. React component + hook ──
echo "\n── components/QboDriftBadge.jsx ──\n";
$jsxPath = '/app/dashboard/src/components/QboDriftBadge.jsx';
check('component file exists', file_exists($jsxPath));
$jsx = (string) file_get_contents($jsxPath);
check('exports QboDriftBadge',     str_contains($jsx, 'export function QboDriftBadge'));
check('exports useQboDriftBadges', str_contains($jsx, 'export function useQboDriftBadges'));
check('hook calls /api/admin/qbo/drift_badges.php',
    str_contains($jsx, '/api/admin/qbo/drift_badges.php'));
check('returns null when entry is undefined / null',
    str_contains($jsx, 'if (!entry) return null'));
check('renders "QBO synced" subtle pill when no drift kind',
    str_contains($jsx, "'QBO synced'") || str_contains($jsx, 'QBO synced'));
foreach ([
    'paid_out_of_band' => 'Paid in QBO',
    'balance_changed'  => 'QBO partial',
    'voided_in_qbo'    => 'Voided in QBO',
] as $kind => $label) {
    check("badge label for kind '{$kind}' is '{$label}'",
        preg_match("/{$kind}:\\s*'{$label}'/", $jsx) === 1);
}
check('hook uses stable idsKey to avoid refetch storms',
    str_contains($jsx, 'idsKey'));
check('hook fails silently on API error (non-critical decoration)',
    preg_match('/catch.*?setMap\(\{\}\)/s', $jsx) === 1);

// ────────────────────── 3. BillsList wiring ──
echo "\n── BillsList wiring ──\n";
$bl = (string) file_get_contents('/app/modules/ap/ui/BillsList.jsx');
check('BillsList imports QboDriftBadge + useQboDriftBadges',
    str_contains($bl, 'QboDriftBadge') && str_contains($bl, 'useQboDriftBadges'));
check('BillsList batch-fetches drift for current rows',
    str_contains($bl, "useQboDriftBadges('bill'") );
check('BillsList renders <QboDriftBadge> next to status cell',
    str_contains($bl, '<QboDriftBadge entry={qboDrift[r.id]} />'));

// ────────────────────── 4. InvoicesList wiring ──
echo "\n── InvoicesList wiring ──\n";
$il = (string) file_get_contents('/app/modules/billing/ui/InvoicesList.jsx');
check('InvoicesList imports the badge helpers',
    str_contains($il, 'QboDriftBadge') && str_contains($il, 'useQboDriftBadges'));
check('InvoicesList batch-fetches drift',
    str_contains($il, "useQboDriftBadges('invoice'"));
check('InvoicesList renders <QboDriftBadge> next to status cell',
    str_contains($il, '<QboDriftBadge entry={qboDrift[r.id]} />'));

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "qbo_drift_badges smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
