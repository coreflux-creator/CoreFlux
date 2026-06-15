<?php
/**
 * Payroll Module Manifest (W2 employee payroll)
 *
 * Per /app/modules/payroll/SPEC.md.
 * Deterministic gross-to-net engine with engine-swappable interface (MVP in-house;
 * future Phase B: Check HQ or Gusto adapter via API).
 * AI never in calc path. Two-eye: build ≠ approve ≠ disburse.
 *
 * Source-of-truth: /app/modules/payroll/SPEC.md.
 */

return [
    'id'          => 'payroll',
    'name'        => 'Payroll',
    'icon'        => '/assets/icons/icon-payroll.png',
    'description' => 'W2 payroll — schedules, profiles, pay runs, gross-to-net, tax accruals, W-2s.',
    'version'     => '0.2.0',

    'actions' => [
        ['name' => 'Payroll Dashboard', 'route' => 'dashboard',  'permission' => 'payroll.view'],
        ['name' => 'Pay Schedules',     'route' => 'schedules',  'permission' => 'payroll.schedules.manage'],
        ['name' => 'Pay Cycles',        'route' => 'cycles',     'permission' => 'payroll.cycles.manage'],
        ['name' => 'Pay Periods',       'route' => 'periods',    'permission' => 'payroll.run.build'],
        ['name' => 'Employee Setup',    'route' => 'profiles',   'permission' => 'payroll.profiles.manage'],
        ['name' => 'Pay Runs',          'route' => 'runs',       'permission' => 'payroll.run.build'],
        ['name' => 'Anomalies',         'route' => 'anomalies',  'permission' => 'payroll.anomalies.view'],
        ['name' => 'Tax Liabilities',   'route' => 'tax',        'permission' => 'payroll.tax.view'],
        ['name' => 'W-2 Ledger',        'route' => 'w2',         'permission' => 'payroll.w2.view'],
        ['name' => 'Reports',           'route' => 'reports',    'permission' => 'payroll.reports.view'],
    ],

    'permissions' => [
        'payroll.view'                  => 'View payroll dashboards',
        'payroll.schedules.manage'      => 'Define pay schedules',
        'payroll.cycles.manage'         => 'Define and advance pay cycles',
        'payroll.anomalies.view'        => 'View AI anomaly findings',
        'payroll.anomalies.acknowledge' => 'Acknowledge AI anomaly findings',
        'payroll.profiles.view'         => 'View employee payroll setup',
        'payroll.profiles.manage'       => 'Edit employee payroll setup',
        'payroll.profiles.banking.view' => 'View encrypted DD info',
        'payroll.profiles.banking.manage'=> 'Edit encrypted DD info',
        'payroll.run.create'            => 'Create or preflight a payroll run',
        'payroll.run.build'             => 'Access pay-period and run-building workbench',
        'payroll.run.compute'           => 'Compute or import a payroll run',
        'payroll.run.approve'           => 'Approve a built run (two-eye)',
        'payroll.run.disburse'          => 'Release disbursements',
        'payroll.run.reverse'           => 'Reverse a disbursed run',
        'payroll.run.post'              => 'Post run to GL via Accounting',
        'payroll.deductions.manage'     => 'Manage employee deductions',
        'payroll.tax.view'              => 'View tax liabilities + remittance',
        'payroll.tax.manage'            => 'Mark tax liabilities paid / filed',
        'payroll.w2.view'               => 'View W-2 ledger',
        'payroll.w2.generate'           => 'Generate W-2 form PDFs',
        'payroll.reports.view'          => 'View payroll reports',
    ],

    'audit_events' => [
        'payroll.schedule.created',
        'payroll.schedule.updated',
        'payroll.schedule.deactivated',
        'payroll.profile.created',
        'payroll.profile.updated',
        'payroll.profile.banking_viewed',
        'payroll.profile.banking_updated',
        'payroll.run.created',
        'payroll.run.built',
        'payroll.run.workflow_started',
        'payroll.run.workflow_start_failed',
        'payroll.run.workflow_cancelled',
        'payroll.run.approval_blocked',
        'payroll.run.approval_rejected',
        'payroll.run.approved',
        'payroll.run.disbursed',
        'payroll.run.marked_paid',
        'payroll.run.posted',
        'payroll.run.reversed',
        'payroll.run.voided',
        'payroll.run.originated',
        'payroll.run.originate_failed',
        'payroll.run.exported_template',
        'payroll.run.exported_gusto',
        'payroll.run.exported_csv',
        'payroll.run.gusto_synced',
        'payroll.run.gusto_marked_paid',
        'payroll.run.gusto_unlinked',
        'payroll.deduction.created',
        'payroll.deduction.updated',
        'payroll.deduction.deactivated',
        'payroll.tax.liability_accrued',
        'payroll.tax.liability_paid',
        'payroll.tax.liability_filed',
        'payroll.w2.ledger_built',
        'payroll.w2.form_generated',
        'payroll.w2.submitted',
        'payroll.cycle.created',
        'payroll.cycle.updated',
        'payroll.cycle.deactivated',
        'payroll.cycle.advanced',
        'payroll.cycle.auto_advanced',
        'payroll.period.updated',
        'payroll.anomalies.detected',
        'payroll.anomalies.acknowledged',
        'payroll.gusto.connect_initiated',
        'payroll.gusto.connect_denied',
        'payroll.gusto.connect_state_invalid',
        'payroll.gusto.connect_exchange_failed',
        'payroll.gusto.connect_persist_failed',
        'payroll.gusto.connected',
        'payroll.gusto.disconnected',
        'payroll.gusto.token_refreshed',
        'payroll.gusto.token_refreshed_on_401',
        'payroll.gusto.run_submitted',
        'payroll.gusto.run_submission_failed',
        'payroll.gusto.webhook_received',
        'payroll.gusto.webhook_signature_invalid',
        'payroll.gusto.webhook_verification_received',
    ],

    'people_graph' => [
        'consumes' => true,
        'mode' => 'source_module_consumer',
        'object_types' => [
            'run' => [
                'responsibilities' => ['owner', 'preparer', 'reviewer', 'approver', 'operator', 'ai_supervisor', 'escalation_contact'],
                'approval_resource' => 'payroll.run',
            ],
            'profile' => [
                'responsibilities' => ['owner', 'reviewer', 'viewer', 'escalation_contact'],
            ],
            'tax_liability' => [
                'responsibilities' => ['owner', 'preparer', 'reviewer', 'approver', 'operator'],
                'approval_resource' => 'payroll.tax_liability',
            ],
            'gusto_submission' => [
                'responsibilities' => ['owner', 'preparer', 'approver', 'operator', 'escalation_contact'],
            ],
        ],
    ],

    'default_roles' => ['master_admin', 'tenant_admin', 'admin'],

    'export_datasets' => [
        'payroll_disbursements' => [
            'dataset'          => 'payroll_disbursements',
            'label'            => 'Payroll Disbursements',
            'permission'       => 'payroll.reports.view',
            'formats'          => ['csv'],
            'audit_event'      => 'payroll.run.exported_template',
            'sensitive_fields' => ['bank_routing_number', 'bank_account_number'],
        ],
    ],

    'report_datasets' => [
        'payroll_disbursements' => [
            'dataset'          => 'payroll_disbursements',
            'label'            => 'Payroll Disbursements',
            'permission'       => 'payroll.reports.view',
            'source'           => 'export_dataset',
            'sensitive_fields' => ['bank_routing_number', 'bank_account_number'],
        ],
    ],

    'depends_on' => ['people', 'placements', 'time', 'accounting'],
];
