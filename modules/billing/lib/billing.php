<?php
/**
 * Billing Module — Phase A0 library.
 *
 * Pure functions only. Controllers live in /api. SPEC reference:
 * /app/modules/billing/SPEC.md (§3.3-§3.8, §9 validation, §11/12 decisions).
 */

require_once __DIR__ . '/../../../core/tenant_scope.php';

/**
 * Atomically allocate the next invoice number for a tenant.
 * Format: {prefix}-{YYYY}-{NNNN}, where NNNN is zero-padded to ≥4 digits.
 */
function billingNextInvoiceNumber(int $tenantId): string
{
    $pdo = getDB();
    $pdo->beginTransaction();
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
        $pdo->commit();

        return sprintf('%s-%s-%04d', $prefix, date('Y'), $seq);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
    $pdo->beginTransaction();
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

        $pdo->commit();

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
        if ($pdo->inTransaction()) $pdo->rollBack();
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

function billingAudit(string $event, array $meta = [], ?int $targetId = null): void
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
        error_log('[billing.audit] ' . $event . ' write-failed: ' . $e->getMessage());
    }
}
