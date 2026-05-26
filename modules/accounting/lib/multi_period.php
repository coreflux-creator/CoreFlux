<?php
/**
 * Multi-period JE split — accrual-basis revenue/cost recognition.
 *
 * Problem this solves
 * ─────────────────────────────────────────────────────────────────
 *   Operator: "If time is accrued weekly what happens when the
 *   month (GL period) ends on a Wednesday? How about monthly billing
 *   periods but Artemis (four week) GL periods?"
 *
 *   Standard accrual answer: an invoice that spans a GL boundary
 *   must post as TWO journal entries — one in each period — with
 *   `AR Unbilled` (or `AP Accrued`) carrying the bridge.
 *
 * How it works
 * ─────────────────────────────────────────────────────────────────
 *   1. For each invoice / bill, read its lines.
 *   2. For `source_type='time'` lines, walk back to time_downstream_feed
 *      then time_entries to recover the per-day work_date breakdown.
 *      For `source_type='manual'` lines, treat the entire amount as
 *      occurring on the document's issue_date (no work_date data).
 *   3. Resolve each work_date to its accounting_period via the
 *      existing accountingResolvePeriod() helper.
 *   4. Group amounts by accounting_period_id. If only one period
 *      results → degenerate case, post exactly as the legacy
 *      single-JE path (this is the common case for clean monthly
 *      cycles and we don't want to bloat the GL with redundant JEs).
 *   5. If multiple periods → for every NON-issue-date period emit:
 *           Dr AR Unbilled  / Cr Revenue          (accrue revenue)
 *      Then on the issue-date period emit:
 *           Dr AR (full)    / Cr AR Unbilled (prior accrual sum)
 *                            / Cr Revenue (issue period portion)
 *                            / Cr Tax (full)
 *      AP mirrors with AP Accrued + COGS/Expense.
 *
 * Safety
 * ─────────────────────────────────────────────────────────────────
 *   - Gated by accounting_settings.multi_period_split_enabled per
 *     tenant — default OFF so this is a pure additive feature.
 *   - Loud-fails on missing accounting_period (no silent skip).
 *   - Loud-fails on missing AR Unbilled / AP Accrued account row
 *     (per operator: setup mistakes should surface fast, not
 *     queue silently).
 *   - All component JEs share the same `external_ref` so the audit
 *     trail can collapse them into a single conceptual post.
 */
declare(strict_types=1);

require_once __DIR__ . '/accounting.php';

/**
 * Read tenant-level accounting settings, applying defaults for any
 * row not yet inserted. Idempotent and safe to call before the
 * migration has run (returns defaults on missing-table error).
 */
function accountingSettingsGet(int $tenantId): array {
    $defaults = [
        'ar_unbilled_account_code'   => '13100',
        'ap_accrued_account_code'    => '21500',
        'multi_period_split_enabled' => 0,
    ];
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT ar_unbilled_account_code, ap_accrued_account_code, multi_period_split_enabled
               FROM accounting_settings WHERE tenant_id = :t'
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return $defaults;
        return [
            'ar_unbilled_account_code'   => (string) ($row['ar_unbilled_account_code']   ?: $defaults['ar_unbilled_account_code']),
            'ap_accrued_account_code'    => (string) ($row['ap_accrued_account_code']    ?: $defaults['ap_accrued_account_code']),
            'multi_period_split_enabled' => (int)    ($row['multi_period_split_enabled'] ?? 0),
        ];
    } catch (\Throwable $_) {
        return $defaults;
    }
}

/**
 * Group an invoice's revenue lines by work_date.
 *
 * Returns an associative array keyed by 'YYYY-MM-DD' where each
 * value is a per-account-code totals map:
 *   [
 *     '2026-01-31' => ['4000' => 642.86, '2100_tax' => 53.57],
 *     '2026-02-04' => ['4000' => 857.14, '2100_tax' => 71.43],
 *   ]
 *
 * For `source_type='time'` lines we recover per-day fidelity by
 * joining back to time_entries via time_downstream_feed. Each entry
 * contributes hours × bill_rate_snapshot proportional to the line's
 * total. For `source_type='manual'` lines, the entire amount is
 * attributed to the invoice's issue_date.
 */
function accountingBreakdownInvoiceByDate(int $tenantId, int $invoiceId): array {
    $pdo = getDB();
    $inv = $pdo->prepare('SELECT * FROM billing_invoices WHERE tenant_id = :t AND id = :id');
    $inv->execute(['t' => $tenantId, 'id' => $invoiceId]);
    $invoice = $inv->fetch(\PDO::FETCH_ASSOC);
    if (!$invoice) throw new \RuntimeException("Invoice {$invoiceId} not found");

    $lines = $pdo->prepare(
        'SELECT id, source_type, source_ref_id, gl_revenue_account_code,
                subtotal, tax_amount, item_type
           FROM billing_invoice_lines
          WHERE invoice_id = :id'
    );
    $lines->execute(['id' => $invoiceId]);

    $byDate = [];
    foreach ($lines->fetchAll(\PDO::FETCH_ASSOC) as $l) {
        $revCode = (string) ($l['gl_revenue_account_code'] ?: '4000');
        $subtotal = (float) $l['subtotal'];
        $taxAmt   = (float) ($l['tax_amount'] ?? 0);

        if ($l['source_type'] === 'time' && !empty($l['source_ref_id'])) {
            // Pull per-day hours from time_entries via the bundle's
            // (period_id, placement_id). Weight each day's revenue
            // by hours-share so the splits sum exactly back to the
            // line subtotal (avoids rounding drift).
            $dayStmt = $pdo->prepare(
                'SELECT te.work_date, SUM(te.hours) AS hrs
                   FROM time_downstream_feed tdf
                   JOIN time_entries te ON te.period_id = tdf.period_id
                                        AND te.placement_id = tdf.placement_id
                                        AND te.tenant_id    = tdf.tenant_id
                                        AND te.status       = "approved"
                  WHERE tdf.tenant_id = :t AND tdf.id = :bid
                  GROUP BY te.work_date
                  ORDER BY te.work_date'
            );
            $dayStmt->execute(['t' => $tenantId, 'bid' => (int) $l['source_ref_id']]);
            $days = $dayStmt->fetchAll(\PDO::FETCH_ASSOC);
            $totalHrs = array_sum(array_column($days, 'hrs')) ?: 1.0;

            $allocated = 0.0; $taxAlloc = 0.0;
            for ($i = 0, $n = count($days); $i < $n; $i++) {
                $d = $days[$i];
                $share = (float) $d['hrs'] / (float) $totalHrs;
                $rev = ($i === $n - 1) ? round($subtotal - $allocated, 2) : round($subtotal * $share, 2);
                $tax = ($i === $n - 1) ? round($taxAmt   - $taxAlloc,   2) : round($taxAmt   * $share, 2);
                $allocated += $rev; $taxAlloc += $tax;
                $byDate[$d['work_date']][$revCode]    = ($byDate[$d['work_date']][$revCode]    ?? 0.0) + $rev;
                if ($tax > 0.005) {
                    $byDate[$d['work_date']]['__tax'] = ($byDate[$d['work_date']]['__tax']     ?? 0.0) + $tax;
                }
            }
            // Edge case: a time-source line whose bundle has no
            // approved entries left (e.g. all reversed). Fall back
            // to issue_date attribution instead of dropping money.
            if (!$days) {
                $byDate[$invoice['issue_date']][$revCode]   = ($byDate[$invoice['issue_date']][$revCode]   ?? 0.0) + $subtotal;
                if ($taxAmt > 0.005) {
                    $byDate[$invoice['issue_date']]['__tax']= ($byDate[$invoice['issue_date']]['__tax']    ?? 0.0) + $taxAmt;
                }
            }
        } else {
            // Manual line — no time data → entire amount on issue_date.
            $byDate[$invoice['issue_date']][$revCode]   = ($byDate[$invoice['issue_date']][$revCode]   ?? 0.0) + $subtotal;
            if ($taxAmt > 0.005) {
                $byDate[$invoice['issue_date']]['__tax']= ($byDate[$invoice['issue_date']]['__tax']    ?? 0.0) + $taxAmt;
            }
        }
    }
    ksort($byDate);
    return $byDate;
}

/**
 * Group per-date amounts into per-accounting-period amounts.
 * Resolves each date via accountingResolvePeriod() — loud-fails
 * with a useful error pointing at the offending date when no
 * accounting_period covers it.
 *
 * Returns an ordered (by start_date) array of:
 *   [
 *     ['period' => <period_row>, 'amounts' => ['4000' => 642.86, '__tax' => 53.57]],
 *     ...
 *   ]
 */
function accountingGroupBreakdownByPeriod(int $tenantId, int $entityId, array $byDate): array {
    $byPeriod = [];
    foreach ($byDate as $date => $codes) {
        $period = accountingResolvePeriod($tenantId, $entityId, $date);
        if (!$period) {
            throw new \RuntimeException(
                "No accounting_period covers {$date}. Seed periods before posting a document that spans this date — multi-period split refuses to drop revenue silently."
            );
        }
        $pid = (int) $period['id'];
        if (!isset($byPeriod[$pid])) $byPeriod[$pid] = ['period' => $period, 'amounts' => []];
        foreach ($codes as $code => $amt) {
            $byPeriod[$pid]['amounts'][$code] = ($byPeriod[$pid]['amounts'][$code] ?? 0.0) + $amt;
        }
    }
    // Sort by period start_date so the JE batch reads chronologically.
    uasort($byPeriod, static function ($a, $b) {
        return strcmp((string) $a['period']['start_date'], (string) $b['period']['start_date']);
    });
    return array_values($byPeriod);
}

/**
 * Build the JE batch (array of N JE-shaped arrays) for an invoice.
 * Doesn't post — caller wraps each JE in accountingPostJE() inside
 * one outer transaction so a failure rolls everything back.
 *
 * @param array $invoice The billing_invoices row.
 * @param array $perPeriod Output of accountingGroupBreakdownByPeriod().
 * @param string $arUnbilledCode Tenant-configured AR Unbilled code.
 * @return array<int, array{date:string, period_id:int, lines:array}>
 */
function accountingBuildInvoiceJEBatch(array $invoice, array $perPeriod, string $arUnbilledCode): array {
    $issueDate = (string) $invoice['issue_date'];
    $party     = !empty($invoice['client_company_id']) ? (int) $invoice['client_company_id'] : null;
    $invNo     = (string) $invoice['invoice_number'];

    // Identify the period containing issue_date — that's where the
    // AR receivable gets recognised and prior accruals get reversed.
    $issuePeriodIdx = null;
    foreach ($perPeriod as $i => $grp) {
        if ($issueDate >= $grp['period']['start_date'] && $issueDate <= $grp['period']['end_date']) {
            $issuePeriodIdx = $i; break;
        }
    }
    if ($issuePeriodIdx === null) {
        // Issue date falls outside every period that work was performed
        // in — e.g. an invoice cut weeks after the last work_date.
        // Treat the LATEST period containing work as the recognition
        // point. (Alternative: refuse with a setup error — but real
        // operators legitimately issue invoices late.)
        $issuePeriodIdx = count($perPeriod) - 1;
    }

    $batch = [];
    $priorUnbilledByCode = []; // accumulated accruals to reverse on issue period

    foreach ($perPeriod as $idx => $grp) {
        $period = $grp['period'];
        $amounts = $grp['amounts']; // ['<code>' => amount, '__tax' => amount?]
        $isIssue = ($idx === $issuePeriodIdx);
        // Post date: end of the period for accrual JEs; issue_date for
        // the recognition JE. Keeps the period stamp on accruals while
        // letting the receivable land on the operator-chosen date.
        $postDate = $isIssue ? $issueDate : (string) $period['end_date'];

        $tax = (float) ($amounts['__tax'] ?? 0); unset($amounts['__tax']);
        $revenueTotal = 0.0;
        foreach ($amounts as $a) $revenueTotal += (float) $a;
        $periodTotal = $revenueTotal + $tax;

        $lines = [];
        if ($isIssue) {
            // Recognition JE for the issue period:
            //   Dr  AR (full invoice total — accruals turn into real receivable here)
            //   Cr  AR Unbilled (sum of all prior period accruals so unbilled clears)
            //   Cr  Revenue per code (this period's portion)
            //   Cr  Tax (full)
            $fullTotal = (float) $invoice['total'];
            $lines[] = ['account_code' => '1100', 'debit' => round($fullTotal, 2), 'credit' => 0,
                        'memo' => "Inv {$invNo} / {$invoice['client_name']}", 'counterparty_company_id' => $party];
            $priorAccruedSum = 0.0;
            foreach ($priorUnbilledByCode as $c => $a) $priorAccruedSum += $a;
            if ($priorAccruedSum > 0.005) {
                $lines[] = ['account_code' => $arUnbilledCode, 'debit' => 0, 'credit' => round($priorAccruedSum, 2),
                            'memo' => "Clear unbilled accrual — {$invNo}", 'counterparty_company_id' => $party];
            }
            foreach ($amounts as $code => $amt) {
                if (round($amt, 2) <= 0.005) continue;
                $lines[] = ['account_code' => (string) $code, 'debit' => 0, 'credit' => round($amt, 2),
                            'memo' => "Revenue — {$invNo}", 'counterparty_company_id' => $party];
            }
            if ($tax > 0.005) {
                $lines[] = ['account_code' => '2100', 'debit' => 0, 'credit' => round($tax, 2),
                            'memo' => "Sales tax — {$invNo}", 'counterparty_company_id' => $party];
            }
        } else {
            // Accrual JE for a non-issue period:
            //   Dr  AR Unbilled (this period's revenue + tax)
            //   Cr  Revenue per code (this period's portion)
            //   Cr  Tax (this period's portion)
            $lines[] = ['account_code' => $arUnbilledCode, 'debit' => round($periodTotal, 2), 'credit' => 0,
                        'memo' => "Accrue unbilled revenue — {$invNo} period {$period['period_number']}",
                        'counterparty_company_id' => $party];
            foreach ($amounts as $code => $amt) {
                if (round($amt, 2) <= 0.005) continue;
                $lines[] = ['account_code' => (string) $code, 'debit' => 0, 'credit' => round($amt, 2),
                            'memo' => "Revenue (accrued) — {$invNo}", 'counterparty_company_id' => $party];
                $priorUnbilledByCode[$code] = ($priorUnbilledByCode[$code] ?? 0.0) + round($amt, 2);
            }
            if ($tax > 0.005) {
                $lines[] = ['account_code' => '2100', 'debit' => 0, 'credit' => round($tax, 2),
                            'memo' => "Tax (accrued) — {$invNo}", 'counterparty_company_id' => $party];
                $priorUnbilledByCode['__tax'] = ($priorUnbilledByCode['__tax'] ?? 0.0) + round($tax, 2);
            }
        }

        $batch[] = ['date' => $postDate, 'period_id' => (int) $period['id'], 'is_issue_period' => $isIssue, 'lines' => $lines];
    }
    return $batch;
}
