<?php
/**
 * AP Phase A1 — Recurring bills library.
 *
 * Pure functions. Schedules a draft bill on a frequency. Generated bills
 * follow the normal AP flow (pending_approval → approved → paid).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/ap.php';

/**
 * Compute the next bill date after $current given $frequency + $dayOfPeriod.
 * Pure: no DB. Uses string date math (no float drift).
 */
function apRecurringNextDate(string $current, string $frequency, int $dayOfPeriod = 1): string
{
    $ts = strtotime($current);
    if ($ts === false) throw new \InvalidArgumentException("invalid date: {$current}");

    switch ($frequency) {
        case 'weekly':    return date('Y-m-d', strtotime('+7 days', $ts));
        case 'biweekly':  return date('Y-m-d', strtotime('+14 days', $ts));
        case 'monthly':
            // Bump month robustly (strtotime '+1 month' overflows Jan 31 → Mar 3).
            $year = (int) date('Y', $ts); $mo = (int) date('m', $ts);
            $mo++; if ($mo > 12) { $mo = 1; $year++; }
            $maxDay = (int) date('t', mktime(0,0,0,$mo,1,$year));
            $day = min(max(1, $dayOfPeriod), $maxDay);
            return sprintf('%04d-%02d-%02d', $year, $mo, $day);
        case 'quarterly':
            $next = strtotime('+3 months', $ts);
            return date('Y-m-d', $next);
        case 'yearly':
            $next = strtotime('+1 year', $ts);
            return date('Y-m-d', $next);
    }
    throw new \InvalidArgumentException("invalid frequency: {$frequency}");
}

/**
 * Scan all active recurring bills whose next_bill_date <= $asOf and
 * generate a draft bill row for each. Idempotent in the sense that each
 * generation advances last_generated_date + next_bill_date.
 *
 * Returns ['generated' => N, 'bill_ids' => [...]].
 */
function apRecurringGenerateDue(int $tenantId, ?string $asOf = null): array
{
    $pdo = getDB();
    $asOf = $asOf ?: date('Y-m-d');

    $rows = $pdo->prepare(
        "SELECT * FROM ap_recurring_bills
          WHERE tenant_id = :t
            AND status = 'active'
            AND next_bill_date <= :asof_lo
            AND (end_date IS NULL OR end_date >= :asof_hi)
          ORDER BY next_bill_date ASC, id ASC"
    );
    $rows->execute(['t' => $tenantId, 'asof_lo' => $asOf, 'asof_hi' => $asOf]);
    $due = $rows->fetchAll(\PDO::FETCH_ASSOC);

    $billIds = [];
    foreach ($due as $r) {
        $internalRef = apNextInternalRef($tenantId);
        $billDate = $r['next_bill_date'];
        $dueDate  = date('Y-m-d', strtotime('+30 days', strtotime($billDate)));

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO ap_bills
                    (tenant_id, bill_number, internal_ref, vendor_name, vendor_type,
                     received_at, bill_date, due_date, currency,
                     subtotal, tax_total, total, amount_paid, amount_due,
                     status, source, source_ref_id, created_by_user_id, notes_internal)
                 VALUES
                    (:t, :bn, :ir, :vn, "other",
                     :rec, :bd, :dd, "USD",
                     :sub, 0, :tot1, 0, :tot2,
                     "pending_review", "recurring", :rid, :cby, :notes)'
            )->execute([
                't' => $tenantId,
                'bn' => $r['vendor_name'] . ' ' . substr($billDate, 0, 7),
                'ir' => $internalRef,
                'vn' => $r['vendor_name'],
                'rec' => $billDate,
                'bd'  => $billDate,
                'dd'  => $dueDate,
                'sub' => $r['amount'],
                'tot1' => $r['amount'],
                'tot2' => $r['amount'],
                'rid' => (int) $r['id'],
                'cby' => $r['created_by_user_id'],
                'notes' => 'Generated from recurring schedule #' . $r['id'] . ': ' . $r['description'],
            ]);
            $billId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO ap_bill_lines
                    (bill_id, line_no, source_type, source_ref_id, description,
                     quantity, unit, unit_price, subtotal, total,
                     gl_expense_account_code, is_1099_eligible)
                 VALUES
                    (:b, 1, "recurring", :rid, :desc,
                     1, "fixed", :amt1, :amt2, :amt3,
                     :gl, :elig)'
            )->execute([
                'b' => $billId,
                'rid' => (int) $r['id'],
                'desc' => $r['description'],
                'amt1' => $r['amount'],
                'amt2' => $r['amount'],
                'amt3' => $r['amount'],
                'gl' => $r['gl_expense_account_code'],
                'elig' => (int) $r['is_1099_eligible'],
            ]);

            // Advance the schedule.
            $next = apRecurringNextDate($billDate, $r['frequency'], (int) $r['day_of_period']);
            $pdo->prepare(
                'UPDATE ap_recurring_bills
                    SET last_generated_date = :gen,
                        last_generated_bill_id = :bid,
                        next_bill_date = :next
                  WHERE id = :id AND tenant_id = :t'
            )->execute([
                'gen' => $billDate,
                'bid' => $billId,
                'next' => $next,
                'id' => (int) $r['id'],
                't' => $tenantId,
            ]);

            $pdo->commit();
            $billIds[] = $billId;
            apAudit('ap.recurring.generated', [
                'recurring_id' => (int) $r['id'],
                'bill_id' => $billId,
                'amount' => (float) $r['amount'],
                'next_bill_date' => $next,
            ], (int) $r['id']);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[ap.recurring] generate failed for ' . (int) $r['id'] . ': ' . $e->getMessage());
        }
    }
    return ['generated' => count($billIds), 'bill_ids' => $billIds];
}
