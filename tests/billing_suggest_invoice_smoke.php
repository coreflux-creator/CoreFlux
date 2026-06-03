<?php
/**
 * Smoke — AI "Suggest invoice" per placement (2026-02).
 *
 * Locks:
 *   - modules/billing/lib/billing.php → billingSuggestInvoiceForPlacement()
 *   - modules/billing/api/invoices.php → ?action=suggest-from-placement
 *   - modules/billing/ui/SuggestInvoiceModal.jsx
 *   - modules/placements/ui/PlacementTimesheetsTab.jsx (button wired)
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

echo "\n── Lib: billingSuggestInvoiceForPlacement ──\n";
$lib = file_get_contents('/app/modules/billing/lib/billing.php');
$a('billingSuggestInvoiceForPlacement() defined',
    str_contains($lib, 'function billingSuggestInvoiceForPlacement('));
$a('resolves placement scoped to tenant',
    preg_match('/billingSuggestInvoiceForPlacement[\s\S]{0,1600}WHERE tenant_id = :tenant_id AND id = :id/', $lib) === 1);
$a('looks up the placement\'s last invoice issue_date',
    str_contains($lib, 'MAX(i.issue_date) AS last_invoice_date'));
$a('filters entries to placement + approved + billable + hours>0',
    str_contains($lib, "te.status IN ('approved','locked','billing_ready','payroll_ready')")
    && str_contains($lib, "te.billable = 1")
    && str_contains($lib, "te.hours > 0"));
$a('cuts off entries after last invoice date',
    str_contains($lib, "'te.work_date > :cutoff'"));
$a('computes per-entry bill_rate via placementCurrentRate()',
    str_contains($lib, 'placementCurrentRate($placementId, (string) $e[\'work_date\'])'));
$a('applies OT/DT multipliers per hour_type',
    str_contains($lib, "'overtime'   => \$ot"));
$a('rule-based: short span (≤7d) → per_placement',
    str_contains($lib, '$aggregation = \'per_placement\';')
    && str_contains($lib, '$daySpan <= 7'));
$a('rule-based: long span + single worker → per_day',
    str_contains($lib, '$workerCount === 1 && $distinctDays > 7')
    && str_contains($lib, "\$aggregation = 'per_day';"));
$a('rule-based: multi-worker → per_placement (consolidated)',
    str_contains($lib, "$distinctDays working days, {$workerCount}"));   // reasoning string
$a('builds deterministic memo fallback before AI call',
    str_contains($lib, '$detMemo = sprintf('));
$a('calls aiAsk() with feature_class=suggestion',
    str_contains($lib, "'feature_class'     => 'suggestion'")
    && str_contains($lib, "'feature_key'       => 'billing.invoice.suggest_memo'"));
$a('AI errors silently fall back to deterministic memo',
    str_contains($lib, "} catch (\\Throwable \$_) {\n            \$aiUsed = false;\n        }"));
$a('returns suggestion shape (placement, period, candidate_entry_ids, suggested_aggregation, suggested_memo, ai_used)',
    str_contains($lib, "'placement' =>")
    && str_contains($lib, "'period' =>")
    && str_contains($lib, "'candidate_entry_ids'")
    && str_contains($lib, "'suggested_aggregation'")
    && str_contains($lib, "'suggested_memo'")
    && str_contains($lib, "'ai_used'"));

echo "\n── API: ?action=suggest-from-placement ──\n";
$api = file_get_contents('/app/modules/billing/api/invoices.php');
$a('suggest-from-placement action wired',
    str_contains($api, "'POST' && \$action === 'suggest-from-placement'"));
$a('requires billing.invoice.draft permission',
    preg_match("/suggest-from-placement[\s\S]{0,400}rbac_legacy_require\(\\\$user, 'billing\\.invoice\\.draft'\)/", $api) === 1);
$a('requires placement_id',
    preg_match("/suggest-from-placement[\s\S]{0,400}placement_id required/", $api) === 1);
$a('returns suggestion via api_ok',
    preg_match("/suggest-from-placement[\s\S]{0,400}api_ok\(\\\$sug\)/", $api) === 1);

echo "\n── React: SuggestInvoiceModal.jsx ──\n";
$mod = file_get_contents('/app/modules/billing/ui/SuggestInvoiceModal.jsx');
$a('posts to suggest-from-placement on mount',
    str_contains($mod, '/modules/billing/api/invoices.php?action=suggest-from-placement'));
$a('posts to from-time-entries on confirm',
    str_contains($mod, '/modules/billing/api/invoices.php?action=from-time-entries'));
$a('sends time_entry_ids + aggregation in confirm body',
    str_contains($mod, 'time_entry_ids: Array.from(selectedIds),'));
$a('renders AI badge only when ai_used',
    str_contains($mod, 'suggestion.ai_used && (')
    && str_contains($mod, 'data-testid="suggest-invoice-ai-badge"'));
$a('allows aggregation override',
    str_contains($mod, "['per_day', 'per_placement', 'per_client'].map"));
foreach ([
    'suggest-invoice-modal',
    'suggest-invoice-loading',
    'suggest-invoice-error',
    'suggest-invoice-summary',
    'suggest-invoice-reasoning',
    'suggest-invoice-memo',
    'suggest-invoice-cancel',
    'suggest-invoice-confirm',
    'suggest-invoice-selected-count',
    'suggest-invoice-no-entries',
    'suggest-invoice-entries',
] as $tid) {
    $a("testid '{$tid}' present", str_contains($mod, "data-testid=\"{$tid}\""));
}
foreach ([
    'suggest-invoice-entry-${e.id}',
    'suggest-invoice-entry-check-${e.id}',
    'suggest-invoice-agg-${opt}',
] as $template) {
    $a("template testid '{$template}' present",
        str_contains($mod, "data-testid={`{$template}`}"));
}

echo "\n── Wiring: PlacementTimesheetsTab.jsx ──\n";
$ptab = file_get_contents('/app/modules/placements/ui/PlacementTimesheetsTab.jsx');
$a('imports SuggestInvoiceModal',
    str_contains($ptab, "import SuggestInvoiceModal from '../../billing/ui/SuggestInvoiceModal'"));
$a('renders Suggest invoice button',
    str_contains($ptab, 'data-testid="placement-timesheets-suggest-invoice"'));
$a('mounts <SuggestInvoiceModal /> when toggled',
    str_contains($ptab, '<SuggestInvoiceModal'));
$a('passes placementId + placementTitle into the modal',
    str_contains($ptab, 'placementId={pid}')
    && str_contains($ptab, 'placementTitle={placement?.title}'));

$pdet = file_get_contents('/app/modules/placements/ui/PlacementDetail.jsx');
$a('PlacementDetail passes placement to the tab',
    str_contains($pdet, '<PlacementTimesheetsTab pid={placement.id} placement={placement} />'));

echo "\n=========================================\n";
echo "Suggest-invoice (AI) smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
