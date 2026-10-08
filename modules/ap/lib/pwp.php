<?php
/**
 * AP Module — Pay-When-Paid (PWP) helpers.
 *
 * Pure functions that operate on `ap_bills.payment_terms`,
 * `linked_ar_invoice_id`, `pwp_status`, `pwp_released_at`.
 *
 * Public surface:
 *   apPwpParseTerms(?string $terms): ['is_pwp'=>bool, 'net_days'=>int]
 *   apPwpAutoLinkForArInvoice(int $tenantId, int $arInvoiceId, ?int $actorUserId = null): array
 *   apPwpSetLink(int $tenantId, int $billId, int $arInvoiceId, ?string $paymentTerms = null, ?int $actorUserId = null): array
 *   apPwpClearLink(int $tenantId, int $billId, ?int $actorUserId = null): array
 *   apPwpReleaseForArInvoice(int $tenantId, int $arInvoiceId, ?int $actorUserId = null): array
 *
 * SPEC: PRD §"Pay-When-Paid" — released only on FULL AR collection.
 */

declare(strict_types=1);

require_once __DIR__ . '/ap.php';

/**
 * Parse a payment_terms string. Examples:
 *   'NET30'       → ['is_pwp' => false, 'net_days' => 30]
 *   'PWP'         → ['is_pwp' => true,  'net_days' => 0]
 *   'PWP_NET10'   → ['is_pwp' => true,  'net_days' => 10]
 *   null / 'foo'  → ['is_pwp' => false, 'net_days' => 30]   (caller-side default)
 */
function apPwpParseTerms(?string $terms): array {
    $t = strtoupper(trim((string) $terms));
    if ($t === '') return ['is_pwp' => false, 'net_days' => 30];
    if ($t === 'PWP') return ['is_pwp' => true, 'net_days' => 0];
    if (preg_match('/^PWP_NET(\d+)$/', $t, $m)) {
        return ['is_pwp' => true, 'net_days' => (int) $m[1]];
    }
    if (preg_match('/^NET(\d+)$/', $t, $m)) {
        return ['is_pwp' => false, 'net_days' => (int) $m[1]];
    }
    return ['is_pwp' => false, 'net_days' => 30];
}

/**
 * Auto-link AP bills to an AR invoice when they came from the same time
 * source (same placement_id + overlapping period). Only AP bills whose
 * `payment_terms` resolve to PWP — either an explicit override OR their
 * vendor's `default_pwp` flag — get linked.
 *
 * Returns ['linked' => [['bill_id'=>N, 'vendor_name'=>..., 'amount_due'=>X], ...]].
 * Idempotent: bills already linked to a DIFFERENT AR invoice are skipped.
 */
function apPwpAutoLinkForArInvoice(int $tenantId, int $arInvoiceId, ?int $actorUserId = null): array {
    $pdo = getDB();

    // Load the AR invoice + its placement_ids.
    $inv = $pdo->prepare(
        'SELECT id, tenant_id, entity_id, period_start, period_end, status
           FROM billing_invoices WHERE id = :id AND tenant_id = :t'
    );
    $inv->execute(['id' => $arInvoiceId, 't' => $tenantId]);
    $invRow = $inv->fetch(\PDO::FETCH_ASSOC);
    if (!$invRow) throw new \RuntimeException("AR invoice {$arInvoiceId} not found");
    if ($invRow['status'] === 'void') return ['linked' => [], 'reason' => 'AR invoice is void'];
    $entityId = (int) ($invRow['entity_id'] ?? 0);
    if ($entityId <= 0) return ['linked' => [], 'reason' => 'Assign the AR invoice to a legal entity before linking PWP bills'];

    $pq = $pdo->prepare(
        'SELECT DISTINCT placement_id FROM billing_invoice_lines
          WHERE invoice_id = :i AND placement_id IS NOT NULL'
    );
    $pq->execute(['i' => $arInvoiceId]);
    $placementIds = array_map('intval', array_column($pq->fetchAll(\PDO::FETCH_ASSOC), 'placement_id'));
    if (empty($placementIds)) {
        return ['linked' => [], 'reason' => 'AR invoice has no placement lines — nothing to link'];
    }

    // Find AP bills from same period + placements that are either explicitly
    // PWP-termed or belong to a vendor whose default_pwp flag is on.
    $placeholders = [];
    $params = ['t' => $tenantId, 'e' => $entityId,
        'ps' => $invRow['period_start'], 'pe' => $invRow['period_end']];
    foreach ($placementIds as $i => $pid) {
        $k = 'p' . $i;
        $placeholders[] = ':' . $k;
        $params[$k] = $pid;
    }

    $sql = 'SELECT DISTINCT b.id, b.vendor_name, b.amount_due, b.status, b.payment_terms,
                            b.linked_ar_invoice_id, b.pwp_status,
                            v.default_pwp
              FROM ap_bills b
              JOIN ap_bill_lines bl ON bl.bill_id = b.id
              LEFT JOIN ap_vendors_index v ON v.tenant_id = b.tenant_id AND v.vendor_name = b.vendor_name
             WHERE b.tenant_id = :t
               AND b.entity_id = :e
               AND b.status NOT IN ("paid","void")
               AND b.period_start = :ps AND b.period_end = :pe
               AND bl.placement_id IN (' . implode(',', $placeholders) . ')';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $candidates = $st->fetchAll(\PDO::FETCH_ASSOC);

    $linked = [];
    $ownsTxn = !$pdo->inTransaction();
    if ($ownsTxn) $pdo->beginTransaction();
    try {
        foreach ($candidates as $b) {
            $parsed = apPwpParseTerms($b['payment_terms']);
            $isPwp  = $parsed['is_pwp'] || (int) ($b['default_pwp'] ?? 0) === 1
                || ($b['pwp_status'] ?? '') === 'awaiting_ar';
            if (!$isPwp) continue;
            // Don't clobber an existing link to a different invoice.
            if (!empty($b['linked_ar_invoice_id']) && (int) $b['linked_ar_invoice_id'] !== $arInvoiceId) continue;
            // A released bill cannot be silently returned to the collection hold.
            if (in_array($b['pwp_status'] ?? '', ['triggered', 'partial_triggered'], true)) continue;

            $newTerms = $parsed['is_pwp'] ? $b['payment_terms'] : 'PWP';
            $pdo->prepare(
                'UPDATE ap_bills
                    SET linked_ar_invoice_id = :ar,
                        payment_terms = COALESCE(payment_terms, :nt),
                        pwp_status = "awaiting_ar"
                  WHERE id = :id AND tenant_id = :t'
            )->execute([
                'ar' => $arInvoiceId, 'nt' => $newTerms, 'id' => (int) $b['id'], 't' => $tenantId,
            ]);
            $linked[] = [
                'bill_id'    => (int) $b['id'],
                'vendor_name'=> $b['vendor_name'],
                'amount_due' => (float) $b['amount_due'],
            ];
            apAudit('ap.bill.pwp.linked', [
                'bill_id' => (int) $b['id'],
                'ar_invoice_id' => $arInvoiceId,
                'auto'    => true,
            ], (int) $b['id']);
        }
        if ($ownsTxn) $pdo->commit();
    } catch (\Throwable $e) {
        if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['linked' => $linked];
}

/**
 * Link a newly-created PWP AP bill when its matching AR invoice already
 * exists. This is the inverse ordering of apPwpAutoLinkForArInvoice().
 */
function apPwpAutoLinkForApBill(int $tenantId, int $billId, ?int $actorUserId = null): array {
    $pdo = getDB();
    $st = $pdo->prepare(
        'SELECT b.id, b.entity_id, b.status, b.period_start, b.period_end, b.payment_terms, b.pwp_status,
                b.linked_ar_invoice_id, v.default_pwp
           FROM ap_bills b
      LEFT JOIN ap_vendors_index v
             ON v.tenant_id = b.tenant_id AND v.vendor_name = b.vendor_name
          WHERE b.tenant_id = :t AND b.id = :id LIMIT 1'
    );
    $st->execute(['t' => $tenantId, 'id' => $billId]);
    $bill = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    if (!$bill) throw new \RuntimeException("AP bill {$billId} not found");
    if (in_array($bill['status'], ['paid', 'void'], true)) {
        return ['linked' => [], 'reason' => 'bill is paid or void'];
    }
    $entityId = (int) ($bill['entity_id'] ?? 0);
    if ($entityId <= 0) return ['linked' => [], 'reason' => 'Assign the AP bill to a legal entity before linking'];
    $parsed = apPwpParseTerms($bill['payment_terms']);
    if (!$parsed['is_pwp'] && empty($bill['default_pwp']) && ($bill['pwp_status'] ?? '') !== 'awaiting_ar') {
        return ['linked' => [], 'reason' => 'bill is not paid-when-paid'];
    }
    if (!empty($bill['linked_ar_invoice_id'])) {
        return ['linked' => [], 'ar_invoice_id' => (int) $bill['linked_ar_invoice_id'], 'reason' => 'already linked'];
    }
    if (empty($bill['period_start']) || empty($bill['period_end'])) {
        return ['linked' => [], 'reason' => 'bill has no settlement period'];
    }

    $placements = $pdo->prepare(
        'SELECT DISTINCT placement_id FROM ap_bill_lines
          WHERE bill_id = :id AND placement_id IS NOT NULL'
    );
    $placements->execute(['id' => $billId]);
    $placementIds = array_map('intval', array_column($placements->fetchAll(\PDO::FETCH_ASSOC), 'placement_id'));
    if (!$placementIds) return ['linked' => [], 'reason' => 'bill has no placement lines'];

    $params = ['t' => $tenantId, 'e' => $entityId,
        'ps' => $bill['period_start'], 'pe' => $bill['period_end']];
    $ph = [];
    foreach ($placementIds as $i => $placementId) {
        $key = 'p' . $i;
        $ph[] = ':' . $key;
        $params[$key] = $placementId;
    }
    $invoice = $pdo->prepare(
        'SELECT DISTINCT i.id
           FROM billing_invoices i
           JOIN billing_invoice_lines il ON il.invoice_id = i.id
          WHERE i.tenant_id = :t AND i.status <> "void"
            AND i.entity_id = :e
            AND i.period_start = :ps AND i.period_end = :pe
            AND il.placement_id IN (' . implode(',', $ph) . ')
          ORDER BY i.id'
    );
    $invoice->execute($params);
    $invoiceIds = array_map('intval', array_column($invoice->fetchAll(\PDO::FETCH_ASSOC), 'id'));
    if (count($invoiceIds) !== 1) {
        return ['linked' => [], 'reason' => count($invoiceIds) ? 'multiple matching AR invoices' : 'matching AR invoice not created yet'];
    }

    $arInvoiceId = $invoiceIds[0];
    $result = apPwpAutoLinkForArInvoice($tenantId, $arInvoiceId, $actorUserId);
    $release = apPwpReleaseForArInvoice($tenantId, $arInvoiceId, $actorUserId);
    return ['linked' => $result['linked'] ?? [], 'ar_invoice_id' => $arInvoiceId, 'released' => $release['released'] ?? []];
}

/**
 * Explicitly link/relink an AP bill to an AR invoice and set its PWP terms.
 * Used by the API for manual control.
 */
function apPwpSetLink(int $tenantId, int $billId, int $arInvoiceId, ?string $paymentTerms = null, ?int $actorUserId = null): array {
    $pdo = getDB();
    $ownsTxn = !$pdo->inTransaction();
    if ($ownsTxn) $pdo->beginTransaction();
    try {
        $inv = $pdo->prepare('SELECT entity_id, status FROM billing_invoices
            WHERE id = :id AND tenant_id = :t FOR UPDATE');
        $inv->execute(['id' => $arInvoiceId, 't' => $tenantId]);
        $invoice = $inv->fetch(\PDO::FETCH_ASSOC);
        if (!$invoice || $invoice['status'] === 'void') {
            throw new \RuntimeException('Choose an active AR invoice in this workspace');
        }
        $b = $pdo->prepare('SELECT id, entity_id, status, payment_terms, pwp_status FROM ap_bills
            WHERE id = :id AND tenant_id = :t FOR UPDATE');
        $b->execute(['id' => $billId, 't' => $tenantId]);
        $row = $b->fetch(\PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException("AP bill {$billId} not found");
        if (in_array($row['status'], ['paid', 'void'], true)) {
            throw new \RuntimeException("AP bill is {$row['status']}; cannot link");
        }
        if (in_array($row['pwp_status'], ['triggered', 'partial_triggered'], true)) {
            throw new \RuntimeException('This bill was already released by a client payment; review its AP history before changing the link');
        }
        if ((int) ($row['entity_id'] ?? 0) <= 0
            || (int) $row['entity_id'] !== (int) ($invoice['entity_id'] ?? 0)) {
            throw new \RuntimeException('PWP bill and invoice must belong to the same legal entity');
        }
        $terms = $paymentTerms !== null ? strtoupper(trim($paymentTerms)) : ($row['payment_terms'] ?: 'PWP');
        $parsed = apPwpParseTerms($terms);
        if (!$parsed['is_pwp']) throw new \RuntimeException("payment_terms '{$terms}' is not a PWP variant");

        $pdo->prepare(
            'UPDATE ap_bills
                SET linked_ar_invoice_id = :ar, payment_terms = :pt, pwp_status = "awaiting_ar"
              WHERE id = :id AND tenant_id = :t'
        )->execute(['ar' => $arInvoiceId, 'pt' => $terms, 'id' => $billId, 't' => $tenantId]);

        apAudit('ap.bill.pwp.linked', [
            'bill_id' => $billId, 'ar_invoice_id' => $arInvoiceId,
            'payment_terms' => $terms, 'auto' => false,
        ], $billId);
        if ($ownsTxn) $pdo->commit();
        return ['bill_id' => $billId, 'ar_invoice_id' => $arInvoiceId, 'payment_terms' => $terms];
    } catch (\Throwable $e) {
        if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function apPwpClearLink(int $tenantId, int $billId, ?int $actorUserId = null): array {
    $pdo = getDB();
    $ownsTxn = !$pdo->inTransaction();
    if ($ownsTxn) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT status, pwp_status, linked_ar_invoice_id FROM ap_bills
            WHERE id = :id AND tenant_id = :t FOR UPDATE');
        $st->execute(['id' => $billId, 't' => $tenantId]);
        $bill = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$bill) throw new \RuntimeException("AP bill {$billId} not found");
        if ($bill['pwp_status'] !== 'awaiting_ar' || in_array($bill['status'], ['paid', 'void'], true)) {
            throw new \RuntimeException('Only an unreleased PWP bill can be unlinked');
        }
        $previousInvoiceId = (int) ($bill['linked_ar_invoice_id'] ?? 0);
        if ($previousInvoiceId > 0) {
            $pdo->prepare('UPDATE ap_bills SET linked_ar_invoice_id = NULL
                WHERE id = :id AND tenant_id = :t')
                ->execute(['id' => $billId, 't' => $tenantId]);
            apAudit('ap.bill.pwp.cleared', [
                'bill_id' => $billId, 'ar_invoice_id' => $previousInvoiceId,
            ], $billId);
        }
        if ($ownsTxn) $pdo->commit();
        return ['bill_id' => $billId, 'cleared' => $previousInvoiceId > 0,
            'pwp_status' => 'awaiting_ar'];
    } catch (\Throwable $e) {
        if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Called from the billing cash-application path the instant an AR invoice
 * is FULLY paid. Releases every PWP bill linked to it:
 *   - sets pwp_status='triggered', pwp_released_at=NOW()
 *   - bumps due_date = today + N days (from PWP_NET<N>)
 *   - leaves the bill's approval status untouched; collection does not authorize AP
 *
 * Returns ['released' => [['bill_id','new_due_date','prev_status','new_status'], ...]].
 *
 * NOTE: caller decides when to invoke (full vs partial). We only trigger if
 * the AR invoice is paid and its amount_due rounds to 0.
 */
/**
 * Return any allocated bills on the given payment that are still
 * `pwp_status='awaiting_ar'` — i.e. the linked AR invoice hasn't been
 * fully paid by the client yet. Used as the 4-way match gate before
 * AP `send` / `originate_batch` actually releases vendor cash.
 *
 * Returns rows shaped: [ {id, internal_ref, vendor_name, linked_ar_invoice_id}, ... ]
 * Empty array means "clear to release".
 */
function apPwpAllocatedBillsAwaitingAr(int $tenantId, int $paymentId): array {
    $pdo = getDB();
    $st = $pdo->prepare(
        'SELECT DISTINCT b.id, b.internal_ref, b.vendor_name, b.linked_ar_invoice_id
           FROM ap_payment_allocations a
           JOIN ap_bills b ON b.id = a.bill_id
          WHERE a.payment_id = :p
            AND b.tenant_id  = :t
            AND b.pwp_status = "awaiting_ar"'
    );
    $st->execute(['p' => $paymentId, 't' => $tenantId]);
    return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function apPwpReleaseForArInvoice(int $tenantId, int $arInvoiceId, ?int $actorUserId = null): array {
    $pdo = getDB();
    $ownsTxn = !$pdo->inTransaction();
    if ($ownsTxn) $pdo->beginTransaction();
    try {
        $inv = $pdo->prepare('SELECT id, entity_id, amount_due, status FROM billing_invoices
            WHERE id = :id AND tenant_id = :t FOR UPDATE');
        $inv->execute(['id' => $arInvoiceId, 't' => $tenantId]);
        $invRow = $inv->fetch(\PDO::FETCH_ASSOC);
        if (!$invRow || $invRow['status'] !== 'paid'
            || round((float) $invRow['amount_due'], 2) > 0.005) {
            if ($ownsTxn) $pdo->commit();
            return ['released' => [], 'reason' => $invRow
                ? 'AR invoice not fully paid yet' : 'AR invoice not found'];
        }

        $st = $pdo->prepare(
            'SELECT id, entity_id, status, payment_terms, due_date
               FROM ap_bills
              WHERE tenant_id = :t AND linked_ar_invoice_id = :ar AND pwp_status = "awaiting_ar"
              FOR UPDATE'
        );
        $released = [];
        $st->execute(['t' => $tenantId, 'ar' => $arInvoiceId]);
        $bills = $st->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($bills as $bill) {
            if ((int) ($bill['entity_id'] ?? 0) <= 0
                || (int) $bill['entity_id'] !== (int) ($invRow['entity_id'] ?? 0)) {
                throw new \RuntimeException('Linked PWP bill and AR invoice have different legal entities; review the link');
            }
        }

        foreach ($bills as $b) {
            $parsed = apPwpParseTerms($b['payment_terms']);
            $netDays = $parsed['is_pwp'] ? $parsed['net_days'] : 0;
            $newDue = date('Y-m-d', strtotime("+{$netDays} days"));

            $prevStatus = (string) $b['status'];

            $pdo->prepare(
                'UPDATE ap_bills
                    SET pwp_status = "triggered",
                        pwp_released_at = NOW(),
                        due_date = :due
                  WHERE id = :id AND tenant_id = :t'
            )->execute([
                'due' => $newDue,
                'id' => (int) $b['id'], 't' => $tenantId,
            ]);

            apAudit('ap.bill.pwp.released', [
                'bill_id' => (int) $b['id'],
                'ar_invoice_id' => $arInvoiceId,
                'prev_status' => $prevStatus,
                'new_status'  => $prevStatus,
                'new_due_date'=> $newDue,
                'net_days_after_ar' => $netDays,
            ], (int) $b['id']);

            $released[] = [
                'bill_id'      => (int) $b['id'],
                'prev_status'  => $prevStatus,
                'new_status'   => $prevStatus,
                'new_due_date' => $newDue,
            ];
        }
        if ($ownsTxn) $pdo->commit();
    } catch (\Throwable $e) {
        if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['released' => $released];
}
