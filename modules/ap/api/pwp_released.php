<?php
/**
 * /api/ap/pwp_released — bills the PWP gate just released for payment.
 *
 *   GET /modules/ap/api/pwp_released.php[?days=7]
 *
 * Returns bills where:
 *   - pwp_status = 'triggered'
 *   - pwp_released_at >= NOW() - :days
 *   - status IN ('approved','partially_paid')
 *   - amount_due > 0
 *
 * Powers the AR-paid → AP-payment-run nudge on the AP Weekly Queue and
 * the CFO Dashboard "newly releasable" tile (P2.2 / P2.3).
 *
 * Read-only. Tenant-scoped via scopedQuery.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';

$ctx  = api_require_auth();
$user = $ctx['user'];

rbac_legacy_require($user, 'ap.view');

if (api_method() !== 'GET') api_error('Method not allowed', 405);

$days = max(1, min(60, (int) ($_GET['days'] ?? 7)));
$tid  = currentTenantId();

$rows = scopedQuery(
    "SELECT id, internal_ref, vendor_name, vendor_type, total, amount_due,
            currency, status, payment_terms, due_date, pwp_released_at,
            linked_ar_invoice_id
       FROM ap_bills
      WHERE tenant_id = :tenant_id
        AND pwp_status = 'triggered'
        AND pwp_released_at IS NOT NULL
        AND pwp_released_at >= DATE_SUB(NOW(), INTERVAL :d DAY)
        AND status IN ('approved','partially_paid')
        AND amount_due > 0
      ORDER BY pwp_released_at DESC, due_date ASC",
    ['d' => $days]
);

$totalDue = 0.0;
foreach ($rows as $r) $totalDue += (float) $r['amount_due'];

api_ok([
    'days'      => $days,
    'count'     => count($rows),
    'total_due' => round($totalDue, 2),
    'bills'     => $rows,
]);
