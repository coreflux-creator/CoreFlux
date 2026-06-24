<?php
/**
 * Smoke — Engagement detail page + CFO revenue-stream widget.
 *
 * Locks:
 *   - /modules/engagements/ui/EngagementDetail.jsx exists with editable
 *     header, milestones editor, archive button, per-row actions.
 *   - EngagementsModule routes /modules/engagements/:id to EngagementDetail.
 *   - EngagementsList row links to the detail page.
 *   - /api/cfo_revenue_stream.php exists with auth + CFO gate.
 *   - Bucket math: 4 sources tagged correctly.
 *   - RevenueStreamWidget.jsx mounted on CFODashboard above FscHealthPanel.
 *   - Vite bundle picked up both components.
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nEngagement detail + Revenue stream smoke\n";
echo "==========================================\n\n";

// ─── Detail page source ───
echo "── EngagementDetail.jsx ──\n";
$dPath = '/app/modules/engagements/ui/EngagementDetail.jsx';
check('detail page exists', is_file($dPath));
$d = (string) file_get_contents($dPath);
check('uses useParams for id',                       str_contains($d, 'useParams()'));
check('hits detail.php with :id param',              str_contains($d, '/modules/engagements/api/detail.php?id='));
check('editable header — PATCH detail.php',          str_contains($d, '/modules/engagements/api/detail.php?id='));
check('archive via DELETE',                          str_contains($d, 'api.delete') && str_contains($d, '/modules/engagements/api/detail.php?id='));
check('milestone PATCH endpoint wired',
    str_contains($d, '/modules/engagements/api/milestones.php?id='));
check('milestone create endpoint wired',
    str_contains($d, '/modules/engagements/api/milestones.php?engagement_id='));
check('invoice CTA on milestone hits invoice_milestone',
    str_contains($d, '/modules/engagements/api/invoice_milestone.php?milestone_id='));
check('mark-ready action transition',                str_contains($d, "patch({ status: 'ready_to_invoice' })"));
check('mark-paid action transition',                 str_contains($d, "patch({ status: 'paid' })"));
check('cancel milestone action',                     str_contains($d, "patch({ status: 'cancelled' })"));
check('window.confirm gate on archive',              str_contains($d, "window.confirm('Archive this engagement"));
check('window.confirm gate on cancel milestone',     str_contains($d, "window.confirm('Cancel this milestone"));
check('window.confirm gate on invoice now',          str_contains($d, "window.confirm(`Generate a draft invoice"));

// Test ids
foreach ([
    'engagement-detail', 'engagement-detail-back', 'engagement-detail-edit',
    'engagement-detail-save', 'engagement-detail-archive', 'engagement-detail-status',
    'engagement-detail-add-milestone', 'engagement-detail-milestones-table',
    'engagement-detail-add-submit',
] as $t) {
    check("testid present: {$t}", str_contains($d, "data-testid=\"{$t}\""));
}
foreach ([
    'milestone-detail-row', 'milestone-detail-invoice-btn', 'milestone-mark-ready',
    'milestone-detail-markpaid', 'milestone-detail-cancel', 'milestone-detail-edit',
    'milestone-edit-name', 'milestone-edit-save',
] as $t) {
    check("interpolated testid present: {$t}",
        str_contains($d, 'data-testid={`' . $t . '-'));
}

// ─── Router wiring ───
echo "\n── EngagementsModule.jsx (route) ──\n";
$mod = (string) file_get_contents('/app/modules/engagements/ui/EngagementsModule.jsx');
check('imports EngagementDetail',                    str_contains($mod, "import EngagementDetail from './EngagementDetail'"));
check('routes :id → EngagementDetail',
    str_contains($mod, '<Route path=":id" element={<EngagementDetail />} />'));

// ─── List → Detail link ───
$lst = (string) file_get_contents('/app/modules/engagements/ui/EngagementsList.jsx');
check('list row links to detail page',
    str_contains($lst, 'to={`/modules/engagements/${row.id}`}'));
check('list link has testid',
    str_contains($lst, 'data-testid={`engagement-link-'));

// ─── Revenue stream endpoint ───
echo "\n── /api/cfo_revenue_stream.php ──\n";
$ep = '/app/api/cfo_revenue_stream.php';
check('endpoint exists', is_file($ep));
$es = (string) file_get_contents($ep);
check('endpoint calls api_require_auth',             str_contains($es, 'api_require_auth()'));
check('endpoint enforces CFO gate',                   str_contains($es, 'api_require_cfo()'));
check('weeks param clamped 1..26',                    str_contains($es, 'min(26, (int) ($_GET[\'weeks\'] ?? 4))'));
check('T&M bucket recognises time_entry / placement / timesheet',
    str_contains($es, "'time_entry', 'placement', 'timesheet'"));
check('Fixed-fee bucket = engagement_milestone',
    str_contains($es, "'engagement_milestone'"));
check('QBO recon bucket from billing_payments source=qbo',
    str_contains($es, "source_system = 'qbo'"));
check('excludes draft/void/cancelled invoices',
    str_contains($es, "status NOT IN ('draft','void','cancelled')"));
check('weekly trend uses YEARWEEK ISO mode (3)',
    str_contains($es, 'YEARWEEK(bi.issue_date, 3)'));
check('graceful fallback when source_type column missing',
    str_contains($es, 'catch (\\Throwable $e)') &&
    str_contains($es, 'Older schemas may not have source_type'));

// ─── Widget mounted ───
echo "\n── RevenueStreamWidget.jsx ──\n";
$w = (string) file_get_contents('/app/dashboard/src/components/RevenueStreamWidget.jsx');
check('widget exists', $w !== '');
check('widget fetches /api/cfo_revenue_stream.php',  str_contains($w, '/api/cfo_revenue_stream.php?weeks='));
check('renders four buckets (tm, fixed_fee, manual, qbo_recon)',
    str_contains($w, "key: 'tm'") && str_contains($w, "key: 'fixed_fee'") &&
    str_contains($w, "key: 'manual'") && str_contains($w, "key: 'qbo_recon'"));
check('renders SVG donut',                            str_contains($w, '<svg'));
check('renders stacked-bar trend',
    str_contains($w, 'data-testid={`cfo-revenue-stream-bar-${w.week}`}'));
check('period picker exposes 4/8/13/26 weeks',
    str_contains($w, '[4, 8, 13, 26]'));
check('fixed-fee pulse banner',                       str_contains($w, 'cfo-revenue-stream-fixed-pulse'));

// CFO dashboard mount
echo "\n── CFODashboard.jsx mount point ──\n";
$cfo = (string) file_get_contents('/app/dashboard/src/pages/CFODashboard.jsx');
check('imports RevenueStreamWidget',                  str_contains($cfo, "import RevenueStreamWidget from '../components/RevenueStreamWidget'"));
check('mounts above FscHealthPanel',
    strpos($cfo, '<RevenueStreamWidget />') < strpos($cfo, '<FscHealthPanel />'));

// ─── Bundle check ───
echo "\n── Vite bundle ──\n";
$deployVer = (string) file_get_contents('/app/.deploy-version');
if (preg_match('/^- spa-assets\/(index-[A-Za-z0-9_\-]+\.js)/m', $deployVer, $m)) {
    $jsBundle = '/app/spa-assets/' . $m[1];
    if (is_file($jsBundle)) {
        $body = (string) file_get_contents($jsBundle);
        check('bundle contains cfo-revenue-stream testid',
            str_contains($body, 'cfo-revenue-stream'));
        check('bundle contains engagement-detail testid',
            str_contains($body, 'engagement-detail'));
        check('bundle contains cfo_revenue_stream.php path',
            str_contains($body, 'cfo_revenue_stream.php'));
    } else {
        check('Vite bundle present', false);
    }
} else {
    check('Vite bundle pointer resolved', false);
}

echo "\nengagement_detail_and_revenue_stream smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
