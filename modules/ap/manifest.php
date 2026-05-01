<?php
/**
 * AP Module Manifest (Accounts Payable side of Finance)
 *
 * Per /app/modules/ap/SPEC.md.
 * Vendor invoice intake (via Core MailService), 1099 / C2C contractor pay,
 * employee expense reports, payment runs, 1099-NEC ledger.
 * Posts journals to Accounting via /api/v1/accounting/journal-entries.
 *
 * Source-of-truth: /app/modules/ap/SPEC.md.
 */

return [
    'id'          => 'ap',
    'name'        => 'Accounts Payable',
    'icon'        => '/assets/icons/icon-ap.png',
    'description' => 'Vendor invoices, payments, 1099 / C2C contractor pay, expense reports, AP aging, 1099 ledger.',
    'version'     => '0.1.0',

    'actions' => [
        ['name' => 'AP Dashboard',     'route' => 'dashboard',  'permission' => 'ap.view'],
        ['name' => 'Vendor Inbox',     'route' => 'inbox',      'permission' => 'ap.bill.review'],
        ['name' => 'Bills',            'route' => 'bills',      'permission' => 'ap.view'],
        ['name' => 'Payments',         'route' => 'payments',   'permission' => 'ap.payment.create'],
        ['name' => 'Expense Reports',  'route' => 'expenses',   'permission' => 'ap.expense.submit'],
        ['name' => 'Recurring Bills',  'route' => 'recurring',  'permission' => 'ap.recurring.manage'],
        ['name' => 'AP Aging',         'route' => 'aging',      'permission' => 'ap.reports.view'],
        ['name' => '1099 Ledger',      'route' => '1099',       'permission' => 'ap.1099.view'],
        ['name' => 'Reports',          'route' => 'reports',    'permission' => 'ap.reports.view'],
        ['name' => 'Export',           'route' => 'export',     'permission' => 'ap.export.run'],
    ],

    'permissions' => [
        'ap.view'                  => 'View AP data',
        'ap.bill.create'           => 'Enter / draft bills',
        'ap.bill.review'           => 'Work the AI / inbox review queue',
        'ap.bill.approve'          => 'Approve bills (two-eye)',
        'ap.bill.void'             => 'Void bill with reason',
        'ap.bill.post'             => 'Post bill to GL via Accounting',
        'ap.payment.create'        => 'Create payments / payment runs',
        'ap.payment.send'          => 'Release payments (transmit ACH/wire/check)',
        'ap.payment.allocate'      => 'Allocate payments to bills',
        'ap.expense.submit'        => 'Submit own expense report',
        'ap.expense.approve'       => 'Approve another user\'s expense report',
        'ap.recurring.manage'      => 'Manage recurring bills',
        'ap.vendor.view_pii'       => 'View vendor tax IDs (full)',
        'ap.1099.view'             => 'View 1099 ledger',
        'ap.1099.generate'         => 'Generate 1099-NEC forms',
        'ap.reports.view'          => 'View AP reports',
        'ap.export.run'            => 'Export AP data (CSV / Gusto)',
    ],

    'audit_events' => [
        'ap.bill.created',
        'ap.bill.updated',
        'ap.bill.approved',
        'ap.bill.posted',
        'ap.bill.voided',
        'ap.bill.disputed',
        'ap.bill.paid',
        'ap.bill.attachment.added',
        'ap.bill.line.attachment.added',
        'ap.bill.extracted_from_pdf',
        'ap.bill.line.extracted_from_receipt',
        'ap.vendor.extracted_from_w9',
        'ap.intake.received',
        'ap.intake.parsed',
        'ap.intake.converted',
        'ap.intake.dismissed',
        'ap.payment.drafted',
        'ap.payment.run_built',
        'ap.payment.sent',
        'ap.payment.cleared',
        'ap.payment.voided',
        'ap.expense.submitted',
        'ap.expense.approved',
        'ap.expense.rejected',
        'ap.expense.paid',
        'ap.expense.line.attachment.added',
        'ap.expense.line.extracted_from_receipt',
        'ap.export.csv',
        'ap.1099.ledger_built',
        'ap.1099.form_generated',
        'ap.1099.submitted',
        'ap.vendor.created',
        'ap.vendor.tax_id_viewed',
        'ap.vendor.tax_id_updated',
    ],

    'default_roles' => ['master_admin', 'tenant_admin', 'admin'],

    'depends_on' => ['placements', 'time'],
];
