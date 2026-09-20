<?php
/**
 * Cross-module business-graph integrity audit.
 *
 * Read-only by design. A failed query is an error result, never a clean pass.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sub_tenants.php';

function businessIntegrityAudit(int $tenantId): array
{
    if ($tenantId <= 0) throw new InvalidArgumentException('tenantId required');
    $checks = [];
    $scopeTenant = static fn(string $module): int =>
        (int) (effectiveTenantIdForModule($module, $tenantId) ?? $tenantId);
    $staffingTenantId = $scopeTenant('staffing');
    $accountingTenantId = $scopeTenant('accounting');
    $apTenantId = $scopeTenant('ap');
    $billingTenantId = $scopeTenant('billing');
    $payrollTenantId = $scopeTenant('payroll');
    $placementsTenantId = $scopeTenant('placements');
    $peopleTenantId = $scopeTenant('people');
    $treasuryTenantId = $scopeTenant('treasury');
    $artifactScopeTenantIds = array_values(array_unique([
        $tenantId,
        $staffingTenantId,
        $accountingTenantId,
        $apTenantId,
        $billingTenantId,
        $payrollTenantId,
        $placementsTenantId,
        $peopleTenantId,
        $treasuryTenantId,
    ]));
    $additionalArtifactTenantIds = array_values(array_filter(
        $artifactScopeTenantIds,
        static fn(int $id): bool => $id !== $tenantId
    ));
    $artifactTenantPredicate = $additionalArtifactTenantIds
        ? 'IN (:tenant_id, ' . implode(', ', array_map('intval', $additionalArtifactTenantIds)) . ')'
        : '= :tenant_id';

    foreach ([
        ['staffing_timesheets', 'staffing_timesheet', 'staffing', 'staffing_timesheet', $staffingTenantId],
        ['cash_forecast_runs', 'cash_forecast', 'ai', 'cash_forecast_run', $accountingTenantId],
        ['ap_invoice_extraction_runs', 'ap_invoice_review', 'ap', 'ap_invoice_extraction_run', $apTenantId],
        ['accounting_reconciliations', 'accounting_reconciliation', 'accounting', 'accounting_reconciliation', $accountingTenantId],
        ['payroll_runs', 'payroll_review', 'payroll', 'payroll_run', $payrollTenantId],
    ] as [$table, $artifactType, $sourceModule, $sourceRecordType, $domainTenantId]) {
        $checks[] = businessIntegrityArtifactCoverageCheck(
            $domainTenantId,
            $table,
            $artifactType,
            $sourceModule,
            $sourceRecordType
        );
    }

    $checks[] = businessIntegrityCountCheck(
        $tenantId,
        'artifact_source_duplicates',
        'Duplicate artifact source identities',
        'error',
        "SELECT COUNT(*) FROM (
            SELECT tenant_id, artifact_type, source_module, source_record_type, source_record_id
              FROM artifact_objects
             WHERE tenant_id {$artifactTenantPredicate}
               AND source_module IS NOT NULL
               AND source_record_type IS NOT NULL
               AND source_record_id IS NOT NULL
             GROUP BY tenant_id, artifact_type, source_module, source_record_type, source_record_id
            HAVING COUNT(*) > 1
        ) duplicate_sources",
        "SELECT artifact_type, source_module, source_record_type, source_record_id, COUNT(*) AS duplicate_count
           FROM artifact_objects
          WHERE tenant_id {$artifactTenantPredicate}
            AND source_module IS NOT NULL
            AND source_record_type IS NOT NULL
            AND source_record_id IS NOT NULL
          GROUP BY artifact_type, source_module, source_record_type, source_record_id
         HAVING COUNT(*) > 1
          ORDER BY duplicate_count DESC
          LIMIT 25",
        ['artifact_objects']
    );

    $checks[] = businessIntegrityCountCheck(
        $tenantId,
        'artifact_edge_duplicates',
        'Duplicate artifact lineage edges',
        'error',
        "SELECT COUNT(*) FROM (
            SELECT tenant_id, source_artifact_id, relationship_type,
                   COALESCE(target_artifact_id, ''), COALESCE(target_table, ''), COALESCE(target_record_id, 0)
              FROM artifact_relationships
             WHERE tenant_id {$artifactTenantPredicate}
             GROUP BY tenant_id, source_artifact_id, relationship_type,
                      COALESCE(target_artifact_id, ''), COALESCE(target_table, ''), COALESCE(target_record_id, 0)
            HAVING COUNT(*) > 1
        ) duplicate_edges",
        "SELECT source_artifact_id, relationship_type, target_artifact_id, target_table,
                target_record_id, COUNT(*) AS duplicate_count
           FROM artifact_relationships
          WHERE tenant_id {$artifactTenantPredicate}
          GROUP BY source_artifact_id, relationship_type, target_artifact_id, target_table, target_record_id
         HAVING COUNT(*) > 1
          ORDER BY duplicate_count DESC
          LIMIT 25",
        ['artifact_relationships']
    );

    $checks[] = businessIntegrityCountCheck(
        $tenantId,
        'artifact_event_ownership',
        'Artifact events point to an artifact in the same tenant',
        'critical',
        "SELECT COUNT(*)
           FROM artifact_events e
           LEFT JOIN artifact_objects a
             ON a.tenant_id = e.tenant_id AND a.id = e.artifact_id
          WHERE e.tenant_id {$artifactTenantPredicate} AND a.id IS NULL",
        "SELECT e.id AS event_id, e.artifact_id, e.event_type, e.created_at
           FROM artifact_events e
           LEFT JOIN artifact_objects a
             ON a.tenant_id = e.tenant_id AND a.id = e.artifact_id
          WHERE e.tenant_id {$artifactTenantPredicate} AND a.id IS NULL
          ORDER BY e.id DESC LIMIT 25",
        ['artifact_events', 'artifact_objects']
    );

    $checks[] = businessIntegrityCountCheck(
        $tenantId,
        'artifact_lineage_ownership',
        'Artifact lineage has a valid source and exactly one valid target',
        'critical',
        "SELECT COUNT(*)
           FROM artifact_relationships r
           LEFT JOIN artifact_objects source
             ON source.tenant_id = r.tenant_id AND source.id = r.source_artifact_id
           LEFT JOIN artifact_objects target
             ON target.tenant_id = r.tenant_id AND target.id = r.target_artifact_id
          WHERE r.tenant_id {$artifactTenantPredicate}
            AND (
                source.id IS NULL
                OR (r.target_artifact_id IS NOT NULL AND target.id IS NULL)
                OR (r.target_artifact_id IS NOT NULL AND (r.target_table IS NOT NULL OR r.target_record_id IS NOT NULL))
                OR (r.target_artifact_id IS NULL AND (r.target_table IS NULL OR r.target_record_id IS NULL))
            )",
        "SELECT r.id AS relationship_id, r.source_artifact_id, r.target_artifact_id,
                r.target_table, r.target_record_id, r.relationship_type
           FROM artifact_relationships r
           LEFT JOIN artifact_objects source
             ON source.tenant_id = r.tenant_id AND source.id = r.source_artifact_id
           LEFT JOIN artifact_objects target
             ON target.tenant_id = r.tenant_id AND target.id = r.target_artifact_id
          WHERE r.tenant_id {$artifactTenantPredicate}
            AND (
                source.id IS NULL
                OR (r.target_artifact_id IS NOT NULL AND target.id IS NULL)
                OR (r.target_artifact_id IS NOT NULL AND (r.target_table IS NOT NULL OR r.target_record_id IS NOT NULL))
                OR (r.target_artifact_id IS NULL AND (r.target_table IS NULL OR r.target_record_id IS NULL))
            )
          ORDER BY r.id DESC LIMIT 25",
        ['artifact_relationships', 'artifact_objects']
    );

    $workflowSpecs = [
        ['ap_bill', 'ap_bills', "('approved','partially_paid','paid','void')", $apTenantId],
        ['billing_invoice', 'billing_invoices', "('approved','sent','partially_paid','paid','void')", $billingTenantId],
        ['payroll_run', 'payroll_runs', "('approved','paid','voided')", $payrollTenantId],
        ['treasury_payment', 'treasury_payments', "('approved','scheduled','executed','voided')", $treasuryTenantId],
        ['treasury_transfer', 'treasury_transfers', "('approved','scheduled','executed','voided')", $treasuryTenantId],
    ];
    foreach ($workflowSpecs as [$subjectType, $table, $allowedStatuses, $domainTenantId]) {
        $checks[] = businessIntegrityCountCheck(
            $domainTenantId,
            'workflow_projection_' . $subjectType,
            "Approved {$subjectType} workflows agree with their records",
            'critical',
            "SELECT COUNT(*)
               FROM workflow_instances w
               LEFT JOIN {$table} d ON d.tenant_id = w.tenant_id AND d.id = w.subject_id
              WHERE w.tenant_id = :tenant_id
                AND w.subject_type = '{$subjectType}'
                AND w.status = 'approved'
                AND (d.id IS NULL OR d.status NOT IN {$allowedStatuses})",
            "SELECT w.id AS workflow_instance_id, w.subject_id, w.status AS workflow_status,
                    d.status AS domain_status
               FROM workflow_instances w
               LEFT JOIN {$table} d ON d.tenant_id = w.tenant_id AND d.id = w.subject_id
              WHERE w.tenant_id = :tenant_id
                AND w.subject_type = '{$subjectType}'
                AND w.status = 'approved'
                AND (d.id IS NULL OR d.status NOT IN {$allowedStatuses})
              ORDER BY w.id DESC LIMIT 25",
            ['workflow_instances', $table]
        );
    }

    $checks[] = businessIntegrityCountCheck(
        $accountingTenantId,
        'journal_balance',
        'Posted journal entries balance to their lines',
        'critical',
        "SELECT COUNT(*) FROM (
            SELECT j.id
              FROM accounting_journal_entries j
              LEFT JOIN accounting_journal_entry_lines l ON l.je_id = j.id
             WHERE j.tenant_id = :tenant_id AND j.status IN ('posted','reversed')
             GROUP BY j.id, j.total_debit, j.total_credit
            HAVING ABS(j.total_debit - j.total_credit) >= 0.005
                OR ABS(COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0)) >= 0.005
                OR ABS(j.total_debit - COALESCE(SUM(l.debit), 0)) >= 0.005
                OR ABS(j.total_credit - COALESCE(SUM(l.credit), 0)) >= 0.005
        ) bad_journals",
        "SELECT j.id, j.je_number, j.status, j.total_debit, j.total_credit,
                COALESCE(SUM(l.debit), 0) AS line_debits,
                COALESCE(SUM(l.credit), 0) AS line_credits
           FROM accounting_journal_entries j
           LEFT JOIN accounting_journal_entry_lines l ON l.je_id = j.id
          WHERE j.tenant_id = :tenant_id AND j.status IN ('posted','reversed')
          GROUP BY j.id, j.je_number, j.status, j.total_debit, j.total_credit
         HAVING ABS(j.total_debit - j.total_credit) >= 0.005
             OR ABS(line_debits - line_credits) >= 0.005
             OR ABS(j.total_debit - line_debits) >= 0.005
             OR ABS(j.total_credit - line_credits) >= 0.005
          ORDER BY j.id DESC LIMIT 25",
        ['accounting_journal_entries', 'accounting_journal_entry_lines']
    );

    $checks[] = businessIntegrityCountCheck(
        $accountingTenantId,
        'posted_event_journal_links',
        'Posted accounting events have a valid posted journal',
        'critical',
        "SELECT COUNT(*)
           FROM accounting_events e
           LEFT JOIN accounting_journal_entries j
             ON j.tenant_id = e.tenant_id AND j.id = e.journal_entry_id
          WHERE e.tenant_id = :tenant_id
            AND e.status IN ('posted','reversed')
            AND (e.journal_entry_id IS NULL OR j.id IS NULL OR j.status NOT IN ('posted','reversed'))",
        "SELECT e.id AS event_id, e.event_type, e.status AS event_status,
                e.journal_entry_id, j.status AS journal_status
           FROM accounting_events e
           LEFT JOIN accounting_journal_entries j
             ON j.tenant_id = e.tenant_id AND j.id = e.journal_entry_id
          WHERE e.tenant_id = :tenant_id
            AND e.status IN ('posted','reversed')
            AND (e.journal_entry_id IS NULL OR j.id IS NULL OR j.status NOT IN ('posted','reversed'))
          ORDER BY e.id DESC LIMIT 25",
        ['accounting_events', 'accounting_journal_entries']
    );

    $checks[] = businessIntegrityCountCheck(
        $accountingTenantId,
        'event_registry_coverage',
        'Accounting events use registered event types',
        'error',
        "SELECT COUNT(*)
           FROM accounting_events e
           LEFT JOIN event_registry r ON r.event_type = e.event_type AND r.schema_version = 1
          WHERE e.tenant_id = :tenant_id AND r.event_type IS NULL",
        "SELECT e.event_type, COUNT(*) AS event_count
           FROM accounting_events e
           LEFT JOIN event_registry r ON r.event_type = e.event_type AND r.schema_version = 1
          WHERE e.tenant_id = :tenant_id AND r.event_type IS NULL
          GROUP BY e.event_type ORDER BY event_count DESC LIMIT 25",
        ['accounting_events', 'event_registry']
    );

    $checks[] = businessIntegrityCountCheck(
        $accountingTenantId,
        'stale_accounting_events',
        'Accounting events are not stuck before posting',
        'warning',
        "SELECT COUNT(*) FROM accounting_events
          WHERE tenant_id = :tenant_id
            AND status IN ('received','mapped')
            AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)",
        "SELECT id, event_type, source_module, source_record_id, status, created_at
           FROM accounting_events
          WHERE tenant_id = :tenant_id
            AND status IN ('received','mapped')
            AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
          ORDER BY created_at ASC LIMIT 25",
        ['accounting_events']
    );

    $checks[] = businessIntegrityCountCheck(
        $accountingTenantId,
        'event_lineage',
        'Event lineage stays inside the tenant and points to real events',
        'critical',
        "SELECT COUNT(*)
           FROM event_lineage l
           LEFT JOIN accounting_events p ON p.id = l.parent_event_id
           LEFT JOIN accounting_events c ON c.id = l.child_event_id
          WHERE l.tenant_id = :tenant_id
            AND (p.id IS NULL OR c.id IS NULL OR p.tenant_id <> l.tenant_id OR c.tenant_id <> l.tenant_id)",
        "SELECT l.id AS lineage_id, l.parent_event_id, l.child_event_id,
                p.tenant_id AS parent_tenant_id, c.tenant_id AS child_tenant_id
           FROM event_lineage l
           LEFT JOIN accounting_events p ON p.id = l.parent_event_id
           LEFT JOIN accounting_events c ON c.id = l.child_event_id
          WHERE l.tenant_id = :tenant_id
            AND (p.id IS NULL OR c.id IS NULL OR p.tenant_id <> l.tenant_id OR c.tenant_id <> l.tenant_id)
          ORDER BY l.id DESC LIMIT 25",
        ['event_lineage', 'accounting_events']
    );

    $checks[] = businessIntegrityCountCheck(
        $staffingTenantId,
        'timesheet_entry_ownership',
        'Timesheet entries belong to the same person, tenant, and week',
        'critical',
        "SELECT COUNT(*)
           FROM time_entries e
           JOIN staffing_timesheets t ON t.id = e.timesheet_id
          WHERE t.tenant_id = :tenant_id
            AND e.status <> 'superseded'
            AND (e.tenant_id <> t.tenant_id OR e.person_id <> t.person_id
                 OR e.work_date < t.period_start OR e.work_date > t.period_end)",
        "SELECT e.id AS time_entry_id, e.timesheet_id, e.tenant_id AS entry_tenant_id,
                t.tenant_id AS timesheet_tenant_id, e.person_id AS entry_person_id,
                t.person_id AS timesheet_person_id, e.work_date, t.period_start, t.period_end
           FROM time_entries e
           JOIN staffing_timesheets t ON t.id = e.timesheet_id
          WHERE t.tenant_id = :tenant_id
            AND e.status <> 'superseded'
            AND (e.tenant_id <> t.tenant_id OR e.person_id <> t.person_id
                 OR e.work_date < t.period_start OR e.work_date > t.period_end)
          ORDER BY e.id DESC LIMIT 25",
        ['time_entries', 'staffing_timesheets']
    );

    $checks[] = businessIntegrityCountCheck(
        $staffingTenantId,
        'timesheet_header_totals',
        'Timesheet header hours equal their active entries',
        'error',
        "SELECT COUNT(*) FROM (
            SELECT t.id
              FROM staffing_timesheets t
              LEFT JOIN time_entries e
                ON e.tenant_id = t.tenant_id AND e.timesheet_id = t.id
               AND e.status <> 'superseded'
             WHERE t.tenant_id = :tenant_id
             GROUP BY t.id, t.total_hours
            HAVING ABS(t.total_hours - COALESCE(SUM(e.hours), 0)) >= 0.005
        ) bad_timesheets",
        "SELECT t.id AS timesheet_id, t.person_id, t.period_start, t.period_end,
                t.total_hours AS header_hours, COALESCE(SUM(e.hours), 0) AS entry_hours
           FROM staffing_timesheets t
           LEFT JOIN time_entries e
             ON e.tenant_id = t.tenant_id AND e.timesheet_id = t.id
            AND e.status <> 'superseded'
          WHERE t.tenant_id = :tenant_id
          GROUP BY t.id, t.person_id, t.period_start, t.period_end, t.total_hours
         HAVING ABS(t.total_hours - entry_hours) >= 0.005
          ORDER BY t.id DESC LIMIT 25",
        ['time_entries', 'staffing_timesheets']
    );

    $checks[] = businessIntegrityCountCheck(
        $placementsTenantId,
        'placement_person_ownership',
        'Placements point to a person in the resolved People scope',
        'critical',
        "SELECT COUNT(*)
           FROM placements p
           LEFT JOIN people person
             ON person.tenant_id = :people_tenant_id AND person.id = p.person_id
          WHERE p.tenant_id = :tenant_id AND p.deleted_at IS NULL AND person.id IS NULL",
        "SELECT p.id AS placement_id, p.external_id, p.person_id, p.title, p.status
           FROM placements p
           LEFT JOIN people person
             ON person.tenant_id = :people_tenant_id AND person.id = p.person_id
          WHERE p.tenant_id = :tenant_id AND p.deleted_at IS NULL AND person.id IS NULL
          ORDER BY p.id DESC LIMIT 25",
        ['placements', 'people'],
        ['people_tenant_id' => $peopleTenantId]
    );

    $checks[] = businessIntegrityCountCheck(
        $billingTenantId,
        'billing_invoice_totals',
        'Invoice headers agree with line items and open balance',
        'critical',
        "SELECT COUNT(*) FROM (
            SELECT i.id
              FROM billing_invoices i
              LEFT JOIN billing_invoice_lines l ON l.invoice_id = i.id
             WHERE i.tenant_id = :tenant_id
             GROUP BY i.id, i.subtotal, i.tax_total, i.total, i.amount_paid, i.amount_due
            HAVING ABS(i.subtotal - COALESCE(SUM(l.subtotal), 0)) >= 0.01
                OR ABS(i.tax_total - COALESCE(SUM(l.tax_amount), 0)) >= 0.01
                OR ABS(i.total - COALESCE(SUM(l.total), 0)) >= 0.01
                OR ABS(i.amount_due - GREATEST(i.total - i.amount_paid, 0)) >= 0.01
        ) bad_invoices",
        "SELECT i.id AS invoice_id, i.invoice_number, i.status,
                i.subtotal, COALESCE(SUM(l.subtotal), 0) AS line_subtotal,
                i.tax_total, COALESCE(SUM(l.tax_amount), 0) AS line_tax,
                i.total, COALESCE(SUM(l.total), 0) AS line_total,
                i.amount_paid, i.amount_due
           FROM billing_invoices i
           LEFT JOIN billing_invoice_lines l ON l.invoice_id = i.id
          WHERE i.tenant_id = :tenant_id
          GROUP BY i.id, i.invoice_number, i.status, i.subtotal, i.tax_total,
                   i.total, i.amount_paid, i.amount_due
         HAVING ABS(i.subtotal - line_subtotal) >= 0.01
             OR ABS(i.tax_total - line_tax) >= 0.01
             OR ABS(i.total - line_total) >= 0.01
             OR ABS(i.amount_due - GREATEST(i.total - i.amount_paid, 0)) >= 0.01
          ORDER BY i.id DESC LIMIT 25",
        ['billing_invoices', 'billing_invoice_lines']
    );

    $checks[] = businessIntegrityCountCheck(
        $apTenantId,
        'ap_bill_totals',
        'Bill headers agree with line items and open balance',
        'critical',
        "SELECT COUNT(*) FROM (
            SELECT b.id
              FROM ap_bills b
              LEFT JOIN ap_bill_lines l ON l.bill_id = b.id
             WHERE b.tenant_id = :tenant_id
             GROUP BY b.id, b.subtotal, b.tax_total, b.total, b.amount_paid, b.amount_due
            HAVING ABS(b.subtotal - COALESCE(SUM(l.subtotal), 0)) >= 0.01
                OR ABS(b.tax_total - COALESCE(SUM(l.tax_amount), 0)) >= 0.01
                OR ABS(b.total - COALESCE(SUM(l.total), 0)) >= 0.01
                OR ABS(b.amount_due - GREATEST(b.total - b.amount_paid, 0)) >= 0.01
        ) bad_bills",
        "SELECT b.id AS bill_id, b.bill_number, b.status,
                b.subtotal, COALESCE(SUM(l.subtotal), 0) AS line_subtotal,
                b.tax_total, COALESCE(SUM(l.tax_amount), 0) AS line_tax,
                b.total, COALESCE(SUM(l.total), 0) AS line_total,
                b.amount_paid, b.amount_due
           FROM ap_bills b
           LEFT JOIN ap_bill_lines l ON l.bill_id = b.id
          WHERE b.tenant_id = :tenant_id
          GROUP BY b.id, b.bill_number, b.status, b.subtotal, b.tax_total,
                   b.total, b.amount_paid, b.amount_due
         HAVING ABS(b.subtotal - line_subtotal) >= 0.01
             OR ABS(b.tax_total - line_tax) >= 0.01
             OR ABS(b.total - line_total) >= 0.01
             OR ABS(b.amount_due - GREATEST(b.total - b.amount_paid, 0)) >= 0.01
          ORDER BY b.id DESC LIMIT 25",
        ['ap_bills', 'ap_bill_lines']
    );

    $checks[] = businessIntegrityCountCheck(
        $payrollTenantId,
        'payroll_run_totals',
        'Payroll run totals equal their employee lines',
        'critical',
        "SELECT COUNT(*) FROM (
            SELECT r.id
              FROM payroll_runs r
              LEFT JOIN payroll_line_items l
                ON l.tenant_id = r.tenant_id AND l.run_id = r.id
             WHERE r.tenant_id = :tenant_id AND r.status <> 'draft'
             GROUP BY r.id, r.employee_count, r.gross_total_cents, r.taxes_total_cents,
                      r.deductions_total_cents, r.net_total_cents, r.employer_taxes_cents
            HAVING r.employee_count <> COUNT(l.id)
                OR r.gross_total_cents <> COALESCE(SUM(l.gross_cents), 0)
                OR r.taxes_total_cents <> COALESCE(SUM(l.employee_taxes_cents), 0)
                OR r.deductions_total_cents <> COALESCE(SUM(l.pretax_cents + l.posttax_cents), 0)
                OR r.net_total_cents <> COALESCE(SUM(l.net_cents), 0)
                OR r.employer_taxes_cents <> COALESCE(SUM(l.employer_taxes_cents), 0)
        ) bad_payroll_runs",
        "SELECT r.id AS run_id, r.status, r.employee_count, COUNT(l.id) AS line_count,
                r.gross_total_cents, COALESCE(SUM(l.gross_cents), 0) AS line_gross_cents,
                r.taxes_total_cents, COALESCE(SUM(l.employee_taxes_cents), 0) AS line_taxes_cents,
                r.deductions_total_cents,
                COALESCE(SUM(l.pretax_cents + l.posttax_cents), 0) AS line_deductions_cents,
                r.net_total_cents, COALESCE(SUM(l.net_cents), 0) AS line_net_cents,
                r.employer_taxes_cents,
                COALESCE(SUM(l.employer_taxes_cents), 0) AS line_employer_taxes_cents
           FROM payroll_runs r
           LEFT JOIN payroll_line_items l
             ON l.tenant_id = r.tenant_id AND l.run_id = r.id
          WHERE r.tenant_id = :tenant_id AND r.status <> 'draft'
          GROUP BY r.id, r.status, r.employee_count, r.gross_total_cents,
                   r.taxes_total_cents, r.deductions_total_cents,
                   r.net_total_cents, r.employer_taxes_cents
         HAVING r.employee_count <> line_count
             OR r.gross_total_cents <> line_gross_cents
             OR r.taxes_total_cents <> line_taxes_cents
             OR r.deductions_total_cents <> line_deductions_cents
             OR r.net_total_cents <> line_net_cents
             OR r.employer_taxes_cents <> line_employer_taxes_cents
          ORDER BY r.id DESC LIMIT 25",
        ['payroll_runs', 'payroll_line_items']
    );

    $checks[] = businessIntegrityCountCheck(
        $accountingTenantId,
        'bank_match_integrity',
        'Matched bank lines point to a posted journal touching that bank account',
        'critical',
        "SELECT COUNT(*)
           FROM accounting_bank_statement_lines bank_line
           JOIN accounting_bank_accounts bank
             ON bank.tenant_id = bank_line.tenant_id AND bank.id = bank_line.bank_account_id
           LEFT JOIN accounting_accounts account
             ON account.tenant_id = bank.tenant_id AND account.code = bank.gl_account_code
           LEFT JOIN accounting_journal_entries journal
             ON journal.tenant_id = bank_line.tenant_id AND journal.id = bank_line.matched_je_id
          WHERE bank_line.tenant_id = :tenant_id
            AND bank_line.match_status = 'matched'
            AND (
                bank_line.matched_je_id IS NULL OR account.id IS NULL OR journal.id IS NULL
                OR journal.status NOT IN ('posted','reversed')
                OR NOT EXISTS (
                    SELECT 1 FROM accounting_journal_entry_lines journal_line
                     WHERE journal_line.je_id = journal.id AND journal_line.account_id = account.id
                )
            )",
        "SELECT bank_line.id AS bank_line_id, bank_line.posted_date, bank_line.amount,
                bank_line.matched_je_id, journal.status AS journal_status,
                bank.id AS bank_account_id, bank.gl_account_code
           FROM accounting_bank_statement_lines bank_line
           JOIN accounting_bank_accounts bank
             ON bank.tenant_id = bank_line.tenant_id AND bank.id = bank_line.bank_account_id
           LEFT JOIN accounting_accounts account
             ON account.tenant_id = bank.tenant_id AND account.code = bank.gl_account_code
           LEFT JOIN accounting_journal_entries journal
             ON journal.tenant_id = bank_line.tenant_id AND journal.id = bank_line.matched_je_id
          WHERE bank_line.tenant_id = :tenant_id
            AND bank_line.match_status = 'matched'
            AND (
                bank_line.matched_je_id IS NULL OR account.id IS NULL OR journal.id IS NULL
                OR journal.status NOT IN ('posted','reversed')
                OR NOT EXISTS (
                    SELECT 1 FROM accounting_journal_entry_lines journal_line
                     WHERE journal_line.je_id = journal.id AND journal_line.account_id = account.id
                )
            )
          ORDER BY bank_line.id DESC LIMIT 25",
        [
            'accounting_bank_statement_lines', 'accounting_bank_accounts',
            'accounting_accounts', 'accounting_journal_entries', 'accounting_journal_entry_lines',
        ]
    );

    $checks[] = businessIntegrityCountCheck(
        $accountingTenantId,
        'reconciliation_balance_integrity',
        'Reconciliation differences are derived and closed records balance',
        'critical',
        "SELECT COUNT(*) FROM accounting_reconciliations
          WHERE tenant_id = :tenant_id
            AND (
                ABS(difference - (statement_balance - gl_balance)) >= 0.01
                OR (status = 'closed' AND (ABS(difference) >= 0.01 OR closed_at IS NULL))
            )",
        "SELECT id AS reconciliation_id, bank_account_id, period_end, status,
                statement_balance, gl_balance, difference, closed_at
           FROM accounting_reconciliations
          WHERE tenant_id = :tenant_id
            AND (
                ABS(difference - (statement_balance - gl_balance)) >= 0.01
                OR (status = 'closed' AND (ABS(difference) >= 0.01 OR closed_at IS NULL))
            )
          ORDER BY id DESC LIMIT 25",
        ['accounting_reconciliations']
    );

    $checks[] = businessIntegrityCountCheck(
        $placementsTenantId,
        'active_placement_rates',
        'Active placements have a current approved rate',
        'error',
        "SELECT COUNT(*) FROM placements p
          WHERE p.tenant_id = :tenant_id AND p.status = 'active' AND p.deleted_at IS NULL
            AND NOT EXISTS (
                SELECT 1 FROM placement_rates r
                 WHERE r.tenant_id = p.tenant_id AND r.placement_id = p.id
                   AND r.approved_at IS NOT NULL AND r.superseded_by IS NULL
                   AND r.effective_from <= CURDATE()
                   AND (r.effective_to IS NULL OR r.effective_to >= CURDATE())
            )",
        "SELECT p.id AS placement_id, p.external_id, p.person_id, p.title, p.engagement_type
           FROM placements p
          WHERE p.tenant_id = :tenant_id AND p.status = 'active' AND p.deleted_at IS NULL
            AND NOT EXISTS (
                SELECT 1 FROM placement_rates r
                 WHERE r.tenant_id = p.tenant_id AND r.placement_id = p.id
                   AND r.approved_at IS NOT NULL AND r.superseded_by IS NULL
                   AND r.effective_from <= CURDATE()
                   AND (r.effective_to IS NULL OR r.effective_to >= CURDATE())
            )
          ORDER BY p.id LIMIT 25",
        ['placements', 'placement_rates']
    );

    $checks[] = businessIntegrityCountCheck(
        $placementsTenantId,
        'active_placement_receivables',
        'Active placements have a receivable party',
        'error',
        "SELECT COUNT(*) FROM placements p
          WHERE p.tenant_id = :tenant_id AND p.status = 'active' AND p.deleted_at IS NULL
            AND NOT EXISTS (
                SELECT 1 FROM placement_economic_parties ep
                 WHERE ep.tenant_id = p.tenant_id AND ep.placement_id = p.id
                   AND ep.active = 1 AND ep.money_flow = 'receivable' AND ep.settlement_channel = 'ar'
            )",
        "SELECT p.id AS placement_id, p.external_id, p.person_id, p.title, p.end_client_name
           FROM placements p
          WHERE p.tenant_id = :tenant_id AND p.status = 'active' AND p.deleted_at IS NULL
            AND NOT EXISTS (
                SELECT 1 FROM placement_economic_parties ep
                 WHERE ep.tenant_id = p.tenant_id AND ep.placement_id = p.id
                   AND ep.active = 1 AND ep.money_flow = 'receivable' AND ep.settlement_channel = 'ar'
            )
          ORDER BY p.id LIMIT 25",
        ['placements', 'placement_economic_parties']
    );

    $summary = ['ok' => 0, 'fail' => 0, 'error' => 0, 'skipped' => 0, 'issues' => 0];
    foreach ($checks as $check) {
        $status = (string) ($check['status'] ?? 'error');
        $summary[$status] = ($summary[$status] ?? 0) + 1;
        $summary['issues'] += (int) ($check['issue_count'] ?? 0);
    }

    return [
        'tenant_id' => $tenantId,
        'ok' => ($summary['fail'] + $summary['error']) === 0,
        'summary' => $summary,
        'checks' => $checks,
        'ran_at' => date(DATE_ATOM),
    ];
}

function businessIntegrityArtifactCoverageCheck(
    int $tenantId,
    string $table,
    string $artifactType,
    string $sourceModule,
    string $sourceRecordType
): array
{
    $key = 'artifact_coverage_' . $table;
    if (!businessIntegrityTableExists($table) || !businessIntegrityColumnExists($table, 'artifact_id')) {
        return businessIntegritySkipped($key, "{$table} records have artifacts", 'table or artifact_id column is unavailable');
    }
    return businessIntegrityCountCheck(
        $tenantId,
        $key,
        "{$table} records have valid {$artifactType} artifacts",
        'error',
        "SELECT COUNT(*) FROM {$table} d
           LEFT JOIN artifact_objects a ON a.tenant_id = d.tenant_id AND a.id = d.artifact_id
          WHERE d.tenant_id = :tenant_id
            AND (d.artifact_id IS NULL OR d.artifact_id = '' OR a.id IS NULL
                 OR a.artifact_type <> '{$artifactType}'
                 OR COALESCE(a.source_module, '') <> '{$sourceModule}'
                 OR COALESCE(a.source_record_type, '') <> '{$sourceRecordType}'
                 OR COALESCE(a.source_record_id, 0) <> d.id)",
        "SELECT d.id, d.artifact_id, a.artifact_type, a.source_module,
                a.source_record_type, a.source_record_id
           FROM {$table} d
           LEFT JOIN artifact_objects a ON a.tenant_id = d.tenant_id AND a.id = d.artifact_id
          WHERE d.tenant_id = :tenant_id
            AND (d.artifact_id IS NULL OR d.artifact_id = '' OR a.id IS NULL
                 OR a.artifact_type <> '{$artifactType}'
                 OR COALESCE(a.source_module, '') <> '{$sourceModule}'
                 OR COALESCE(a.source_record_type, '') <> '{$sourceRecordType}'
                 OR COALESCE(a.source_record_id, 0) <> d.id)
          ORDER BY d.id DESC LIMIT 25",
        [$table, 'artifact_objects']
    );
}

function businessIntegrityCountCheck(
    int $tenantId,
    string $key,
    string $label,
    string $severity,
    string $countSql,
    string $sampleSql,
    array $requiredTables = [],
    array $params = []
): array {
    foreach ($requiredTables as $table) {
        if (!businessIntegrityTableExists($table)) {
            return businessIntegritySkipped($key, $label, "required table {$table} is unavailable");
        }
    }
    $started = microtime(true);
    try {
        $pdo = getDB();
        $queryParams = array_merge(['tenant_id' => $tenantId], $params);
        $count = $pdo->prepare($countSql);
        $count->execute($queryParams);
        $issueCount = (int) $count->fetchColumn();
        $sample = [];
        if ($issueCount > 0) {
            $rows = $pdo->prepare($sampleSql);
            $rows->execute($queryParams);
            $sample = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return [
            'key' => $key,
            'label' => $label,
            'severity' => $severity,
            'status' => $issueCount > 0 ? 'fail' : 'ok',
            'issue_count' => $issueCount,
            'sample' => $sample,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    } catch (Throwable $e) {
        return [
            'key' => $key,
            'label' => $label,
            'severity' => $severity,
            'status' => 'error',
            'issue_count' => 0,
            'sample' => [],
            'error' => $e->getMessage(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}

function businessIntegritySkipped(string $key, string $label, string $reason): array
{
    return [
        'key' => $key,
        'label' => $label,
        'severity' => 'info',
        'status' => 'skipped',
        'issue_count' => 0,
        'sample' => [],
        'reason' => $reason,
        'duration_ms' => 0,
    ];
}

function businessIntegrityTableExists(string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    $stmt = getDB()->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = :table_name'
    );
    $stmt->execute(['table_name' => $table]);
    return $cache[$table] = (int) $stmt->fetchColumn() > 0;
}

function businessIntegrityColumnExists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = getDB()->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
    );
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);
    return $cache[$key] = (int) $stmt->fetchColumn() > 0;
}
