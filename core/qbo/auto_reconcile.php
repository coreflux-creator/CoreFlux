<?php
/**
 * core/qbo/auto_reconcile.php
 *
 * Auto-reconciliation for QBO `paid_out_of_band` drift.
 *
 * Architecture:
 *   The two-way-sync cron (cron/qbo_two_way_sync.php) writes drift rows
 *   into `qbo_sync_drift` whenever an inbound QBO Payment / BillPayment
 *   settles an AR Invoice / AP Bill that CoreFlux still has marked
 *   `sent` / `partially_paid` / `approved` / `pending`.
 *
 *   Detection alone is the safe default — the operator triages the row
 *   in the Integration Triage admin UI and decides whether to apply or
 *   dismiss. When a tenant explicitly opts in via
 *   `qbo_connections.auto_reconcile_paid_out_of_band = 1`, this module
 *   closes the loop automatically:
 *
 *   1. Read every OPEN paid_out_of_band drift row for the tenant.
 *   2. For each invoice drift, pull the linked QBO Payment(s) out of
 *      `qbo_inbound_payments` (or `qbo_inbound_billpayments` for AP).
 *   3. Idempotently INSERT a `billing_payments` / `ap_payments` row
 *      keyed off (tenant_id, source_system='qbo', external_id=qbo_payment_id).
 *   4. Allocate that payment to the matching CoreFlux invoice / bill
 *      via the existing `billingAllocatePayment` / `apAllocatePayment`
 *      engines — same code path as a human operator would hit.
 *   5. When the CoreFlux entity reaches status='paid', mark the drift
 *      row `status='reconciled'` and stamp resolution_note.
 *
 * Idempotency:
 *   - UNIQUE KEY (tenant_id, source_system, external_id) on both payment
 *     tables prevents double-insertion on cron retries (migration 096).
 *   - drift rows leave the `status='open'` filter after first success so
 *     subsequent runs skip them.
 *
 * Public surface:
 *   qboAutoReconcileTenant(int $tenantId, ?int $actorUserId = null): array
 *     → counters: ['invoices_reconciled', 'bills_reconciled',
 *                  'payments_created', 'drift_rows_closed',
 *                  'skipped', 'errors']
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../../modules/billing/lib/billing.php';
require_once __DIR__ . '/../../modules/ap/lib/ap.php';

function qboAutoReconcileTenant(int $tenantId, ?int $actorUserId = null): array
{
    $out = [
        'tenant_id'           => $tenantId,
        'enabled'             => false,
        'invoices_reconciled' => 0,
        'bills_reconciled'    => 0,
        'payments_created'    => 0,
        'drift_rows_closed'   => 0,
        'skipped'             => 0,
        'errors'              => [],
    ];

    if (!qboAutoReconcileEnabled($tenantId)) {
        return $out; // explicit opt-in only
    }
    $out['enabled'] = true;

    $pdo = getDB();
    try {
        $stmt = $pdo->prepare(
            "SELECT id, entity_type, coreflux_id, qbo_id, drift_kind, status
               FROM qbo_sync_drift
              WHERE tenant_id = :t
                AND status = 'open'
                AND drift_kind = 'paid_out_of_band'
                AND entity_type IN ('invoice','bill')
              ORDER BY detected_at ASC"
        );
        $stmt->execute(['t' => $tenantId]);
        $drifts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $out['errors'][] = 'drift_query_failed: ' . substr($e->getMessage(), 0, 200);
        return $out;
    }

    foreach ($drifts as $d) {
        try {
            if ($d['entity_type'] === 'invoice') {
                $res = _qboAutoReconcileInvoice($tenantId, $d, $actorUserId);
            } else {
                $res = _qboAutoReconcileBill($tenantId, $d, $actorUserId);
            }
            $out['payments_created'] += (int) ($res['payments_created'] ?? 0);
            if (!empty($res['reconciled'])) {
                if ($d['entity_type'] === 'invoice') $out['invoices_reconciled']++;
                else                                 $out['bills_reconciled']++;
                $out['drift_rows_closed']++;
            } else {
                $out['skipped']++;
            }
        } catch (\Throwable $e) {
            $out['errors'][] = sprintf(
                '%s drift_id=%d: %s',
                $d['entity_type'], (int) $d['id'], substr($e->getMessage(), 0, 200)
            );
        }
    }

    qboAudit($tenantId, 'auto_reconcile_run', [
        'direction' => 'inbound',
        'ok'        => empty($out['errors']),
        'detail'    => [
            'invoices_reconciled' => $out['invoices_reconciled'],
            'bills_reconciled'    => $out['bills_reconciled'],
            'payments_created'    => $out['payments_created'],
            'drift_rows_closed'   => $out['drift_rows_closed'],
            'skipped'             => $out['skipped'],
            'error_count'         => count($out['errors']),
        ],
    ]);

    return $out;
}

// ─────────────────────────────────────────────────────────────────────
// Invoice reconciliation
// ─────────────────────────────────────────────────────────────────────

function _qboAutoReconcileInvoice(int $tenantId, array $drift, ?int $actorUserId): array
{
    $pdo  = getDB();
    $cfId = (int) ($drift['coreflux_id'] ?? 0);
    if ($cfId <= 0) {
        return ['reconciled' => false, 'reason' => 'no_coreflux_id'];
    }

    // Pull the live CoreFlux invoice; bail if already paid/voided.
    $invStmt = $pdo->prepare(
        'SELECT id, invoice_number, client_name, currency, status,
                amount_due, total, amount_paid
           FROM billing_invoices WHERE tenant_id = :t AND id = :id LIMIT 1'
    );
    $invStmt->execute(['t' => $tenantId, 'id' => $cfId]);
    $inv = $invStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$inv) {
        return ['reconciled' => false, 'reason' => 'invoice_missing'];
    }
    if (in_array($inv['status'], ['paid', 'void', 'cancelled'], true)) {
        // Drift is now stale — close it.
        _qboCloseDrift($tenantId, (int) $drift['id'], 'no-op (CoreFlux already ' . $inv['status'] . ')');
        return ['reconciled' => true, 'reason' => 'already_' . $inv['status']];
    }

    $qboInvoiceId = (string) ($drift['qbo_id'] ?? '');
    if ($qboInvoiceId === '') {
        return ['reconciled' => false, 'reason' => 'no_qbo_id'];
    }

    // Find the QBO Payment(s) tied to this invoice in the inbound shadow.
    // The shadow stores `linked_invoice_ids` as a JSON-encoded array string;
    // a LIKE filter on `"qboId"` works on both MySQL and SQLite.
    $needle  = '%"' . str_replace('"', '', $qboInvoiceId) . '"%';
    $payStmt = $pdo->prepare(
        "SELECT id, qbo_payment_id, customer_name, payment_date,
                total_amount_cents, linked_invoice_ids
           FROM qbo_inbound_payments
          WHERE tenant_id = :t
            AND linked_invoice_ids LIKE :nd
          ORDER BY payment_date ASC, id ASC"
    );
    $payStmt->execute(['t' => $tenantId, 'nd' => $needle]);
    $qboPayments = $payStmt->fetchAll(\PDO::FETCH_ASSOC);
    if (!$qboPayments) {
        return ['reconciled' => false, 'reason' => 'no_linked_qbo_payment'];
    }

    $created = 0;
    foreach ($qboPayments as $qp) {
        // Refresh CoreFlux invoice state inside the loop — earlier payments may
        // have already brought amount_due to zero.
        $invStmt->execute(['t' => $tenantId, 'id' => $cfId]);
        $inv = $invStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$inv || (float) $inv['amount_due'] <= 0.005) break;

        $qboPaymentId = (string) $qp['qbo_payment_id'];
        $amount       = round(((int) $qp['total_amount_cents']) / 100, 2);
        $applyAmt     = min($amount, round((float) $inv['amount_due'], 2));
        if ($applyAmt <= 0) continue;

        // Idempotent insert by (tenant_id, source_system, external_id).
        $paymentId = _qboFindExistingCorefluxPayment($tenantId, 'billing_payments', $qboPaymentId);
        if ($paymentId === null) {
            try {
                _qboInsertBillingPaymentFromQbo($tenantId, $qp, $inv, $amount, $actorUserId);
                $paymentId = (int) $pdo->lastInsertId();
                $created++;
            } catch (\PDOException $e) {
                // Race or replay — try the lookup again.
                $paymentId = _qboFindExistingCorefluxPayment($tenantId, 'billing_payments', $qboPaymentId);
                if ($paymentId === null) throw $e;
            }
        }

        // Allocate to this specific invoice.
        try {
            billingAllocatePayment(
                (int) $paymentId,
                ['allocations' => [['invoice_id' => (int) $inv['id'], 'amount' => $applyAmt]]],
                $actorUserId
            );
            billingAudit('billing.auto_reconcile.allocated', [
                'payment_id'      => (int) $paymentId,
                'qbo_payment_id'  => $qboPaymentId,
                'invoice_id'      => (int) $inv['id'],
                'invoice_number'  => $inv['invoice_number'],
                'amount_applied'  => $applyAmt,
                'source'          => 'qbo_two_way_sync',
                'drift_id'        => (int) $drift['id'],
            ], (int) $paymentId);
        } catch (\Throwable $e) {
            // The payment row exists; allocation may already be full from a
            // prior cron pass. Log & move on.
            billingAudit('billing.auto_reconcile.alloc_skipped', [
                'payment_id' => (int) $paymentId,
                'reason'     => substr($e->getMessage(), 0, 200),
                'drift_id'   => (int) $drift['id'],
            ], (int) $paymentId);
        }
    }

    // Re-read final state.
    $invStmt->execute(['t' => $tenantId, 'id' => $cfId]);
    $inv = $invStmt->fetch(\PDO::FETCH_ASSOC);
    $isPaid = $inv && in_array($inv['status'], ['paid', 'void', 'cancelled'], true);

    if ($isPaid) {
        _qboCloseDrift(
            $tenantId,
            (int) $drift['id'],
            'auto-reconciled via QBO Payment(s) — invoice now ' . $inv['status']
        );
    }
    return ['reconciled' => $isPaid, 'payments_created' => $created];
}

// ─────────────────────────────────────────────────────────────────────
// Bill reconciliation
// ─────────────────────────────────────────────────────────────────────

function _qboAutoReconcileBill(int $tenantId, array $drift, ?int $actorUserId): array
{
    $pdo  = getDB();
    $cfId = (int) ($drift['coreflux_id'] ?? 0);
    if ($cfId <= 0) {
        return ['reconciled' => false, 'reason' => 'no_coreflux_id'];
    }

    $billStmt = $pdo->prepare(
        'SELECT id, bill_number, internal_ref, vendor_name, currency, status,
                amount_due, total, amount_paid
           FROM ap_bills WHERE tenant_id = :t AND id = :id LIMIT 1'
    );
    $billStmt->execute(['t' => $tenantId, 'id' => $cfId]);
    $bill = $billStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$bill) {
        return ['reconciled' => false, 'reason' => 'bill_missing'];
    }
    if (in_array($bill['status'], ['paid', 'void', 'cancelled'], true)) {
        _qboCloseDrift($tenantId, (int) $drift['id'], 'no-op (CoreFlux already ' . $bill['status'] . ')');
        return ['reconciled' => true, 'reason' => 'already_' . $bill['status']];
    }
    if ($bill['status'] === 'disputed') {
        return ['reconciled' => false, 'reason' => 'disputed_skip'];
    }

    $qboBillId = (string) ($drift['qbo_id'] ?? '');
    if ($qboBillId === '') {
        return ['reconciled' => false, 'reason' => 'no_qbo_id'];
    }

    $needle  = '%"' . str_replace('"', '', $qboBillId) . '"%';
    $payStmt = $pdo->prepare(
        "SELECT id, qbo_billpayment_id, payment_date, total_amount_cents,
                pay_type, linked_bill_ids
           FROM qbo_inbound_billpayments
          WHERE tenant_id = :t
            AND linked_bill_ids LIKE :nd
          ORDER BY payment_date ASC, id ASC"
    );
    $payStmt->execute(['t' => $tenantId, 'nd' => $needle]);
    $qboPayments = $payStmt->fetchAll(\PDO::FETCH_ASSOC);
    if (!$qboPayments) {
        return ['reconciled' => false, 'reason' => 'no_linked_qbo_billpayment'];
    }

    $created = 0;
    foreach ($qboPayments as $qp) {
        $billStmt->execute(['t' => $tenantId, 'id' => $cfId]);
        $bill = $billStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$bill || (float) $bill['amount_due'] <= 0.005) break;

        $qboBillPaymentId = (string) $qp['qbo_billpayment_id'];
        $amount           = round(((int) $qp['total_amount_cents']) / 100, 2);
        $applyAmt         = min($amount, round((float) $bill['amount_due'], 2));
        if ($applyAmt <= 0) continue;

        $paymentId = _qboFindExistingCorefluxPayment($tenantId, 'ap_payments', $qboBillPaymentId);
        if ($paymentId === null) {
            try {
                _qboInsertApPaymentFromQbo($tenantId, $qp, $bill, $amount, $actorUserId);
                $paymentId = (int) $pdo->lastInsertId();
                $created++;
            } catch (\PDOException $e) {
                $paymentId = _qboFindExistingCorefluxPayment($tenantId, 'ap_payments', $qboBillPaymentId);
                if ($paymentId === null) throw $e;
            }
        }

        try {
            apAllocatePayment(
                (int) $paymentId,
                ['allocations' => [['bill_id' => (int) $bill['id'], 'amount' => $applyAmt]]],
                $actorUserId
            );
            apAudit('ap.auto_reconcile.allocated', [
                'payment_id'        => (int) $paymentId,
                'qbo_billpayment_id'=> $qboBillPaymentId,
                'bill_id'           => (int) $bill['id'],
                'amount_applied'    => $applyAmt,
                'source'            => 'qbo_two_way_sync',
                'drift_id'          => (int) $drift['id'],
            ], (int) $paymentId);
        } catch (\Throwable $e) {
            apAudit('ap.auto_reconcile.alloc_skipped', [
                'payment_id' => (int) $paymentId,
                'reason'     => substr($e->getMessage(), 0, 200),
                'drift_id'   => (int) $drift['id'],
            ], (int) $paymentId);
        }
    }

    $billStmt->execute(['t' => $tenantId, 'id' => $cfId]);
    $bill = $billStmt->fetch(\PDO::FETCH_ASSOC);
    $isPaid = $bill && in_array($bill['status'], ['paid', 'void', 'cancelled'], true);

    if ($isPaid) {
        _qboCloseDrift(
            $tenantId,
            (int) $drift['id'],
            'auto-reconciled via QBO BillPayment(s) — bill now ' . $bill['status']
        );
    }
    return ['reconciled' => $isPaid, 'payments_created' => $created];
}

// ─────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────

function _qboFindExistingCorefluxPayment(int $tenantId, string $table, string $externalId): ?int
{
    if (!in_array($table, ['billing_payments', 'ap_payments'], true)) return null;
    try {
        $stmt = getDB()->prepare(
            "SELECT id FROM {$table}
              WHERE tenant_id = :t AND source_system = 'qbo' AND external_id = :x
              LIMIT 1"
        );
        $stmt->execute(['t' => $tenantId, 'x' => $externalId]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $r ? (int) $r['id'] : null;
    } catch (\Throwable $_) {
        return null;
    }
}

function _qboInsertBillingPaymentFromQbo(
    int $tenantId,
    array $qboPayment,
    array $invoice,
    float $amount,
    ?int $actorUserId
): void {
    $clientName = (string) ($qboPayment['customer_name'] ?? $invoice['client_name'] ?? 'QuickBooks Customer');
    $rcvAt      = (string) ($qboPayment['payment_date']  ?? date('Y-m-d'));
    getDB()->prepare(
        "INSERT INTO billing_payments
            (tenant_id, client_name, received_at, method, reference, external_id,
             source_system, amount, currency, unallocated_amount, notes,
             created_by_user_id, created_at)
         VALUES
            (:t, :cn, :rd, 'other', :ref, :ext,
             'qbo', :amt, :cur, :amt2,
             :nt, :u, " . _qboPaymentNow() . ")"
    )->execute([
        't'  => $tenantId,
        'cn' => $clientName,
        'rd' => $rcvAt,
        'ref'=> 'QBO Payment ' . (string) $qboPayment['qbo_payment_id'],
        'ext'=> (string) $qboPayment['qbo_payment_id'],
        'amt'=> $amount,
        'amt2'=> $amount,
        'cur'=> (string) ($invoice['currency'] ?? 'USD'),
        'nt' => 'Auto-reconciled from QBO two-way sync.',
        'u'  => $actorUserId,
    ]);
}

function _qboInsertApPaymentFromQbo(
    int $tenantId,
    array $qboBillPayment,
    array $bill,
    float $amount,
    ?int $actorUserId
): void {
    $vendor = (string) ($bill['vendor_name'] ?? 'QuickBooks Vendor');
    $payAt  = (string) ($qboBillPayment['payment_date'] ?? date('Y-m-d'));
    getDB()->prepare(
        "INSERT INTO ap_payments
            (tenant_id, vendor_name, pay_date, method, reference, external_id,
             source_system, amount, currency, unallocated_amount,
             status, notes, created_by_user_id, created_at, updated_at)
         VALUES
            (:t, :vn, :pd, 'other', :ref, :ext,
             'qbo', :amt, :cur, :amt2,
             'cleared', :nt, :u, " . _qboPaymentNow() . ", " . _qboPaymentNow() . ")"
    )->execute([
        't'  => $tenantId,
        'vn' => $vendor,
        'pd' => $payAt,
        'ref'=> 'QBO BillPayment ' . (string) $qboBillPayment['qbo_billpayment_id'],
        'ext'=> (string) $qboBillPayment['qbo_billpayment_id'],
        'amt'=> $amount,
        'amt2'=> $amount,
        'cur'=> (string) ($bill['currency'] ?? 'USD'),
        'nt' => 'Auto-reconciled from QBO two-way sync.',
        'u'  => $actorUserId,
    ]);
}

function _qboPaymentNow(): string
{
    // Use CURRENT_TIMESTAMP across MySQL + SQLite (both honour it).
    return 'CURRENT_TIMESTAMP';
}

function _qboCloseDrift(int $tenantId, int $driftId, string $note): void
{
    try {
        getDB()->prepare(
            "UPDATE qbo_sync_drift
                SET status = 'reconciled',
                    resolved_at = :ra,
                    resolution_note = :n
              WHERE id = :id AND tenant_id = :t"
        )->execute([
            'ra' => date('Y-m-d H:i:s'),
            'n'  => substr($note, 0, 500),
            'id' => $driftId,
            't'  => $tenantId,
        ]);
    } catch (\Throwable $_) { /* swallow — drift row may have been resolved by an operator concurrently */ }
}
