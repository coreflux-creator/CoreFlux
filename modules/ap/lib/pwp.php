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
        'SELECT id, tenant_id, period_start, period_end, status
           FROM billing_invoices WHERE id = :id AND tenant_id = :t'
    );
    $inv->execute(['id' => $arInvoiceId, 't' => $tenantId]);
    $invRow = $inv->fetch(\PDO::FETCH_ASSOC);
    if (!$invRow) throw new \RuntimeException("AR invoice {$arInvoiceId} not found");

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
    $params = ['t' => $tenantId, 'ps' => $invRow['period_start'], 'pe' => $invRow['period_end']];
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
               AND b.status NOT IN ("paid","void")
               AND b.period_start = :ps AND b.period_end = :pe
               AND bl.placement_id IN (' . implode(',', $placeholders) . ')';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $candidates = $st->fetchAll(\PDO::FETCH_ASSOC);

    $linked = [];
    $pdo->beginTransaction();
    try {
        foreach ($candidates as $b) {
            $parsed = apPwpParseTerms($b['payment_terms']);
            $isPwp  = $parsed['is_pwp'] || (int) ($b['default_pwp'] ?? 0) === 1;
            if (!$isPwp) continue;
            // Don't clobber an existing link to a different invoice.
            if (!empty($b['linked_ar_invoice_id']) && (int) $b['linked_ar_invoice_id'] !== $arInvoiceId) continue;
            // Already triggered? Leave alone.
            if (($b['pwp_status'] ?? '') === 'triggered') continue;

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
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['linked' => $linked];
}

/**
 * Explicitly link/relink an AP bill to an AR invoice and set its PWP terms.
 * Used by the API for manual control.
 */
function apPwpSetLink(int $tenantId, int $billId, int $arInvoiceId, ?string $paymentTerms = null, ?int $actorUserId = null): array {
    $pdo = getDB();
    $b = $pdo->prepare('SELECT id, tenant_id, status, payment_terms FROM ap_bills WHERE id = :id AND tenant_id = :t');
    $b->execute(['id' => $billId, 't' => $tenantId]);
    $row = $b->fetch(\PDO::FETCH_ASSOC);
    if (!$row) throw new \RuntimeException("AP bill {$billId} not found");
    if (in_array($row['status'], ['paid', 'void'], true)) {
        throw new \RuntimeException("AP bill is {$row['status']}; cannot link");
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

    return ['bill_id' => $billId, 'ar_invoice_id' => $arInvoiceId, 'payment_terms' => $terms];
}

function apPwpClearLink(int $tenantId, int $billId, ?int $actorUserId = null): array {
    $pdo = getDB();
    $pdo->prepare(
        'UPDATE ap_bills
            SET linked_ar_invoice_id = NULL, pwp_status = "not_pwp"
          WHERE id = :id AND tenant_id = :t AND pwp_status IN ("awaiting_ar","partial_triggered")'
    )->execute(['id' => $billId, 't' => $tenantId]);

    apAudit('ap.bill.pwp.cleared', ['bill_id' => $billId], $billId);
    return ['bill_id' => $billId];
}

/**
 * Called from the billing cash-application path the instant an AR invoice
 * is FULLY paid. Releases every PWP bill linked to it:
 *   - sets pwp_status='triggered', pwp_released_at=NOW()
 *   - bumps due_date = today + N days (from PWP_NET<N>)
 *   - if bill is still pending_review/pending_approval → transition to 'approved'
 *     (approved_by = system / actor)
 *
 * Returns ['released' => [['bill_id','new_due_date','prev_status','new_status'], ...]].
 *
 * NOTE: caller decides when to invoke (full vs partial). We only trigger if
 * the AR invoice's amount_due rounds to 0.
 */
function apPwpReleaseForArInvoice(int $tenantId, int $arInvoiceId, ?int $actorUserId = null): array {
    $pdo = getDB();
    $inv = $pdo->prepare('SELECT id, amount_due, status FROM billing_invoices WHERE id = :id AND tenant_id = :t');
    $inv->execute(['id' => $arInvoiceId, 't' => $tenantId]);
    $invRow = $inv->fetch(\PDO::FETCH_ASSOC);
    if (!$invRow) return ['released' => [], 'reason' => 'AR invoice not found'];
    if (round((float) $invRow['amount_due'], 2) > 0.005) {
        return ['released' => [], 'reason' => 'AR invoice not fully paid yet'];
    }

    $st = $pdo->prepare(
        'SELECT id, status, payment_terms, due_date
           FROM ap_bills
          WHERE tenant_id = :t AND linked_ar_invoice_id = :ar AND pwp_status = "awaiting_ar"
          FOR UPDATE'
    );
    $released = [];
    $pdo->beginTransaction();
    try {
        $st->execute(['t' => $tenantId, 'ar' => $arInvoiceId]);
        $bills = $st->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($bills as $b) {
            $parsed = apPwpParseTerms($b['payment_terms']);
            $netDays = $parsed['is_pwp'] ? $parsed['net_days'] : 0;
            $newDue = date('Y-m-d', strtotime("+{$netDays} days"));

            $prevStatus = (string) $b['status'];
            $newStatus  = $prevStatus;
            if (in_array($prevStatus, ['inbox', 'pending_review', 'pending_approval'], true)) {
                $newStatus = 'approved';
            }

            $pdo->prepare(
                'UPDATE ap_bills
                    SET pwp_status = "triggered",
                        pwp_released_at = NOW(),
                        due_date = :due,
                        status = :st,
                        approved_by_user_id = COALESCE(approved_by_user_id, :u),
                        approved_at = COALESCE(approved_at, NOW())
                  WHERE id = :id AND tenant_id = :t'
            )->execute([
                'due' => $newDue, 'st' => $newStatus, 'u' => $actorUserId,
                'id' => (int) $b['id'], 't' => $tenantId,
            ]);

            apAudit('ap.bill.pwp.released', [
                'bill_id' => (int) $b['id'],
                'ar_invoice_id' => $arInvoiceId,
                'prev_status' => $prevStatus,
                'new_status'  => $newStatus,
                'new_due_date'=> $newDue,
                'net_days_after_ar' => $netDays,
            ], (int) $b['id']);

            $released[] = [
                'bill_id'      => (int) $b['id'],
                'prev_status'  => $prevStatus,
                'new_status'   => $newStatus,
                'new_due_date' => $newDue,
            ];
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['released' => $released];
}
