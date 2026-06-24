<?php
/**
 * /api/cfo_revenue_stream.php — Revenue mix by source.
 *
 *   GET /api/cfo_revenue_stream.php?weeks=4
 *
 * Splits GMV (Gross Merchandise Value = sum of invoice line totals)
 * across the four ways CoreFlux operators currently bill:
 *
 *   1. **T&M billing**          — `billing_invoice_lines.source_type`
 *                                 IN ('time_entry','placement','timesheet').
 *   2. **Fixed-fee Engagements**— `billing_invoice_lines.source_type`
 *                                 = 'engagement_milestone'.
 *   3. **Manual invoices**      — Everything else inside `billing_invoices`
 *                                 that isn't tagged with one of the above
 *                                 (operator-typed line items).
 *   4. **QBO drift recon'd**    — Out-of-band payments closed by the
 *                                 auto-reconcile resolver. Counted as a
 *                                 SEPARATE bucket because the underlying
 *                                 invoice belongs to one of the above
 *                                 buckets — these are payments routed
 *                                 around CoreFlux that we picked back up
 *                                 via QBO Two-Way Sync.
 *
 * Also returns a stacked week-over-week trend covering the past
 * $weeks weeks (default 4, max 26).
 *
 * Response shape:
 *   {
 *     range: { from: 'YYYY-MM-DD', to: 'YYYY-MM-DD' },
 *     totals: { tm, fixed_fee, manual, qbo_recon, total },
 *     weekly: [ { week: 'YYYY-Wnn', tm, fixed_fee, manual, qbo_recon } ]
 *   }
 *
 * RBAC: same as CFO Dashboard — api_require_cfo().
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/rbac/legacy_map.php';

$ctx = api_require_auth();
api_require_cfo();
$tid = (int) $ctx['tenant_id'];

$weeks  = max(1, min(26, (int) ($_GET['weeks'] ?? 4)));
$toDate = date('Y-m-d');
$fromDate = date('Y-m-d', strtotime("-{$weeks} weeks"));

$pdo = getDB();

// Source-type → bucket map (covers historical labels too).
$TM_SOURCES        = ['time_entry', 'placement', 'timesheet', 'time'];
$FIXED_FEE_SOURCES = ['engagement_milestone'];
$bucketCase = "CASE
    WHEN bil.source_type IN ('" . implode("','", $TM_SOURCES) . "') THEN 'tm'
    WHEN bil.source_type IN ('" . implode("','", $FIXED_FEE_SOURCES) . "') THEN 'fixed_fee'
    ELSE 'manual'
END";

// ─── Totals query — entire window ───
try {
    $totalsStmt = $pdo->prepare(
        "SELECT {$bucketCase} AS bucket, COALESCE(SUM(bil.total), 0) AS subtotal
           FROM billing_invoice_lines bil
           JOIN billing_invoices bi ON bi.id = bil.invoice_id
          WHERE bi.tenant_id = :t
            AND bi.status NOT IN ('draft','void','cancelled')
            AND bi.issue_date BETWEEN :f AND :tt
          GROUP BY bucket"
    );
    $totalsStmt->execute(['t' => $tid, 'f' => $fromDate, 'tt' => $toDate]);
    $rows = $totalsStmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // Older schemas may not have source_type — degrade to single bucket.
    $rows = [];
    try {
        $fallback = $pdo->prepare(
            "SELECT COALESCE(SUM(total), 0) AS s FROM billing_invoices
              WHERE tenant_id = :t AND status NOT IN ('draft','void','cancelled')
                AND issue_date BETWEEN :f AND :tt"
        );
        $fallback->execute(['t' => $tid, 'f' => $fromDate, 'tt' => $toDate]);
        $rows[] = ['bucket' => 'manual', 'subtotal' => (float) $fallback->fetchColumn()];
    } catch (\Throwable $_) {}
}

$totals = ['tm' => 0.0, 'fixed_fee' => 0.0, 'manual' => 0.0, 'qbo_recon' => 0.0];
foreach ($rows as $r) {
    $totals[$r['bucket']] = round((float) $r['subtotal'], 2);
}

// ─── QBO recon'd revenue — payments reconciled by the auto-recon resolver. ───
// We count the matching billing_payments row (source_system='qbo') net,
// scoped to received_at within the window. These are payments routed
// around CoreFlux that we picked back up via Two-Way Sync.
try {
    $reconStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS s
           FROM billing_payments
          WHERE tenant_id = :t AND source_system = 'qbo'
            AND received_at BETWEEN :f AND :tt"
    );
    $reconStmt->execute(['t' => $tid, 'f' => $fromDate, 'tt' => $toDate]);
    $totals['qbo_recon'] = round((float) $reconStmt->fetchColumn(), 2);
} catch (\Throwable $_) { /* table or column may not exist on legacy pods */ }

$totals['total'] = round(
    $totals['tm'] + $totals['fixed_fee'] + $totals['manual'] + $totals['qbo_recon'],
    2
);

// ─── Weekly trend ───
$weekly = [];
try {
    // ISO week labels.
    $weekStmt = $pdo->prepare(
        "SELECT YEARWEEK(bi.issue_date, 3) AS yw,
                {$bucketCase} AS bucket,
                COALESCE(SUM(bil.total), 0) AS subtotal
           FROM billing_invoice_lines bil
           JOIN billing_invoices bi ON bi.id = bil.invoice_id
          WHERE bi.tenant_id = :t
            AND bi.status NOT IN ('draft','void','cancelled')
            AND bi.issue_date BETWEEN :f AND :tt
          GROUP BY yw, bucket
          ORDER BY yw ASC"
    );
    $weekStmt->execute(['t' => $tid, 'f' => $fromDate, 'tt' => $toDate]);
    $weeklyRows = $weekStmt->fetchAll(\PDO::FETCH_ASSOC);
    $idx = [];
    foreach ($weeklyRows as $w) {
        $yw = (int) $w['yw'];
        if (!isset($idx[$yw])) {
            $idx[$yw] = [
                'week' => substr((string) $yw, 0, 4) . '-W' . substr((string) $yw, 4),
                'tm' => 0.0, 'fixed_fee' => 0.0, 'manual' => 0.0, 'qbo_recon' => 0.0,
            ];
        }
        $idx[$yw][$w['bucket']] = round((float) $w['subtotal'], 2);
    }
    // Weekly recon overlay.
    try {
        $reconWeek = $pdo->prepare(
            "SELECT YEARWEEK(received_at, 3) AS yw,
                    COALESCE(SUM(amount), 0) AS s
               FROM billing_payments
              WHERE tenant_id = :t AND source_system = 'qbo'
                AND received_at BETWEEN :f AND :tt
              GROUP BY yw"
        );
        $reconWeek->execute(['t' => $tid, 'f' => $fromDate, 'tt' => $toDate]);
        foreach ($reconWeek->fetchAll(\PDO::FETCH_ASSOC) as $w) {
            $yw = (int) $w['yw'];
            if (!isset($idx[$yw])) {
                $idx[$yw] = [
                    'week' => substr((string) $yw, 0, 4) . '-W' . substr((string) $yw, 4),
                    'tm' => 0.0, 'fixed_fee' => 0.0, 'manual' => 0.0, 'qbo_recon' => 0.0,
                ];
            }
            $idx[$yw]['qbo_recon'] = round((float) $w['s'], 2);
        }
    } catch (\Throwable $_) {}
    ksort($idx);
    $weekly = array_values($idx);
} catch (\Throwable $_) { /* keep weekly empty if schema doesn't support it */ }

api_ok([
    'range'  => ['from' => $fromDate, 'to' => $toDate, 'weeks' => $weeks],
    'totals' => $totals,
    'weekly' => $weekly,
]);
