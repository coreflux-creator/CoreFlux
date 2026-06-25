<?php
/**
 * Smoke — Batch 4 (2026-02): Flexible Invoicing & Payables.
 *
 * Locks the new "from time entries" (day-level) flow that lives
 * alongside the existing bundle-driven flow:
 *   - modules/billing/lib/billing.php → billingBuildDraftFromTimeEntries
 *   - modules/ap/lib/ap.php → apBuildDraftFromTimeEntries
 *   - modules/billing/api/invoices.php → ?action=from-time-entries
 *   - modules/ap/api/bills.php → ?action=from-time-entries
 *   - modules/staffing/api/timesheets.php → ?action=approved_entries
 *   - modules/billing/ui/InvoiceFromTimeEntriesModal.jsx (picker)
 *   - modules/ap/ui/BillFromTimeEntriesModal.jsx (picker)
 *   - modules/billing/ui/InvoicesList.jsx (mount + CTA)
 *   - modules/ap/ui/BillsList.jsx (mount + CTA)
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// ──────────────────────────────────────────────────────────────────────
// 1) Lib helpers
// ──────────────────────────────────────────────────────────────────────
echo "\n── Lib helpers ──\n";
$billLib = file_get_contents($ROOT . '/modules/billing/lib/billing.php');
$apLib   = file_get_contents($ROOT . '/modules/ap/lib/ap.php');

$a('billingBuildDraftFromTimeEntries() defined',
    str_contains($billLib, 'function billingBuildDraftFromTimeEntries('));
$a('billing helper validates aggregation enum',
    str_contains($billLib, "in_array(\$aggregation, ['per_day', 'per_placement', 'per_client']"));
$a('billing helper enforces max 500 entries',
    str_contains($billLib, 'Too many time_entry_ids (max 500 per call)'));
$a('billing helper rejects non-approved entries',
    str_contains($billLib, 'only approved entries can be invoiced'));
$a('billing helper resolves rate via placementCurrentRate()',
    str_contains($billLib, 'placementCurrentRate((int) $e[\'placement_id\']'));
$a('billing helper applies OT/DT multipliers per hour_type',
    str_contains($billLib, "'overtime'   => \$ot")
    && str_contains($billLib, "'doubletime' => \$dt"));
$a('billing helper uses source_type="time_entry" on lines',
    str_contains($billLib, "'source_type'      => 'time_entry'"));
$a('billing helper returns invoice + lines + entry_ids',
    str_contains($billLib, "'entry_ids'    => array_merge")
    && str_contains($billLib, "'bundle_ids'   => [],"));

$a('apBuildDraftFromTimeEntries() defined',
    str_contains($apLib, 'function apBuildDraftFromTimeEntries('));
$a('AP helper validates aggregation enum',
    str_contains($apLib, "in_array(\$aggregation, ['per_day', 'per_placement', 'per_vendor']"));
$a('AP helper validates entry status',
    str_contains($apLib, 'only approved entries can be paid'));
$a('AP helper recognises c2c corp vs 1099 individual',
    str_contains($apLib, "'c2c_corp'") && str_contains($apLib, "'1099_individual'"));
$a('AP helper joins placement_corp_details for corp name',
    str_contains($apLib, 'LEFT JOIN placement_corp_details pcd ON pcd.placement_id = p.id'));
$a('AP helper applies pay_rate * multiplier',
    str_contains($apLib, "'pay_rate'        => (float) \$r['_pay_rate']"));
$a('AP helper marks bill source as time_entries',
    str_contains($apLib, "'source'         => 'time_entries'"));

// ──────────────────────────────────────────────────────────────────────
// 2) API actions
// ──────────────────────────────────────────────────────────────────────
echo "\n── API actions ──\n";
$invApi  = file_get_contents($ROOT . '/modules/billing/api/invoices.php');
$billApi = file_get_contents($ROOT . '/modules/ap/api/bills.php');
$tsApi   = file_get_contents($ROOT . '/modules/staffing/api/timesheets.php');

$a('billing/invoices ?action=from-time-entries wired',
    str_contains($invApi, "'POST' && \$action === 'from-time-entries'"));
$a('billing from-time-entries requires billing.invoice.draft permission',
    preg_match("/from-time-entries[\s\S]{0,400}rbac_legacy_require\(\\\$user, 'billing\\.invoice\\.draft'\)/", $invApi) === 1);
$a('billing from-time-entries requires time_entry_ids',
    preg_match("/from-time-entries[\s\S]{0,400}\\['time_entry_ids'\\]/", $invApi) === 1);
$a('billing from-time-entries audits with source=time_entries',
    str_contains($invApi, "'source'          => 'time_entries'"));

$a('ap/bills ?action=from-time-entries wired',
    str_contains($billApi, "'POST' && \$action === 'from-time-entries'"));
$a('ap from-time-entries requires ap.bill.create permission',
    preg_match("/from-time-entries[\s\S]{0,400}rbac_legacy_require\(\\\$user, 'ap\\.bill\\.create'\)/", $billApi) === 1);
$a('ap from-time-entries upserts ap_vendors_index',
    preg_match("/from-time-entries[\s\S]{0,3500}INSERT INTO ap_vendors_index/", $billApi) === 1);

$a('staffing approved_entries action wired',
    str_contains($tsApi, "'GET' && \$action === 'approved_entries'"));
$a('approved_entries gates on status IN (approved/locked/payroll_ready/billing_ready)',
    str_contains($tsApi, "te.status IN ('approved','locked','payroll_ready','billing_ready')"));
$a('approved_entries supports billable vs payable purpose',
    str_contains($tsApi, "purpose === 'payable'")
    && str_contains($tsApi, "purpose === 'billable'"));
$a('approved_entries optional placement_id, person_id, date range filters',
    str_contains($tsApi, "'te.placement_id = :plid'")
    && str_contains($tsApi, "'te.person_id = :pid'")
    && str_contains($tsApi, "'te.work_date >= :df'")
    && str_contains($tsApi, "'te.work_date <= :dt'"));

// ──────────────────────────────────────────────────────────────────────
// 3) React modals
// ──────────────────────────────────────────────────────────────────────
echo "\n── React: InvoiceFromTimeEntriesModal.jsx ──\n";
$invMod = file_get_contents($ROOT . '/modules/billing/ui/InvoiceFromTimeEntriesModal.jsx');
$a('hits staffing approved_entries endpoint',
    str_contains($invMod, "action: 'approved_entries'")
    && str_contains($invMod, "purpose: 'billable'"));
$a('posts to billing from-time-entries endpoint',
    str_contains($invMod, '/api/v1/billing/invoices?action=from-time-entries'));
$a('offers all three aggregation modes',
    str_contains($invMod, "value=\"per_day\"")
    && str_contains($invMod, "value=\"per_placement\"")
    && str_contains($invMod, "value=\"per_client\""));
foreach ([
    'billing-from-entries-modal',
    'billing-from-entries-placement',
    'billing-from-entries-date-from',
    'billing-from-entries-date-to',
    'billing-from-entries-agg-day',
    'billing-from-entries-agg-placement',
    'billing-from-entries-agg-client',
    'billing-from-entries-table',
    'billing-from-entries-select-all',
    'billing-from-entries-cancel',
    'billing-from-entries-confirm',
    'billing-from-entries-selected-count',
] as $tid) {
    $a("testid '{$tid}' present", str_contains($invMod, "data-testid=\"{$tid}\""));
}
foreach ([
    'billing-from-entries-row-${e.id}',
    'billing-from-entries-check-${e.id}',
] as $template) {
    $a("template testid '{$template}' present",
        str_contains($invMod, "data-testid={`{$template}`}"));
}

echo "\n── React: BillFromTimeEntriesModal.jsx ──\n";
$apMod = file_get_contents($ROOT . '/modules/ap/ui/BillFromTimeEntriesModal.jsx');
$a('payable purpose set',
    str_contains($apMod, "purpose: 'payable'"));
$a('posts to ap from-time-entries endpoint',
    str_contains($apMod, '/modules/ap/api/bills.php?action=from-time-entries'));
$a('offers per_day/per_placement/per_vendor modes',
    str_contains($apMod, "value=\"per_day\"")
    && str_contains($apMod, "value=\"per_placement\"")
    && str_contains($apMod, "value=\"per_vendor\""));
foreach ([
    'ap-from-entries-modal',
    'ap-from-entries-agg-day',
    'ap-from-entries-agg-placement',
    'ap-from-entries-agg-vendor',
    'ap-from-entries-confirm',
    'ap-from-entries-cancel',
    'ap-from-entries-table',
] as $tid) {
    $a("testid '{$tid}' present", str_contains($apMod, "data-testid=\"{$tid}\""));
}

// ──────────────────────────────────────────────────────────────────────
// 4) List wiring
// ──────────────────────────────────────────────────────────────────────
echo "\n── List wiring ──\n";
$invList = file_get_contents($ROOT . '/modules/billing/ui/InvoicesList.jsx');
$apList  = file_get_contents($ROOT . '/modules/ap/ui/BillsList.jsx');
$a('InvoicesList imports InvoiceFromTimeEntriesModal',
    str_contains($invList, "import InvoiceFromTimeEntriesModal from './InvoiceFromTimeEntriesModal'"));
$a('InvoicesList has the new CTA button',
    str_contains($invList, 'data-testid="billing-new-from-time-entries"'));
$a('InvoicesList renders <InvoiceFromTimeEntriesModal /> when toggled',
    str_contains($invList, '<InvoiceFromTimeEntriesModal'));

$a('BillsList imports BillFromTimeEntriesModal',
    str_contains($apList, "import BillFromTimeEntriesModal from './BillFromTimeEntriesModal'"));
$a('BillsList has the new CTA button',
    str_contains($apList, 'data-testid="ap-bills-new-from-time-entries"'));
$a('BillsList renders <BillFromTimeEntriesModal /> when toggled',
    str_contains($apList, '<BillFromTimeEntriesModal'));

echo "\n=========================================\n";
echo "Flexible Invoicing & Payables Batch 4 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
