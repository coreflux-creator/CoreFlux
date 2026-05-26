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
 * Ensure the AR Unbilled + AP Accrued accounts exist in this tenant's
 * COA. Idempotent. Called lazily before any multi-period post —
 * cheaper than maintaining a separate seed pathway, and means a
 * tenant flipping `multi_period_split_enabled=1` doesn't have to
 * first remember to seed two account rows manually.
 *
 * Account TYPE is set conservatively: AR Unbilled = `asset` (current
 * receivable contra), AP Accrued = `liability` (accrued payable).
 * Operator can rename / re-classify post-creation; the function
 * never updates an existing row.
 */
function accountingEnsureAccrualAccounts(int $tenantId, array $settings): void {
    $pdo = getDB();
    $rows = [
        [$settings['ar_unbilled_account_code'], 'AR Unbilled (Accrued Revenue)', 'asset'],
        [$settings['ap_accrued_account_code'],  'AP Accrued (Unbilled Costs)',   'liability'],
    ];
    foreach ($rows as [$code, $name, $type]) {
        $exists = $pdo->prepare(
            'SELECT id FROM accounting_accounts WHERE tenant_id = :t AND account_code = :c LIMIT 1'
        );
        $exists->execute(['t' => $tenantId, 'c' => $code]);
        if ($exists->fetch()) continue;
        // Create with `is_active=1`. Sub-ledger flag stays default (0)
        // — these are pure GL accruals, not customer/vendor scoped.
        try {
            $ins = $pdo->prepare(
                'INSERT INTO accounting_accounts (tenant_id, account_code, account_name, account_type, is_active, created_at, updated_at)
                 VALUES (:t, :c, :n, :ty, 1, NOW(), NOW())'
            );
            $ins->execute(['t' => $tenantId, 'c' => $code, 'n' => $name, 'ty' => $type]);
        } catch (\Throwable $e) {
            // Race or schema drift — leave the loud-fail to the JE
            // post itself, which will report "account code X not
            // found" with a much more useful operator-facing message.
            continue;
        }
    }
}

/**
 * AP mirror — group a bill's expense lines by work_date.
 *
 * Same structure as accountingBreakdownInvoiceByDate() but reads
 * ap_bill_lines + walks back through the AP bundle to the
 * underlying time_entries. Manual lines attribute to the bill's
 * `bill_date` (no time fidelity).
 *
 * Returns the same shape:
 *   [ 'YYYY-MM-DD' => ['<expense_code>' => amount, '__tax' => amount?], ... ]
 */
function accountingBreakdownBillByDate(int $tenantId, int $billId): array {
    $pdo = getDB();
    $b   = $pdo->prepare('SELECT * FROM ap_bills WHERE tenant_id = :t AND id = :id');
    $b->execute(['t' => $tenantId, 'id' => $billId]);
    $bill = $b->fetch(\PDO::FETCH_ASSOC);
    if (!$bill) throw new \RuntimeException("Bill {$billId} not found");

    $linesStmt = $pdo->prepare(
        'SELECT id, source_type, source_ref_id, gl_expense_account_code,
                subtotal, tax_amount
           FROM ap_bill_lines WHERE bill_id = :id'
    );
    $linesStmt->execute(['id' => $billId]);

    $byDate = [];
    $billDate = (string) ($bill['bill_date'] ?? $bill['received_date'] ?? date('Y-m-d'));

    foreach ($linesStmt->fetchAll(\PDO::FETCH_ASSOC) as $l) {
        $expCode  = (string) ($l['gl_expense_account_code'] ?: '5000');
        $subtotal = (float) $l['subtotal'];
        $taxAmt   = (float) ($l['tax_amount'] ?? 0);

        if ($l['source_type'] === 'time' && !empty($l['source_ref_id'])) {
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
                $exp = ($i === $n - 1) ? round($subtotal - $allocated, 2) : round($subtotal * $share, 2);
                $tax = ($i === $n - 1) ? round($taxAmt   - $taxAlloc,   2) : round($taxAmt   * $share, 2);
                $allocated += $exp; $taxAlloc += $tax;
                $byDate[$d['work_date']][$expCode] = ($byDate[$d['work_date']][$expCode] ?? 0.0) + $exp;
                if ($tax > 0.005) {
                    $byDate[$d['work_date']]['__tax'] = ($byDate[$d['work_date']]['__tax'] ?? 0.0) + $tax;
                }
            }
            if (!$days) {
                $byDate[$billDate][$expCode] = ($byDate[$billDate][$expCode] ?? 0.0) + $subtotal;
                if ($taxAmt > 0.005) {
                    $byDate[$billDate]['__tax'] = ($byDate[$billDate]['__tax'] ?? 0.0) + $taxAmt;
                }
            }
        } else {
            $byDate[$billDate][$expCode] = ($byDate[$billDate][$expCode] ?? 0.0) + $subtotal;
            if ($taxAmt > 0.005) {
                $byDate[$billDate]['__tax'] = ($byDate[$billDate]['__tax'] ?? 0.0) + $taxAmt;
            }
        }
    }
    ksort($byDate);
    return $byDate;
}

/**
 * Build the JE batch for an AP bill spanning N accounting periods.
 *
 * Mirrors accountingBuildInvoiceJEBatch() with the sides flipped:
 *   Non-bill periods (cost accrued before bill received):
 *       Dr Expense / Cr AP Accrued
 *   Bill-date period (cost recognised on the actual payable):
 *       Dr Expense (this period's portion) / Dr AP Accrued (clear prior accruals) / Cr AP (full)
 *
 * @param array $bill        ap_bills row.
 * @param array $perPeriod   accountingGroupBreakdownByPeriod() output.
 * @param string $apAccrued  Tenant-configured AP Accrued code.
 */
function accountingBuildBillJEBatch(array $bill, array $perPeriod, string $apAccrued): array {
    $billDate = (string) ($bill['bill_date'] ?? $bill['received_date'] ?? date('Y-m-d'));
    $party    = !empty($bill['vendor_company_id']) ? (int) $bill['vendor_company_id'] : null;
    $billNo   = (string) ($bill['bill_number'] ?? $bill['internal_ref'] ?? "BILL-{$bill['id']}");

    // Identify the period containing bill_date — recognition lives there.
    $billPeriodIdx = null;
    foreach ($perPeriod as $i => $grp) {
        if ($billDate >= $grp['period']['start_date'] && $billDate <= $grp['period']['end_date']) {
            $billPeriodIdx = $i; break;
        }
    }
    if ($billPeriodIdx === null) {
        $billPeriodIdx = count($perPeriod) - 1;
    }

    $batch = [];
    $priorAccruedByCode = [];

    foreach ($perPeriod as $idx => $grp) {
        $period = $grp['period'];
        $amounts = $grp['amounts'];
        $isRecognition = ($idx === $billPeriodIdx);
        $postDate = $isRecognition ? $billDate : (string) $period['end_date'];

        $tax = (float) ($amounts['__tax'] ?? 0); unset($amounts['__tax']);
        $expenseTotal = 0.0;
        foreach ($amounts as $a) $expenseTotal += (float) $a;
        $periodTotal  = $expenseTotal + $tax;

        $lines = [];
        if ($isRecognition) {
            // Recognition JE — credit AP for the full payable, debit
            // this period's expense, debit AP Accrued to clear prior
            // accruals.
            $fullTotal = (float) ($bill['total'] ?? ($bill['subtotal'] + $bill['tax_total']));
            foreach ($amounts as $code => $amt) {
                if (round($amt, 2) <= 0.005) continue;
                $lines[] = ['account_code' => (string) $code, 'debit' => round($amt, 2), 'credit' => 0,
                            'memo' => "Expense — {$billNo}", 'counterparty_company_id' => $party];
            }
            if ($tax > 0.005) {
                $lines[] = ['account_code' => '1310', 'debit' => round($tax, 2), 'credit' => 0,
                            'memo' => "Sales tax (input) — {$billNo}", 'counterparty_company_id' => $party];
            }
            $priorAccruedSum = 0.0;
            foreach ($priorAccruedByCode as $a) $priorAccruedSum += $a;
            if ($priorAccruedSum > 0.005) {
                $lines[] = ['account_code' => $apAccrued, 'debit' => round($priorAccruedSum, 2), 'credit' => 0,
                            'memo' => "Clear AP accrual — {$billNo}", 'counterparty_company_id' => $party];
            }
            $lines[] = ['account_code' => '2000', 'debit' => 0, 'credit' => round($fullTotal, 2),
                        'memo' => "Bill {$billNo} / vendor", 'counterparty_company_id' => $party];
        } else {
            // Pre-bill accrual JE: Dr expense / Cr AP Accrued.
            foreach ($amounts as $code => $amt) {
                if (round($amt, 2) <= 0.005) continue;
                $lines[] = ['account_code' => (string) $code, 'debit' => round($amt, 2), 'credit' => 0,
                            'memo' => "Expense (accrued) — {$billNo}", 'counterparty_company_id' => $party];
                $priorAccruedByCode[$code] = ($priorAccruedByCode[$code] ?? 0.0) + round($amt, 2);
            }
            if ($tax > 0.005) {
                $lines[] = ['account_code' => '1310', 'debit' => round($tax, 2), 'credit' => 0,
                            'memo' => "Tax (accrued) — {$billNo}", 'counterparty_company_id' => $party];
                $priorAccruedByCode['__tax'] = ($priorAccruedByCode['__tax'] ?? 0.0) + round($tax, 2);
            }
            $lines[] = ['account_code' => $apAccrued, 'debit' => 0, 'credit' => round($periodTotal, 2),
                        'memo' => "Accrue payable — {$billNo} period {$period['period_number']}",
                        'counterparty_company_id' => $party];
        }

        $batch[] = ['date' => $postDate, 'period_id' => (int) $period['id'], 'is_recognition_period' => $isRecognition, 'lines' => $lines];
    }
    return $batch;
}

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

/* =========================================================================
 * ACCRUAL-AT-APPROVAL MODEL (2026-02 architectural correction)
 * =========================================================================
 *
 * Per the operator's correction: timesheet approval is the recognition
 * event, NOT invoice/bill posting. When a `time_downstream_feed` bundle
 * lands in status='ready' (the period has been built / approved), we
 * immediately post per-period accrual JEs:
 *
 *   bundle_type='ar' →  Dr AR Unbilled / Cr Revenue (per accounting_period)
 *   bundle_type='ap' →  Dr Expense     / Cr AP Accrued (per accounting_period)
 *
 * Later, when the invoice/bill is posted to GL, it becomes a pure
 * RECLASSIFICATION (single JE on issue_date / bill_date):
 *
 *   Invoice → Dr Accounts Receivable / Cr AR Unbilled / Cr Sales Tax
 *   Bill    → Dr AP Accrued / Dr Input Tax / Cr Accounts Payable
 *
 * The multi-period split happens at the ACCRUAL step, not the document
 * posting step. This matches accrual-basis GAAP: revenue/expense recognised
 * when earned/incurred (work performed), receivable/payable recognised
 * when invoiced/billed.
 *
 * The legacy helpers `accountingBuildInvoiceJEBatch` and
 * `accountingBuildBillJEBatch` above are DEPRECATED — they bundle
 * recognition + accrual on the document post, which would double-recognise
 * revenue/expense if combined with the accrual-at-approval poster below.
 * They remain in the file for backward compatibility with existing test
 * scaffolding but are no longer wired into the production endpoints.
 * ========================================================================= */

/**
 * Group a time_downstream_feed bundle's underlying entries by work_date.
 *
 * Distributes the bundle's headline amount (total_amount_bill for ar,
 * total_amount_pay for ap) across work_dates proportional to billable
 * hours per day so the per-period accrual JEs sum back exactly to the
 * bundle total (no rounding drift).
 *
 * Returns:
 *   [ 'YYYY-MM-DD' => amount_in_dollars, ... ]   (chronological)
 *
 * @param string $bundleType  'ar' or 'ap' — selects the amount column.
 * @throws \RuntimeException if bundle missing or unknown bundle_type.
 */
function accountingBreakdownBundleByDate(int $tenantId, int $bundleId, string $bundleType): array {
    if (!in_array($bundleType, ['ar', 'ap'], true)) {
        throw new \RuntimeException("Bundle accrual only supports ar/ap, got '{$bundleType}'");
    }
    $pdo = getDB();
    $b = $pdo->prepare(
        'SELECT id, tenant_id, period_id, placement_id, bundle_type,
                total_amount_bill, total_amount_pay, entries_json
           FROM time_downstream_feed
          WHERE tenant_id = :t AND id = :id'
    );
    $b->execute(['t' => $tenantId, 'id' => $bundleId]);
    $bundle = $b->fetch(\PDO::FETCH_ASSOC);
    if (!$bundle) throw new \RuntimeException("Bundle {$bundleId} not found");

    $total = $bundleType === 'ar'
        ? (float) $bundle['total_amount_bill']
        : (float) $bundle['total_amount_pay'];
    if (round($total, 2) <= 0.005) {
        // Zero-bill bundles are valid (e.g. all PTO) — caller should
        // skip accrual entirely. Return empty map (signal to caller).
        return [];
    }

    // Pull the billable-hours per work_date for this bundle's
    // (period_id, placement_id). Mirrors the join used by
    // accountingBreakdownInvoiceByDate(); restricted to billable
    // categories because non-billable PTO/unpaid hours don't drive
    // revenue or cost.
    $dayStmt = $pdo->prepare(
        'SELECT te.work_date, SUM(te.hours) AS hrs
           FROM time_entries te
          WHERE te.tenant_id    = :t
            AND te.period_id    = :pid
            AND te.placement_id = :plid
            AND te.status       = "approved"
            AND te.category     IN ("regular_billable","OT_billable")
          GROUP BY te.work_date
          ORDER BY te.work_date'
    );
    $dayStmt->execute([
        't'    => $tenantId,
        'pid'  => (int) $bundle['period_id'],
        'plid' => (int) $bundle['placement_id'],
    ]);
    $days = $dayStmt->fetchAll(\PDO::FETCH_ASSOC);
    if (!$days) return [];

    $totalHrs = (float) array_sum(array_column($days, 'hrs'));
    if ($totalHrs <= 0) return [];

    $byDate    = [];
    $allocated = 0.0;
    $n         = count($days);
    foreach ($days as $i => $d) {
        $share = (float) $d['hrs'] / $totalHrs;
        // Last day absorbs rounding so the sum is exactly $total.
        $amt   = ($i === $n - 1) ? round($total - $allocated, 2) : round($total * $share, 2);
        $allocated += $amt;
        $byDate[$d['work_date']] = $amt;
    }
    ksort($byDate);
    return $byDate;
}

/**
 * Post accrual JEs for a time_downstream_feed bundle, split per
 * accounting_period the underlying work_dates touch.
 *
 * Idempotent: each per-period JE uses an idempotency_key of
 *   "time:bundle:<bundleId>:accrual:<periodId>"
 * so re-running (e.g. after a bundle rebuild on entry correction)
 * does not double-post. The caller is responsible for posting a
 * reversal JE if the bundle is superseded.
 *
 * Gated by tenant flag `multi_period_split_enabled` — caller checks
 * before invoking this function.
 *
 * @param string $bundleType 'ar' or 'ap' — only types eligible for accrual.
 * @return array Per-period results: [['period_id'=>X, 'je_id'=>Y, 'idempotent_replay'=>bool], ...]
 */
function accountingPostBundleAccrual(int $tenantId, int $bundleId, string $bundleType, ?int $actorUserId = null): array {
    if (!in_array($bundleType, ['ar', 'ap'], true)) {
        return []; // payroll/revrec/superseded bundles don't drive GL accrual
    }
    $settings = accountingSettingsGet($tenantId);
    accountingEnsureAccrualAccounts($tenantId, $settings);

    $pdo = getDB();
    $b = $pdo->prepare(
        'SELECT id, period_id, placement_id, bundle_type, status,
                total_amount_bill, total_amount_pay
           FROM time_downstream_feed
          WHERE tenant_id = :t AND id = :id'
    );
    $b->execute(['t' => $tenantId, 'id' => $bundleId]);
    $bundle = $b->fetch(\PDO::FETCH_ASSOC);
    if (!$bundle) return [];

    $byDate = accountingBreakdownBundleByDate($tenantId, $bundleId, $bundleType);
    if (!$byDate) return []; // zero-bill or no billable hours — nothing to accrue

    // Reshape into the form accountingGroupBreakdownByPeriod expects.
    // It accepts ['YYYY-MM-DD' => ['<code>' => amount, ...]], so we
    // route the accrual amount under a synthetic key '__accrual'.
    $byDateCoded = [];
    foreach ($byDate as $date => $amt) {
        $byDateCoded[$date] = ['__accrual' => $amt];
    }
    // Resolve placement → entity_id (multi-entity tenants route the
    // accrual to the placement's owning entity).
    $entityStmt = $pdo->prepare(
        'SELECT entity_id FROM placements WHERE tenant_id = :t AND id = :id LIMIT 1'
    );
    try { $entityStmt->execute(['t' => $tenantId, 'id' => (int) $bundle['placement_id']]); } catch (\Throwable $_) {}
    $entityId = (int) ($entityStmt ? ($entityStmt->fetchColumn() ?: 0) : 0);

    $perPeriod = accountingGroupBreakdownByPeriod($tenantId, $entityId, $byDateCoded);

    // Resolve counterparty: AR bundle → client; AP bundle → vendor.
    // We pull both via a single placement join. Both may be NULL —
    // accountingPostJe accepts that.
    $partyStmt = $pdo->prepare(
        'SELECT end_client_company_id, person_id
           FROM placements WHERE tenant_id = :t AND id = :id LIMIT 1'
    );
    $partyStmt->execute(['t' => $tenantId, 'id' => (int) $bundle['placement_id']]);
    $partyRow = $partyStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    $partyCompanyId = $bundleType === 'ar' && !empty($partyRow['end_client_company_id'])
        ? (int) $partyRow['end_client_company_id'] : null;

    $arUnbilled = (string) $settings['ar_unbilled_account_code'];
    $apAccrued  = (string) $settings['ap_accrued_account_code'];
    $revenueCode = '4000';
    $expenseCode = '5000';

    $results = [];
    foreach ($perPeriod as $grp) {
        $period = $grp['period'];
        $amt    = (float) ($grp['amounts']['__accrual'] ?? 0);
        if (round($amt, 2) <= 0.005) continue;

        if ($bundleType === 'ar') {
            $lines = [
                ['account_code' => $arUnbilled,  'debit' => round($amt, 2), 'credit' => 0,
                 'memo' => "Accrue unbilled revenue — bundle #{$bundleId} period {$period['period_number']}",
                 'counterparty_company_id' => $partyCompanyId],
                ['account_code' => $revenueCode, 'debit' => 0, 'credit' => round($amt, 2),
                 'memo' => "Revenue (work performed) — bundle #{$bundleId}",
                 'counterparty_company_id' => $partyCompanyId],
            ];
        } else { // 'ap'
            $lines = [
                ['account_code' => $expenseCode, 'debit' => round($amt, 2), 'credit' => 0,
                 'memo' => "Accrue cost — bundle #{$bundleId} period {$period['period_number']}",
                 'counterparty_company_id' => null],
                ['account_code' => $apAccrued,   'debit' => 0, 'credit' => round($amt, 2),
                 'memo' => "AP accrued (work performed) — bundle #{$bundleId}",
                 'counterparty_company_id' => null],
            ];
        }

        $res = accountingPostJe($tenantId, [
            // Accruals land on the period's end_date so the GL stamp is
            // unambiguous; the work_date is preserved in the memo line.
            'posting_date'    => (string) $period['end_date'],
            'currency'        => 'USD',
            'source_module'   => $bundleType === 'ar' ? 'time' : 'time',
            'source_ref_type' => 'time_bundle',
            'source_ref_id'   => $bundleId,
            'idempotency_key' => sprintf('time:bundle:%d:accrual:%d', $bundleId, (int) $period['id']),
            'memo'            => "Bundle #{$bundleId} accrual — period {$period['period_number']} ({$bundleType})",
            'lines'           => $lines,
        ], $actorUserId, true);
        $results[] = [
            'period_id'         => (int) $period['id'],
            'je_id'             => (int) ($res['je_id'] ?? 0),
            'idempotent_replay' => (bool) ($res['idempotent_replay'] ?? false),
            'amount'            => round($amt, 2),
        ];
    }
    return $results;
}
