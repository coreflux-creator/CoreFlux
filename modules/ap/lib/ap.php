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
require_once __DIR__ . '/../../../core/encryption.php';

/**
 * Atomically allocate the next internal bill reference for a tenant.
 * Format: {prefix}-{YYYY}-{NNNN}. Distinct from vendor's own bill_number.
 */
function apNextInternalRef(int $tenantId): string
{
    $pdo = getDB();
    $pdo->beginTransaction();
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
        $pdo->commit();

        return sprintf('%s-%s-%04d', $prefix, date('Y'), $seq);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
         LEFT JOIN placements p ON p.id = tdf.placement_id AND p.tenant_id = tdf.tenant_id
         LEFT JOIN placement_corp_details pcd ON pcd.placement_id = p.id
         LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = p.tenant_id
         WHERE tdf.tenant_id = :tenant_id
           AND tdf.period_id = :per
           AND tdf.bundle_type = "ap"
           AND tdf.placement_id IN (' . implode(',', $placeholders) . ')',
        $params
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
 * Allocate a payment across bills atomically. Mirrors Billing's engine.
 *
 *   $request = ['allocations' => [{bill_id, amount}]]
 *            OR ['auto' => 'fifo']  — picks oldest-due unpaid bills for vendor.
 */
function apAllocatePayment(int $paymentId, array $request, ?int $actorUserId = null): array
{
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
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
        $pdo->prepare('UPDATE ap_payments SET unallocated_amount = :u WHERE id = :id')
            ->execute(['u' => $newUnalloc, 'id' => $paymentId]);

        $pdo->commit();
        return ['applied' => $applied, 'unallocated_remaining' => $newUnalloc];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
