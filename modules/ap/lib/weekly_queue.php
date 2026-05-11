<?php
/**
 * AP Module — Weekly Queue & blocker detection.
 *
 * The "weekly queue" is the AP department's working set: every AP bill that
 * is past due OR coming due in the next 7 days, with a per-bill blocker
 * annotation so operators know what's holding each one up.
 *
 * Surfaces:
 *   - GET  /api/ap/weekly_queue.php                  (read-only listing)
 *   - POST /api/ap/weekly_queue.php?action=finalize  (bulk submit to approver)
 *   - Sunday-night email cron (`scripts/ap_weekly_queue_sunday.php`)
 *
 * Blocker semantics (per-bill):
 *   - awaiting_client     — PWP bill, AR invoice not yet paid
 *   - missing_hours       — bill has time-source lines but the upstream bundle
 *                           isn't in 'consumed' status (rare; usually means
 *                           the time period got reopened)
 *   - needs_review        — bill is still in 'inbox'/'pending_review'
 *                           and has not yet been finalized by AP
 *   - approver_pending    — already submitted; awaiting an approver step
 *   - disputed            — approver rejected; needs AP attention
 *   - none                — bill is approved, ready for payment
 *
 * Pure functions. Controllers live in /api.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/pwp.php';

const AP_WEEKLY_QUEUE_BLOCKERS = [
    'awaiting_client', 'missing_hours', 'needs_review',
    'approver_pending', 'disputed', 'none',
];

/**
 * Return every "in scope" AP bill for the AP team's weekly queue. Bills are
 * "in scope" when they are still owed (status NOT IN paid/void) AND their
 * due_date is on or before today + lookahead_days, OR the bill is already
 * past due.
 *
 * For PWP bills awaiting AR, we still surface them so AP can see "client
 * hasn't paid yet — INV-XXX is the blocker". The blocker classifier returns
 * 'awaiting_client' in that case.
 *
 * @return list<array>  decorated bill rows + .blocker, .blocker_detail
 */
function apWeeklyQueueList(int $tenantId, int $lookaheadDays = 7): array {
    $pdo = getDB();
    if (!$pdo) return [];

    $stmt = $pdo->prepare(
        'SELECT b.id, b.bill_number, b.internal_ref, b.vendor_name, b.vendor_type,
                b.bill_date, b.due_date, b.period_start, b.period_end, b.total,
                b.amount_paid, b.amount_due, b.status, b.source,
                b.payment_terms, b.pwp_status, b.linked_ar_invoice_id,
                b.entity_id, b.created_at, b.updated_at
           FROM ap_bills b
          WHERE b.tenant_id = :t
            AND b.status NOT IN ("paid","void")
            AND (
                b.due_date <  CURDATE()
             OR b.due_date <= DATE_ADD(CURDATE(), INTERVAL :n DAY)
            )
          ORDER BY b.due_date ASC, b.id ASC
          LIMIT 500'
    );
    $stmt->execute(['t' => $tenantId, 'n' => $lookaheadDays]);
    $bills = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if (empty($bills)) return [];

    // --- Pre-fetch AR invoice numbers for PWP blocker detail
    $arIds = array_filter(array_map(fn ($b) => (int) ($b['linked_ar_invoice_id'] ?? 0), $bills));
    $arMap = [];
    if (!empty($arIds)) {
        $ph = []; $p = ['t' => $tenantId];
        foreach (array_values(array_unique($arIds)) as $i => $aid) {
            $k = 'a' . $i; $ph[] = ':' . $k; $p[$k] = $aid;
        }
        try {
            $st = $pdo->prepare(
                'SELECT id, invoice_number, status, amount_due
                   FROM billing_invoices
                  WHERE tenant_id = :t AND id IN (' . implode(',', $ph) . ')'
            );
            $st->execute($p);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $arMap[(int) $r['id']] = $r;
            }
        } catch (\Throwable $_) { /* billing not installed */ }
    }

    // --- Pre-fetch bundle status for missing_hours blocker
    $billIds = array_map(fn ($b) => (int) $b['id'], $bills);
    $bundleStatusMap = [];
    if (!empty($billIds)) {
        $bp = []; $bparams = ['t' => $tenantId];
        foreach ($billIds as $i => $bid) {
            $k = 'b' . $i; $bp[] = ':' . $k; $bparams[$k] = $bid;
        }
        try {
            $bs = $pdo->prepare(
                'SELECT bl.bill_id, tdf.status AS bundle_status
                   FROM ap_bill_lines bl
                   LEFT JOIN time_downstream_feed tdf ON tdf.id = bl.source_ref_id
                                                     AND tdf.tenant_id = :t
                  WHERE bl.bill_id IN (' . implode(',', $bp) . ')
                    AND bl.source_type = "time"'
            );
            $bs->execute($bparams);
            foreach ($bs->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $bid = (int) $r['bill_id'];
                $bundleStatusMap[$bid] = $bundleStatusMap[$bid] ?? [];
                $bundleStatusMap[$bid][] = $r['bundle_status'];
            }
        } catch (\Throwable $_) { /* time module absent */ }
    }

    foreach ($bills as &$b) {
        $b['blocker'] = 'none';
        $b['blocker_detail'] = null;

        // 1. PWP awaiting AR: highest signal — surface client invoice
        if (($b['pwp_status'] ?? '') === 'awaiting_ar' && !empty($b['linked_ar_invoice_id'])) {
            $ar = $arMap[(int) $b['linked_ar_invoice_id']] ?? null;
            $b['blocker'] = 'awaiting_client';
            $b['blocker_detail'] = $ar
                ? "Awaiting client payment of invoice {$ar['invoice_number']} (status: {$ar['status']}, due \$" . number_format((float) $ar['amount_due'], 2) . ')'
                : 'Awaiting linked client invoice (not yet issued)';
            continue;
        }

        // 2. Time bundle blocked: any source bundle that isn't in 'consumed'
        $bundleStatuses = $bundleStatusMap[(int) $b['id']] ?? [];
        $bundleStatuses = array_filter($bundleStatuses, fn ($s) => $s !== null);
        if (!empty($bundleStatuses) && !in_array('consumed', $bundleStatuses, true)) {
            $b['blocker'] = 'missing_hours';
            $b['blocker_detail'] = 'Source time bundle status: ' . implode(', ', array_unique($bundleStatuses));
            continue;
        }

        // 3. Disputed (approver rejected)
        if ($b['status'] === 'disputed') {
            $b['blocker'] = 'disputed';
            $b['blocker_detail'] = 'Approver rejected — AP must address';
            continue;
        }

        // 4. Not yet finalized to approver
        if (in_array($b['status'], ['inbox', 'pending_review'], true)) {
            $b['blocker'] = 'needs_review';
            $b['blocker_detail'] = 'AP has not yet finalized to approver';
            continue;
        }

        // 5. Already with approver
        if ($b['status'] === 'pending_approval') {
            $b['blocker'] = 'approver_pending';
            $b['blocker_detail'] = 'Awaiting approver decision';
            continue;
        }
    }
    unset($b);

    return $bills;
}

/**
 * Bucket helper for the email layout.
 *   - past_due:  due_date < today
 *   - due_soon:  due_date in [today, today + lookahead]
 *
 * Bills with `awaiting_client` blocker are kept in the same bucket but
 * tagged so the email can highlight them.
 */
function apWeeklyQueueBucket(array $rows): array {
    $today = date('Y-m-d');
    $past = []; $soon = [];
    foreach ($rows as $r) {
        if ((string) $r['due_date'] < $today) $past[] = $r;
        else $soon[] = $r;
    }
    return ['past_due' => $past, 'due_soon' => $soon];
}

/**
 * Aggregate counters used by the Sunday-night email subject line and the
 * digest blurb. Returns:
 *   ['past_due_count','past_due_amount','due_soon_count','due_soon_amount',
 *    'blocked_count','ready_count'].
 */
function apWeeklyQueueSummary(array $rows): array {
    $sum = [
        'past_due_count' => 0, 'past_due_amount' => 0.0,
        'due_soon_count' => 0, 'due_soon_amount' => 0.0,
        'blocked_count'  => 0, 'ready_count'    => 0,
    ];
    $today = date('Y-m-d');
    foreach ($rows as $r) {
        $isPast = (string) $r['due_date'] < $today;
        $isReady = $r['blocker'] === 'none';
        if ($isPast) {
            $sum['past_due_count']++;
            $sum['past_due_amount'] += (float) $r['amount_due'];
        } else {
            $sum['due_soon_count']++;
            $sum['due_soon_amount'] += (float) $r['amount_due'];
        }
        if (!$isReady && $r['blocker'] !== 'approver_pending') $sum['blocked_count']++;
        if ($isReady) $sum['ready_count']++;
    }
    $sum['past_due_amount'] = round($sum['past_due_amount'], 2);
    $sum['due_soon_amount'] = round($sum['due_soon_amount'], 2);
    return $sum;
}
