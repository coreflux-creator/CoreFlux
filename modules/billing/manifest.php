<?php
/**
 * Billing Module Manifest (AR side of Finance)
 *
 * Per /app/modules/billing/SPEC.md.
 * Customer-facing money movement — invoice generation, recurring billing,
 * payment tracking, AR aging, dunning, credit/debit memos, tax matrix.
 * Posts journals to Accounting via /api/v1/accounting/journal-entries.
 *
 * Source-of-truth: /app/modules/billing/SPEC.md.
 */

return [
    'id'          => 'billing',
    'name'        => 'Billing',
    'icon'        => '/assets/icons/icon-billing.png',
    'description' => 'Customer invoices, recurring services, payments, AR aging, dunning, credits/debits, tax.',
    'version'     => '0.1.0',

    'actions' => [
        ['name' => 'AR Dashboard',      'route' => 'dashboard',  'permission' => 'billing.view'],
        ['name' => 'Invoices',          'route' => 'invoices',   'permission' => 'billing.view'],
        ['name' => 'Recurring',         'route' => 'recurring',  'permission' => 'billing.recurring.manage'],
        ['name' => 'Payments',          'route' => 'payments',   'permission' => 'billing.payments.record'],
        ['name' => 'Credits & Debits',  'route' => 'credits',    'permission' => 'billing.credits.manage'],
        ['name' => 'Aging',             'route' => 'aging',      'permission' => 'billing.reports.view'],
        ['name' => 'Dunning Queue',     'route' => 'dunning',    'permission' => 'billing.dunning.manage'],
        ['name' => 'Tax Settings',      'route' => 'tax',        'permission' => 'billing.tax.manage'],
        ['name' => 'Templates',         'route' => 'templates',  'permission' => 'billing.templates.manage'],
        ['name' => 'Billing Rules',     'route' => 'rules',      'permission' => 'billing.invoice.draft'],
        ['name' => 'Reports',           'route' => 'reports',    'permission' => 'billing.reports.view'],
    ],

    'permissions' => [
        'billing.view'                => 'View billing data',
        'billing.invoice.draft'       => 'Create / edit draft invoices',
        'billing.invoice.approve'     => 'Move draft → approved (two-eye)',
        'billing.invoice.send'        => 'Send invoice via MailService',
        'billing.invoice.void'        => 'Void invoice with reason',
        'billing.invoice.post'        => 'Post invoice to GL via Accounting',
        'billing.recurring.manage'    => 'Manage recurring service catalog',
        'billing.payments.record'     => 'Record received payment',
        'billing.payments.allocate'   => 'Allocate payments to invoices',
        'billing.credits.manage'      => 'Issue credit / debit memos',
        'billing.dunning.manage'      => 'Manage dunning + send overdue notices',
        'billing.tax.manage'          => 'Manage tax jurisdictions + rates',
        'billing.templates.manage'    => 'Manage invoice / email templates',
        'billing.reports.view'        => 'AR aging, sales by client, etc.',
    ],

    'audit_events' => [
        'billing.invoice.created',
        'billing.invoice.updated',
        'billing.invoice.approved',
        'billing.invoice.workflow_started',
        'billing.invoice.workflow_start_failed',
        'billing.invoice.workflow_approved',
        'billing.invoice.approval_blocked',
        'billing.invoice.approval_rejected',
        'billing.invoice.sent',
        'billing.invoice.voided',
        'billing.invoice.posted',
        'billing.invoice.posted_ic',
        'billing.invoice.disputed',
        'billing.invoice.paid_in_full',
        'billing.invoice.exported',
        'billing.recurring.created',
        'billing.recurring.run',
        'billing.recurring.paused',
        'billing.recurring.ended',
        'billing.credit.created',
        'billing.credit.approved',
        'billing.credit.applied',
        'billing.credit.voided',
        'billing.payment.recorded',
        'billing.payment.allocated',
        'billing.payment.unallocated',
        'billing.payment.exported',
        'billing.dunning.step_sent',
        'billing.dunning.escalated',
        'billing.tax.jurisdiction.created',
        'billing.tax.rate_added',
        'billing.template.updated',
        'billing.aging.snapshot.built',
    ],

    'people_graph' => [
        'consumes' => true,
        'mode' => 'source_module_consumer',
        'object_types' => [
            'invoice' => [
                'responsibilities' => ['owner', 'preparer', 'reviewer', 'approver', 'recipient', 'notifier', 'escalation_contact'],
                'approval_resource' => 'billing.invoice',
            ],
            'payment' => [
                'responsibilities' => ['owner', 'preparer', 'reviewer', 'operator'],
            ],
            'credit_memo' => [
                'responsibilities' => ['owner', 'preparer', 'reviewer', 'approver'],
                'approval_resource' => 'billing.credit_memo',
            ],
            'dunning_case' => [
                'responsibilities' => ['owner', 'notifier', 'escalation_contact'],
            ],
        ],
    ],

    'default_roles' => ['master_admin', 'tenant_admin', 'admin'],

    'export_datasets' => [
        'billing_invoices' => [
            'dataset'     => 'billing_invoices',
            'label'       => 'Billing Invoices',
            'permission'  => 'billing.view',
            'formats'     => ['csv'],
            'audit_event' => 'billing.invoice.exported',
        ],
        'billing_payments' => [
            'dataset'     => 'billing_payments',
            'label'       => 'Billing Payments',
            'permission'  => 'billing.view',
            'formats'     => ['csv'],
            'audit_event' => 'billing.payment.exported',
        ],
    ],

    'report_datasets' => [
        'billing_invoices' => [
            'dataset'    => 'billing_invoices',
            'label'      => 'Billing Invoices',
            'permission' => 'billing.view',
            'source'     => 'export_dataset',
        ],
        'billing_payments' => [
            'dataset'    => 'billing_payments',
            'label'      => 'Billing Payments',
            'permission' => 'billing.view',
            'source'     => 'export_dataset',
        ],
    ],

    'depends_on' => ['placements', 'time'],
];
