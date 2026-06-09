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
                'last_name'          => ['label' => 'Last name',       'sample' => 'Rivera'],
                'preferred_name'     => ['label' => 'Preferred name',  'sample' => 'Jordy'],
                'email_primary'      => ['label' => 'Primary email',   'sample' => 'jordan@example.com'],
                'phone_primary'      => ['label' => 'Primary phone',   'sample' => '+1 555 0100'],
                'classification'     => ['label' => 'Classification',  'sample' => 'w2'],
                'status'             => ['label' => 'Status',          'sample' => 'active'],
                'work_auth_status'   => ['label' => 'Work auth',       'sample' => 'authorized'],
                'work_auth_expiry'   => ['label' => 'Work auth expiry','sample' => '2027-01-31'],
                'employment_type'    => ['label' => 'Employment type', 'sample' => 'full_time'],
                'source'             => ['label' => 'Source',          'sample' => 'jobdiva'],
            ],
        ],
    ];

    return $registry;
}

function exportDatasetGet(string $key): ?array {
    $reg = exportDatasetRegistry();
    return $reg[$key] ?? null;
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

function exportDatasetIsSensitiveField(string $dataset, string $field): bool {
    $ds = exportDatasetGet($dataset);
    if (!$ds) return false;
    if (in_array($field, $ds['sensitive_fields'] ?? [], true)) return true;
    $fields = exportDatasetFieldRegistry($dataset, null);
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

function exportDatasetFetchPeopleDirectory(int $tenantId, array $opts): array {
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $stmt = $pdo->prepare(
        'SELECT id AS person_id, external_id, first_name, last_name, preferred_name,
                email_primary, phone_primary, classification, status,
                work_auth_status, work_auth_expiry, employment_type, source
           FROM people
          WHERE tenant_id = :tenant_id AND deleted_at IS NULL
          ORDER BY last_name, first_name
          LIMIT ' . $limit
    );
    $stmt->execute(['tenant_id' => $tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $defs = [];
    try {
        foreach (customFieldDefinitions($tenantId, 'people') as $def) {
            if (!empty($def['pii'])) continue;
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

function _exportDatasetCustomValue(array $def, array $row) {
    return match ((string) ($def['field_type'] ?? 'text')) {
        'number'  => $row['value_number'],
        'date'    => $row['value_date'],
        'boolean' => $row['value_boolean'],
        default   => $row['value_text'],
    };
}
