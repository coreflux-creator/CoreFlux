<?php
/**
 * Smoke — AP "Suggest payment run" (2026-02).
 *
 * Locks:
 *   - modules/ap/lib/ap.php → apSuggestPaymentRun() + apExecutePaymentRun()
 *   - modules/ap/api/bills.php → ?action=suggest-payment-run + execute-payment-run
 *   - modules/ap/ui/SuggestPaymentRunModal.jsx
 *   - modules/ap/ui/BillsList.jsx (CTA + mount)
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

echo "\n── Lib: apSuggestPaymentRun ──\n";
$lib = file_get_contents('/app/modules/ap/lib/ap.php');
$a('apSuggestPaymentRun() defined',
    str_contains($lib, 'function apSuggestPaymentRun(int $tenantId, int $daysAhead = 7, ?string $rail = null'));
$a('horizon clamped to [1, 60]',
    str_contains($lib, '$horizon = max(1, min(60, $daysAhead));'));
$a('only considers status IN (approved, partially_paid)',
    str_contains($lib, "status IN ('approved','partially_paid')"));
$a('only amount_due > 0',
    str_contains($lib, 'AND amount_due > 0'));
$a('filters by due_date <= cutoff',
    str_contains($lib, 'AND due_date <= :cutoff'));
$a('groups by vendor_name',
    str_contains($lib, '$groups[$v] = ['));
$a('PWP-blocked bills surfaced separately (not paid)',
    str_contains($lib, "(\$b['pwp_status'] ?? '') === 'awaiting_ar'")
    && str_contains($lib, '$blocked[] = $b;'));
$a('Mercury rail flags vendors with no mercury_recipients row',
    preg_match('/rail === \'mercury\'[\s\S]{0,1200}mercury_recipients[\s\S]{0,600}No Mercury recipient on file/', $lib) === 1);
$a('falls back to tenant disbursement_rail when no rail supplied',
    str_contains($lib, "(string) (\$set['disbursement_rail'] ?? '')) ?: 'mercury'"));
$a('returns rail_configured flag',
    str_contains($lib, "'rail_configured'   => \$railConfigured"));
$a('returns totals.{vendor_count, bill_count, total_due, rail_eligible_total, needs_review_total, pwp_blocked_count}',
    str_contains($lib, "'vendor_count'        => \$vendorCount")
    && str_contains($lib, "'rail_eligible_total' =>")
    && str_contains($lib, "'needs_review_total'  =>")
    && str_contains($lib, "'pwp_blocked_count'   =>"));
$a('builds deterministic AI fallback summary',
    str_contains($lib, '$detSummary = sprintf('));
$a('AI call goes through aiAsk(feature_class=suggestion, feature_key=ap.payment_run.suggest_summary)',
    str_contains($lib, "'feature_class'     => 'suggestion'")
    && str_contains($lib, "'feature_key'       => 'ap.payment_run.suggest_summary'"));
$a('AI errors silently fall back',
    str_contains($lib, '$aiUsed    = false;'));

echo "\n── Lib: apExecutePaymentRun ──\n";
$a('apExecutePaymentRun() defined',
    str_contains($lib, 'function apExecutePaymentRun(int $tenantId, string $rail, array $groups'));
$a('rejects empty groups',
    str_contains($lib, "throw new \\InvalidArgumentException('No vendor groups supplied')"));
$a('validates rail via paymentRailsGetDriver()',
    str_contains($lib, "paymentRailsGetDriver(\$rail);"));
$a('re-fetches each bill to confirm payable status (no stale data)',
    str_contains($lib, "SELECT id, amount_due, status, vendor_name, pwp_status, currency, payment_method"));
$a('skips bills whose vendor mismatches',
    str_contains($lib, "if (\$b['vendor_name'] !== \$vendorName) continue;"));
$a('skips PWP-blocked rows during execute',
    str_contains($lib, "if ((\$b['pwp_status'] ?? '') === 'awaiting_ar') continue;"));
$a('creates ap_payments in DRAFT status (operator still has to send)',
    str_contains($lib, "'status'             => 'draft'"));
$a('stamps disbursement_rail',
    str_contains($lib, "'disbursement_rail'  => \$rail"));
$a('voids the draft + audits if allocation fails (no orphan)',
    str_contains($lib, "SET status = \"void\"")
    && str_contains($lib, "ap.payment.run_allocation_failed"));
$a('audits each created payment with source=suggest_payment_run',
    str_contains($lib, "'source'       => 'suggest_payment_run'"));

echo "\n── API actions ──\n";
$api = file_get_contents('/app/modules/ap/api/bills.php');
$a('suggest-payment-run action wired',
    str_contains($api, "'POST' && \$action === 'suggest-payment-run'"));
$a('execute-payment-run action wired',
    str_contains($api, "'POST' && \$action === 'execute-payment-run'"));
$a('both actions require ap.payment.create',
    substr_count($api, "rbac_legacy_require(\$user, 'ap.payment.create');") >= 2);
$a('execute-payment-run rejects empty rail',
    str_contains($api, 'rail required'));
$a('execute-payment-run rejects empty vendor_groups',
    str_contains($api, 'vendor_groups required'));

echo "\n── React: SuggestPaymentRunModal.jsx ──\n";
$mod = file_get_contents('/app/modules/ap/ui/SuggestPaymentRunModal.jsx');
$a('posts suggest-payment-run on mount + filter change',
    str_contains($mod, '/modules/ap/api/bills.php?action=suggest-payment-run'));
$a('posts execute-payment-run on confirm',
    str_contains($mod, '/modules/ap/api/bills.php?action=execute-payment-run'));
$a('default-selects only rail_eligible vendor groups',
    str_contains($mod, 'g => g.rail_eligible'));
$a('disables checkbox for non-rail-eligible vendors',
    str_contains($mod, 'disabled={!g.rail_eligible}'));
$a('renders AI badge only when ai_used',
    str_contains($mod, 'suggestion.ai_used &&')
    && str_contains($mod, 'data-testid="suggest-payment-run-ai-badge"'));
$a('warns when rail not configured for tenant',
    str_contains($mod, 'data-testid="suggest-payment-run-rail-warning"')
    && str_contains($mod, 'Rail not configured for this tenant'));
$a('shows PWP-blocked notice when count > 0',
    str_contains($mod, 'data-testid="suggest-payment-run-pwp-notice"')
    && str_contains($mod, 'are blocked by the Pay-When-Paid gate'));
$a('rail selector exposes mercury, plaid_transfer, nacha',
    str_contains($mod, "id: 'mercury'")
    && str_contains($mod, "id: 'plaid_transfer'")
    && str_contains($mod, "id: 'nacha'"));
foreach ([
    'suggest-payment-run-modal',
    'suggest-payment-run-horizon',
    'suggest-payment-run-rail',
    'suggest-payment-run-refresh',
    'suggest-payment-run-loading',
    'suggest-payment-run-error',
    'suggest-payment-run-summary',
    'suggest-payment-run-summary-banner',
    'suggest-payment-run-empty',
    'suggest-payment-run-groups',
    'suggest-payment-run-cancel',
    'suggest-payment-run-confirm',
    'suggest-payment-run-selected-count',
] as $tid) {
    $a("testid '{$tid}' present", str_contains($mod, "data-testid=\"{$tid}\""));
}

echo "\n── BillsList wiring ──\n";
$bl = file_get_contents('/app/modules/ap/ui/BillsList.jsx');
$a('imports SuggestPaymentRunModal',
    str_contains($bl, "import SuggestPaymentRunModal from './SuggestPaymentRunModal'"));
$a('has the Suggest payment run CTA',
    str_contains($bl, 'data-testid="ap-bills-suggest-payment-run"'));
$a('mounts <SuggestPaymentRunModal /> when toggled',
    str_contains($bl, '<SuggestPaymentRunModal'));

echo "\n=========================================\n";
echo "AP Suggest payment run smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
