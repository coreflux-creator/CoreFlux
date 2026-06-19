<?php
/**
 * AP Module — Phase A0 library.
 *
 * Pure functions only. Controllers live in /api. SPEC reference:
 * /app/modules/ap/SPEC.md (§3, §9 validation, §11/12 decisions).
 *
 * Mirrors Billing lib conventions: atomic numbering, state machine,
 * allocation engine, on-read aging, audit. Vendor tax IDs encrypted via
 * Core\encryption (AES-256-GCM).
 *
 * Plaid Transfer ACH origination is scaffolded behind an env-gated driver
 * (see apPlaidConfigured()); Phase A0 only exposes manual/CSV/check rails.
 */

require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';
require_once __DIR__ . '/../../../core/encryption.php';

/**
 * Atomically allocate the next internal bill reference for a tenant.
 * Format: {prefix}-{YYYY}-{NNNN}. Distinct from vendor's own bill_number.
 */
function apNextInternalRef(int $tenantId): string
{
    $pdo = getDB();
    // Nested-safe: do not re-open a transaction the caller already owns.
    // The Create-Bill endpoint wraps this call in cf_begin_transaction();
    // a raw beginTransaction() here would throw "There is already an
    // active transaction" (Feb-2026 regression).
    $ownsTxn = !$pdo->inTransaction();
    if ($ownsTxn) $pdo->beginTransaction();
    try {
        $row = $pdo->prepare(
            'SELECT ap_bill_prefix, ap_next_bill_seq
             FROM tenants WHERE id = :id FOR UPDATE'
        );
        $row->execute(['id' => $tenantId]);
        $r = $row->fetch(\PDO::FETCH_ASSOC);
        if (!$r) throw new \RuntimeException("tenant {$tenantId} not found");
        $prefix = trim((string) ($r['ap_bill_prefix'] ?? 'BILL')) ?: 'BILL';
        $seq    = (int) $r['ap_next_bill_seq'];

        $pdo->prepare('UPDATE tenants SET ap_next_bill_seq = :n WHERE id = :id')
            ->execute(['n' => $seq + 1, 'id' => $tenantId]);
        if ($ownsTxn) $pdo->commit();

        return sprintf('%s-%s-%04d', $prefix, date('Y'), $seq);
    } catch (\Throwable $e) {
        if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Build draft bill header(s) + lines from a closed time period's `ap`
 * bundles (1099 / C2C contractor pay). Returns array of {bill, lines, bundle_ids}.
 *
 *   $aggregation = 'per_vendor'   (default) → one bill per consultant
 *                = 'per_placement'           → one bill per placement
 *
 * Refuses bundles not 'ready'. Refuses non-'ap' bundle_types.
 */
function apBuildDraftFromBundle(int $tenantId, int $periodId, array $placementIds, string $aggregation = 'per_vendor'): array
{
    if (!in_array($aggregation, ['per_vendor', 'per_placement'], true)) {
        throw new \InvalidArgumentException("invalid aggregation: {$aggregation}");
    }
    if (empty($placementIds)) {
        throw new \InvalidArgumentException('placement_ids required');
    }

    $pdo    = getDB();
    $period = scopedFind('SELECT * FROM time_periods WHERE tenant_id = :tenant_id AND id = :id', ['id' => $periodId]);
    if (!$period) throw new \RuntimeException("period {$periodId} not found");

    $tenant = $pdo->prepare('SELECT ap_default_terms, ap_1099_threshold FROM tenants WHERE id = :id');
    $tenant->execute(['id' => $tenantId]);
    $tcfg = $tenant->fetch(\PDO::FETCH_ASSOC) ?: [];
    $terms = (string) ($tcfg['ap_default_terms'] ?? 'NET30');

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
        'SELECT tdf.*, p.title AS placement_title, p.person_id,
                p.engagement_type,
                pcd.corp_name,
                pe.first_name, pe.last_name
         FROM time_downstream_feed tdf
         LEFT JOIN placements p ON p.id = tdf.placement_id AND p.tenant_id = :placements_tid
         LEFT JOIN placement_corp_details pcd ON pcd.placement_id = p.id
         LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = :people_tid
         WHERE tdf.tenant_id = :tenant_id
           AND tdf.period_id = :per
           AND tdf.bundle_type = "ap"
           AND tdf.placement_id IN (' . implode(',', $placeholders) . ')',
        array_merge($params, [
            // Same cross-tenant JOIN-drift fix as billing/staffing/time:
            // placements + people default to `'shared'` scope, so binding
            // tdf.tenant_id misses on every sub-tenant row.
            'placements_tid' => effectiveTenantIdForModule('placements') ?? currentTenantId(),
            'people_tid'     => effectiveTenantIdForModule('people')     ?? currentTenantId(),
        ])
    );
    if (empty($bundles)) {
        throw new \RuntimeException('No AP bundles found for the given period+placements.');
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
        $engType    = strtolower((string) ($b['engagement_type'] ?? ''));
        $isCorp     = ($engType === 'c2c' || !empty($b['corp_name']));
        $vendorName = $isCorp
            ? (string) ($b['corp_name'] ?? 'Unknown Corp')
            : (trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: 'Unknown Vendor');
        $vendorType = $isCorp ? 'c2c_corp' : '1099_individual';

        $key = $aggregation === 'per_placement'
            ? 'PL:' . (int) $b['placement_id']
            : 'V:' . $vendorName;

        $groups[$key][] = ['bundle' => $b, 'vendor_name' => $vendorName, 'vendor_type' => $vendorType];
    }

    // Pre-load PWP flags for these vendors. Bills for vendors marked
    // default_pwp=1 get NET90 due-dates (accelerate to "due now" when the
    // matching AR clears — see apPwpReleaseForArInvoice()).
    $vendorNames = array_values(array_unique(array_map(fn ($g) => $g[0]['vendor_name'], $groups)));
    $vendorPwp = [];
    if (!empty($vendorNames)) {
        $vph = []; $vpparams = ['t' => $tenantId];
        foreach ($vendorNames as $i => $vn) { $k = 'vn' . $i; $vph[] = ':' . $k; $vpparams[$k] = $vn; }
        $vpStmt = $pdo->prepare(
            'SELECT vendor_name, COALESCE(default_pwp, 0) AS default_pwp
               FROM ap_vendors_index
              WHERE tenant_id = :t AND vendor_name IN (' . implode(',', $vph) . ')'
        );
        try {
            $vpStmt->execute($vpparams);
            foreach ($vpStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $vendorPwp[(string) $r['vendor_name']] = (int) $r['default_pwp'] === 1;
            }
        } catch (\Throwable $_) { /* default_pwp column not migrated yet — treat as 0 */ }
    }
    $pwpNetDays = 90;  // standard PWP carry term; release accelerates this to "today" when AR clears

    $today   = date('Y-m-d');
    $dueDate = date('Y-m-d', strtotime("+{$netDays} days"));
    $bills   = [];

    foreach ($groups as $key => $groupItems) {
        $first      = $groupItems[0];
        $vendorName = $first['vendor_name'];
        $vendorType = $first['vendor_type'];
        $is1099     = ($vendorType === '1099_individual');
        $isPwp      = !empty($vendorPwp[$vendorName]);
        $billDue    = $isPwp ? date('Y-m-d', strtotime("+{$pwpNetDays} days")) : $dueDate;

        $lines = [];
        $lineNo = 1;
        $subtotal = 0.0;

        foreach ($groupItems as $item) {
            $b = $item['bundle'];
            $hours = (float) $b['total_hours_billable'];
            if ($hours <= 0) continue;
            $payRate = $hours > 0 ? round((float) $b['total_amount_pay'] / $hours, 4) : 0.0;
            $sub     = round($hours * $payRate, 2);

            $consultant = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: 'Consultant';
            $desc = sprintf(
                '%s — %s (period %s → %s)',
                $b['placement_title'] ?: ('Placement #' . $b['placement_id']),
                $consultant,
                $period['start_date'],
                $period['end_date']
            );

            $lines[] = [
                'line_no'                 => $lineNo++,
                'source_type'             => 'time',
                'item_type'               => 'labor',
                'source_ref_id'           => (int) $b['id'],
                'placement_id'            => (int) $b['placement_id'],
                'rate_snapshot_id'        => $b['rate_snapshot_id'] ? (int) $b['rate_snapshot_id'] : null,
                'description'             => $desc,
                'quantity'                => round($hours, 4),
                'unit'                    => 'hour',
                'unit_price'              => $payRate,
                'subtotal'                => $sub,
                'tax_rate_pct'            => 0,
                'tax_amount'              => 0,
                'total'                   => $sub,
                'gl_expense_account_code' => null,
                'is_1099_eligible'        => $is1099 ? 1 : 0,
            ];
            $subtotal += $sub;
        }

        if (empty($lines)) continue;

        $bills[] = [
            'bill' => [
                'vendor_name'   => $vendorName,
                'vendor_type'   => $vendorType,
                'received_at'   => $today,
                'bill_date'     => $today,
                'due_date'      => $billDue,
                'period_start'  => $period['start_date'],
                'period_end'    => $period['end_date'],
                'currency'      => 'USD',
                'subtotal'      => round($subtotal, 2),
                'tax_total'     => 0,
                'total'         => round($subtotal, 2),
                'amount_due'    => round($subtotal, 2),
                'status'        => 'pending_approval',
                'source'        => 'time_bundle',
                'notes_internal'=> null,
                'payment_terms' => $isPwp ? 'PWP' : null,
                'pwp_status'    => $isPwp ? 'awaiting_ar' : 'not_pwp',
            ],
            'lines'      => $lines,
            'bundle_ids' => array_map(fn ($l) => $l['source_ref_id'], $lines),
        ];
    }

    return $bills;
}

/**
 * Batch 4 (2026-02) — Build draft AP bill(s) from a flat list of
 * `time_entries` IDs, bypassing the bundle abstraction.  Mirrors
 * billingBuildDraftFromTimeEntries(): day-level granularity for
 * payables, without waiting for period-close bundle build.
 *
 *   $aggregation = 'per_day'        → one bill per (placement, work_date)
 *                = 'per_placement'  → one bill per placement
 *                = 'per_vendor'     → one bill per vendor (1099 / corp)
 *
 * Pay-rate is looked up via placementCurrentRate(); OT/DT multipliers
 * apply to the entry's hour_type. Returns the same shape as
 * apBuildDraftFromBundle() with `bundle_ids: []` and an extra
 * `entry_ids` array for the audit trail.
 */
function apBuildDraftFromTimeEntries(int $tenantId, array $timeEntryIds, string $aggregation = 'per_day'): array
{
    if (!in_array($aggregation, ['per_day', 'per_placement', 'per_vendor'], true)) {
        throw new \InvalidArgumentException("invalid aggregation: {$aggregation}");
    }
    $ids = array_values(array_unique(array_map('intval', $timeEntryIds)));
    $ids = array_values(array_filter($ids, static fn ($n) => $n > 0));
    if (empty($ids)) throw new \InvalidArgumentException('time_entry_ids required');
    if (count($ids) > 500) throw new \InvalidArgumentException('Too many time_entry_ids (max 500 per call)');

    require_once __DIR__ . '/../../placements/lib/placements.php';

    $pdo = getDB();
    $tenant = $pdo->prepare('SELECT ap_default_terms FROM tenants WHERE id = :id');
    $tenant->execute(['id' => $tenantId]);
    $tcfg = $tenant->fetch(\PDO::FETCH_ASSOC) ?: [];
    $terms = (string) ($tcfg['ap_default_terms'] ?? 'NET30');
    $netDays = 30;
    if (preg_match('/^NET(\d+)$/i', $terms, $m)) $netDays = (int) $m[1];

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
                p.title AS placement_title, p.engagement_type,
                pcd.corp_name,
                pe.first_name, pe.last_name
           FROM time_entries te
      LEFT JOIN placements p   ON p.id = te.placement_id AND p.tenant_id = te.tenant_id
      LEFT JOIN placement_corp_details pcd ON pcd.placement_id = p.id
      LEFT JOIN people pe ON pe.id = te.person_id AND pe.tenant_id = te.tenant_id
          WHERE te.tenant_id = :tenant_id
            AND te.id IN (' . implode(',', $placeholders) . ')
          ORDER BY te.placement_id, te.work_date, te.id',
        $params
    );
    if (empty($entries)) throw new \RuntimeException('No matching time_entries found');

    $payable = [];
    foreach ($entries as $e) {
        if ((int) $e['payable'] !== 1) continue;
        if (!in_array((string) $e['status'], ['approved','locked','payroll_ready','billing_ready'], true)) {
            throw new \RuntimeException(
                "Entry #{$e['id']} (placement {$e['placement_id']}, {$e['work_date']}) status '{$e['status']}' — only approved entries can be paid."
            );
        }
        if ((float) $e['hours'] <= 0) continue;
        $payable[] = $e;
    }
    if (empty($payable)) throw new \RuntimeException('Selected entries have no payable hours');

    // Resolve rate per (placement, work_date), cached.
    $rateCache = [];
    foreach ($payable as &$e) {
        $key = $e['placement_id'] . ':' . $e['work_date'];
        if (!isset($rateCache[$key])) {
            $rateCache[$key] = placementCurrentRate((int) $e['placement_id'], (string) $e['work_date']);
        }
        $rate = $rateCache[$key];
        $base = (float) ($rate['pay_rate'] ?? 0);
        $ot   = (float) ($rate['ot_multiplier'] ?? 1.5);
        $dt   = (float) ($rate['dt_multiplier'] ?? 2.0);
        $mult = match ((string) $e['hour_type']) {
            'overtime'   => $ot,
            'doubletime' => $dt,
            default      => 1.0,
        };
        $e['_pay_rate']         = round($base * $mult, 4);
        $e['_rate_snapshot_id'] = $rate['id'] ?? null;
        $engType    = strtolower((string) ($e['engagement_type'] ?? ''));
        $e['_is_corp']    = ($engType === 'c2c' || !empty($e['corp_name']));
        $e['_vendor_name']= $e['_is_corp']
            ? (string) ($e['corp_name'] ?? 'Unknown Corp')
            : (trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? '')) ?: 'Unknown Vendor');
        $e['_vendor_type']= $e['_is_corp'] ? 'c2c_corp' : '1099_individual';
    }
    unset($e);

    $groups = [];
    foreach ($payable as $e) {
        $key = match ($aggregation) {
            'per_day'       => 'D:' . $e['placement_id'] . ':' . $e['work_date'] . ':' . $e['_vendor_name'],
            'per_placement' => 'P:' . $e['placement_id'],
            'per_vendor'    => 'V:' . $e['_vendor_name'],
        };
        $groups[$key][] = $e;
    }

    $today   = date('Y-m-d');
    $dueDate = date('Y-m-d', strtotime("+{$netDays} days"));
    $bills   = [];

    foreach ($groups as $rows) {
        $first = $rows[0];
        $vendorName = $first['_vendor_name'];
        $vendorType = $first['_vendor_type'];
        $is1099     = ($vendorType === '1099_individual');
        $minDate = null; $maxDate = null;

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
                    'pay_rate'        => (float) $r['_pay_rate'],
                    'rate_snapshot_id'=> $r['_rate_snapshot_id'] ?? null,
                    'hours'           => 0.0,
                    'source_refs'     => [],
                ];
            }
            $linesByKey[$lk]['hours']       += (float) $r['hours'];
            $linesByKey[$lk]['source_refs'][]= (int) $r['id'];
            if (!$minDate || strcmp((string) $r['work_date'], $minDate) < 0) $minDate = (string) $r['work_date'];
            if (!$maxDate || strcmp((string) $r['work_date'], $maxDate) > 0) $maxDate = (string) $r['work_date'];
        }
        ksort($linesByKey);

        $lines = []; $lineNo = 1; $subtotal = 0.0;
        foreach ($linesByKey as $ld) {
            $sub = round($ld['hours'] * $ld['pay_rate'], 2);
            $consultant = trim($ld['first_name'] . ' ' . $ld['last_name']) ?: 'Consultant';
            $desc = sprintf(
                '%s — %s · %s · %s',
                $ld['placement_title'] ?: ('Placement #' . $ld['placement_id']),
                $consultant,
                $ld['work_date'],
                $ld['hour_type']
            );
            $primaryRef = $ld['source_refs'][0];
            $lines[] = [
                'line_no'                 => $lineNo++,
                'source_type'             => 'time_entry',
                'item_type'               => 'labor',
                'source_ref_id'           => $primaryRef,
                'placement_id'            => $ld['placement_id'],
                'rate_snapshot_id'        => $ld['rate_snapshot_id'] ? (int) $ld['rate_snapshot_id'] : null,
                'description'             => $desc . (count($ld['source_refs']) > 1 ? ' (' . count($ld['source_refs']) . ' entries)' : ''),
                'quantity'                => round($ld['hours'], 4),
                'unit'                    => 'hour',
                'unit_price'              => $ld['pay_rate'],
                'subtotal'                => $sub,
                'tax_rate_pct'            => 0,
                'tax_amount'              => 0,
                'total'                   => $sub,
                'gl_expense_account_code' => null,
                'is_1099_eligible'        => $is1099 ? 1 : 0,
                '_entry_ids'              => $ld['source_refs'],
            ];
            $subtotal += $sub;
        }
        if (empty($lines)) continue;

        $bills[] = [
            'bill' => [
                'vendor_name'    => $vendorName,
                'vendor_type'    => $vendorType,
                'received_at'    => $today,
                'bill_date'      => $today,
                'due_date'       => $dueDate,
                'period_start'   => $minDate ?? $today,
                'period_end'     => $maxDate ?? $today,
                'currency'       => 'USD',
                'subtotal'       => round($subtotal, 2),
                'tax_total'      => 0,
                'total'          => round($subtotal, 2),
                'amount_due'     => round($subtotal, 2),
                'status'         => 'pending_approval',
                'source'         => 'time_entries',
                'notes_internal' => null,
                'payment_terms'  => null,
                'pwp_status'     => 'not_pwp',
            ],
            'lines'      => $lines,
            'bundle_ids' => [],
            'entry_ids'  => array_merge(...array_column($lines, '_entry_ids')),
        ];
    }

    return $bills;
}


/**
 * Whitelist for the item_type ENUM on ap_bill_lines / billing_invoice_lines.
 * Single source of truth — both modules import this list.
 */
const AP_LINE_ITEM_TYPES = [
    'labor', 'expense', 'materials', 'fixed_fee', 'milestone',
    'discount', 'subscription', 'mileage', 'per_diem', 'reimbursement', 'other',
];

/**
 * Coerce a free-text item_type into the ENUM domain. If the caller passes
 * nothing (or garbage), we infer a sensible default from the source_type
 * the line is being inserted as.
 *
 * Mapping rule:
 *   source_type = 'time'   → 'labor'
 *   source_type = 'expense'→ 'expense'
 *   source_type = others   → 'other' (safer than 'labor' for manual lines
 *                            so that GL coding never misclassifies billables)
 */
function apNormalizeItemType(?string $itemType, string $sourceType = 'manual'): string
{
    $candidate = is_string($itemType) ? strtolower(trim($itemType)) : '';
    if ($candidate !== '' && in_array($candidate, AP_LINE_ITEM_TYPES, true)) {
        return $candidate;
    }
    return match ($sourceType) {
        'time'    => 'labor',
        'expense' => 'expense',
        default   => 'other',
    };
}

/**
 * Apply a flat tax rate to each line (used on manual PATCH). Returns recomputed totals.
 */
function apComputeTotals(array $lines, float $taxPct = 0.0): array
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
 * Bill state machine per SPEC §9.
 *
 *  - inbox → pending_review | void
 *  - pending_review → pending_approval | void | disputed
 *  - pending_approval → approved | void | disputed
 *  - approved → partially_paid | paid | void | disputed
 *  - partially_paid → paid | void
 *  - paid → void (with reason; A0 permissive)
 *  - disputed → pending_approval | void
 *  - void → (terminal)
 */
function apBillTransitionAllowed(string $from, string $to): bool
{
    static $allowed = [
        'inbox'             => ['pending_review','void'],
        'pending_review'    => ['pending_approval','void','disputed'],
        'pending_approval'  => ['approved','void','disputed'],
        'approved'          => ['partially_paid','paid','void','disputed'],
        'partially_paid'    => ['paid','void'],
        'paid'              => ['void'],
        'disputed'          => ['pending_approval','void'],
        'void'              => [],
    ];
    if (!isset($allowed[$from])) return false;
    return in_array($to, $allowed[$from], true);
}

/**
 * Payment state machine.
 *  - draft → queued | void
 *  - queued → sent | void
 *  - sent → cleared | failed | void
 *  - failed → queued | void
 *  - cleared → void  (treat as reversal)
 *  - void → terminal
 */
function apPaymentTransitionAllowed(string $from, string $to): bool
{
    static $allowed = [
        'draft'    => ['queued','void'],
        'queued'   => ['sent','void'],
        'sent'     => ['cleared','failed','void'],
        'failed'   => ['queued','void'],
        'cleared'  => ['void'],
        'void'     => [],
    ];
    if (!isset($allowed[$from])) return false;
    return in_array($to, $allowed[$from], true);
}

/**
 * AI-assisted payment-run suggestion (Mercury rail expansion, 2026-02).
 *
 * Mirror of `billingSuggestInvoiceForPlacement()` on the AR side: scan
 * approved/partially-paid bills due within the next $daysAhead days,
 * skip anything PWP-awaiting-AR (the 4-way-match gate), group by
 * vendor, and return a preview the operator can confirm to fire a
 * batch payment run on the chosen rail.
 *
 * This function NEVER moves money — it just builds the suggestion.
 * The matching `executePaymentRun()` helper below performs the actual
 * dispatch under SoD approval.
 *
 *   $rail   — preferred rail id (mercury|plaid_transfer|nacha). Passed
 *             through to the rail-eligibility check; defaults to the
 *             tenant's ap_settings.disbursement_rail.
 */
function apSuggestPaymentRun(int $tenantId, int $daysAhead = 7, ?string $rail = null, ?int $userId = null): array
{
    $pdo = getDB();
    require_once __DIR__ . '/../../../core/payment_rails.php';

    // Resolve rail. Operator-supplied wins; else tenant default; else mercury.
    if (!$rail) {
        $set = scopedFind('SELECT disbursement_rail FROM ap_settings WHERE tenant_id = :tenant_id LIMIT 1');
        $rail = trim((string) ($set['disbursement_rail'] ?? '')) ?: 'mercury';
    }
    try {
        $driver = paymentRailsGetDriver($rail);
        $railConfigured = $driver->isConfigured();
        if (method_exists($driver, 'isConfiguredForTenant')) {
            $railConfigured = $railConfigured && $driver->isConfiguredForTenant($tenantId);
        }
    } catch (\Throwable $_) {
        $railConfigured = false;
    }

    $horizon = max(1, min(60, $daysAhead));
    $cutoff = date('Y-m-d', strtotime("+{$horizon} days"));

    // Pull every payable bill in the horizon. PWP-blocked rows are
    // surfaced separately so the operator can see them but they are
    // NOT included in the rail-eligible total.
    $bills = scopedQuery(
        "SELECT id, internal_ref, bill_number, vendor_name, vendor_type, payment_method,
                bill_date, due_date, total, amount_paid, amount_due,
                currency, pwp_status, source
           FROM ap_bills
          WHERE tenant_id = :tenant_id
            AND status IN ('approved','partially_paid')
            AND amount_due > 0
            AND due_date <= :cutoff
          ORDER BY due_date ASC, vendor_name ASC",
        ['cutoff' => $cutoff]
    );

    $groups   = [];     // vendor_name → group payload
    $blocked  = [];     // PWP-blocked rows for operator visibility
    foreach ($bills as $b) {
        // PWP gate — same logic as ap_payments?action=send refuses.
        if (($b['pwp_status'] ?? '') === 'awaiting_ar') {
            $blocked[] = $b;
            continue;
        }
        $v = (string) $b['vendor_name'];
        if (!isset($groups[$v])) {
            $groups[$v] = [
                'vendor_name'        => $v,
                'vendor_type'        => (string) ($b['vendor_type'] ?? ''),
                'payment_method'     => (string) ($b['payment_method'] ?? ''),
                'bill_count'         => 0,
                'bill_ids'           => [],
                'bill_refs'          => [],
                'total_due'          => 0.0,
                'earliest_due_date'  => null,
                'oldest_bill_date'   => null,
                'currency'           => (string) ($b['currency'] ?? 'USD'),
                'rail_eligible'      => true,
                'eligibility_note'   => null,
            ];
        }
        $g = &$groups[$v];
        $g['bill_count']++;
        $g['bill_ids'][]  = (int) $b['id'];
        $g['bill_refs'][] = (string) ($b['internal_ref'] ?? $b['bill_number'] ?? ('#' . $b['id']));
        $g['total_due'] += round((float) $b['amount_due'], 2);
        if (!$g['earliest_due_date'] || strcmp($b['due_date'], $g['earliest_due_date']) < 0) {
            $g['earliest_due_date'] = $b['due_date'];
        }
        if (!$g['oldest_bill_date'] || strcmp($b['bill_date'], $g['oldest_bill_date']) < 0) {
            $g['oldest_bill_date'] = $b['bill_date'];
        }
        unset($g);
    }
    unset($g);

    // Mercury rail-eligibility: vendor must have a `mercury_recipients`
    // active row OR a vendor record with banking info we can upsert
    // into one. For now, we mark "eligible" if the vendor has banking
    // attached (ap_vendors_index.payment_method is set or vendor_type
    // is 1099_individual / c2c_corp). Lack of banking → flag with a
    // human-friendly note.
    if (!empty($groups)) {
        $vendorNames = array_keys($groups);
        $placeholders = [];
        $params = ['t' => $tenantId];
        foreach ($vendorNames as $i => $vn) {
            $k = 'v' . $i; $placeholders[] = ':' . $k; $params[$k] = $vn;
        }
        try {
            $vix = $pdo->prepare(
                'SELECT vendor_name, default_pwp, last_bill_at
                   FROM ap_vendors_index
                  WHERE tenant_id = :t AND vendor_name IN (' . implode(',', $placeholders) . ')'
            );
            $vix->execute($params);
            $vix->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $_) { /* graceful — no flags */ }

        // Mercury-specific recipient check (only when rail=mercury).
        if ($rail === 'mercury') {
            try {
                $merc = $pdo->prepare(
                    'SELECT name FROM mercury_recipients
                      WHERE tenant_id = :t AND status = "active" AND kind = "vendor"
                        AND name IN (' . implode(',', $placeholders) . ')'
                );
                $merc->execute($params);
                $haveRecipient = array_column($merc->fetchAll(\PDO::FETCH_ASSOC), 'name');
                $haveSet = array_flip($haveRecipient);
                foreach ($groups as $vn => &$g) {
                    if (!isset($haveSet[$vn])) {
                        $g['rail_eligible']    = false;
                        $g['eligibility_note'] = 'No Mercury recipient on file. Add one under Treasury → Mercury Recipients before this payment can be dispatched on the Mercury rail.';
                    }
                }
                unset($g);
            } catch (\Throwable $_) { /* mercury_recipients table missing — leave defaults */ }
        }
    }

    // Totals.
    $groupRows = array_values($groups);
    $vendorCount = count($groupRows);
    $billCount   = array_sum(array_column($groupRows, 'bill_count'));
    $totalDue    = round(array_sum(array_column($groupRows, 'total_due')), 2);
    $eligibleTotal = 0.0;
    $needsReviewTotal = 0.0;
    foreach ($groupRows as $g) {
        if ($g['rail_eligible']) $eligibleTotal += $g['total_due'];
        else                     $needsReviewTotal += $g['total_due'];
    }

    // AI commentary — short summary tied to risk + cashflow context.
    $aiUsed = false; $aiSummary = null;
    $detSummary = sprintf(
        '%d vendor(s) and %d bill(s) due by %s — $%s total. %s on the %s rail, $%s flagged for review.',
        $vendorCount, $billCount, $cutoff,
        number_format($totalDue, 2),
        '$' . number_format($eligibleTotal, 2) . ' rail-eligible',
        $rail,
        number_format($needsReviewTotal, 2)
    );
    if ($vendorCount > 0) {
        try {
            require_once __DIR__ . '/../../../core/ai_service.php';
            $env = aiAsk([
                'feature_class'     => 'suggestion',
                'kind'              => 'suggestion',
                'feature_key'       => 'ap.payment_run.suggest_summary',
                'system'            => 'You write short AP payment-run summaries for a CFO. Maximum 2 sentences, ~40 words. Mention vendor count, total amount, the rail, and any flagged items. Be specific but never restate raw rates or formulas.',
                'prompt'            => "Summarise this AP payment run: {$vendorCount} vendors, {$billCount} bills, $" . number_format($totalDue, 2) . " total due by {$cutoff}, rail={$rail}, $" . number_format($needsReviewTotal, 2) . ' flagged for review (no rail-recipient on file).',
                'context'           => [
                    'rail'                 => $rail,
                    'rail_configured'      => $railConfigured,
                    'vendor_count'         => $vendorCount,
                    'bill_count'           => $billCount,
                    'horizon_days'         => $horizon,
                    'cutoff'               => $cutoff,
                    'total_due'            => $totalDue,
                    'eligible_total'       => round($eligibleTotal, 2),
                    'needs_review_total'   => round($needsReviewTotal, 2),
                    'pwp_blocked_count'    => count($blocked),
                ],
                'max_output_tokens' => 140,
            ]);
            $aiSummary = trim((string) ($env['content'] ?? ''));
            $aiUsed    = $aiSummary !== '';
        } catch (\Throwable $_) {
            $aiSummary = null;
            $aiUsed    = false;
        }
    }

    return [
        'rail'              => $rail,
        'rail_configured'   => $railConfigured,
        'run_horizon_days'  => $horizon,
        'cutoff_date'       => $cutoff,
        'vendor_groups'     => $groupRows,
        'pwp_blocked'       => $blocked,
        'totals' => [
            'vendor_count'        => $vendorCount,
            'bill_count'          => (int) $billCount,
            'total_due'           => $totalDue,
            'rail_eligible_total' => round($eligibleTotal, 2),
            'needs_review_total'  => round($needsReviewTotal, 2),
            'pwp_blocked_count'   => count($blocked),
            'pwp_blocked_amount'  => round(array_sum(array_map(static fn ($b) => (float) $b['amount_due'], $blocked)), 2),
        ],
        'ai_summary' => $aiSummary ?: $detSummary,
        'ai_used'    => $aiUsed,
    ];
}

/**
 * Execute an AP payment run produced by `apSuggestPaymentRun()`.
 *
 * Creates one `ap_payments` row per vendor group in `draft` status,
 * auto-allocates the specified bill_ids, and stamps `disbursement_rail`
 * so the next `?action=send` step routes through `paymentRailsDispatch()`.
 *
 * Critically: this does NOT call `?action=send` — the operator must
 * still release the payment, preserving SoD. Returns the created
 * payment_ids so the UI can deep-link the operator to the queue.
 *
 *   $groups = [{vendor_name, bill_ids: int[], pay_date?, method?}, ...]
 */
function apExecutePaymentRun(int $tenantId, string $rail, array $groups, ?int $userId = null): array
{
    if (empty($groups)) throw new \InvalidArgumentException('No vendor groups supplied');
    require_once __DIR__ . '/../../../core/payment_rails.php';
    try { paymentRailsGetDriver($rail); }
    catch (\Throwable $e) { throw new \InvalidArgumentException("Unknown rail '{$rail}'"); }

    $pdo = getDB();
    $created = [];
    foreach ($groups as $g) {
        $vendorName = trim((string) ($g['vendor_name'] ?? ''));
        $billIds    = array_values(array_filter(array_map('intval', (array) ($g['bill_ids'] ?? [])), static fn ($n) => $n > 0));
        if ($vendorName === '' || empty($billIds)) continue;

        // Re-fetch each bill to get the live amount_due (avoids stale
        // suggestion data) and confirm it's still payable.
        $placeholders = [];
        $params = ['t' => $tenantId];
        foreach ($billIds as $i => $bid) { $k = 'b' . $i; $placeholders[] = ':' . $k; $params[$k] = $bid; }
        $st = $pdo->prepare(
            'SELECT id, amount_due, status, vendor_name, pwp_status, currency, payment_method
               FROM ap_bills
              WHERE tenant_id = :t AND id IN (' . implode(',', $placeholders) . ')'
        );
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        $allocations = [];
        $total = 0.0;
        $currency = 'USD';
        foreach ($rows as $b) {
            if ($b['vendor_name'] !== $vendorName) continue;
            if (!in_array($b['status'], ['approved','partially_paid'], true)) continue;
            if (($b['pwp_status'] ?? '') === 'awaiting_ar') continue;
            $due = (float) $b['amount_due'];
            if ($due <= 0) continue;
            $allocations[] = ['bill_id' => (int) $b['id'], 'amount' => round($due, 2)];
            $total += $due;
            $currency = (string) ($b['currency'] ?? 'USD');
        }
        if (empty($allocations)) continue;

        $payId = scopedInsert('ap_payments', [
            'tenant_id'          => $tenantId,
            'vendor_name'        => $vendorName,
            'pay_date'           => $g['pay_date'] ?? date('Y-m-d'),
            'method'             => $g['method'] ?? $rail,
            'reference'          => 'payment-run:' . date('Ymd') . ':' . substr(bin2hex(random_bytes(3)), 0, 6),
            'amount'             => round($total, 2),
            'currency'           => $currency,
            'unallocated_amount' => round($total, 2),
            'status'             => 'draft',
            'disbursement_rail'  => $rail,
            'notes'              => "Auto-created by AP payment-run suggestion ({$rail}, " . count($allocations) . ' bills).',
            'created_by_user_id' => $userId,
        ]);
        try {
            apAllocatePayment($payId, ['allocations' => $allocations], $userId);
        } catch (\Throwable $e) {
            // Rollback: void the payment so we don't leave orphan drafts.
            // tenant-leak-allow: $payId was just returned by scopedInsert() with tenant scope.
            $pdo->prepare('UPDATE ap_payments SET status = "void", notes = CONCAT(COALESCE(notes,""), " · allocation failed: ", :err) WHERE id = :id')
                ->execute(['err' => $e->getMessage(), 'id' => $payId]);
            apAudit('ap.payment.run_allocation_failed', [
                'payment_id' => $payId, 'vendor' => $vendorName, 'error' => $e->getMessage(),
            ], $payId);
            continue;
        }
        apAudit('ap.payment.run_created', [
            'payment_id'   => $payId,
            'vendor'       => $vendorName,
            'rail'         => $rail,
            'bill_ids'     => array_column($allocations, 'bill_id'),
            'amount'       => round($total, 2),
            'source'       => 'suggest_payment_run',
        ], $payId);
        $created[] = [
            'payment_id'  => $payId,
            'vendor_name' => $vendorName,
            'amount'      => round($total, 2),
            'bill_count'  => count($allocations),
        ];
    }
    return ['payments_created' => $created, 'rail' => $rail];
}



/**
 * Allocate a payment across bills atomically. Mirrors Billing's engine.
 *
 *   $request = ['allocations' => [{bill_id, amount}]]
 *            OR ['auto' => 'fifo']  — picks oldest-due unpaid bills for vendor.
 */
function apAllocatePayment(int $paymentId, array $request, ?int $actorUserId = null): array
{
    $pdo = getDB();
    // Nested-safe — apAllocatePayment is also invoked from inside a
    // billing/PWP cascade where the outer txn is already open.
    $ownsTxn = !$pdo->inTransaction();
    if ($ownsTxn) $pdo->beginTransaction();
    try {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $payStmt = $pdo->prepare('SELECT * FROM ap_payments WHERE id = :id FOR UPDATE');
        $payStmt->execute(['id' => $paymentId]);
        $pay = $payStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$pay) throw new \RuntimeException("payment {$paymentId} not found");
        $remaining = (float) $pay['unallocated_amount'];
        if ($remaining <= 0) throw new \RuntimeException('payment has no unallocated amount');

        $targets = [];
        if (isset($request['auto']) && $request['auto'] === 'fifo') {
            $q = $pdo->prepare(
                'SELECT id, amount_due FROM ap_bills
                 WHERE tenant_id = :t AND vendor_name = :v
                   AND status IN ("approved","partially_paid","pending_approval")
                   AND amount_due > 0
                 ORDER BY due_date ASC, id ASC'
            );
            $q->execute(['t' => $pay['tenant_id'], 'v' => $pay['vendor_name']]);
            foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $bill) {
                if ($remaining <= 0) break;
                $apply = min($remaining, (float) $bill['amount_due']);
                $targets[] = ['bill_id' => (int) $bill['id'], 'amount' => round($apply, 2)];
                $remaining -= $apply;
            }
        } else {
            foreach ((array) ($request['allocations'] ?? []) as $a) {
                $bid = (int) ($a['bill_id'] ?? 0);
                $amt = round((float) ($a['amount'] ?? 0), 2);
                if ($bid <= 0 || $amt <= 0) continue;
                $targets[] = ['bill_id' => $bid, 'amount' => $amt];
            }
        }
        if (empty($targets)) throw new \RuntimeException('no allocations specified');
        $totalRequested = array_sum(array_column($targets, 'amount'));
        if ($totalRequested - 0.005 > (float) $pay['unallocated_amount']) {
            throw new \RuntimeException('total allocation exceeds payment unallocated amount');
        }

        $applied = [];
        foreach ($targets as $t) {
            $bill = $pdo->prepare('SELECT * FROM ap_bills WHERE id = :id AND tenant_id = :t FOR UPDATE');
            $bill->execute(['id' => $t['bill_id'], 't' => $pay['tenant_id']]);
            $bRow = $bill->fetch(\PDO::FETCH_ASSOC);
            if (!$bRow) throw new \RuntimeException("bill {$t['bill_id']} not found");
            if ($bRow['status'] === 'void' || $bRow['status'] === 'paid') {
                throw new \RuntimeException("bill {$bRow['internal_ref']} is {$bRow['status']}; cannot allocate");
            }
            if ($bRow['status'] === 'disputed') {
                throw new \RuntimeException("bill {$bRow['internal_ref']} is disputed; resolve before allocating");
            }
            $apply = min($t['amount'], (float) $bRow['amount_due']);
            if ($apply <= 0) continue;

            $pdo->prepare(
                'INSERT INTO ap_payment_allocations
                   (payment_id, bill_id, amount_applied, applied_by_user_id)
                 VALUES (:p, :b, :a, :u)'
            )->execute([
                'p' => $paymentId, 'b' => $bRow['id'], 'a' => $apply, 'u' => $actorUserId,
            ]);

            $newPaid = round((float) $bRow['amount_paid'] + $apply, 2);
            $newDue  = round((float) $bRow['total'] - $newPaid, 2);
            if ($newDue < 0.005) { $newDue = 0; $newStatus = 'paid'; }
            elseif ($newPaid > 0) { $newStatus = 'partially_paid'; }
            else { $newStatus = $bRow['status']; }

            // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
            $pdo->prepare(
                'UPDATE ap_bills
                 SET amount_paid = :paid, amount_due = :due, status = :s
                 WHERE id = :id'
            )->execute(['paid' => $newPaid, 'due' => $newDue, 's' => $newStatus, 'id' => $bRow['id']]);

            $applied[] = [
                'bill_id'        => (int) $bRow['id'],
                'internal_ref'   => $bRow['internal_ref'],
                'amount_applied' => $apply,
                'new_status'     => $newStatus,
            ];
        }
        $newUnalloc = round((float) $pay['unallocated_amount'] - array_sum(array_column($applied, 'amount_applied')), 2);
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare('UPDATE ap_payments SET unallocated_amount = :u WHERE id = :id')
            ->execute(['u' => $newUnalloc, 'id' => $paymentId]);

        if ($ownsTxn) $pdo->commit();
        return ['applied' => $applied, 'unallocated_remaining' => $newUnalloc];
    } catch (\Throwable $e) {
        if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Compute AP aging buckets on-read for a tenant + as_of date.
 * Returns array keyed by vendor_name.
 */
function apComputeAging(int $tenantId, string $asOf): array
{
    $pdo = getDB();
    $q = $pdo->prepare(
        'SELECT vendor_name,
                SUM(CASE WHEN due_date >= :a1 THEN amount_due ELSE 0 END) AS bucket_current,
                SUM(CASE WHEN due_date <  :a2 AND DATEDIFF(:a3, due_date) BETWEEN 1 AND 30 THEN amount_due ELSE 0 END) AS bucket_1_30,
                SUM(CASE WHEN due_date <  :a4 AND DATEDIFF(:a5, due_date) BETWEEN 31 AND 60 THEN amount_due ELSE 0 END) AS bucket_31_60,
                SUM(CASE WHEN due_date <  :a6 AND DATEDIFF(:a7, due_date) BETWEEN 61 AND 90 THEN amount_due ELSE 0 END) AS bucket_61_90,
                SUM(CASE WHEN due_date <  :a8 AND DATEDIFF(:a9, due_date) > 90 THEN amount_due ELSE 0 END) AS bucket_91_plus,
                SUM(amount_due) AS total_due
         FROM ap_bills
         WHERE tenant_id = :tid AND status IN ("approved","partially_paid","pending_approval") AND amount_due > 0
         GROUP BY vendor_name
         ORDER BY total_due DESC'
    );
    $bind = ['tid' => $tenantId];
    foreach (['a1','a2','a3','a4','a5','a6','a7','a8','a9'] as $k) $bind[$k] = $asOf;
    $q->execute($bind);
    return $q->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Rebuild the 1099-NEC ledger for a tax year from cleared payments whose
 * allocated bill lines are `is_1099_eligible=1`. Idempotent — upserts per
 * (tenant_id, tax_year, vendor_name). Returns summary.
 */
function apBuild1099Ledger(int $tenantId, int $taxYear): array
{
    $pdo = getDB();

    $threshStmt = $pdo->prepare('SELECT ap_1099_threshold FROM tenants WHERE id = :id');
    $threshStmt->execute(['id' => $tenantId]);
    $threshold = (float) ($threshStmt->fetchColumn() ?: 600.0);

    // Sum cleared payment $ attributable to 1099-eligible lines per vendor, via
    // proportional line share: bill_lines_eligible_total / bill_total.
    $q = $pdo->prepare(
        'SELECT b.vendor_name, b.vendor_type,
                SUM(a.amount_applied * (
                    COALESCE((SELECT SUM(bl.total) FROM ap_bill_lines bl WHERE bl.bill_id = b.id AND bl.is_1099_eligible = 1), 0) /
                    NULLIF(b.total, 0)
                )) AS total_eligible_paid
         FROM ap_payments p
         JOIN ap_payment_allocations a ON a.payment_id = p.id
         JOIN ap_bills b                ON b.id        = a.bill_id
         WHERE p.tenant_id = :t
           AND p.status = "cleared"
           AND YEAR(p.cleared_at) = :y
           AND b.vendor_type IN ("1099_individual","c2c_corp")
         GROUP BY b.vendor_name, b.vendor_type'
    );
    $q->execute(['t' => $tenantId, 'y' => $taxYear]);
    $rows = $q->fetchAll(\PDO::FETCH_ASSOC);

    $upserted = 0;
    $upsert = $pdo->prepare(
        'INSERT INTO ap_1099_ledger
           (tenant_id, tax_year, vendor_name, vendor_type, total_paid, requires_1099_nec, computed_at)
         VALUES (:t, :y, :v, :vt, :tp, :r, NOW())
         ON DUPLICATE KEY UPDATE
           vendor_type = VALUES(vendor_type),
           total_paid = VALUES(total_paid),
           requires_1099_nec = VALUES(requires_1099_nec),
           computed_at = NOW()'
    );
    foreach ($rows as $r) {
        $total = round((float) ($r['total_eligible_paid'] ?? 0), 2);
        if ($total <= 0) continue;
        $upsert->execute([
            't'  => $tenantId,
            'y'  => $taxYear,
            'v'  => $r['vendor_name'],
            'vt' => $r['vendor_type'],
            'tp' => $total,
            'r'  => ($total >= $threshold && $r['vendor_type'] === '1099_individual') ? 1 : 0,
        ]);
        $upserted++;
    }
    return ['vendors_counted' => count($rows), 'vendors_upserted' => $upserted, 'threshold' => $threshold];
}

/**
 * Plaid Transfer config probe. Returns true only when env vars are populated.
 * Phase A0: no actual Plaid calls — gate UI "Send via Plaid" affordance on this.
 */
function apPlaidConfigured(): bool
{
    $id  = getenv('PLAID_CLIENT_ID');
    $sec = getenv('PLAID_SECRET_SANDBOX') ?: getenv('PLAID_SECRET');
    return is_string($id) && $id !== '' && is_string($sec) && $sec !== '';
}

function apAudit(string $event, array $meta = [], ?int $targetId = null): void
{
    try {
        $ctx  = function_exists('currentTenantContext') ? currentTenantContext() : null;
        $pdo  = getDB();
        $pdo->prepare(
            'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)
             VALUES (:tenant_id, :actor, :event, :target_id, :meta_json, :ip, NOW())'
        )->execute([
            'tenant_id' => $ctx['tenant_id'] ?? null,
            'actor'     => $ctx['user']['id'] ?? null,
            'event'     => $event,
            'target_id' => $targetId,
            'meta_json' => json_encode($meta),
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log('[ap.audit] ' . $event . ' write-failed: ' . $e->getMessage());
    }
}
