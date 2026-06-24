<?php
/**
 * Staffing Timesheet Lifecycle Resolver — 2026-02
 *
 * Walks the entire downstream cascade for a single timesheet or
 * `time_entry` and returns a structured timeline the UI can render
 * vertically:
 *
 *   1. Submission + Approval audit
 *   2. Accrual JEs (via accounting_events.source_module='staffing')
 *   3. AR Invoice(s)         — billing_invoice_lines.source_type='time_entry'
 *      ├─ Billing JE          — accounting_journal_entries.source_module='billing'
 *      └─ Cash receipts       — billing_payment_allocations → billing_payments
 *   4. AP Bill(s)             — ap_bill_lines.source_type='time_entry'
 *      ├─ AP JE               — accounting_journal_entries.source_module='ap'
 *      ├─ PWP link/release    — audit_log ap.bill.pwp.*
 *      └─ Cash disbursement   — ap_payment_allocations → ap_payments
 *                                 (incl. rail_external_ref + rail status)
 *
 * Returns shape:
 *   [
 *     'timesheet'       => row,
 *     'approvals'       => [...],
 *     'accrual_events'  => [...],
 *     'ar' => [{ invoice, lines, je, payments[] }, ...],
 *     'ap' => [{ bill, lines, je, payments[], pwp_events[] }, ...],
 *     'summary' => { revenue_billed, ar_collected, vendor_owed, vendor_paid }
 *   ]
 *
 * Read-only — never mutates. All SQL is tenant-scoped.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/tenant_scope.php';

/**
 * Full lifecycle cascade for a single timesheet.
 */
function staffingTimesheetLifecycle(int $tenantId, int $timesheetId): array
{
    $pdo = getDB();

    // 1. Timesheet header + entries.
    $ts = scopedFind(
        'SELECT t.*, p.first_name, p.last_name, p.email_primary
           FROM staffing_timesheets t
      LEFT JOIN people p ON p.id = t.person_id AND p.tenant_id = t.tenant_id
          WHERE t.tenant_id = :tenant_id AND t.id = :id LIMIT 1',
        ['id' => $timesheetId]
    );
    if (!$ts) throw new \RuntimeException("timesheet {$timesheetId} not found");

    $entries = scopedQuery(
        "SELECT te.id, te.placement_id, te.work_date, te.hour_type,
                te.hours, te.billable, te.payable, te.description, te.status,
                pl.title AS placement_title,
                COALESCE(pl.end_client_name, '') AS client_name
           FROM time_entries te
      LEFT JOIN placements pl ON pl.id = te.placement_id AND pl.tenant_id = te.tenant_id
          WHERE te.tenant_id = :tenant_id
            AND te.timesheet_id = :tid
            AND te.status != 'superseded'
          ORDER BY te.work_date, te.placement_id, te.id",
        ['tid' => $timesheetId]
    );
    $entryIds = array_map(static fn ($e) => (int) $e['id'], $entries);

    // 2. Approval audit — submitted_at / approved_at on the header +
    //    any audit_log rows for this timesheet.
    $approvals = [
        'submitted_at'    => $ts['submitted_at']    ?? null,
        'approved_at'     => $ts['approved_at']     ?? null,
        'rejection_reason'=> $ts['rejection_reason'] ?? null,
        'audit_events'    => _lifecycleAuditEvents($pdo, $tenantId, [
            'staffing.timesheet.submitted',
            'staffing.timesheet.approved',
            'staffing.timesheet.rejected',
            'staffing.timesheet.reopened',
        ], $timesheetId),
    ];

    // 3. Accrual JEs — accounting_events for this timesheet.
    $accrualEvents = _lifecycleAccrualEvents($pdo, $tenantId, $timesheetId);

    // 4. AR side — invoices that consumed at least one of these entries.
    $arRows = _lifecycleArSide($pdo, $tenantId, $entryIds);

    // 5. AP side — bills that consumed at least one of these entries.
    $apRows = _lifecycleApSide($pdo, $tenantId, $entryIds);

    // 6. Summary rollups.
    $summary = [
        'revenue_billed' => 0.0,
        'ar_collected'   => 0.0,
        'vendor_owed'    => 0.0,
        'vendor_paid'    => 0.0,
    ];
    foreach ($arRows as $ar) {
        $summary['revenue_billed'] += (float) ($ar['invoice']['total'] ?? 0);
        foreach ($ar['payments'] as $p) {
            $summary['ar_collected'] += (float) ($p['amount_applied'] ?? 0);
        }
    }
    foreach ($apRows as $ap) {
        $summary['vendor_owed'] += (float) ($ap['bill']['total'] ?? 0);
        foreach ($ap['payments'] as $p) {
            $summary['vendor_paid'] += (float) ($p['amount_applied'] ?? 0);
        }
    }
    foreach ($summary as $k => $v) $summary[$k] = round($v, 2);

    return [
        'timesheet'      => $ts,
        'entries'        => $entries,
        'approvals'      => $approvals,
        'accrual_events' => $accrualEvents,
        'ar'             => $arRows,
        'ap'             => $apRows,
        'summary'        => $summary,
    ];
}

/**
 * Lifecycle for a single time_entry — same shape, narrower scope.
 * Filters AR/AP arrays to only the artifacts that touched THIS entry.
 */
function staffingTimeEntryLifecycle(int $tenantId, int $entryId): array
{
    $te = scopedFind(
        'SELECT * FROM time_entries WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
        ['id' => $entryId]
    );
    if (!$te) throw new \RuntimeException("time_entry {$entryId} not found");

    $full = staffingTimesheetLifecycle($tenantId, (int) $te['timesheet_id']);

    // Narrow to AR/AP rows referencing THIS entry.
    $full['focused_entry'] = $te;
    $full['ar'] = array_values(array_filter($full['ar'], static function ($ar) use ($entryId) {
        foreach ($ar['lines'] as $l) {
            if ((int) ($l['source_ref_id'] ?? 0) === $entryId) return true;
        }
        return false;
    }));
    $full['ap'] = array_values(array_filter($full['ap'], static function ($ap) use ($entryId) {
        foreach ($ap['lines'] as $l) {
            if ((int) ($l['source_ref_id'] ?? 0) === $entryId) return true;
        }
        return false;
    }));
    return $full;
}

// ─── helpers ────────────────────────────────────────────────────────────

function _lifecycleAuditEvents(\PDO $pdo, int $tenantId, array $events, int $targetId): array
{
    if (empty($events)) return [];
    $placeholders = [];
    $params = ['t' => $tenantId, 'id' => $targetId];
    foreach ($events as $i => $ev) {
        $k = 'e' . $i;
        $placeholders[] = ':' . $k;
        $params[$k] = $ev;
    }
    $sql = 'SELECT id, event, target_id, actor_user_id, meta_json, created_at
              FROM audit_log
             WHERE tenant_id = :t
               AND target_id = :id
               AND event IN (' . implode(',', $placeholders) . ')
             ORDER BY created_at ASC, id ASC';
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $_) {
        return [];
    }
}

function _lifecycleAccrualEvents(\PDO $pdo, int $tenantId, int $timesheetId): array
{
    // accounting_events.source_record_id is "{timesheet_id}:{engagement_type}"
    // Use LIKE to catch all engagement-type variants.
    try {
        $st = $pdo->prepare(
            "SELECT ae.id, ae.event_type, ae.source_module, ae.source_record_id,
                    ae.event_date, ae.status, ae.journal_entry_id, ae.error_message,
                    ae.created_at,
                    je.je_number, je.posting_date, je.status AS je_status,
                    je.total_debit, je.memo
               FROM accounting_events ae
          LEFT JOIN accounting_journal_entries je
                 ON je.id = ae.journal_entry_id AND je.tenant_id = ae.tenant_id
              WHERE ae.tenant_id = :t
                AND ae.source_module = 'staffing'
                AND (ae.source_record_id = :id1 OR ae.source_record_id LIKE :pfx)
              ORDER BY ae.created_at ASC"
        );
        $st->execute([
            't'   => $tenantId,
            'id1' => (string) $timesheetId,
            'pfx' => $timesheetId . ':%',
        ]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $_) {
        return [];
    }
}

function _lifecycleArSide(\PDO $pdo, int $tenantId, array $entryIds): array
{
    if (empty($entryIds)) return [];
    $placeholders = []; $params = ['t' => $tenantId];
    foreach ($entryIds as $i => $eid) {
        $k = 'e' . $i; $placeholders[] = ':' . $k; $params[$k] = $eid;
    }

    // Lines referencing any of these entries.
    $lines = $pdo->prepare(
        "SELECT bil.id AS line_id, bil.invoice_id, bil.line_no, bil.description,
                bil.quantity, bil.unit_price, bil.total, bil.placement_id,
                bil.source_type, bil.source_ref_id
           FROM billing_invoice_lines bil
           JOIN billing_invoices i ON i.id = bil.invoice_id
          WHERE i.tenant_id = :t
            AND bil.source_type IN ('time_entry','time')
            AND bil.source_ref_id IN (" . implode(',', $placeholders) . ")
          ORDER BY bil.invoice_id, bil.line_no"
    );
    $lines->execute($params);
    $allLines = $lines->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if (empty($allLines)) return [];

    // Group by invoice_id.
    $byInvoice = [];
    foreach ($allLines as $l) {
        $byInvoice[(int) $l['invoice_id']][] = $l;
    }
    $invIds = array_keys($byInvoice);
    $invPlace = [];
    $invParams = ['t' => $tenantId];
    foreach ($invIds as $i => $iid) {
        $k = 'i' . $i; $invPlace[] = ':' . $k; $invParams[$k] = $iid;
    }
    $invStmt = $pdo->prepare(
        'SELECT id, invoice_number, client_name, currency, issue_date, due_date,
                period_start, period_end, subtotal, tax_total, total,
                amount_paid, amount_due, status
           FROM billing_invoices
          WHERE tenant_id = :t AND id IN (' . implode(',', $invPlace) . ')'
    );
    $invStmt->execute($invParams);
    $invRows = $invStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    // Billing JEs.
    $jeStmt = $pdo->prepare(
        "SELECT id, je_number, posting_date, status, total_debit, memo,
                source_module, source_ref_type, source_ref_id
           FROM accounting_journal_entries
          WHERE tenant_id = :t
            AND source_module = 'billing'
            AND source_ref_id IN (" . implode(',', $invPlace) . ")"
    );
    $jeStmt->execute($invParams);
    $jeByInv = [];
    foreach ($jeStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $je) {
        $jeByInv[(int) $je['source_ref_id']][] = $je;
    }

    // Payment allocations.
    $allocStmt = $pdo->prepare(
        'SELECT a.invoice_id, a.amount_applied, a.applied_at,
                p.id AS payment_id, p.received_at, p.method AS payment_method,
                p.amount AS payment_amount, p.source_system, p.external_id,
                p.client_name AS payment_client
           FROM billing_payment_allocations a
           JOIN billing_payments p ON p.id = a.payment_id
          WHERE p.tenant_id = :t
            AND a.invoice_id IN (' . implode(',', $invPlace) . ')
          ORDER BY a.applied_at ASC, a.id ASC'
    );
    $allocStmt->execute($invParams);
    $allocByInv = [];
    foreach ($allocStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $a) {
        $allocByInv[(int) $a['invoice_id']][] = $a;
    }

    $result = [];
    foreach ($invRows as $inv) {
        $iid = (int) $inv['id'];
        $result[] = [
            'invoice'  => $inv,
            'lines'    => $byInvoice[$iid] ?? [],
            'je'       => ($jeByInv[$iid] ?? [])[0] ?? null,
            'payments' => $allocByInv[$iid] ?? [],
        ];
    }
    return $result;
}

function _lifecycleApSide(\PDO $pdo, int $tenantId, array $entryIds): array
{
    if (empty($entryIds)) return [];
    $placeholders = []; $params = ['t' => $tenantId];
    foreach ($entryIds as $i => $eid) {
        $k = 'e' . $i; $placeholders[] = ':' . $k; $params[$k] = $eid;
    }

    $lines = $pdo->prepare(
        "SELECT abl.id AS line_id, abl.bill_id, abl.line_no, abl.description,
                abl.quantity, abl.unit_price, abl.total, abl.placement_id,
                abl.source_type, abl.source_ref_id, abl.is_1099_eligible
           FROM ap_bill_lines abl
           JOIN ap_bills b ON b.id = abl.bill_id
          WHERE b.tenant_id = :t
            AND abl.source_type IN ('time_entry','time')
            AND abl.source_ref_id IN (" . implode(',', $placeholders) . ")
          ORDER BY abl.bill_id, abl.line_no"
    );
    $lines->execute($params);
    $allLines = $lines->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if (empty($allLines)) return [];

    $byBill = [];
    foreach ($allLines as $l) $byBill[(int) $l['bill_id']][] = $l;
    $billIds = array_keys($byBill);
    $bp = []; $bparams = ['t' => $tenantId];
    foreach ($billIds as $i => $bid) { $k = 'b' . $i; $bp[] = ':' . $k; $bparams[$k] = $bid; }

    $billStmt = $pdo->prepare(
        'SELECT id, internal_ref, bill_number, vendor_name, vendor_type,
                bill_date, due_date, period_start, period_end,
                subtotal, tax_total, total, amount_paid, amount_due,
                status, payment_terms, linked_ar_invoice_id, pwp_status,
                pwp_released_at, currency
           FROM ap_bills
          WHERE tenant_id = :t AND id IN (' . implode(',', $bp) . ')'
    );
    $billStmt->execute($bparams);
    $billRows = $billStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    // AP JEs.
    $jeStmt = $pdo->prepare(
        "SELECT id, je_number, posting_date, status, total_debit, memo,
                source_module, source_ref_type, source_ref_id
           FROM accounting_journal_entries
          WHERE tenant_id = :t
            AND source_module = 'ap'
            AND source_ref_id IN (" . implode(',', $bp) . ")"
    );
    $jeStmt->execute($bparams);
    $jeByBill = [];
    foreach ($jeStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $je) {
        $jeByBill[(int) $je['source_ref_id']][] = $je;
    }

    // Payments.
    $allocStmt = $pdo->prepare(
        'SELECT a.bill_id, a.amount_applied, a.applied_at,
                p.id AS payment_id, p.pay_date, p.method, p.amount AS payment_amount,
                p.reference, p.status AS payment_status,
                p.disbursement_rail, p.rail_external_ref, p.rail_status,
                p.rail_originated_at, p.sent_at, p.cleared_at
           FROM ap_payment_allocations a
           JOIN ap_payments p ON p.id = a.payment_id
          WHERE p.tenant_id = :t
            AND a.bill_id IN (' . implode(',', $bp) . ')
          ORDER BY a.applied_at ASC, a.id ASC'
    );
    $allocStmt->execute($bparams);
    $allocByBill = [];
    foreach ($allocStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $a) {
        $allocByBill[(int) $a['bill_id']][] = $a;
    }

    // PWP audit events per bill.
    $pwpStmt = $pdo->prepare(
        'SELECT id, event, target_id, actor_user_id, meta_json, created_at
           FROM audit_log
          WHERE tenant_id = :t
            AND event IN ("ap.bill.pwp.linked","ap.bill.pwp.cleared","ap.bill.pwp.released")
            AND target_id IN (' . implode(',', $bp) . ')
          ORDER BY created_at ASC, id ASC'
    );
    try { $pwpStmt->execute($bparams); } catch (\Throwable $_) { /* no audit_log yet */ }
    $pwpByBill = [];
    foreach ($pwpStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $a) {
        $pwpByBill[(int) $a['target_id']][] = $a;
    }

    $result = [];
    foreach ($billRows as $b) {
        $bid = (int) $b['id'];
        $result[] = [
            'bill'       => $b,
            'lines'      => $byBill[$bid] ?? [],
            'je'         => ($jeByBill[$bid] ?? [])[0] ?? null,
            'payments'   => $allocByBill[$bid] ?? [],
            'pwp_events' => $pwpByBill[$bid] ?? [],
        ];
    }
    return $result;
}
