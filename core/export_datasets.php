<?php
/**
 * CoreFlux Export Dataset Registry.
 *
 * Declares every exportable dataset + its available source fields. Export
 * templates map an output column to one of these source field keys (or emit
 * a fixed string). Adding a new exportable surface = adding an entry here
 * and a fetcher function that returns rows in this shape.
 *
 *   EXPORT_DATASETS[dataset_key] = [
 *     'label'      => 'Human-friendly label',
 *     'fetcher'    => function(int $tenantId, array $opts): iterable { … },
 *     'fields'     => [
 *        'source_field_key' => ['label' => '…', 'sample' => 'X', 'note' => '…']
 *     ],
 *   ]
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/custom_fields.php';

function exportDatasetRegistry(): array {
    static $registry = null;
    if ($registry !== null) return $registry;

    $registry = [
        'payroll_disbursements' => [
            'label'                 => 'Payroll Disbursements',
            'module_id'             => 'payroll',
            'permission'            => 'payroll.reports.view',
            'formats'               => ['csv'],
            'audit_event'           => 'payroll.run.exported_template',
            'sensitive_fields'      => ['bank_routing_number', 'bank_account_number'],
            'custom_field_entities' => [],
            'fetcher'               => 'exportDatasetFetchPayrollDisbursements',
            'fields'                => [
                'run_id'                => ['label' => 'Payroll run ID',      'sample' => '1047'],
                'pay_date'              => ['label' => 'Pay date (YYYY-MM-DD)','sample' => '2026-02-14'],
                'period_start'          => ['label' => 'Period start',        'sample' => '2026-02-01'],
                'period_end'            => ['label' => 'Period end',          'sample' => '2026-02-14'],
                'employee_id'           => ['label' => 'Internal employee ID','sample' => '42'],
                'employee_external_id'  => ['label' => 'External employee ID','sample' => 'E042'],
                'employee_first_name'   => ['label' => 'Employee first name', 'sample' => 'Jordan'],
                'employee_last_name'    => ['label' => 'Employee last name',  'sample' => 'Rivera'],
                'employee_email'        => ['label' => 'Employee email',      'sample' => 'jordan@…'],
                'regular_hours'         => ['label' => 'Regular hours',       'sample' => '80.00'],
                'overtime_hours'        => ['label' => 'Overtime hours',      'sample' => '2.50'],
                'pto_hours'             => ['label' => 'PTO hours',           'sample' => '8.00'],
                'gross_pay_dollars'     => ['label' => 'Gross pay ($)',       'sample' => '4800.00'],
                'gross_pay_cents'       => ['label' => 'Gross pay (¢)',       'sample' => '480000'],
                'net_pay_dollars'       => ['label' => 'Net pay ($)',         'sample' => '3624.58'],
                'net_pay_cents'         => ['label' => 'Net pay (¢)',         'sample' => '362458'],
                'reimbursement_dollars' => ['label' => 'Reimbursement ($)',   'sample' => '125.00'],
                'bonus_dollars'         => ['label' => 'Bonus ($)',           'sample' => '0.00'],
                'bank_routing_number'   => ['label' => 'Bank routing',        'sample' => '021000021'],
                'bank_account_number'   => ['label' => 'Bank account',        'sample' => '1234567890'],
                'bank_account_type'     => ['label' => 'Account type',        'sample' => 'checking'],
            ],
        ],

        'ap_payments' => [
            'label'                 => 'AP Payments',
            'module_id'             => 'ap',
            'permission'            => 'ap.export.run',
            'formats'               => ['csv'],
            'audit_event'           => 'ap.payments.exported_template',
            'sensitive_fields'      => ['bank_routing_number', 'bank_account_number'],
            'custom_field_entities' => [],
            'fetcher'               => 'exportDatasetFetchApPayments',
            'fields'                => [
                'payment_id'          => ['label' => 'Payment ID',           'sample' => '9001'],
                'payment_date'        => ['label' => 'Payment date',         'sample' => '2026-02-14'],
                'vendor_id'           => ['label' => 'Vendor ID (internal)', 'sample' => '58'],
                'vendor_external_id'  => ['label' => 'Vendor ID (external)', 'sample' => 'V-058'],
                'vendor_name'         => ['label' => 'Vendor name',          'sample' => 'Acme Corp'],
                'bill_id'             => ['label' => 'Bill ID',              'sample' => '412'],
                'bill_number'         => ['label' => 'Bill number',          'sample' => 'INV-00412'],
                'amount_dollars'      => ['label' => 'Amount ($)',           'sample' => '1,250.00'],
                'amount_cents'        => ['label' => 'Amount (¢)',           'sample' => '125000'],
                'currency'            => ['label' => 'Currency',             'sample' => 'USD'],
                'memo'                => ['label' => 'Memo',                 'sample' => 'Feb services'],
                'bank_routing_number' => ['label' => 'Bank routing',         'sample' => '021000021'],
                'bank_account_number' => ['label' => 'Bank account',         'sample' => '1234567890'],
                'bank_account_type'   => ['label' => 'Account type',         'sample' => 'checking'],
                'rail'                => ['label' => 'Rail',                 'sample' => 'plaid_transfer'],
                'rail_external_ref'   => ['label' => 'Rail reference',       'sample' => 'trn-abc123'],
            ],
        ],

        'expenses' => [
            'label'                 => 'Expense Reports',
            'module_id'             => 'ap',
            'permission'            => 'ap.export.run',
            'formats'               => ['csv'],
            'audit_event'           => 'ap.expense.export_selected_template',
            'sensitive_fields'      => [],
            'custom_field_entities' => [],
            'fetcher'               => 'exportDatasetFetchExpenses',
            'fields'                => [
                'report_id'                 => ['label' => 'Report ID',           'sample' => '301'],
                'period_label'              => ['label' => 'Period',              'sample' => '2026-02'],
                'submitter_user_id'         => ['label' => 'Submitter user ID',   'sample' => '12'],
                'submitter_name'            => ['label' => 'Submitter name',      'sample' => 'Alex K.'],
                'status'                    => ['label' => 'Status',              'sample' => 'approved'],
                'currency'                  => ['label' => 'Currency',            'sample' => 'USD'],
                'bill_id'                   => ['label' => 'Linked bill ID',      'sample' => '412'],
                'line_id'                   => ['label' => 'Line ID',             'sample' => '8801'],
                'expense_date'              => ['label' => 'Expense date',        'sample' => '2026-02-07'],
                'merchant'                  => ['label' => 'Merchant',            'sample' => 'Uber'],
                'category'                  => ['label' => 'Category',            'sample' => 'travel'],
                'amount_dollars'            => ['label' => 'Amount ($)',          'sample' => '24.75'],
                'amount_cents'              => ['label' => 'Amount (¢)',          'sample' => '2475'],
                'gl_expense_account_code'   => ['label' => 'GL account code',     'sample' => '6200'],
                'description'               => ['label' => 'Description',        'sample' => 'Client mtg'],
            ],
        ],

        'billing_invoices' => [
            'label'                 => 'Billing Invoices',
            'module_id'             => 'billing',
            'permission'            => 'billing.view',
            'formats'               => ['csv'],
            'audit_event'           => 'billing.invoice.exported',
            'sensitive_fields'      => [],
            'custom_field_entities' => [],
            'fetcher'               => 'exportDatasetFetchBillingInvoices',
            'fields'                => [
                'invoice_id'      => ['label' => 'Invoice ID',       'sample' => '1204'],
                'invoice_number'  => ['label' => 'Invoice #',        'sample' => 'INV-1204'],
                'client_name'     => ['label' => 'Client name',      'sample' => 'Acme Corp'],
                'currency'        => ['label' => 'Currency',         'sample' => 'USD'],
                'issue_date'      => ['label' => 'Issue date',       'sample' => '2026-02-14'],
                'due_date'        => ['label' => 'Due date',         'sample' => '2026-03-15'],
                'period_start'    => ['label' => 'Period start',     'sample' => '2026-02-01'],
                'period_end'      => ['label' => 'Period end',       'sample' => '2026-02-14'],
                'subtotal'        => ['label' => 'Subtotal',         'sample' => '1000.00', 'field_type' => 'number'],
                'tax_total'       => ['label' => 'Tax total',        'sample' => '80.00', 'field_type' => 'number'],
                'total'           => ['label' => 'Total',            'sample' => '1080.00', 'field_type' => 'number'],
                'amount_paid'     => ['label' => 'Amount paid',      'sample' => '250.00', 'field_type' => 'number'],
                'amount_due'      => ['label' => 'Amount due',       'sample' => '830.00', 'field_type' => 'number'],
                'status'          => ['label' => 'Status',           'sample' => 'sent'],
                'po_number'       => ['label' => 'PO number',        'sample' => 'PO-44'],
                'aggregation'     => ['label' => 'Aggregation',      'sample' => 'weekly'],
                'notes_external'  => ['label' => 'Notes (external)', 'sample' => 'Thank you'],
            ],
        ],

        'billing_payments' => [
            'label'                 => 'Billing Payments',
            'module_id'             => 'billing',
            'permission'            => 'billing.view',
            'formats'               => ['csv'],
            'audit_event'           => 'billing.payment.exported',
            'sensitive_fields'      => [],
            'custom_field_entities' => [],
            'fetcher'               => 'exportDatasetFetchBillingPayments',
            'fields'                => [
                'payment_id'         => ['label' => 'Payment ID',     'sample' => '9004'],
                'client_name'        => ['label' => 'Client name',    'sample' => 'Acme Corp'],
                'received_at'        => ['label' => 'Received at',    'sample' => '2026-02-14'],
                'method'             => ['label' => 'Method',         'sample' => 'ach'],
                'reference'          => ['label' => 'Reference',      'sample' => 'ACH-123'],
                'amount'             => ['label' => 'Amount',         'sample' => '500.00', 'field_type' => 'number'],
                'currency'           => ['label' => 'Currency',       'sample' => 'USD'],
                'unallocated_amount' => ['label' => 'Unallocated',    'sample' => '0.00', 'field_type' => 'number'],
                'notes'              => ['label' => 'Notes',          'sample' => 'Partial payment'],
            ],
        ],

        'time_entries' => [
            'label'                 => 'Time Entries',
            'module_id'             => 'time',
            'permission'            => 'time.view',
            'formats'               => ['csv'],
            'audit_event'           => 'time.entries.exported',
            'sensitive_fields'      => [],
            'custom_field_entities' => [],
            'fetcher'               => 'exportDatasetFetchTimeEntries',
            'fields'                => [
                'entry_id'              => ['label' => 'Entry ID',              'sample' => '4401'],
                'placement_id'          => ['label' => 'Placement ID',          'sample' => '7001'],
                'placement_external_id' => ['label' => 'Placement external ID', 'sample' => 'JD-7001'],
                'placement_title'       => ['label' => 'Placement title',       'sample' => 'Senior Accountant'],
                'end_client_name'       => ['label' => 'End client name',       'sample' => 'Acme Corp'],
                'person_id'             => ['label' => 'Person ID',             'sample' => '42'],
                'person_first_name'     => ['label' => 'Person first name',     'sample' => 'Jordan'],
                'person_last_name'      => ['label' => 'Person last name',      'sample' => 'Rivera'],
                'person_name'           => ['label' => 'Person name',           'sample' => 'Jordan Rivera'],
                'person_email'          => ['label' => 'Person email',          'sample' => 'jordan@example.com'],
                'period_id'             => ['label' => 'Period ID',             'sample' => '203'],
                'period_label'          => ['label' => 'Period',                'sample' => '2026-W07'],
                'period_start'          => ['label' => 'Period start',          'sample' => '2026-02-09'],
                'period_end'            => ['label' => 'Period end',            'sample' => '2026-02-15'],
                'work_date'             => ['label' => 'Work date',             'sample' => '2026-02-14'],
                'category'              => ['label' => 'Category',              'sample' => 'regular_billable'],
                'hours'                 => ['label' => 'Hours',                 'sample' => '8.00', 'field_type' => 'number'],
                'status'                => ['label' => 'Status',                'sample' => 'approved'],
                'source'                => ['label' => 'Source',                'sample' => 'manual_entry'],
                'description'           => ['label' => 'Description',           'sample' => 'Client work'],
                'approved_at'           => ['label' => 'Approved at',           'sample' => '2026-02-15 09:30:00'],
                'approved_via'          => ['label' => 'Approved via',          'sample' => 'manual'],
                'client_approver_email' => ['label' => 'Client approver email', 'sample' => 'manager@example.com'],
                'rate_snapshot_id'      => ['label' => 'Rate snapshot ID',      'sample' => '118'],
            ],
        ],

        'people_directory' => [
            'label'                 => 'People Directory',
            'module_id'             => 'people',
            'permission'            => 'people.view',
            'formats'               => ['csv'],
            'audit_event'           => 'people.directory.exported',
            'sensitive_fields'      => [],
            'custom_field_entities' => ['people'],
            'fetcher'               => 'exportDatasetFetchPeopleDirectory',
            'fields'                => [
                'person_id'          => ['label' => 'Person ID',       'sample' => '42'],
                'external_id'        => ['label' => 'External ID',     'sample' => 'JD-1001'],
                'first_name'         => ['label' => 'First name',      'sample' => 'Jordan'],
                'middle_name'        => ['label' => 'Middle name',     'sample' => 'A.'],
                'last_name'          => ['label' => 'Last name',       'sample' => 'Rivera'],
                'preferred_name'     => ['label' => 'Preferred name',  'sample' => 'Jordy'],
                'email_primary'      => ['label' => 'Primary email',   'sample' => 'jordan@example.com'],
                'email_secondary'    => ['label' => 'Secondary email', 'sample' => 'j.rivera@example.com'],
                'phone_primary'      => ['label' => 'Primary phone',   'sample' => '+1 555 0100'],
                'phone_secondary'    => ['label' => 'Secondary phone', 'sample' => '+1 555 0101'],
                'classification'     => ['label' => 'Classification',  'sample' => 'w2'],
                'status'             => ['label' => 'Status',          'sample' => 'active'],
                'work_auth_status'   => ['label' => 'Work auth',       'sample' => 'authorized'],
                'work_auth_expiry'   => ['label' => 'Work auth expiry','sample' => '2027-01-31'],
                'requires_sponsorship' => ['label' => 'Requires sponsorship', 'sample' => '0'],
                'employment_type'    => ['label' => 'Employment type', 'sample' => 'full_time'],
                'linkedin_url'       => ['label' => 'LinkedIn URL',    'sample' => 'https://linkedin.com/in/example'],
                'source'             => ['label' => 'Source',          'sample' => 'jobdiva'],
                'recruiter_notes'    => ['label' => 'Recruiter notes', 'sample' => 'Strong fit'],
            ],
        ],

        'placements_directory' => [
            'label'                 => 'Placements',
            'module_id'             => 'placements',
            'permission'            => 'placements.view',
            'formats'               => ['csv'],
            'audit_event'           => 'placement.exported',
            'sensitive_fields'      => [],
            'custom_field_entities' => ['placements'],
            'fetcher'               => 'exportDatasetFetchPlacementsDirectory',
            'fields'                => [
                'placement_id'       => ['label' => 'Placement ID',      'sample' => '7001'],
                'person_id'          => ['label' => 'Person ID',         'sample' => '42'],
                'person_first_name'  => ['label' => 'Person first name', 'sample' => 'Jordan'],
                'person_last_name'   => ['label' => 'Person last name',  'sample' => 'Rivera'],
                'person_name'        => ['label' => 'Person name',       'sample' => 'Jordan Rivera'],
                'person_email'       => ['label' => 'Person email',      'sample' => 'jordan@example.com'],
                'title'              => ['label' => 'Title',             'sample' => 'Senior Accountant'],
                'engagement_type'    => ['label' => 'Engagement type',   'sample' => 'w2'],
                'status'             => ['label' => 'Status',            'sample' => 'active'],
                'start_date'         => ['label' => 'Start date',        'sample' => '2026-02-01'],
                'end_date'           => ['label' => 'End date',          'sample' => '2026-08-31'],
                'actual_end_date'    => ['label' => 'Actual end date',   'sample' => ''],
                'due_date'           => ['label' => 'Due date',          'sample' => '2026-08-15'],
                'expiring_date'      => ['label' => 'Expiring date',     'sample' => '2026-08-15'],
                'end_client_name'    => ['label' => 'End client name',   'sample' => 'Acme Corp'],
                'worksite_state'     => ['label' => 'Worksite state',    'sample' => 'NY'],
                'worksite_country'   => ['label' => 'Worksite country',  'sample' => 'US'],
                'remote_policy'      => ['label' => 'Remote policy',     'sample' => 'hybrid'],
                'bill_rate'          => ['label' => 'Bill rate ($/hr)',  'sample' => '100.00', 'field_type' => 'number'],
                'pay_rate'           => ['label' => 'Pay rate ($/hr)',   'sample' => '60.00', 'field_type' => 'number'],
                'placement_count'    => ['label' => 'Placement count',   'sample' => '1', 'field_type' => 'number', 'aggregate' => 'sum'],
                'external_id'        => ['label' => 'External ID',       'sample' => 'jd:1234'],
                'notes'              => ['label' => 'Notes',             'sample' => ''],
            ],
        ],
    ];

    return $registry;
}

function exportDatasetGet(string $key): ?array {
    $reg = exportDatasetRegistry();
    return $reg[$key] ?? null;
}

function exportDatasetUserCanAccess(array $user, array $dataset): bool {
    $permission = (string) ($dataset['permission'] ?? '');
    if ($permission === '' || !function_exists('rbac_legacy_can')) return true;
    return rbac_legacy_can($user, $permission);
}

function exportDatasetAccessibleRegistry(array $user): array {
    $out = [];
    foreach (exportDatasetRegistry() as $key => $dataset) {
        if (exportDatasetUserCanAccess($user, $dataset)) {
            $out[$key] = $dataset;
        }
    }
    return $out;
}

function exportDatasetFieldRegistry(string $dataset, ?int $tenantId = null): array {
    $ds = exportDatasetGet($dataset);
    if (!$ds) return [];
    $fields = $ds['fields'] ?? [];
    if ($tenantId !== null) {
        foreach (($ds['custom_field_entities'] ?? []) as $entityType) {
            try {
                foreach (customFieldDefinitions($tenantId, (string) $entityType) as $def) {
                    $key = 'custom_fields.' . $entityType . '.' . (string) ($def['field_key'] ?? '');
                    if ($key === 'custom_fields.' . $entityType . '.') continue;
                    $fields[$key] = [
                        'label'        => (string) ($def['field_label'] ?? $key),
                        'sample'       => '',
                        'custom_field' => true,
                        'entity_type'  => $entityType,
                        'field_type'   => (string) ($def['field_type'] ?? 'text'),
                        'sensitive'    => !empty($def['pii']),
                    ];
                }
            } catch (\Throwable $e) {
                error_log('[export_datasets] custom fields unavailable for ' . $entityType . ': ' . $e->getMessage());
            }
        }
    }
    return $fields;
}

function exportDatasetIsSensitiveField(string $dataset, string $field, ?int $tenantId = null): bool {
    $ds = exportDatasetGet($dataset);
    if (!$ds) return false;
    if (in_array($field, $ds['sensitive_fields'] ?? [], true)) return true;
    $fields = exportDatasetFieldRegistry($dataset, $tenantId);
    return !empty($fields[$field]['sensitive']);
}

// ───────── Fetchers ─────────
// Each fetcher returns an iterable of flat associative arrays matching the
// dataset's fields[]. Missing fields are emitted as '' during render.

function exportDatasetFetchPayrollDisbursements(int $tenantId, array $opts): array {
    $runId = (int) ($opts['run_id'] ?? 0);
    if (!$runId) return [];
    $pdo = getDB();

    // payroll_line_items + payroll_runs + people_employees join.
    $stmt = $pdo->prepare("
        SELECT r.id AS run_id, r.pay_date, r.period_start, r.period_end,
               e.id   AS employee_id,
               e.external_id AS employee_external_id,
               e.first_name  AS employee_first_name,
               e.last_name   AS employee_last_name,
               e.email       AS employee_email,
               COALESCE(l.regular_hours, 0)  AS regular_hours,
               COALESCE(l.overtime_hours, 0) AS overtime_hours,
               COALESCE(l.pto_hours, 0)      AS pto_hours,
               l.gross_pay_cents,
               l.net_pay_cents,
               COALESCE(l.reimbursement_cents, 0) AS reimbursement_cents,
               COALESCE(l.bonus_cents, 0)         AS bonus_cents,
               l.bank_routing_number, l.bank_account_number, l.bank_account_type
          FROM payroll_runs r
          JOIN payroll_line_items l ON l.run_id = r.id
          JOIN people_employees   e ON e.id    = l.employee_id
         WHERE r.tenant_id = :t AND r.id = :r
         ORDER BY e.last_name, e.first_name
    ");
    $stmt->execute(['t' => $tenantId, 'r' => $runId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $gc = (int) ($row['gross_pay_cents'] ?? 0);
        $nc = (int) ($row['net_pay_cents'] ?? 0);
        $rc = (int) ($row['reimbursement_cents'] ?? 0);
        $bc = (int) ($row['bonus_cents'] ?? 0);
        $row['gross_pay_dollars']     = sprintf('%.2f', $gc / 100);
        $row['gross_pay_cents']       = $gc;
        $row['net_pay_dollars']       = sprintf('%.2f', $nc / 100);
        $row['net_pay_cents']         = $nc;
        $row['reimbursement_dollars'] = sprintf('%.2f', $rc / 100);
        $row['bonus_dollars']         = sprintf('%.2f', $bc / 100);
        $out[] = $row;
    }
    return $out;
}

function exportDatasetFetchApPayments(int $tenantId, array $opts): array {
    $ids = array_values(array_filter(array_map('intval', (array) ($opts['ids'] ?? [])), fn ($x) => $x > 0));
    if (!$ids && !empty($opts['run_id'])) {
        // Optional: all payments in an AP run.
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT id FROM ap_payments WHERE tenant_id = :t AND run_id = :r'
        );
        $stmt->execute(['t' => $tenantId, 'r' => (int) $opts['run_id']]);
        $ids = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    }
    if (!$ids) return [];

    $pdo = getDB();
    $place = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids; array_unshift($params, $tenantId);
    $stmt = $pdo->prepare("
        SELECT p.id AS payment_id, p.payment_date, p.amount_cents, p.currency,
               p.memo, p.rail, p.rail_external_ref,
               p.bank_routing_number, p.bank_account_number, p.bank_account_type,
               p.vendor_id, v.external_id AS vendor_external_id, v.name AS vendor_name,
               p.bill_id, b.bill_number
          FROM ap_payments p
          LEFT JOIN ap_vendors v ON v.id = p.vendor_id
          LEFT JOIN ap_bills   b ON b.id = p.bill_id
         WHERE p.tenant_id = ? AND p.id IN ($place)
         ORDER BY p.payment_date, p.id
    ");
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $c = (int) ($row['amount_cents'] ?? 0);
        $row['amount_dollars'] = sprintf('%.2f', $c / 100);
        $row['amount_cents']   = $c;
        $out[] = $row;
    }
    return $out;
}

function exportDatasetFetchExpenses(int $tenantId, array $opts): array {
    $ids = array_values(array_filter(array_map('intval', (array) ($opts['ids'] ?? [])), fn ($x) => $x > 0));
    if (!$ids) return [];
    $pdo = getDB();
    $place = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids; array_unshift($params, $tenantId);
    $stmt = $pdo->prepare("
        SELECT er.id AS report_id, er.period_label, er.submitter_user_id, er.status,
               er.currency, er.bill_id,
               COALESCE(u.name, u.email) AS submitter_name,
               erl.id AS line_id, erl.expense_date, erl.merchant, erl.category,
               erl.amount, erl.description, erl.gl_expense_account_code
          FROM ap_expense_reports er
     LEFT JOIN ap_expense_report_lines erl ON erl.expense_report_id = er.id
     LEFT JOIN users u ON u.id = er.submitter_user_id
         WHERE er.tenant_id = ? AND er.id IN ($place)
         ORDER BY er.id, erl.id
    ");
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $a = (float) ($row['amount'] ?? 0);
        $row['amount_dollars'] = sprintf('%.2f', $a);
        $row['amount_cents']   = (int) round($a * 100);
        $out[] = $row;
    }
    return $out;
}

function exportDatasetFetchBillingInvoices(int $tenantId, array $opts): array {
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (!empty($opts['status'])) {
        $where[] = 'status = :status';
        $params['status'] = (string) $opts['status'];
    }
    if (!empty($opts['from'])) {
        $where[] = 'issue_date >= :from_date';
        $params['from_date'] = (string) $opts['from'];
    }
    if (!empty($opts['to'])) {
        $where[] = 'issue_date <= :to_date';
        $params['to_date'] = (string) $opts['to'];
    }
    if (!empty($opts['client_name'])) {
        $where[] = 'client_name = :client_name';
        $params['client_name'] = (string) $opts['client_name'];
    }

    $stmt = $pdo->prepare(
        'SELECT id AS invoice_id, invoice_number, client_name, currency, issue_date, due_date,
                period_start, period_end, subtotal, tax_total, total, amount_paid, amount_due,
                status, po_number, aggregation, notes_external
           FROM billing_invoices
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY issue_date DESC, id DESC
          LIMIT ' . $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function exportDatasetFetchBillingPayments(int $tenantId, array $opts): array {
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (!empty($opts['from'])) {
        $where[] = 'received_at >= :from_date';
        $params['from_date'] = (string) $opts['from'];
    }
    if (!empty($opts['to'])) {
        $where[] = 'received_at <= :to_date';
        $params['to_date'] = (string) $opts['to'];
    }
    if (!empty($opts['client_name'])) {
        $where[] = 'client_name = :client_name';
        $params['client_name'] = (string) $opts['client_name'];
    }
    if (!empty($opts['method'])) {
        $where[] = 'method = :method';
        $params['method'] = (string) $opts['method'];
    }

    $stmt = $pdo->prepare(
        'SELECT id AS payment_id, client_name, received_at, method, reference, amount, currency,
                unallocated_amount, notes
           FROM billing_payments
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY received_at DESC, id DESC
          LIMIT ' . $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function exportDatasetFetchTimeEntries(int $tenantId, array $opts): array {
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $where = ['te.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (!empty($opts['from'])) {
        $where[] = 'te.work_date >= :from_date';
        $params['from_date'] = (string) $opts['from'];
    }
    if (!empty($opts['to'])) {
        $where[] = 'te.work_date <= :to_date';
        $params['to_date'] = (string) $opts['to'];
    }
    if (!empty($opts['status'])) {
        $where[] = 'te.status = :status';
        $params['status'] = (string) $opts['status'];
    }
    if (!empty($opts['placement_external_id'])) {
        $where[] = 'pl.external_id = :placement_external_id';
        $params['placement_external_id'] = (string) $opts['placement_external_id'];
    }

    $stmt = $pdo->prepare(
        'SELECT te.id AS entry_id,
                te.placement_id,
                pl.external_id AS placement_external_id,
                pl.title AS placement_title,
                pl.end_client_name,
                te.person_id,
                pe.first_name AS person_first_name,
                pe.last_name AS person_last_name,
                CONCAT_WS(" ", pe.first_name, pe.last_name) AS person_name,
                pe.email_primary AS person_email,
                te.period_id,
                tp.label AS period_label,
                tp.start_date AS period_start,
                tp.end_date AS period_end,
                te.work_date,
                te.category,
                te.hours,
                te.status,
                te.source,
                te.description,
                te.approved_at,
                te.approved_via,
                te.client_approver_email,
                te.rate_snapshot_id
           FROM time_entries te
           LEFT JOIN placements pl ON pl.id = te.placement_id AND pl.tenant_id = te.tenant_id
           LEFT JOIN people pe ON pe.id = te.person_id AND pe.tenant_id = te.tenant_id
           LEFT JOIN time_periods tp ON tp.id = te.period_id AND tp.tenant_id = te.tenant_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY te.work_date DESC, te.id DESC
          LIMIT ' . $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function exportDatasetFetchPeopleDirectory(int $tenantId, array $opts): array {
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $includeSensitiveCustomFields = !empty($opts['include_sensitive_custom_fields']);
    $where = ['tenant_id = :tenant_id', 'deleted_at IS NULL'];
    $params = ['tenant_id' => $tenantId];
    if (!empty($opts['status'])) {
        $where[] = 'status = :status';
        $params['status'] = (string) $opts['status'];
    }
    if (!empty($opts['classification'])) {
        $where[] = 'classification = :classification';
        $params['classification'] = (string) $opts['classification'];
    }
    $stmt = $pdo->prepare(
        'SELECT id AS person_id, external_id, first_name, middle_name, last_name, preferred_name,
                email_primary, email_secondary, phone_primary, phone_secondary,
                classification, status, work_auth_status, work_auth_expiry,
                requires_sponsorship, employment_type, linkedin_url, source, recruiter_notes
           FROM people
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY last_name, first_name
          LIMIT ' . $limit
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $defs = [];
    try {
        foreach (customFieldDefinitions($tenantId, 'people') as $def) {
            if (!$includeSensitiveCustomFields && !empty($def['pii'])) continue;
            $defs[(int) $def['id']] = $def;
        }
    } catch (\Throwable $e) {
        $defs = [];
    }
    if (!$rows || !$defs) return $rows;

    $ids = array_map('intval', array_column($rows, 'person_id'));
    $place = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    array_unshift($params, $tenantId);
    $values = $pdo->prepare(
        "SELECT person_id, field_def_id, value_text, value_number, value_date, value_boolean
           FROM people_custom_field_values
          WHERE tenant_id = ? AND person_id IN ($place)"
    );
    $values->execute($params);
    $byPerson = [];
    foreach ($values->fetchAll(PDO::FETCH_ASSOC) ?: [] as $valueRow) {
        $def = $defs[(int) $valueRow['field_def_id']] ?? null;
        if (!$def) continue;
        $key = 'custom_fields.people.' . $def['field_key'];
        $byPerson[(int) $valueRow['person_id']][$key] = _exportDatasetCustomValue($def, $valueRow);
    }

    foreach ($rows as &$row) {
        foreach (($byPerson[(int) $row['person_id']] ?? []) as $key => $value) {
            $row[$key] = $value;
        }
    }
    unset($row);
    return $rows;
}

function exportDatasetFetchPlacementsDirectory(int $tenantId, array $opts): array {
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $where = ['p.tenant_id = :tenant_id', 'p.deleted_at IS NULL'];
    $params = ['tenant_id' => $tenantId];
    if (!empty($opts['status'])) {
        $where[] = 'p.status = :status';
        $params['status'] = (string) $opts['status'];
    }
    if (!empty($opts['engagement_type'])) {
        $where[] = 'p.engagement_type = :engagement_type';
        $params['engagement_type'] = (string) $opts['engagement_type'];
    }
    $stmt = $pdo->prepare(
        'SELECT p.id AS placement_id,
                p.person_id,
                pe.first_name AS person_first_name,
                pe.last_name AS person_last_name,
                pe.email_primary AS person_email,
                CONCAT_WS(" ", pe.first_name, pe.last_name) AS person_name,
                p.title, p.engagement_type, p.status,
                p.start_date, p.end_date, p.actual_end_date, p.due_date,
                CASE
                    WHEN p.due_date IS NULL THEN p.end_date
                    WHEN p.end_date IS NULL THEN p.due_date
                    WHEN p.due_date <= p.end_date THEN p.due_date
                    ELSE p.end_date
                END AS expiring_date,
                p.end_client_name, p.worksite_state, p.worksite_country, p.remote_policy,
                (SELECT bill_rate FROM placement_rates r
                  WHERE r.tenant_id = p.tenant_id AND r.placement_id = p.id
                  ORDER BY r.effective_from DESC LIMIT 1) AS bill_rate,
                (SELECT pay_rate FROM placement_rates r
                  WHERE r.tenant_id = p.tenant_id AND r.placement_id = p.id
                  ORDER BY r.effective_from DESC LIMIT 1) AS pay_rate,
                1 AS placement_count,
                p.external_id, p.notes
           FROM placements p
           LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = p.tenant_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY p.start_date DESC, p.id DESC
          LIMIT ' . $limit
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return exportDatasetAttachCustomFieldValues(
        $rows,
        $tenantId,
        'placements',
        'placement_id',
        !empty($opts['include_sensitive_custom_fields'])
    );
}

function exportDatasetAttachCustomFieldValues(array $rows, int $tenantId, string $entityType, string $idKey, bool $includeSensitive = false): array {
    if (!$rows) return $rows;
    try {
        $defs = [];
        foreach (customFieldDefinitions($tenantId, $entityType) as $def) {
            if (!$includeSensitive && !empty($def['pii'])) continue;
            $defs[(string) ($def['field_key'] ?? '')] = true;
        }
        if (!$defs) return $rows;
        foreach ($rows as &$row) {
            $recordId = (int) ($row[$idKey] ?? 0);
            if ($recordId <= 0) continue;
            foreach (customFieldValues($tenantId, $entityType, $recordId, $includeSensitive) as $valueRow) {
                $fieldKey = (string) ($valueRow['field_key'] ?? '');
                if ($fieldKey === '' || !isset($defs[$fieldKey])) continue;
                $row['custom_fields.' . $entityType . '.' . $fieldKey] = $valueRow['value'] ?? null;
            }
        }
        unset($row);
    } catch (\Throwable $e) {
        error_log('[export_datasets] custom field values unavailable for ' . $entityType . ': ' . $e->getMessage());
    }
    return $rows;
}

function _exportDatasetCustomValue(array $def, array $row) {
    return match ((string) ($def['field_type'] ?? 'text')) {
        'number'  => $row['value_number'],
        'date'    => $row['value_date'],
        'boolean' => $row['value_boolean'],
        default   => $row['value_text'],
    };
}
