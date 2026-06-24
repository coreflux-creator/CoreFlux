<?php
/**
 * Billing Module — Phase A0 library.
 *
 * Pure functions only. Controllers live in /api. SPEC reference:
 * /app/modules/billing/SPEC.md (§3.3-§3.8, §9 validation, §11/12 decisions).
 */

require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/../../../core/audit.php';

/**
 * Atomically allocate the next invoice number for a tenant.
 * Format: {prefix}-{YYYY}-{NNNN}, where NNNN is zero-padded to ≥4 digits.
 */
function billingNextInvoiceNumber(int $tenantId): string
{
    $pdo = getDB();
    // Nested-safe: Create-Invoice endpoint wraps this call in a tx.
    $ownsTxn = !$pdo->inTransaction();
    if ($ownsTxn) $pdo->beginTransaction();
    try {
        $row = $pdo->prepare(
            'SELECT billing_invoice_prefix, billing_next_invoice_seq
             FROM tenants WHERE id = :id FOR UPDATE'
        );
        $row->execute(['id' => $tenantId]);
        $r = $row->fetch(\PDO::FETCH_ASSOC);
        if (!$r) throw new \RuntimeException("tenant {$tenantId} not found");
        $prefix = trim((string) ($r['billing_invoice_prefix'] ?? 'INV')) ?: 'INV';
        $seq = (int) $r['billing_next_invoice_seq'];

        $pdo->prepare('UPDATE tenants SET billing_next_invoice_seq = :n WHERE id = :id')
            ->execute(['n' => $seq + 1, 'id' => $tenantId]);
        if ($ownsTxn) $pdo->commit();

        return sprintf('%s-%s-%04d', $prefix, date('Y'), $seq);
    } catch (\Throwable $e) {
        if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Build draft invoice header(s) + lines from a closed time period's `ar`
 * bundles. Returns an array of {invoice, lines} ready for insertion.
 *
 *   $aggregation = 'per_placement' (default) → one invoice per placement
 *                = 'per_client'              → one invoice per client (lines per placement)
 *
 * Refuses bundles that are not 'ready' (already consumed / superseded /
 * locked). Refuses bundle types other than 'ar'.
 */
function billingBuildDraftFromBundle(int $tenantId, int $periodId, array $placementIds, string $aggregation = 'per_placement'): array
{
    if (!in_array($aggregation, ['per_placement', 'per_client'], true)) {
        throw new \InvalidArgumentException("invalid aggregation: {$aggregation}");
    }
    if (empty($placementIds)) {
        throw new \InvalidArgumentException('placement_ids required');
    }

    $pdo = getDB();
    $period = scopedFind('SELECT * FROM time_periods WHERE tenant_id = :tenant_id AND id = :id', ['id' => $periodId]);
    if (!$period) throw new \RuntimeException("period {$periodId} not found");

    $tenant = $pdo->prepare('SELECT billing_tax_rate_pct, billing_invoice_terms FROM tenants WHERE id = :id');
    $tenant->execute(['id' => $tenantId]);
    $tcfg = $tenant->fetch(\PDO::FETCH_ASSOC) ?: [];
    $taxPct = (float) ($tcfg['billing_tax_rate_pct'] ?? 0);
    $terms  = (string) ($tcfg['billing_invoice_terms'] ?? 'NET30');

    $netDays = 30;
    if (preg_match('/^NET(\d+)$/i', $terms, $m)) $netDays = (int) $m[1];

    $placeholders = [];
    $params = ['per' => $periodId];
    foreach (array_values($placementIds) as $i => $pid) {
        $k = 'p' . $i;
        $placeholders[] = ':' . $k;
        $params[$k] = (int) $pid;
    }

    $bundles = scopedQuery(
        'SELECT tdf.*, p.title AS placement_title, p.end_client_name,
                p.bill_to_address_json, p.person_id, pe.first_name, pe.last_name
         FROM time_downstream_feed tdf
         LEFT JOIN placements p ON p.id = tdf.placement_id AND p.tenant_id = tdf.tenant_id
         LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = p.tenant_id
         WHERE tdf.tenant_id = :tenant_id
           AND tdf.period_id = :per
           AND tdf.bundle_type = "ar"
           AND tdf.placement_id IN (' . implode(',', $placeholders) . ')',
        $params
    );
    if (empty($bundles)) {
        throw new \RuntimeException('No AR bundles found for the given period+placements.');
    }
    foreach ($bundles as $b) {
        if ($b['status'] !== 'ready') {
            throw new \RuntimeException(
                "Bundle #{$b['id']} (placement {$b['placement_id']}) is '{$b['status']}', not 'ready'. "
                . ($b['consumed_by_module'] ? "Already consumed by {$b['consumed_by_module']} #{$b['consumed_ref_id']}." : '')
            );
        }
    }

    // Group bundles
    $groups = [];
    foreach ($bundles as $b) {
        $key = $aggregation === 'per_client'
            ? 'C:' . (string) ($b['end_client_name'] ?? 'Unknown Client')
            : 'P:' . (int) $b['placement_id'];
        $groups[$key][] = $b;
    }

    $today = date('Y-m-d');
    $dueDate = date('Y-m-d', strtotime("+{$netDays} days"));
    $invoices = [];

    foreach ($groups as $key => $groupBundles) {
        $first = $groupBundles[0];
        $client = (string) ($first['end_client_name'] ?? 'Unknown Client');
        $billTo = $first['bill_to_address_json'] ?? null;

        $lines = [];
        $lineNo = 1;
        $subtotal = 0.0; $taxTotal = 0.0;

        foreach ($groupBundles as $b) {
            $hours = (float) $b['total_hours_billable'];
            if ($hours <= 0) continue;
            $rate  = $hours > 0 ? round((float) $b['total_amount_bill'] / $hours, 4) : 0.0;
            $sub   = round($hours * $rate, 2);
            $tax   = round($sub * ($taxPct / 100), 2);
            $total = round($sub + $tax, 2);

            $consultant = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: 'Consultant';
            $desc = sprintf(
                '%s — %s (period %s → %s)',
                $b['placement_title'] ?: ('Placement #' . $b['placement_id']),
                $consultant,
                $period['start_date'],
                $period['end_date']
            );

            $lines[] = [
                'line_no'          => $lineNo++,
                'source_type'      => 'time',
                'item_type'        => 'labor',
                'source_ref_id'    => (int) $b['id'],
                'placement_id'     => (int) $b['placement_id'],
                'rate_snapshot_id' => $b['rate_snapshot_id'] ? (int) $b['rate_snapshot_id'] : null,
                'description'      => $desc,
                'quantity'         => round($hours, 4),
                'unit'             => 'hour',
                'unit_price'       => $rate,
                'subtotal'         => $sub,
                'tax_rate_pct'     => $taxPct,
                'tax_amount'       => $tax,
                'total'            => $total,
            ];
            $subtotal += $sub;
            $taxTotal += $tax;
        }

        if (empty($lines)) continue; // group had only zero-hour bundles

        $invoices[] = [
            'invoice' => [
                'client_name'   => $client,
                'bill_to_json'  => $billTo,
                'currency'      => 'USD',
                'issue_date'    => $today,
                'due_date'      => $dueDate,
                'period_start'  => $period['start_date'],
                'period_end'    => $period['end_date'],
                'subtotal'      => round($subtotal, 2),
                'tax_total'     => round($taxTotal, 2),
                'total'         => round($subtotal + $taxTotal, 2),
                'amount_due'    => round($subtotal + $taxTotal, 2),
                'aggregation'   => $aggregation,
                'status'        => 'draft',
            ],
            'lines'        => $lines,
            'bundle_ids'   => array_map(fn ($l) => $l['source_ref_id'], $lines),
        ];
    }

    return $invoices;
}

/**
 * Batch 4 (2026-02) — Build draft invoice(s) from a flat list of
 * `time_entries` IDs, bypassing the bundle abstraction.  Gives operators
 * day-level granularity: "bill these specific approved entries", without
 * needing to wait for period-close bundle build.
 *
 * Each entry's bill rate is looked up via `placementCurrentRate()` for
 * its `work_date`.  Entries that are not approved-and-billable are
 * rejected.
 *
 *   $aggregation = 'per_day'        → one invoice per (placement, work_date)
 *                = 'per_placement'  → one invoice per placement (lines per day)
 *                = 'per_client'     → one invoice per client (lines per placement)
 *
 * Returns the same shape as `billingBuildDraftFromBundle()` so the
 * controller can reuse the persist loop.  `bundle_ids` is empty for
 * entry-driven drafts; lines carry `source_type='time_entry'` +
 * `source_ref_id=time_entries.id` instead so the audit trail still tells
 * the post-close auditor which entries fed the invoice.
 */
function billingBuildDraftFromTimeEntries(int $tenantId, array $timeEntryIds, string $aggregation = 'per_day'): array
{
    if (!in_array($aggregation, ['per_day', 'per_placement', 'per_client'], true)) {
        throw new \InvalidArgumentException("invalid aggregation: {$aggregation}");
    }
    $ids = array_values(array_unique(array_map('intval', $timeEntryIds)));
    $ids = array_values(array_filter($ids, static fn ($n) => $n > 0));
    if (empty($ids)) throw new \InvalidArgumentException('time_entry_ids required');
    if (count($ids) > 500) throw new \InvalidArgumentException('Too many time_entry_ids (max 500 per call)');

    require_once __DIR__ . '/../../placements/lib/placements.php';

    $pdo = getDB();
    $tenant = $pdo->prepare('SELECT billing_tax_rate_pct, billing_invoice_terms FROM tenants WHERE id = :id');
    $tenant->execute(['id' => $tenantId]);
    $tcfg   = $tenant->fetch(\PDO::FETCH_ASSOC) ?: [];
    $taxPct = (float) ($tcfg['billing_tax_rate_pct'] ?? 0);
    $terms  = (string) ($tcfg['billing_invoice_terms'] ?? 'NET30');
    $netDays = 30;
    if (preg_match('/^NET(\d+)$/i', $terms, $m)) $netDays = (int) $m[1];

    // Fetch entries + placement + person joined.
    $placeholders = [];
    $params = [];
    foreach ($ids as $i => $id) {
        $k = 'e' . $i;
        $placeholders[] = ':' . $k;
        $params[$k] = $id;
    }
    $entries = scopedQuery(
        'SELECT te.id, te.placement_id, te.person_id, te.work_date, te.hour_type,
                te.hours, te.billable, te.payable, te.status, te.description,
                p.title AS placement_title, p.end_client_name,
                pe.first_name, pe.last_name
           FROM time_entries te
      LEFT JOIN placements p  ON p.id = te.placement_id AND p.tenant_id = te.tenant_id
      LEFT JOIN people     pe ON pe.id = te.person_id   AND pe.tenant_id = te.tenant_id
          WHERE te.tenant_id = :tenant_id
            AND te.id IN (' . implode(',', $placeholders) . ')
          ORDER BY te.placement_id, te.work_date, te.id',
        $params
    );
    if (empty($entries)) throw new \RuntimeException('No matching time_entries found');

    // Validate every entry is approved + billable. Non-billable rows are
    // silently dropped (not an error — operator probably selected them by
    // accident from a mixed picker).
    $billable = [];
    foreach ($entries as $e) {
        if ((int) $e['billable'] !== 1) continue;
        if (!in_array((string) $e['status'], ['approved','locked','billing_ready','payroll_ready'], true)) {
            throw new \RuntimeException(
                "Entry #{$e['id']} (placement {$e['placement_id']}, {$e['work_date']}) status '{$e['status']}' — only approved entries can be invoiced."
            );
        }
        if ((float) $e['hours'] <= 0) continue;
        $billable[] = $e;
    }
    if (empty($billable)) throw new \RuntimeException('Selected entries have no billable hours');

    // Resolve rate for each entry (cached per placement+date).
    $rateCache = [];
    foreach ($billable as &$e) {
        $key = $e['placement_id'] . ':' . $e['work_date'];
        if (!isset($rateCache[$key])) {
            $rateCache[$key] = placementCurrentRate((int) $e['placement_id'], (string) $e['work_date']);
        }
        $rate = $rateCache[$key];
        $base = (float) ($rate['bill_rate'] ?? 0);
        $ot   = (float) ($rate['ot_multiplier'] ?? 1.5);
        $dt   = (float) ($rate['dt_multiplier'] ?? 2.0);
        $mult = match ((string) $e['hour_type']) {
            'overtime'   => $ot,
            'doubletime' => $dt,
            default      => 1.0,
        };
        $e['_bill_rate']       = round($base * $mult, 4);
        $e['_rate_snapshot_id']= $rate['id'] ?? null;
    }
    unset($e);

    // Group keys
    $groups = [];
    foreach ($billable as $e) {
        $key = match ($aggregation) {
            'per_day'       => 'D:' . $e['placement_id'] . ':' . $e['work_date'],
            'per_placement' => 'P:' . $e['placement_id'],
            'per_client'    => 'C:' . ((string) ($e['end_client_name'] ?? 'Unknown Client')),
        };
        $groups[$key][] = $e;
    }

    $today   = date('Y-m-d');
    $dueDate = date('Y-m-d', strtotime("+{$netDays} days"));
    $invoices = [];
    foreach ($groups as $key => $rows) {
        $first = $rows[0];
        $client = (string) ($first['end_client_name'] ?? 'Unknown Client');
        $billTo = null;  // bill-to address json populated server-side by approval gate, not at draft time.

        $lines = [];
        $lineNo = 1;
        $subtotal = 0.0; $taxTotal = 0.0;
        $minDate = null; $maxDate = null;

        // Day-grouping → one line per hour_type (so OT/regular show separately).
        // Placement / client grouping → one line per (placement, work_date, hour_type)
        // collapsed.
        $linesByKey = [];
        foreach ($rows as $r) {
            $lk = $r['placement_id'] . '|' . $r['work_date'] . '|' . $r['hour_type'];
            if (!isset($linesByKey[$lk])) {
                $linesByKey[$lk] = [
                    'placement_id'    => (int) $r['placement_id'],
                    'work_date'       => (string) $r['work_date'],
                    'hour_type'       => (string) $r['hour_type'],
                    'placement_title' => (string) ($r['placement_title'] ?? ''),
                    'first_name'      => (string) ($r['first_name'] ?? ''),
                    'last_name'       => (string) ($r['last_name'] ?? ''),
                    'bill_rate'       => (float) $r['_bill_rate'],
                    'rate_snapshot_id'=> $r['_rate_snapshot_id'] ?? null,
                    'hours'           => 0.0,
                    'source_refs'     => [],
                ];
            }
            $linesByKey[$lk]['hours']      += (float) $r['hours'];
            $linesByKey[$lk]['source_refs'][] = (int) $r['id'];
            if (!$minDate || strcmp((string) $r['work_date'], $minDate) < 0) $minDate = (string) $r['work_date'];
            if (!$maxDate || strcmp((string) $r['work_date'], $maxDate) > 0) $maxDate = (string) $r['work_date'];
        }
        ksort($linesByKey);

        foreach ($linesByKey as $lk => $ld) {
            $sub   = round($ld['hours'] * $ld['bill_rate'], 2);
            $tax   = round($sub * ($taxPct / 100), 2);
            $total = round($sub + $tax, 2);
            $consultant = trim($ld['first_name'] . ' ' . $ld['last_name']) ?: 'Consultant';
            $desc = sprintf(
                '%s — %s · %s · %s',
                $ld['placement_title'] ?: ('Placement #' . $ld['placement_id']),
                $consultant,
                $ld['work_date'],
                $ld['hour_type']
            );
            // Each entry is a separate source_ref row so the audit trail
            // preserves entry → line mapping. The first entry in the
            // (placement, day, hour_type) bucket is the "primary" line
            // source_ref; supplementary refs ride along in the
            // description for visibility.
            $primaryRef = $ld['source_refs'][0];
            $lines[] = [
                'line_no'          => $lineNo++,
                'source_type'      => 'time_entry',
                'item_type'        => 'labor',
                'source_ref_id'    => $primaryRef,
                'placement_id'     => $ld['placement_id'],
                'rate_snapshot_id' => $ld['rate_snapshot_id'] ? (int) $ld['rate_snapshot_id'] : null,
                'description'      => $desc . (count($ld['source_refs']) > 1 ? ' (' . count($ld['source_refs']) . ' entries)' : ''),
                'quantity'         => round($ld['hours'], 4),
                'unit'             => 'hour',
                'unit_price'       => $ld['bill_rate'],
                'subtotal'         => $sub,
                'tax_rate_pct'     => $taxPct,
                'tax_amount'       => $tax,
                'total'            => $total,
                '_entry_ids'       => $ld['source_refs'],
            ];
            $subtotal += $sub;
            $taxTotal += $tax;
        }
        if (empty($lines)) continue;

        $invoices[] = [
            'invoice' => [
                'client_name'   => $client,
                'bill_to_json'  => $billTo,
                'currency'      => 'USD',
                'issue_date'    => $today,
                'due_date'      => $dueDate,
                'period_start'  => $minDate ?? $today,
                'period_end'    => $maxDate ?? $today,
                'subtotal'      => round($subtotal, 2),
                'tax_total'     => round($taxTotal, 2),
                'total'         => round($subtotal + $taxTotal, 2),
                'amount_due'    => round($subtotal + $taxTotal, 2),
                'aggregation'   => $aggregation,
                'status'        => 'draft',
            ],
            'lines'        => $lines,
            'bundle_ids'   => [],   // entry-driven, no bundle consumption
            'entry_ids'    => array_merge(...array_column($lines, '_entry_ids')),
        ];
    }
    return $invoices;
}

/**
 * Batch 4+ (2026-02) — AI-assisted invoice suggestion per placement.
 *
 * Given a placement, find every approved billable entry since the last
 * invoice was raised for that placement, then propose:
 *   - An aggregation strategy (rule-based, not AI):
 *       • span ≤ 7 days       → per_placement (consolidated weekly)
 *       • > 7 days, 1 worker  → per_day        (each day billable)
 *       • > 7 days, multiple  → per_placement  (still consolidated)
 *   - A human-friendly memo (AI when available, deterministic fallback).
 *   - A preview of the total amount + entry breakdown.
 *
 * The function does NOT create the invoice — it returns a suggestion the
 * operator confirms via the existing from-time-entries POST.
 */
function billingSuggestInvoiceForPlacement(int $tenantId, int $placementId, ?int $userId = null): array
{
    require_once __DIR__ . '/../../placements/lib/placements.php';
    $pdo = getDB();

    // Resolve the placement (and quietly fail if it doesn't exist or
    // belongs to a different tenant).
    $pl = scopedFind(
        'SELECT id, title, end_client_name, person_id, engagement_type
           FROM placements WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
        ['id' => $placementId]
    );
    if (!$pl) throw new \RuntimeException('Placement not found');

    // Look up the last invoice for this placement (via any line that
    // referenced one of its bundles or entries) so we don't double-bill.
    $lastInv = scopedFind(
        'SELECT MAX(i.issue_date) AS last_invoice_date
           FROM billing_invoices i
           JOIN billing_invoice_lines l ON l.invoice_id = i.id
          WHERE i.tenant_id = :tenant_id
            AND l.placement_id = :pid
            AND i.status NOT IN ("void")',
        ['pid' => $placementId]
    );
    $cutoff = $lastInv['last_invoice_date'] ?? null;

    // Pull every approved billable entry for this placement since the
    // cutoff. Same status filter as the from-time-entries flow.
    $where  = [
        'te.tenant_id = :tenant_id',
        'te.placement_id = :pid',
        "te.status IN ('approved','locked','billing_ready','payroll_ready')",
        'te.billable = 1',
        'te.hours > 0',
    ];
    $params = ['pid' => $placementId];
    if ($cutoff) { $where[] = 'te.work_date > :cutoff'; $params['cutoff'] = $cutoff; }
    $entries = scopedQuery(
        'SELECT te.id, te.work_date, te.hour_type, te.hours, te.person_id, te.description,
                p.first_name, p.last_name
           FROM time_entries te
      LEFT JOIN people p ON p.id = te.person_id AND p.tenant_id = te.tenant_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY te.work_date ASC, te.id ASC
          LIMIT 500',
        $params
    );

    // Compute previews using placementCurrentRate per (placement, work_date).
    $rateCache = [];
    $totalHours = 0.0; $estSubtotal = 0.0;
    $workersSeen = [];
    $minDate = null; $maxDate = null;
    foreach ($entries as $e) {
        $key = $placementId . ':' . $e['work_date'];
        if (!isset($rateCache[$key])) {
            $rateCache[$key] = placementCurrentRate($placementId, (string) $e['work_date']);
        }
        $rate = $rateCache[$key];
        $base = (float) ($rate['bill_rate'] ?? 0);
        $ot   = (float) ($rate['ot_multiplier'] ?? 1.5);
        $dt   = (float) ($rate['dt_multiplier'] ?? 2.0);
        $mult = match ((string) $e['hour_type']) {
            'overtime'   => $ot,
            'doubletime' => $dt,
            default      => 1.0,
        };
        $billRate = round($base * $mult, 4);
        $hours    = (float) $e['hours'];
        $totalHours += $hours;
        $estSubtotal += round($hours * $billRate, 2);
        if ($e['person_id']) $workersSeen[(int) $e['person_id']] = true;
        if (!$minDate || strcmp($e['work_date'], $minDate) < 0) $minDate = $e['work_date'];
        if (!$maxDate || strcmp($e['work_date'], $maxDate) > 0) $maxDate = $e['work_date'];
    }

    // Rule-based aggregation pick.
    $daySpan = ($minDate && $maxDate)
        ? max(1, (int) ((strtotime($maxDate) - strtotime($minDate)) / 86400) + 1)
        : 1;
    $distinctDays = count(array_unique(array_column($entries, 'work_date')));
    $workerCount  = count($workersSeen);
    if ($daySpan <= 7) {
        $aggregation = 'per_placement';
        $reasoning   = "Entries span {$daySpan} day(s) with {$distinctDays} working day(s) — recommend a single consolidated invoice for the period.";
    } elseif ($workerCount === 1 && $distinctDays > 7) {
        $aggregation = 'per_day';
        $reasoning   = "{$distinctDays} working days across {$daySpan} day(s) for a single worker — recommend one invoice per day so the client can match against their own time logs.";
    } else {
        $aggregation = 'per_placement';
        $reasoning   = "{$distinctDays} working days, {$workerCount} worker(s), {$daySpan}-day span — recommend a single consolidated invoice grouped by placement (lines split by day + hour-type).";
    }

    // Build the deterministic memo as a guaranteed fallback. AI step
    // augments it when the tenant has AI enabled.
    $clientName = (string) ($pl['end_client_name'] ?? 'Client');
    $plTitle    = (string) ($pl['title'] ?? 'Engagement');
    $detMemo = sprintf(
        'Services rendered for %s — %s (%s → %s, %d entries, %.2fh).',
        $clientName, $plTitle, $minDate ?? '—', $maxDate ?? '—',
        count($entries), $totalHours
    );

    $aiUsed = false;
    $aiMemo = null;
    if (count($entries) > 0) {
        try {
            require_once __DIR__ . '/../../../core/ai_service.php';
            $env = aiAsk([
                'feature_class'     => 'suggestion',
                'kind'              => 'suggestion',
                'feature_key'       => 'billing.invoice.suggest_memo',
                'system'            => 'You write short professional invoice memos. Maximum 2 sentences, ~30 words. Reference the client by name, mention the engagement, and the period range. Do NOT include dollar amounts or rates.',
                'prompt'            => "Draft an invoice memo for client {$clientName} for engagement '{$plTitle}', period {$minDate} to {$maxDate}, covering {$distinctDays} working days.",
                'context'           => [
                    'client_name'     => $clientName,
                    'placement_title' => $plTitle,
                    'period_start'    => $minDate,
                    'period_end'      => $maxDate,
                    'distinct_days'   => $distinctDays,
                    'workers'         => $workerCount,
                ],
                'max_output_tokens' => 120,
            ]);
            $aiMemo = trim((string) ($env['content'] ?? ''));
            $aiUsed = $aiMemo !== '' && empty($env['sim']) === false ? true : true;
        } catch (\Throwable $_) {
            $aiUsed = false;
        }
    }

    return [
        'placement' => [
            'id'              => (int) $pl['id'],
            'title'           => $plTitle,
            'client_name'     => $clientName,
            'engagement_type' => $pl['engagement_type'] ?? null,
        ],
        'last_invoice_date' => $cutoff,
        'period' => [
            'min_date'      => $minDate,
            'max_date'      => $maxDate,
            'day_span'      => $daySpan,
            'distinct_days' => $distinctDays,
            'worker_count'  => $workerCount,
        ],
        'candidate_entries'    => $entries,
        'candidate_entry_ids'  => array_map(static fn ($e) => (int) $e['id'], $entries),
        'total_hours'          => round($totalHours, 2),
        'estimated_subtotal'   => round($estSubtotal, 2),
        'suggested_aggregation'=> $aggregation,
        'suggested_reasoning'  => $reasoning,
        'suggested_memo'       => $aiMemo ?: $detMemo,
        'ai_used'              => $aiUsed && $aiMemo !== null && $aiMemo !== '',
    ];
}

/**
 * Apply a flat tax rate to each line, returning recomputed totals.
 * Used when manually editing draft lines (PATCH) before approve.
 */
function billingComputeTax(array $lines, float $taxPct): array
{
    $sub = 0.0; $tax = 0.0;
    foreach ($lines as &$l) {
        $qty   = (float) ($l['quantity']   ?? 0);
        $price = (float) ($l['unit_price'] ?? 0);
        $s     = round($qty * $price, 2);
        $t     = round($s * ($taxPct / 100), 2);
        $l['subtotal']     = $s;
        $l['tax_rate_pct'] = $taxPct;
        $l['tax_amount']   = $t;
        $l['total']        = round($s + $t, 2);
        $sub += $s; $tax += $t;
    }
    unset($l);
    return ['lines' => $lines, 'subtotal' => round($sub, 2), 'tax_total' => round($tax, 2), 'total' => round($sub + $tax, 2)];
}

/**
 * Allowed status transitions per SPEC §9. Returns true|throws.
 *  - draft → approved | void
 *  - approved → sent | void
 *  - sent → partially_paid | paid | void
 *  - partially_paid → paid | void (void blocked if any payment allocated)
 *  - paid → void (only if voided same day, business rule — A0: allow always with reason)
 *  - void → (terminal)
 */
function billingTransitionAllowed(string $from, string $to): bool
{
    static $allowed = [
        'draft'           => ['approved','void'],
        'approved'        => ['sent','void'],
        'sent'            => ['partially_paid','paid','void'],
        'partially_paid'  => ['paid','void'],
        'paid'            => ['void'],
        'void'            => [],
    ];
    if (!isset($allowed[$from])) return false;
    return in_array($to, $allowed[$from], true);
}

/**
 * Issue a public view token for an invoice. Returns ['token' => raw, 'url' => string].
 * Tokens default to no expiry (NULL) — invoices can be viewed indefinitely.
 */
function billingIssueViewToken(int $tenantId, int $invoiceId, ?int $expiresInDays = null): array
{
    $raw  = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw, true);
    $exp  = $expiresInDays ? date('Y-m-d H:i:s', time() + $expiresInDays * 86400) : null;

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO billing_invoice_tokens
          (tenant_id, invoice_id, token, token_hash, expires_at)
         VALUES (:t, :i, :tk, :h, :e)'
    );
    $stmt->bindValue('t',  $tenantId,  \PDO::PARAM_INT);
    $stmt->bindValue('i',  $invoiceId, \PDO::PARAM_INT);
    $stmt->bindValue('tk', $raw);
    $stmt->bindValue('h',  $hash, \PDO::PARAM_LOB);
    $stmt->bindValue('e',  $exp);
    $stmt->execute();

    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : (getenv('APP_URL') ?: '');
    return [
        'token_id' => (int) $pdo->lastInsertId(),
        'token'    => $raw,
        'url'      => "{$base}/billing/invoice.php?t={$raw}",
    ];
}

function billingTokenFindByRaw(string $raw): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $raw)) return null;
    $pdo = getDB();
    // tenant-leak-allow: token_hash is a 256-bit random secret; row carries tenant_id
    $stmt = $pdo->prepare('SELECT * FROM billing_invoice_tokens WHERE token_hash = :h LIMIT 1');
    $stmt->bindValue('h', hash('sha256', $raw, true), \PDO::PARAM_LOB);
    $stmt->execute();
    return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
}

/**
 * Allocate $payment.amount across $allocations atomically. Updates each
 * invoice's amount_paid + amount_due + status. Bumps payment.unallocated_amount.
 *
 *   $allocations = [['invoice_id' => N, 'amount' => 123.45], ...]
 *   OR ['auto' => 'fifo'] — picks oldest unpaid invoices for that client.
 */
function billingAllocatePayment(int $paymentId, array $request, ?int $actorUserId = null): array
{
    $pdo = getDB();
    // Nested-safe — billingAllocatePayment is also invoked from inbound
    // payment-import flows (CSV / QBO / Plaid webhooks) which may
    // already own a transaction.
    $ownsTxn = !$pdo->inTransaction();
    if ($ownsTxn) $pdo->beginTransaction();
    try {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $payStmt = $pdo->prepare('SELECT * FROM billing_payments WHERE id = :id FOR UPDATE');
        $payStmt->execute(['id' => $paymentId]);
        $pay = $payStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$pay) throw new \RuntimeException("payment {$paymentId} not found");
        $remaining = (float) $pay['unallocated_amount'];
        if ($remaining <= 0) throw new \RuntimeException('payment has no unallocated amount');

        // Build target list
        $targets = [];
        if (isset($request['auto']) && $request['auto'] === 'fifo') {
            $q = $pdo->prepare(
                'SELECT id, amount_due FROM billing_invoices
                 WHERE tenant_id = :t AND client_name = :c
                   AND status IN ("sent","partially_paid","approved")
                   AND amount_due > 0
                 ORDER BY due_date ASC, id ASC'
            );
            $q->execute(['t' => $pay['tenant_id'], 'c' => $pay['client_name']]);
            foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $inv) {
                if ($remaining <= 0) break;
                $apply = min($remaining, (float) $inv['amount_due']);
                $targets[] = ['invoice_id' => (int) $inv['id'], 'amount' => round($apply, 2)];
                $remaining -= $apply;
            }
        } else {
            foreach ((array) ($request['allocations'] ?? []) as $a) {
                $iid = (int) ($a['invoice_id'] ?? 0);
                $amt = round((float) ($a['amount'] ?? 0), 2);
                if ($iid <= 0 || $amt <= 0) continue;
                $targets[] = ['invoice_id' => $iid, 'amount' => $amt];
            }
        }
        if (empty($targets)) throw new \RuntimeException('no allocations specified');
        $totalRequested = array_sum(array_column($targets, 'amount'));
        if ($totalRequested - 0.005 > (float) $pay['unallocated_amount']) {
            throw new \RuntimeException('total allocation exceeds payment unallocated amount');
        }

        $applied = [];
        foreach ($targets as $t) {
            $inv = $pdo->prepare('SELECT * FROM billing_invoices WHERE id = :id AND tenant_id = :t FOR UPDATE');
            $inv->execute(['id' => $t['invoice_id'], 't' => $pay['tenant_id']]);
            $invRow = $inv->fetch(\PDO::FETCH_ASSOC);
            if (!$invRow) throw new \RuntimeException("invoice {$t['invoice_id']} not found");
            if ($invRow['status'] === 'void' || $invRow['status'] === 'paid') {
                throw new \RuntimeException("invoice {$invRow['invoice_number']} is {$invRow['status']}; cannot allocate");
            }
            $apply = min($t['amount'], (float) $invRow['amount_due']);
            if ($apply <= 0) continue;

            $pdo->prepare(
                'INSERT INTO billing_payment_allocations
                   (payment_id, invoice_id, amount_applied, applied_by_user_id)
                 VALUES (:p, :i, :a, :u)'
            )->execute([
                'p' => $paymentId, 'i' => $invRow['id'], 'a' => $apply, 'u' => $actorUserId,
            ]);

            $newPaid = round((float) $invRow['amount_paid'] + $apply, 2);
            $newDue  = round((float) $invRow['total'] - $newPaid, 2);
            if ($newDue < 0.005) { $newDue = 0; $newStatus = 'paid'; }
            elseif ($newPaid > 0) { $newStatus = 'partially_paid'; }
            else { $newStatus = $invRow['status']; }

            // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
            $pdo->prepare(
                'UPDATE billing_invoices
                 SET amount_paid = :paid, amount_due = :due, status = :s
                 WHERE id = :id'
            )->execute(['paid' => $newPaid, 'due' => $newDue, 's' => $newStatus, 'id' => $invRow['id']]);

            $applied[] = [
                'invoice_id' => (int) $invRow['id'],
                'invoice_number' => $invRow['invoice_number'],
                'amount_applied' => $apply,
                'new_status' => $newStatus,
            ];
        }
        $newUnalloc = round((float) $pay['unallocated_amount'] - array_sum(array_column($applied, 'amount_applied')), 2);
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare('UPDATE billing_payments SET unallocated_amount = :u WHERE id = :id')
            ->execute(['u' => $newUnalloc, 'id' => $paymentId]);

        if ($ownsTxn) $pdo->commit();

        // Pay-When-Paid trigger — runs AFTER commit so the AR invoice's new
        // status ('paid') is durable before we release any vendor bills.
        // Best-effort: errors here are logged but never roll back the AR
        // payment, since the customer's cash is real either way.
        $tenantId = (int) $pay['tenant_id'];
        $pwpResults = [];
        foreach ($applied as $a) {
            if (($a['new_status'] ?? null) !== 'paid') continue;
            try {
                if (!function_exists('apPwpReleaseForArInvoice')) {
                    @require_once __DIR__ . '/../../ap/lib/pwp.php';
                }
                if (function_exists('apPwpReleaseForArInvoice')) {
                    $res = apPwpReleaseForArInvoice($tenantId, (int) $a['invoice_id'], $actorUserId);
                    if (!empty($res['released'])) {
                        $pwpResults[] = [
                            'ar_invoice_id' => (int) $a['invoice_id'],
                            'released'      => $res['released'],
                        ];
                    }
                }
            } catch (\Throwable $e) {
                error_log('[billingAllocatePayment] PWP release failed for AR invoice ' . $a['invoice_id'] . ': ' . $e->getMessage());
            }
        }

        return ['applied' => $applied, 'unallocated_remaining' => $newUnalloc, 'pwp' => $pwpResults];
    } catch (\Throwable $e) {
        if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Compute aging buckets on-read for a given tenant + as_of date.
 * Returns array per client_name.
 */
function billingComputeAging(int $tenantId, string $asOf): array
{
    $pdo = getDB();
    $q = $pdo->prepare(
        'SELECT client_name,
                SUM(CASE WHEN due_date >= :a1 THEN amount_due ELSE 0 END) AS bucket_current,
                SUM(CASE WHEN due_date <  :a2 AND DATEDIFF(:a3, due_date) BETWEEN 1 AND 30 THEN amount_due ELSE 0 END) AS bucket_1_30,
                SUM(CASE WHEN due_date <  :a4 AND DATEDIFF(:a5, due_date) BETWEEN 31 AND 60 THEN amount_due ELSE 0 END) AS bucket_31_60,
                SUM(CASE WHEN due_date <  :a6 AND DATEDIFF(:a7, due_date) BETWEEN 61 AND 90 THEN amount_due ELSE 0 END) AS bucket_61_90,
                SUM(CASE WHEN due_date <  :a8 AND DATEDIFF(:a9, due_date) > 90 THEN amount_due ELSE 0 END) AS bucket_91_plus,
                SUM(amount_due) AS total_due
         FROM billing_invoices
         WHERE tenant_id = :tid AND status IN ("sent","partially_paid","approved","overdue") AND amount_due > 0
         GROUP BY client_name
         ORDER BY total_due DESC'
    );
    $bind = ['tid' => $tenantId];
    foreach (['a1','a2','a3','a4','a5','a6','a7','a8','a9'] as $k) $bind[$k] = $asOf;
    $q->execute($bind);
    return $q->fetchAll(\PDO::FETCH_ASSOC);
}

function billingAudit(string $event, array $meta = [], ?int $targetId = null, array $opts = []): void
{
    try {
        $ctx  = function_exists('currentTenantContext') ? currentTenantContext() : null;
        $tenantId = isset($ctx['tenant_id']) ? (int) $ctx['tenant_id'] : currentTenantId();
        $actorUserId = isset($ctx['user']['id']) ? (int) $ctx['user']['id'] : null;
        platformAuditLogWrite($tenantId, $actorUserId, $event, $targetId, $meta, array_merge([
            'object_type' => billingAuditObjectType($event),
            'source' => $meta['source'] ?? 'billing',
        ], $opts));
    } catch (\Throwable $e) {
        error_log('[billing.audit] ' . $event . ' write-failed: ' . $e->getMessage());
    }
}

function billingAuditObjectType(string $event): string
{
    if (str_contains($event, '.invoice.')) return 'billing_invoice';
    if (str_contains($event, '.payment.')) return 'billing_payment';
    if (str_contains($event, '.recurring.')) return 'billing_recurring';
    if (str_contains($event, '.credit.')) return 'billing_credit';
    if (str_contains($event, '.dunning.')) return 'billing_dunning_case';
    if (str_contains($event, '.tax.')) return 'billing_tax';
    if (str_contains($event, '.template.')) return 'billing_template';
    if (str_contains($event, '.money_movement.')) return 'billing_money_movement';
    if (str_contains($event, '.statement.')) return 'billing_statement';
    return 'billing';
}
