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
            'audit_event'           => 'ap.payments.exported',
            'sensitive_fields'      => ['bank_routing_number', 'bank_account_number'],
            'custom_field_entities' => [],
            'fetcher'               => 'exportDatasetFetchApPayments',
            'fields'                => [
                'payment_id'          => ['label' => 'Payment ID',           'sample' => '9001'],
                'payment_date'        => ['label' => 'Payment date',         'sample' => '2026-02-14'],
                'pay_date'            => ['label' => 'Pay date',             'sample' => '2026-02-14'],
                'vendor_id'           => ['label' => 'Vendor ID (internal)', 'sample' => '58'],
                'vendor_external_id'  => ['label' => 'Vendor ID (external)', 'sample' => 'V-058'],
                'vendor_name'         => ['label' => 'Vendor name',          'sample' => 'Acme Corp'],
                'bill_id'             => ['label' => 'Bill ID',              'sample' => '412'],
                'bill_number'         => ['label' => 'Bill number',          'sample' => 'INV-00412'],
                'bill_ids'            => ['label' => 'Bill IDs',             'sample' => '412,413'],
                'bill_numbers'        => ['label' => 'Bill numbers',         'sample' => 'INV-00412,INV-00413'],
                'method'              => ['label' => 'Method',               'sample' => 'ach'],
                'reference'           => ['label' => 'Reference',            'sample' => 'ACH-123'],
                'amount'              => ['label' => 'Amount',               'sample' => '1250.00', 'field_type' => 'number'],
                'amount_dollars'      => ['label' => 'Amount ($)',           'sample' => '1,250.00'],
                'amount_cents'        => ['label' => 'Amount (¢)',           'sample' => '125000'],
                'currency'            => ['label' => 'Currency',             'sample' => 'USD'],
                'unallocated_amount'  => ['label' => 'Unallocated',          'sample' => '0.00', 'field_type' => 'number'],
                'status'              => ['label' => 'Status',               'sample' => 'sent'],
                'cleared_at'          => ['label' => 'Cleared at',           'sample' => '2026-02-15 09:30:00'],
                'sent_at'             => ['label' => 'Sent at',              'sample' => '2026-02-14 16:00:00'],
                'memo'                => ['label' => 'Memo',                 'sample' => 'Feb services'],
                'notes'               => ['label' => 'Notes',                'sample' => 'Feb services'],
                'bank_account_id'     => ['label' => 'Bank account ID',      'sample' => '7'],
                'bank_routing_number' => ['label' => 'Bank routing',         'sample' => '021000021'],
                'bank_account_number' => ['label' => 'Bank account',         'sample' => '1234567890'],
                'bank_account_type'   => ['label' => 'Account type',         'sample' => 'checking'],
                'rail'                => ['label' => 'Rail',                 'sample' => 'plaid_transfer'],
                'disbursement_rail'   => ['label' => 'Disbursement rail',    'sample' => 'plaid_transfer'],
                'rail_external_ref'   => ['label' => 'Rail reference',       'sample' => 'trn-abc123'],
                'rail_status'         => ['label' => 'Rail status',          'sample' => 'submitted'],
                'rail_originated_at'  => ['label' => 'Rail originated at',   'sample' => '2026-02-14 16:05:00'],
                'journal_entry_id'    => ['label' => 'Journal entry ID',     'sample' => '901'],
            ],
        ],

        'ap_bills' => [
            'label'                 => 'AP Bills',
            'module_id'             => 'ap',
            'permission'            => 'ap.export.run',
            'formats'               => ['csv'],
            'audit_event'           => 'ap.bills.exported',
            'sensitive_fields'      => [],
            'custom_field_entities' => [],
            'fetcher'               => 'exportDatasetFetchApBills',
            'fields'                => [
                'bill_id'          => ['label' => 'Bill ID',          'sample' => '412'],
                'bill_number'      => ['label' => 'Bill #',           'sample' => 'INV-00412'],
                'internal_ref'     => ['label' => 'Internal ref',     'sample' => 'AP-2026-00412'],
                'vendor_name'      => ['label' => 'Vendor name',      'sample' => 'Acme Corp'],
                'vendor_type'      => ['label' => 'Vendor type',      'sample' => 'w9_business'],
                'received_at'      => ['label' => 'Received at',      'sample' => '2026-02-13'],
                'bill_date'        => ['label' => 'Bill date',        'sample' => '2026-02-14'],
                'due_date'         => ['label' => 'Due date',         'sample' => '2026-03-15'],
                'period_start'     => ['label' => 'Period start',     'sample' => '2026-02-01'],
                'period_end'       => ['label' => 'Period end',       'sample' => '2026-02-14'],
                'currency'         => ['label' => 'Currency',         'sample' => 'USD'],
                'subtotal'         => ['label' => 'Subtotal',         'sample' => '1000.00', 'field_type' => 'number'],
                'tax_total'        => ['label' => 'Tax total',        'sample' => '80.00', 'field_type' => 'number'],
                'total'            => ['label' => 'Total',            'sample' => '1080.00', 'field_type' => 'number'],
                'amount_paid'      => ['label' => 'Amount paid',      'sample' => '250.00', 'field_type' => 'number'],
                'amount_due'       => ['label' => 'Amount due',       'sample' => '830.00', 'field_type' => 'number'],
                'status'           => ['label' => 'Status',           'sample' => 'approved'],
                'source'           => ['label' => 'Source',           'sample' => 'manual'],
                'po_number'        => ['label' => 'PO number',        'sample' => 'PO-44'],
                'placement_id'     => ['label' => 'Placement ID',     'sample' => '7001'],
                'journal_entry_id' => ['label' => 'Journal entry ID', 'sample' => '901'],
                'notes_internal'   => ['label' => 'Notes (internal)', 'sample' => 'Review complete'],
            ],
        ],

        'ap_vendors' => [
            'label'                 => 'AP Vendors',
            'module_id'             => 'ap',
            'permission'            => 'ap.export.run',
            'formats'               => ['csv'],
            'audit_event'           => 'ap.vendors.exported',
            'sensitive_fields'      => ['tax_id_last4', 'payment_account_last4'],
            'custom_field_entities' => [],
            'fetcher'               => 'exportDatasetFetchApVendors',
            'fields'                => [
                'vendor_id'             => ['label' => 'Vendor ID',          'sample' => '58'],
                'vendor_name'           => ['label' => 'Vendor name',        'sample' => 'Acme Corp'],
                'vendor_type'           => ['label' => 'Vendor type',        'sample' => 'w9_business'],
                'vendor_category'       => ['label' => 'Vendor category',    'sample' => 'service_provider'],
                'default_terms'         => ['label' => 'Default terms',      'sample' => 'NET30'],
                'remit_to_email'        => ['label' => 'Remit-to email',     'sample' => 'ap@example.com'],
                'remit_to_phone'        => ['label' => 'Remit-to phone',     'sample' => '+1 555 0100'],
                'payment_method'        => ['label' => 'Payment method',     'sample' => 'ach'],
                'tax_id_last4'          => ['label' => 'Tax ID last 4',      'sample' => '6789'],
                'payment_account_last4' => ['label' => 'Pay acct last 4',    'sample' => '1234'],
                'requires_1099'         => ['label' => 'Requires 1099',      'sample' => '1'],
                'last_bill_at'          => ['label' => 'Last bill at',       'sample' => '2026-02-14 09:00:00'],
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
                'report_status'             => ['label' => 'Report status',       'sample' => 'approved'],
                'total'                     => ['label' => 'Report total',        'sample' => '241.75', 'field_type' => 'number'],
                'currency'                  => ['label' => 'Currency',            'sample' => 'USD'],
                'bill_id'                   => ['label' => 'Linked bill ID',      'sample' => '412'],
                'created_at'                => ['label' => 'Created at',          'sample' => '2026-02-08 09:00:00'],
                'line_id'                   => ['label' => 'Line ID',             'sample' => '8801'],
                'expense_date'              => ['label' => 'Expense date',        'sample' => '2026-02-07'],
                'merchant'                  => ['label' => 'Merchant',            'sample' => 'Uber'],
                'category'                  => ['label' => 'Category',            'sample' => 'travel'],
                'amount'                    => ['label' => 'Amount',              'sample' => '24.75', 'field_type' => 'number'],
                'amount_dollars'            => ['label' => 'Amount ($)',          'sample' => '24.75'],
                'amount_cents'              => ['label' => 'Amount (¢)',          'sample' => '2475'],
                'gl_expense_account_code'   => ['label' => 'GL account code',     'sample' => '6200'],
                'description'               => ['label' => 'Description',        'sample' => 'Client mtg'],
                'billable_to_client_name'   => ['label' => 'Billable client',     'sample' => 'Acme Corp'],
            ],
        ],

        'accounting_chart_of_accounts' => [
            'label'                 => 'Accounting Chart of Accounts',
            'module_id'             => 'accounting',
            'permission'            => 'accounting.reports.export',
            'formats'               => ['csv'],
            'audit_event'           => 'accounting.ledger.exported',
            'sensitive_fields'      => [],
            'custom_field_entities' => [],
            'fetcher'               => 'exportDatasetFetchAccountingChartOfAccounts',
            'fields'                => [
                'account_id'        => ['label' => 'Account ID',       'sample' => '101'],
                'code'              => ['label' => 'Code',             'sample' => '1010'],
                'name'              => ['label' => 'Name',             'sample' => 'Operating Cash'],
                'account_type'      => ['label' => 'Account type',     'sample' => 'asset'],
                'normal_side'       => ['label' => 'Normal side',      'sample' => 'debit'],
                'cash_flow_tag'     => ['label' => 'Cash flow tag',    'sample' => 'operating_cash'],
                'parent_account_id' => ['label' => 'Parent account ID','sample' => '100'],
                'is_postable'       => ['label' => 'Postable',         'sample' => '1', 'field_type' => 'boolean'],
                'currency'          => ['label' => 'Currency',         'sample' => 'USD'],
                'description'       => ['label' => 'Description',      'sample' => 'Primary checking account'],
                'active'            => ['label' => 'Active',           'sample' => '1', 'field_type' => 'boolean'],
                'created_at'        => ['label' => 'Created at',       'sample' => '2026-02-01 09:00:00'],
                'updated_at'        => ['label' => 'Updated at',       'sample' => '2026-02-14 09:00:00'],
            ],
        ],

        'accounting_journal_entries' => [
            'label'                 => 'Accounting Journal Entries',
            'module_id'             => 'accounting',
            'permission'            => 'accounting.reports.export',
            'formats'               => ['csv'],
            'audit_event'           => 'accounting.ledger.exported',
            'sensitive_fields'      => [],
            'custom_field_entities' => [],
            'fetcher'               => 'exportDatasetFetchAccountingJournalEntries',
            'fields'                => [
                'journal_entry_id'    => ['label' => 'Journal entry ID',  'sample' => '501'],
                'je_number'           => ['label' => 'JE number',         'sample' => 'JE-000501'],
                'posting_date'        => ['label' => 'Posting date',      'sample' => '2026-02-14'],
                'entity_id'           => ['label' => 'Entity ID',         'sample' => '1'],
                'period_id'           => ['label' => 'Period ID',         'sample' => '12'],
                'source_module'       => ['label' => 'Source module',     'sample' => 'ap'],
                'source_ref_type'     => ['label' => 'Source ref type',   'sample' => 'bill'],
                'source_ref_id'       => ['label' => 'Source ref ID',     'sample' => '412'],
                'status'              => ['label' => 'Posting status',    'sample' => 'posted'],
                'approval_state'      => ['label' => 'Approval state',    'sample' => 'approved'],
                'currency'            => ['label' => 'Currency',          'sample' => 'USD'],
                'total_debit'         => ['label' => 'Total debit',       'sample' => '1080.00', 'field_type' => 'currency'],
                'total_credit'        => ['label' => 'Total credit',      'sample' => '1080.00', 'field_type' => 'currency'],
                'memo'                => ['label' => 'Memo',              'sample' => 'AP bill posting'],
                'posted_at'           => ['label' => 'Posted at',         'sample' => '2026-02-14 10:00:00'],
                'posted_by_user_id'   => ['label' => 'Posted by user ID', 'sample' => '7'],
                'created_by_user_id'  => ['label' => 'Created by user ID','sample' => '7'],
                'created_at'          => ['label' => 'Created at',        'sample' => '2026-02-14 09:00:00'],
                'updated_at'          => ['label' => 'Updated at',        'sample' => '2026-02-14 10:00:00'],
            ],
        ],

        'accounting_gl_detail' => [
            'label'                 => 'Accounting GL Detail',
            'module_id'             => 'accounting',
            'permission'            => 'accounting.reports.export',
            'formats'               => ['csv'],
            'audit_event'           => 'accounting.ledger.exported',
            'sensitive_fields'      => [],
            'custom_field_entities' => [],
            'fetcher'               => 'exportDatasetFetchAccountingGlDetail',
            'fields'                => [
                'line_id'              => ['label' => 'Line ID',          'sample' => '9001'],
                'journal_entry_id'     => ['label' => 'Journal entry ID', 'sample' => '501'],
                'line_no'              => ['label' => 'Line #',           'sample' => '1', 'field_type' => 'number'],
                'je_number'            => ['label' => 'JE number',        'sample' => 'JE-000501'],
                'posting_date'         => ['label' => 'Posting date',     'sample' => '2026-02-14'],
                'entity_id'            => ['label' => 'Entity ID',        'sample' => '1'],
                'period_id'            => ['label' => 'Period ID',        'sample' => '12'],
                'account_id'           => ['label' => 'Account ID',       'sample' => '101'],
                'account_code'         => ['label' => 'Account code',     'sample' => '1010'],
                'account_name'         => ['label' => 'Account name',     'sample' => 'Operating Cash'],
                'account_type'         => ['label' => 'Account type',     'sample' => 'asset'],
                'normal_side'          => ['label' => 'Normal side',      'sample' => 'debit'],
                'debit'                => ['label' => 'Debit',            'sample' => '1080.00', 'field_type' => 'currency'],
                'credit'               => ['label' => 'Credit',           'sample' => '0.00', 'field_type' => 'currency'],
                'normal_balance_delta' => ['label' => 'Normal balance delta', 'sample' => '1080.00', 'field_type' => 'currency'],
                'memo'                 => ['label' => 'Line memo',        'sample' => 'AP bill posting'],
                'source_module'        => ['label' => 'Source module',    'sample' => 'ap'],
                'source_ref_type'      => ['label' => 'Source ref type',  'sample' => 'bill'],
                'source_ref_id'        => ['label' => 'Source ref ID',    'sample' => '412'],
                'status'               => ['label' => 'Posting status',   'sample' => 'posted'],
            ],
        ],

        'accounting_periods' => [
            'label'                 => 'Accounting Periods',
            'module_id'             => 'accounting',
            'permission'            => 'accounting.reports.export',
            'formats'               => ['csv'],
            'audit_event'           => 'accounting.ledger.exported',
            'sensitive_fields'      => [],
            'custom_field_entities' => [],
            'fetcher'               => 'exportDatasetFetchAccountingPeriods',
            'fields'                => [
                'period_id'           => ['label' => 'Period ID',         'sample' => '12'],
                'entity_id'           => ['label' => 'Entity ID',         'sample' => '1'],
                'period_number'       => ['label' => 'Period number',     'sample' => '2', 'field_type' => 'number'],
                'start_date'          => ['label' => 'Start date',        'sample' => '2026-02-01'],
                'end_date'            => ['label' => 'End date',          'sample' => '2026-02-28'],
                'status'              => ['label' => 'Status',            'sample' => 'open'],
                'closed_at'           => ['label' => 'Closed at',         'sample' => '2026-03-02 18:00:00'],
                'closed_by_user_id'   => ['label' => 'Closed by user ID', 'sample' => '7'],
                'reopened_at'         => ['label' => 'Reopened at',       'sample' => '2026-03-03 09:00:00'],
                'reopened_by_user_id' => ['label' => 'Reopened by user ID','sample' => '8'],
                'reopen_reason'       => ['label' => 'Reopen reason',     'sample' => 'Late AP accrual'],
            ],
        ],

        'accounting_bank_statement_lines' => [
            'label'                 => 'Accounting Bank Statement Lines',
            'module_id'             => 'accounting',
            'permission'            => 'accounting.reports.export',
            'formats'               => ['csv'],
            'audit_event'           => 'accounting.ledger.exported',
            'sensitive_fields'      => [],
            'custom_field_entities' => [],
            'fetcher'               => 'exportDatasetFetchAccountingBankStatementLines',
            'fields'                => [
                'bank_statement_line_id' => ['label' => 'Statement line ID', 'sample' => '7301'],
                'bank_account_id'        => ['label' => 'Bank account ID',   'sample' => '5'],
                'bank_account_name'      => ['label' => 'Bank account',      'sample' => 'Operating Chase'],
                'entity_id'              => ['label' => 'Entity ID',         'sample' => '1'],
                'gl_account_code'        => ['label' => 'GL account code',   'sample' => '1010'],
                'bank_name'              => ['label' => 'Bank name',         'sample' => 'Chase'],
                'posted_date'            => ['label' => 'Posted date',       'sample' => '2026-02-14'],
                'description'            => ['label' => 'Description',       'sample' => 'ACH CREDIT'],
                'amount'                 => ['label' => 'Amount',            'sample' => '5000.00', 'field_type' => 'currency'],
                'bank_reference'         => ['label' => 'Bank reference',    'sample' => 'ACH-123'],
                'fitid'                  => ['label' => 'FITID',             'sample' => '20260214-1'],
                'match_status'           => ['label' => 'Match status',      'sample' => 'matched'],
                'matched_je_id'          => ['label' => 'Matched JE ID',     'sample' => '501'],
                'matched_at'             => ['label' => 'Matched at',        'sample' => '2026-02-14 12:00:00'],
                'matched_by_user_id'     => ['label' => 'Matched by user ID','sample' => '7'],
                'created_at'             => ['label' => 'Created at',        'sample' => '2026-02-14 09:00:00'],
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

        'staffing_clients' => [
            'label'                 => 'Staffing Clients',
            'module_id'             => 'staffing',
            'permission'            => 'staffing.export.run',
            'formats'               => ['csv'],
            'audit_event'           => 'staffing.clients.exported',
            'sensitive_fields'      => [],
            'custom_field_entities' => [],
            'fetcher'               => 'exportDatasetFetchStaffingClients',
            'fields'                => [
                'client_id'             => ['label' => 'Client ID',             'sample' => '501'],
                'name'                  => ['label' => 'Client name',           'sample' => 'Acme Corp'],
                'legal_name'            => ['label' => 'Legal name',            'sample' => 'Acme Corporation'],
                'external_id'           => ['label' => 'External ID',           'sample' => 'JD-ACME'],
                'source_system'         => ['label' => 'Source system',         'sample' => 'jobdiva'],
                'industry'              => ['label' => 'Industry',              'sample' => 'Healthcare'],
                'primary_contact_name'  => ['label' => 'Primary contact name',  'sample' => 'Morgan Lee'],
                'primary_contact_email' => ['label' => 'Primary contact email', 'sample' => 'morgan@example.com'],
                'primary_contact_phone' => ['label' => 'Primary contact phone', 'sample' => '+1 555 0100'],
                'billing_address_line1' => ['label' => 'Billing address line 1','sample' => '100 Main St'],
                'billing_address_line2' => ['label' => 'Billing address line 2','sample' => 'Suite 400'],
                'billing_city'          => ['label' => 'Billing city',          'sample' => 'New York'],
                'billing_state'         => ['label' => 'Billing state',         'sample' => 'NY'],
                'billing_postal_code'   => ['label' => 'Billing postal code',   'sample' => '10001'],
                'billing_country'       => ['label' => 'Billing country',       'sample' => 'US'],
                'payment_terms_days'    => ['label' => 'Payment terms days',    'sample' => '30', 'field_type' => 'number'],
                'status'                => ['label' => 'Status',                'sample' => 'active'],
                'msa_status'            => ['label' => 'MSA status',            'sample' => 'executed'],
                'msa_executed_at'       => ['label' => 'MSA executed at',       'sample' => '2026-01-15'],
                'msa_expires_at'        => ['label' => 'MSA expires at',        'sample' => '2027-01-14'],
                'active_placements'     => ['label' => 'Active placements',     'sample' => '12', 'field_type' => 'number', 'aggregate' => 'sum'],
                'notes'                 => ['label' => 'Notes',                 'sample' => 'Strategic account'],
                'created_at'            => ['label' => 'Created at',            'sample' => '2026-01-02 09:00:00'],
                'updated_at'            => ['label' => 'Updated at',            'sample' => '2026-02-01 12:00:00'],
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
                foreach (customFieldDefinitions($tenantId, (string) $entityType, true) as $def) {
                    $key = 'custom_fields.' . $entityType . '.' . (string) ($def['field_key'] ?? '');
                    if ($key === 'custom_fields.' . $entityType . '.') continue;
                    $fields[$key] = [
                        'label'        => (string) ($def['field_label'] ?? $key),
                        'sample'       => '',
                        'custom_field' => true,
                        'entity_type'  => $entityType,
                        'field_type'   => (string) ($def['field_type'] ?? 'text'),
                        'sensitive'    => !empty($def['pii']),
                        'visible_to'   => customFieldDefinitionRoleList($def, 'visible'),
                        'editable_by'  => customFieldDefinitionRoleList($def, 'editable'),
                        'archived'     => !empty($def['archived']),
                        'archived_at'  => $def['deleted_at'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                error_log('[export_datasets] custom fields unavailable for ' . $entityType . ': ' . $e->getMessage());
            }
        }
    }
    return $fields;
}

function exportDatasetFieldRegistryForUser(string $dataset, array $user, ?int $tenantId = null): array {
    $fields = exportDatasetFieldRegistry($dataset, $tenantId);
    foreach ($fields as $key => $field) {
        if (!empty($field['custom_field']) && !customFieldUserCanViewDefinition($user, $field)) {
            unset($fields[$key]);
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

function exportDatasetActorUserFromOptions(array $opts): ?array {
    if (isset($opts['actor_user']) && is_array($opts['actor_user'])) return $opts['actor_user'];
    if (function_exists('getCurrentUser')) {
        $user = getCurrentUser();
        if (is_array($user)) return $user;
    }
    return null;
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
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $where = ['p.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];

    if ($ids) {
        $placeholders = [];
        foreach ($ids as $i => $id) {
            $key = 'id' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $where[] = 'p.id IN (' . implode(',', $placeholders) . ')';
    }

    if (!empty($opts['status'])) {
        $where[] = 'p.status = :status';
        $params['status'] = (string) $opts['status'];
    }
    if (!empty($opts['from'])) {
        $where[] = 'p.pay_date >= :from_date';
        $params['from_date'] = (string) $opts['from'];
    }
    if (!empty($opts['to'])) {
        $where[] = 'p.pay_date <= :to_date';
        $params['to_date'] = (string) $opts['to'];
    }
    if (!empty($opts['vendor_name'])) {
        $where[] = 'p.vendor_name = :vendor_name';
        $params['vendor_name'] = (string) $opts['vendor_name'];
    }

    $stmt = $pdo->prepare(
        'SELECT p.id AS payment_id,
                p.pay_date AS payment_date,
                p.pay_date,
                NULL AS vendor_id,
                NULL AS vendor_external_id,
                p.vendor_name,
                (SELECT MIN(a1.bill_id)
                   FROM ap_payment_allocations a1
                  WHERE a1.payment_id = p.id) AS bill_id,
                (SELECT MIN(b1.bill_number)
                   FROM ap_payment_allocations a1
                   JOIN ap_bills b1 ON b1.id = a1.bill_id AND b1.tenant_id = p.tenant_id
                  WHERE a1.payment_id = p.id) AS bill_number,
                (SELECT GROUP_CONCAT(DISTINCT a2.bill_id ORDER BY a2.bill_id SEPARATOR ",")
                   FROM ap_payment_allocations a2
                  WHERE a2.payment_id = p.id) AS bill_ids,
                (SELECT GROUP_CONCAT(DISTINCT b2.bill_number ORDER BY b2.bill_number SEPARATOR ",")
                   FROM ap_payment_allocations a2
                   JOIN ap_bills b2 ON b2.id = a2.bill_id AND b2.tenant_id = p.tenant_id
                  WHERE a2.payment_id = p.id) AS bill_numbers,
                p.method,
                p.reference,
                p.amount,
                p.amount AS amount_dollars,
                ROUND(p.amount * 100) AS amount_cents,
                p.currency,
                p.unallocated_amount,
                p.status,
                p.cleared_at,
                p.sent_at,
                p.notes AS memo,
                p.notes,
                p.bank_account_id,
                NULL AS bank_routing_number,
                NULL AS bank_account_number,
                NULL AS bank_account_type,
                p.disbursement_rail AS rail,
                p.disbursement_rail,
                p.rail_external_ref,
                p.rail_status,
                p.rail_originated_at,
                p.journal_entry_id
           FROM ap_payments p
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY p.pay_date DESC, p.id DESC
          LIMIT ' . $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function exportDatasetFetchApBills(int $tenantId, array $opts): array {
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $ids = array_values(array_filter(array_map('intval', (array) ($opts['ids'] ?? [])), fn ($x) => $x > 0));
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if ($ids) {
        $placeholders = [];
        foreach ($ids as $i => $id) {
            $key = 'id' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $where[] = 'id IN (' . implode(',', $placeholders) . ')';
    }
    if (!empty($opts['status'])) {
        $where[] = 'status = :status';
        $params['status'] = (string) $opts['status'];
    }
    if (!empty($opts['from'])) {
        $where[] = 'bill_date >= :from_date';
        $params['from_date'] = (string) $opts['from'];
    }
    if (!empty($opts['to'])) {
        $where[] = 'bill_date <= :to_date';
        $params['to_date'] = (string) $opts['to'];
    }
    if (!empty($opts['vendor_name'])) {
        $where[] = 'vendor_name = :vendor_name';
        $params['vendor_name'] = (string) $opts['vendor_name'];
    }

    $stmt = $pdo->prepare(
        'SELECT id AS bill_id, bill_number, internal_ref, vendor_name, vendor_type,
                received_at, bill_date, due_date, period_start, period_end, currency,
                subtotal, tax_total, total, amount_paid, amount_due, status, source,
                po_number, placement_id, journal_entry_id, notes_internal
           FROM ap_bills
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY bill_date DESC, id DESC
          LIMIT ' . $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function exportDatasetFetchApVendors(int $tenantId, array $opts): array {
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (!empty($opts['type'])) {
        $where[] = 'vendor_type = :vendor_type';
        $params['vendor_type'] = (string) $opts['type'];
    }
    if (!empty($opts['category'])) {
        $where[] = 'vendor_category = :vendor_category';
        $params['vendor_category'] = (string) $opts['category'];
    }

    $stmt = $pdo->prepare(
        'SELECT id AS vendor_id, vendor_name, vendor_type, vendor_category,
                default_terms, remit_to_email, remit_to_phone, payment_method,
                tax_id_last4, payment_account_last4, requires_1099, last_bill_at
           FROM ap_vendors_index
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY vendor_name ASC
          LIMIT ' . $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function exportDatasetFetchExpenses(int $tenantId, array $opts): array {
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $ids = array_values(array_filter(array_map('intval', (array) ($opts['ids'] ?? [])), fn ($x) => $x > 0));
    $lineIds = array_values(array_filter(array_map('intval', (array) ($opts['line_ids'] ?? [])), fn ($x) => $x > 0));
    $where = ['er.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];

    if ($ids) {
        $placeholders = [];
        foreach ($ids as $i => $id) {
            $key = 'id' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $where[] = 'er.id IN (' . implode(',', $placeholders) . ')';
    }
    if ($lineIds) {
        $placeholders = [];
        foreach ($lineIds as $i => $id) {
            $key = 'line_id' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $where[] = 'erl.id IN (' . implode(',', $placeholders) . ')';
    }
    if (!empty($opts['status'])) {
        $where[] = 'er.status = :status';
        $params['status'] = (string) $opts['status'];
    }
    if (!empty($opts['from'])) {
        $where[] = 'erl.expense_date >= :from_date';
        $params['from_date'] = (string) $opts['from'];
    }
    if (!empty($opts['to'])) {
        $where[] = 'erl.expense_date <= :to_date';
        $params['to_date'] = (string) $opts['to'];
    }
    if (!empty($opts['submitter_user_id'])) {
        $where[] = 'er.submitter_user_id = :submitter_user_id';
        $params['submitter_user_id'] = (int) $opts['submitter_user_id'];
    }

    $stmt = $pdo->prepare('
        SELECT er.id AS report_id, er.period_label, er.submitter_user_id,
               er.status, er.status AS report_status, er.total,
               COALESCE(erl.currency, er.currency) AS currency, er.bill_id, er.created_at,
               COALESCE(u.name, u.email) AS submitter_name,
               erl.id AS line_id, erl.expense_date, erl.merchant, erl.category,
               erl.amount, erl.description, erl.gl_expense_account_code,
               erl.billable_to_client_name
          FROM ap_expense_reports er
     LEFT JOIN ap_expense_report_lines erl ON erl.expense_report_id = er.id
     LEFT JOIN users u ON u.id = er.submitter_user_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY er.id DESC, erl.expense_date DESC, erl.id DESC
         LIMIT ' . $limit
    );
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

function exportDatasetFetchAccountingChartOfAccounts(int $tenantId, array $opts): array {
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (array_key_exists('active', $opts) && $opts['active'] !== '' && $opts['active'] !== null) {
        $where[] = 'active = :active';
        $params['active'] = !empty($opts['active']) ? 1 : 0;
    }
    if (!empty($opts['account_type'])) {
        $where[] = 'account_type = :account_type';
        $params['account_type'] = (string) $opts['account_type'];
    }
    if (!empty($opts['code'])) {
        $where[] = 'code = :code';
        $params['code'] = (string) $opts['code'];
    }

    $stmt = $pdo->prepare(
        'SELECT id AS account_id, code, name, account_type, normal_side,
                cash_flow_tag, parent_account_id, is_postable, currency,
                description, active, created_at, updated_at
           FROM accounting_accounts
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY code
          LIMIT ' . $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function exportDatasetFetchAccountingJournalEntries(int $tenantId, array $opts): array {
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $where = ['je.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    $joinLine = '';

    if (!empty($opts['ids'])) {
        $ids = array_values(array_filter(array_map('intval', (array) $opts['ids']), fn ($x) => $x > 0));
        if ($ids) {
            $placeholders = [];
            foreach ($ids as $i => $id) {
                $key = 'id' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $id;
            }
            $where[] = 'je.id IN (' . implode(',', $placeholders) . ')';
        }
    }
    if (!empty($opts['status'])) {
        $where[] = 'je.status = :status';
        $params['status'] = (string) $opts['status'];
    }
    if (!empty($opts['statuses'])) {
        $statuses = array_values(array_filter(array_map('strval', (array) $opts['statuses']), fn ($x) => $x !== ''));
        if ($statuses) {
            $placeholders = [];
            foreach ($statuses as $i => $status) {
                $key = 'status' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $status;
            }
            $where[] = 'je.status IN (' . implode(',', $placeholders) . ')';
        }
    }
    if (!empty($opts['exclude_status'])) {
        $where[] = 'je.status <> :exclude_status';
        $params['exclude_status'] = (string) $opts['exclude_status'];
    }
    if (!empty($opts['approval_state'])) {
        $where[] = 'je.approval_state = :approval_state';
        $params['approval_state'] = (string) $opts['approval_state'];
    }
    if (!empty($opts['from'])) {
        $where[] = 'je.posting_date >= :from_date';
        $params['from_date'] = (string) $opts['from'];
    }
    if (!empty($opts['to'])) {
        $where[] = 'je.posting_date <= :to_date';
        $params['to_date'] = (string) $opts['to'];
    }
    if (!empty($opts['entity_id'])) {
        $where[] = 'je.entity_id = :entity_id';
        $params['entity_id'] = (int) $opts['entity_id'];
    }
    if (!empty($opts['period_id'])) {
        $where[] = 'je.period_id = :period_id';
        $params['period_id'] = (int) $opts['period_id'];
    }
    if (!empty($opts['source_module'])) {
        $where[] = 'je.source_module = :source_module';
        $params['source_module'] = (string) $opts['source_module'];
    }
    if (!empty($opts['account_code'])) {
        $joinLine = ' INNER JOIN accounting_journal_entry_lines l ON l.je_id = je.id
                      INNER JOIN accounting_accounts a ON a.id = l.account_id ';
        $where[] = 'a.code = :account_code';
        $params['account_code'] = (string) $opts['account_code'];
    }

    $stmt = $pdo->prepare(
        'SELECT DISTINCT je.id AS journal_entry_id, je.je_number, je.posting_date,
                je.entity_id, je.period_id, je.source_module, je.source_ref_type,
                je.source_ref_id, je.status, je.approval_state, je.currency,
                je.total_debit, je.total_credit, je.memo, je.posted_at,
                je.posted_by_user_id, je.created_by_user_id, je.created_at, je.updated_at
           FROM accounting_journal_entries je ' . $joinLine . '
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY je.posting_date DESC, je.id DESC
          LIMIT ' . $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function exportDatasetFetchAccountingGlDetail(int $tenantId, array $opts): array {
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $where = ['je.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    $status = (string) ($opts['status'] ?? 'posted');
    if ($status !== '' && $status !== 'all') {
        $where[] = 'je.status = :status';
        $params['status'] = $status;
    }
    if (!empty($opts['from'])) {
        $where[] = 'je.posting_date >= :from_date';
        $params['from_date'] = (string) $opts['from'];
    }
    if (!empty($opts['to'])) {
        $where[] = 'je.posting_date <= :to_date';
        $params['to_date'] = (string) $opts['to'];
    }
    if (!empty($opts['entity_id'])) {
        $where[] = 'je.entity_id = :entity_id';
        $params['entity_id'] = (int) $opts['entity_id'];
    }
    if (!empty($opts['period_id'])) {
        $where[] = 'je.period_id = :period_id';
        $params['period_id'] = (int) $opts['period_id'];
    }
    if (!empty($opts['account_code'])) {
        $where[] = 'a.code = :account_code';
        $params['account_code'] = (string) $opts['account_code'];
    }
    if (!empty($opts['source_module'])) {
        $where[] = 'je.source_module = :source_module';
        $params['source_module'] = (string) $opts['source_module'];
    }

    $stmt = $pdo->prepare(
        'SELECT l.id AS line_id, je.id AS journal_entry_id, l.line_no,
                je.je_number, je.posting_date, je.entity_id, je.period_id,
                a.id AS account_id, a.code AS account_code, a.name AS account_name,
                a.account_type, a.normal_side, l.debit, l.credit,
                CASE
                    WHEN a.normal_side = "debit" THEN l.debit - l.credit
                    ELSE l.credit - l.debit
                END AS normal_balance_delta,
                l.memo, je.source_module, je.source_ref_type, je.source_ref_id, je.status
           FROM accounting_journal_entry_lines l
           JOIN accounting_journal_entries je ON je.id = l.je_id
           JOIN accounting_accounts a ON a.id = l.account_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY je.posting_date DESC, je.id DESC, l.line_no
          LIMIT ' . $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function exportDatasetFetchAccountingPeriods(int $tenantId, array $opts): array {
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (!empty($opts['entity_id'])) {
        $where[] = 'entity_id = :entity_id';
        $params['entity_id'] = (int) $opts['entity_id'];
    }
    if (!empty($opts['status'])) {
        $where[] = 'status = :status';
        $params['status'] = (string) $opts['status'];
    }
    if (!empty($opts['from'])) {
        $where[] = 'start_date >= :from_date';
        $params['from_date'] = (string) $opts['from'];
    }
    if (!empty($opts['to'])) {
        $where[] = 'end_date <= :to_date';
        $params['to_date'] = (string) $opts['to'];
    }

    $stmt = $pdo->prepare(
        'SELECT id AS period_id, entity_id, period_number, start_date, end_date,
                status, closed_at, closed_by_user_id, reopened_at,
                reopened_by_user_id, reopen_reason
           FROM accounting_periods
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY start_date DESC, period_number DESC
          LIMIT ' . $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function exportDatasetFetchAccountingBankStatementLines(int $tenantId, array $opts): array {
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $where = ['bsl.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (!empty($opts['bank_account_id'])) {
        $where[] = 'bsl.bank_account_id = :bank_account_id';
        $params['bank_account_id'] = (int) $opts['bank_account_id'];
    }
    if (!empty($opts['entity_id'])) {
        $where[] = 'ba.entity_id = :entity_id';
        $params['entity_id'] = (int) $opts['entity_id'];
    }
    if (!empty($opts['match_status'])) {
        $where[] = 'bsl.match_status = :match_status';
        $params['match_status'] = (string) $opts['match_status'];
    }
    if (!empty($opts['from'])) {
        $where[] = 'bsl.posted_date >= :from_date';
        $params['from_date'] = (string) $opts['from'];
    }
    if (!empty($opts['to'])) {
        $where[] = 'bsl.posted_date <= :to_date';
        $params['to_date'] = (string) $opts['to'];
    }

    $stmt = $pdo->prepare(
        'SELECT bsl.id AS bank_statement_line_id, bsl.bank_account_id,
                ba.name AS bank_account_name, ba.entity_id, ba.gl_account_code, ba.bank_name,
                bsl.posted_date, bsl.description, bsl.amount, bsl.bank_reference,
                bsl.fitid, bsl.match_status, bsl.matched_je_id, bsl.matched_at,
                bsl.matched_by_user_id, bsl.created_at
           FROM accounting_bank_statement_lines bsl
      LEFT JOIN accounting_bank_accounts ba
             ON ba.id = bsl.bank_account_id AND ba.tenant_id = bsl.tenant_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY bsl.posted_date DESC, bsl.id DESC
          LIMIT ' . $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

function exportDatasetFetchStaffingClients(int $tenantId, array $opts): array {
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $ids = array_values(array_filter(array_map('intval', (array) ($opts['ids'] ?? [])), fn ($x) => $x > 0));
    $where = ['c.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];

    if ($ids) {
        $placeholders = [];
        foreach ($ids as $i => $id) {
            $key = 'id' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $where[] = 'c.id IN (' . implode(',', $placeholders) . ')';
    }
    if (!empty($opts['status'])) {
        $where[] = 'c.status = :status';
        $params['status'] = (string) $opts['status'];
    }
    if (!empty($opts['q'])) {
        $where[] = '(c.name LIKE :q OR c.legal_name LIKE :q2 OR c.primary_contact_email LIKE :q3)';
        $params['q'] = '%' . (string) $opts['q'] . '%';
        $params['q2'] = $params['q'];
        $params['q3'] = $params['q'];
    }

    $stmt = $pdo->prepare(
        'SELECT c.id AS client_id,
                c.name,
                c.legal_name,
                c.external_id,
                c.source_system,
                c.industry,
                c.primary_contact_name,
                c.primary_contact_email,
                c.primary_contact_phone,
                c.billing_address_line1,
                c.billing_address_line2,
                c.billing_city,
                c.billing_state,
                c.billing_postal_code,
                c.billing_country,
                c.payment_terms_days,
                c.status,
                c.msa_status,
                c.msa_executed_at,
                c.msa_expires_at,
                (SELECT COUNT(*)
                   FROM placements p
                  WHERE p.tenant_id = c.tenant_id
                    AND p.client_id = c.id
                    AND p.status = "active") AS active_placements,
                c.notes,
                c.created_at,
                c.updated_at
           FROM staffing_clients c
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY c.name ASC
          LIMIT ' . $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function exportDatasetFetchPeopleDirectory(int $tenantId, array $opts): array {
    $pdo = getDB();
    $limit = min(10000, max(1, (int) ($opts['limit'] ?? 10000)));
    $includeSensitiveCustomFields = !empty($opts['include_sensitive_custom_fields']);
    $includeArchivedCustomFields = array_key_exists('include_archived_custom_fields', $opts)
        ? !empty($opts['include_archived_custom_fields'])
        : true;
    $actorUser = exportDatasetActorUserFromOptions($opts);
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
        foreach (customFieldDefinitions($tenantId, 'people', $includeArchivedCustomFields) as $def) {
            if (!$includeSensitiveCustomFields && !empty($def['pii'])) continue;
            if ($actorUser !== null && !customFieldUserCanViewDefinition($actorUser, $def)) continue;
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
        !empty($opts['include_sensitive_custom_fields']),
        array_key_exists('include_archived_custom_fields', $opts)
            ? !empty($opts['include_archived_custom_fields'])
            : true,
        exportDatasetActorUserFromOptions($opts)
    );
}

function exportDatasetAttachCustomFieldValues(
    array $rows,
    int $tenantId,
    string $entityType,
    string $idKey,
    bool $includeSensitive = false,
    bool $includeArchived = true,
    ?array $actorUser = null
): array {
    if (!$rows) return $rows;
    try {
        $defs = [];
        foreach (customFieldDefinitions($tenantId, $entityType, $includeArchived) as $def) {
            if (!$includeSensitive && !empty($def['pii'])) continue;
            if ($actorUser !== null && !customFieldUserCanViewDefinition($actorUser, $def)) continue;
            $defs[(string) ($def['field_key'] ?? '')] = true;
        }
        if (!$defs) return $rows;
        foreach ($rows as &$row) {
            $recordId = (int) ($row[$idKey] ?? 0);
            if ($recordId <= 0) continue;
            foreach (customFieldValues($tenantId, $entityType, $recordId, $includeSensitive, $includeArchived) as $valueRow) {
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
