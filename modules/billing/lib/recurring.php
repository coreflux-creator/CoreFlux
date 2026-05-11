<?php
/**
 * Billing — Recurring invoice contracts.
 *
 * Pure (no I/O on the generator helpers; controllers wrap them in API +
 * cron). Today supports flat-fee monthly / quarterly / annual contracts
 * with three proration policies on contracts that begin mid-period.
 *
 * Public surface:
 *   billingRecurringComputeNextDue(array $contract, string $fromDate): string
 *   billingRecurringComputePeriodForGeneration(array $contract, string $dueDate): array
 *   billingRecurringProrationFactor(string $policy, string $periodStart, string $periodEnd, string $contractStart): float
 *   billingRecurringGenerateInvoice(int $tenantId, array $contract, string $forDate, ?int $actorUserId = null): array
 *   billingRecurringEligibleContracts(int $tenantId, string $asOf): array
 *   billingRecurringPreviewNextN(array $contract, int $n, ?string $fromDate = null): list<string>
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/billing.php';

/**
 * Clamp a day-of-month to the actual last day of the target month.
 *   eg. dom=31 on a Feb-2026 query → 2026-02-28
 */
function _billingRecurringClampDom(int $year, int $month, int $dom): int {
    $last = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
    return min($dom, $last);
}

/**
 * Compute when this contract's *next* invoice should be issued, given a
 * pivot date (typically `last_generated_at` or `start_date`).
 *
 *   - monthly   → next month, clamped to day_of_period
 *   - quarterly → +3 months
 *   - annual    → +12 months
 *
 * The pivot is always treated as "what period we just generated"; the
 * return is the next period's invoice date.
 */
function billingRecurringComputeNextDue(array $contract, string $fromDate): string {
    $freq = strtolower((string) $contract['frequency']);
    $dom  = (int) ($contract['day_of_period'] ?? 1);
    $ts   = strtotime($fromDate);
    $y    = (int) date('Y', $ts);
    $m    = (int) date('n', $ts);

    [$dy, $dm] = match ($freq) {
        'quarterly' => [0, 3],
        'annual'    => [1, 0],
        default     => [0, 1],   // monthly
    };
    $m += $dm; $y += $dy;
    while ($m > 12) { $m -= 12; $y++; }
    $d = _billingRecurringClampDom($y, $m, $dom);
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

/**
 * Given a target invoice issue date for this contract, return the
 * service period (period_start..period_end) it covers.
 *
 * Monthly:   period_start = invoice date, period_end = next month-1day - 1
 * Quarterly: 3 months
 * Annual:    12 months
 */
function billingRecurringComputePeriodForGeneration(array $contract, string $dueDate): array {
    $freq = strtolower((string) $contract['frequency']);
    $ts   = strtotime($dueDate);
    [$dy, $dm] = match ($freq) {
        'quarterly' => [0, 3],
        'annual'    => [1, 0],
        default     => [0, 1],
    };
    $endTs = strtotime(($dy ? "+{$dy} year " : '') . "+{$dm} month -1 day", $ts);
    return [
        'period_start' => date('Y-m-d', $ts),
        'period_end'   => date('Y-m-d', $endTs),
    ];
}

/**
 * Pro-ration factor for a contract that begins partway into its first
 * billing period.
 *   full       → 1.0 always (the operator wants to charge full first period)
 *   skip_first → 0.0 if contract_start > period_start (defer to next period)
 *   prorate    → (days_active / total_days)
 */
function billingRecurringProrationFactor(string $policy, string $periodStart, string $periodEnd, string $contractStart): float {
    if ($policy === 'full' || $contractStart <= $periodStart) return 1.0;
    if ($policy === 'skip_first') return 0.0;
    // prorate
    $total = max(1, (int) round((strtotime($periodEnd) - strtotime($periodStart)) / 86400) + 1);
    $active= max(0, (int) round((strtotime($periodEnd) - strtotime($contractStart)) / 86400) + 1);
    return max(0.0, min(1.0, $active / $total));
}

/**
 * Generate ONE invoice from a contract for a given target date. Idempotent:
 * if an invoice for this (contract_id, period_start) already exists, the
 * existing row is returned with `existed=>true`.
 *
 * Returns ['invoice_id' => int, 'period_start' => str, 'period_end' => str,
 *          'amount' => float, 'proration_factor' => float, 'existed' => bool].
 *
 * Operates inside the caller's transaction if one is open; otherwise begins
 * + commits its own.
 */
function billingRecurringGenerateInvoice(int $tenantId, array $contract, string $forDate, ?int $actorUserId = null): array {
    $pdo = getDB();
    $period = billingRecurringComputePeriodForGeneration($contract, $forDate);
    $existing = $pdo->prepare(
        'SELECT id FROM billing_invoices
          WHERE tenant_id = :t AND source_contract_id = :c AND period_start = :ps LIMIT 1'
    );
    $existing->execute(['t' => $tenantId, 'c' => (int) $contract['id'], 'ps' => $period['period_start']]);
    $existingId = (int) ($existing->fetchColumn() ?: 0);
    if ($existingId > 0) {
        return [
            'invoice_id'  => $existingId,
            'period_start'=> $period['period_start'],
            'period_end'  => $period['period_end'],
            'amount'      => (float) $contract['amount'],
            'proration_factor' => 1.0,
            'existed'     => true,
        ];
    }

    $factor = billingRecurringProrationFactor(
        (string) ($contract['proration_policy'] ?? 'full'),
        $period['period_start'], $period['period_end'], (string) $contract['start_date']
    );
    $finalAmount = round((float) $contract['amount'] * $factor, 2);
    if ($factor === 0.0) {
        return [
            'invoice_id'  => 0,
            'period_start'=> $period['period_start'],
            'period_end'  => $period['period_end'],
            'amount'      => 0.0,
            'proration_factor' => 0.0,
            'existed'     => false,
            'skipped'     => 'skip_first_policy',
        ];
    }

    $owns = false;
    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $owns = true; }
    try {
        $invoiceNumber = billingNextInvoiceNumber($tenantId, 'INV');
        $issueDate = $forDate;
        $dueDate   = date('Y-m-d', strtotime('+30 days', strtotime($forDate)));

        $billTo = !empty($contract['bill_to_json'])
            ? $contract['bill_to_json']
            : (!empty($contract['bill_to_email']) ? json_encode(['email' => $contract['bill_to_email']]) : null);

        $invId = scopedInsert('billing_invoices', [
            'tenant_id'        => $tenantId,
            'invoice_number'   => $invoiceNumber,
            'client_name'      => (string) $contract['client_name'],
            'bill_to_json'     => $billTo,
            'currency'         => (string) ($contract['currency'] ?? 'USD'),
            'issue_date'       => $issueDate,
            'due_date'         => $dueDate,
            'period_start'     => $period['period_start'],
            'period_end'       => $period['period_end'],
            'subtotal'         => $finalAmount,
            'tax_total'        => 0,
            'total'            => $finalAmount,
            'amount_paid'      => 0,
            'amount_due'       => $finalAmount,
            'status'           => 'draft',
            'po_number'        => $contract['po_number'] ?? null,
            'notes_internal'   => $contract['notes_internal'] ?? null,
            'aggregation'      => 'per_client',
            'source_contract_id' => (int) $contract['id'],
            'created_by_user_id' => $actorUserId,
        ]);

        $desc = (string) $contract['contract_name'];
        if ($factor < 1.0) $desc .= sprintf(' (prorated %.0f%%)', $factor * 100);
        scopedInsert('billing_invoice_lines', [
            'invoice_id'   => $invId,
            'line_no'      => 1,
            'source_type'  => 'manual',
            'source_ref_id'=> null,
            'placement_id' => null,
            'description'  => $desc,
            'quantity'     => 1,
            'unit'         => 'flat',
            'unit_price'   => $finalAmount,
            'subtotal'     => $finalAmount,
            'tax_rate_pct' => 0,
            'tax_amount'   => 0,
            'total'        => $finalAmount,
        ]);

        // Advance the contract pointer atomically.
        $pdo->prepare(
            'UPDATE billing_invoice_contracts
                SET last_generated_at = NOW(),
                    last_generated_invoice_id = :inv,
                    next_due_at = :nd
              WHERE id = :id AND tenant_id = :t'
        )->execute([
            'inv' => $invId,
            'nd'  => billingRecurringComputeNextDue($contract, $forDate),
            'id'  => (int) $contract['id'],
            't'   => $tenantId,
        ]);

        if ($owns) $pdo->commit();

        if (function_exists('billingAudit')) {
            try {
                billingAudit('billing.invoice.recurring_generated', [
                    'contract_id'    => (int) $contract['id'],
                    'invoice_id'     => $invId,
                    'period_start'   => $period['period_start'],
                    'amount'         => $finalAmount,
                    'proration_factor' => $factor,
                ], $invId);
            } catch (\Throwable $_) { /* audit infra absent — non-fatal */ }
        }

        return [
            'invoice_id'  => $invId,
            'period_start'=> $period['period_start'],
            'period_end'  => $period['period_end'],
            'amount'      => $finalAmount,
            'proration_factor' => $factor,
            'existed'     => false,
        ];
    } catch (\Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * List active contracts whose next_due_at falls on or before $asOf.
 *
 * Bootstrap: a contract whose `next_due_at` is still NULL is treated as
 * "due on its start_date" so the very first invoice generates on the
 * morning of (or after) start_date.
 */
function billingRecurringEligibleContracts(int $tenantId, string $asOf): array {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT * FROM billing_invoice_contracts
          WHERE tenant_id = :t
            AND status = 'active'
            AND start_date <= :asOf
            AND (end_date IS NULL OR end_date >= :asOf)
            AND (
                  (next_due_at IS NULL     AND start_date <= :asOf2)
               OR (next_due_at IS NOT NULL AND next_due_at <= :asOf3)
            )
          ORDER BY id ASC"
    );
    $stmt->execute(['t' => $tenantId, 'asOf' => $asOf, 'asOf2' => $asOf, 'asOf3' => $asOf]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    // Materialise the first-run pivot.
    foreach ($rows as &$r) {
        if (empty($r['next_due_at'])) $r['next_due_at'] = $r['start_date'];
    }
    return $rows;
}

/**
 * Peek at the next N generation dates without mutating anything.
 * Used by the UI to render a "next-3-due" mini-calendar.
 */
function billingRecurringPreviewNextN(array $contract, int $n, ?string $fromDate = null): array {
    $cursor = $fromDate ?: ($contract['next_due_at'] ?: $contract['start_date']);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        if (!empty($contract['end_date']) && $cursor > $contract['end_date']) break;
        $out[] = $cursor;
        $cursor = billingRecurringComputeNextDue($contract, $cursor);
    }
    return $out;
}
