<?php
/**
 * Billing API — Cash Cycle Health snapshot.
 *
 *   GET /api/billing/cash_cycle_health.php
 *
 * Returns one envelope with everything the home-page "Cash cycle health"
 * tile needs:
 *
 *   {
 *     dso_days:                  number,   // avg days-to-pay over last 90d
 *     ar_outstanding_total:      number,   // open AR right now
 *     pwp_awaiting_ar:           { count, total_amount },
 *     pwp_released_last_week:    { count, total_amount, ar_invoice_count },
 *     weekly_queue_blocked_count:number    // AP bills past-due/due-soon w/ blockers
 *   }
 *
 * Each metric is computed independently and tolerates missing schema (e.g.
 * pre-PWP-migration tenants) — a single bad SQL never sinks the whole tile.
 *
 * Permission: `billing.view`.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../ap/lib/weekly_queue.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
rbac_legacy_require($user, 'billing.view');

$pdo = getDB();

$tryOne = function (string $sql, array $params, $fallback = null) use ($pdo) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $val = $st->fetchColumn();
        return $val === false ? $fallback : $val;
    } catch (\Throwable $e) {
        error_log('[cash_cycle_health] ' . $e->getMessage());
        return $fallback;
    }
};

// --- DSO: avg(date_paid - issue_date) for invoices that fully paid in the
// last 90 days. We approximate `date_paid` as the most recent allocation
// timestamp via billing_payment_allocations -> billing_payments.received_at.
$dso = $tryOne(
    "SELECT ROUND(AVG(DATEDIFF(p.received_at, i.issue_date)), 1)
       FROM billing_invoices i
       JOIN billing_payment_allocations a ON a.invoice_id = i.id
       JOIN billing_payments p             ON p.id = a.payment_id
      WHERE i.tenant_id = :t
        AND i.status = 'paid'
        AND i.amount_due < 0.005
        AND p.received_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)",
    ['t' => $tid],
    null
);

// --- AR outstanding (open invoices, all ages)
$arTotal = $tryOne(
    "SELECT COALESCE(SUM(amount_due), 0) FROM billing_invoices
      WHERE tenant_id = :t AND status IN ('sent','partially_paid','overdue')",
    ['t' => $tid],
    0
);

// --- PWP bills awaiting AR
$pwpAwaiting = ['count' => 0, 'total_amount' => 0];
try {
    $st = $pdo->prepare(
        "SELECT COUNT(*) AS c, COALESCE(SUM(amount_due), 0) AS s
           FROM ap_bills
          WHERE tenant_id = :t AND pwp_status = 'awaiting_ar' AND status NOT IN ('paid','void')"
    );
    $st->execute(['t' => $tid]);
    $r = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
    $pwpAwaiting = [
        'count'        => (int)   ($r['c'] ?? 0),
        'total_amount' => (float) ($r['s'] ?? 0),
    ];
} catch (\Throwable $_) { /* pre-migration tenant — leave zeros */ }

// --- PWP released in the last 7 days (joins audit_log → ap_bills for $)
$pwpReleased = ['count' => 0, 'total_amount' => 0, 'ar_invoice_count' => 0];
try {
    $st = $pdo->prepare(
        "SELECT meta_json FROM audit_log
          WHERE tenant_id = :t AND event = 'ap.bill.pwp.released'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    $st->execute(['t' => $tid]);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $billIds = []; $arIds = [];
    foreach ($rows as $r) {
        $m = json_decode((string) ($r['meta_json'] ?? ''), true) ?: [];
        if (!empty($m['bill_id']))       $billIds[(int) $m['bill_id']] = true;
        if (!empty($m['ar_invoice_id'])) $arIds[(int) $m['ar_invoice_id']] = true;
    }
    if (!empty($billIds)) {
        $ph = []; $p = ['t' => $tid];
        foreach (array_keys($billIds) as $i => $bid) {
            $k = 'b' . $i; $ph[] = ':' . $k; $p[$k] = $bid;
        }
        $sumStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(total), 0) FROM ap_bills
              WHERE tenant_id = :t AND id IN (' . implode(',', $ph) . ')'
        );
        $sumStmt->execute($p);
        $pwpReleased = [
            'count'            => count($billIds),
            'total_amount'     => (float) $sumStmt->fetchColumn(),
            'ar_invoice_count' => count($arIds),
        ];
    }
} catch (\Throwable $_) { /* schema absence */ }

// --- Weekly Queue blocked count (anything past-due or due-soon with a blocker
// other than approver_pending; reuses the same library the queue UI uses).
$blocked = 0;
try {
    $rows = apWeeklyQueueList($tid, 7);
    foreach ($rows as $r) {
        $b = $r['blocker'] ?? 'none';
        if ($b !== 'none' && $b !== 'approver_pending') $blocked++;
    }
} catch (\Throwable $_) { /* AP module absent */ }

api_ok([
    'dso_days'                   => $dso === null ? null : (float) $dso,
    'ar_outstanding_total'       => (float) $arTotal,
    'pwp_awaiting_ar'            => $pwpAwaiting,
    'pwp_released_last_week'     => $pwpReleased,
    'weekly_queue_blocked_count' => $blocked,
]);
