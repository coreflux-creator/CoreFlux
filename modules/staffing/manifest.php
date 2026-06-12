<?php
/**
 * CoreStaffing Module Manifest
 *
 * Operating workbench for labor-based service delivery. Staffing consumes
 * People, Placements, Time, Payroll, Billing, Accounting, and Reports data;
 * it owns staffing-specific orchestration views, readiness queues, and
 * operating KPIs, not the source-of-truth domain records.
 *
 * Per /app/memory/PRD.md and the CoreStaffing MVP Spec (Feb 2026).
 *
 * Phase 1 (this sprint): Module shell + weekly timesheet rebuild.
 * Phase 2+: economics, readiness queues, reports views, accounting events.
 */

return [
    'id'          => 'staffing',
    'name'        => 'Staffing',
    'icon'        => '/assets/icons/icon-time.png',
    'description' => 'Staffing workbench over People, Placements, Time, Payroll, Billing, Accounting, and Reports.',
    'version'     => '1.0.0',

    'actions' => [
        ['name' => 'Overview',           'route' => 'overview',           'permission' => 'staffing.view'],
        ['name' => 'Clients',            'route' => 'clients',            'permission' => 'staffing.view'],
        ['name' => 'Jobs',               'route' => 'jobs',               'permission' => 'staffing.view'],
        ['name' => 'Placements',         'route' => 'placements',         'permission' => 'placements.view'],
        ['name' => 'Timesheets',         'route' => 'timesheets',         'permission' => 'time.view'],
        ['name' => 'Approvals',          'route' => 'approvals',          'permission' => 'time.approve'],
        ['name' => 'Payroll Readiness',  'route' => 'payroll-readiness',  'permission' => 'payroll.view'],
        ['name' => 'Billing Readiness',  'route' => 'billing-readiness',  'permission' => 'billing.view'],
        ['name' => 'Profitability',      'route' => 'profitability',      'permission' => 'reports.view'],
        ['name' => 'Settings',           'route' => 'settings',           'permission' => 'staffing.settings.manage'],
    ],

    'permissions' => [
        'staffing.view'                  => 'View the Staffing module (gates the umbrella)',
        'staffing.time.view'             => 'Compatibility alias; source gate prefers time.view',
        'staffing.time.create'           => 'Compatibility alias; source gate prefers time.entry.create',
        'staffing.time.submit'           => 'Compatibility alias; source gate prefers time.entry.create',
        'staffing.time.approve'          => 'Compatibility alias; source gate prefers time.approve',
        'staffing.time.reject'           => 'Compatibility alias; source gate prefers time.reject',
        'staffing.payroll.view'          => 'Compatibility alias; source gate prefers payroll.view',
        'staffing.payroll.manage'        => 'Compatibility alias; source gate prefers payroll.run.create',
        'staffing.billing.view'          => 'Compatibility alias; source gate prefers billing.view',
        'staffing.billing.manage'        => 'Compatibility alias; source gate prefers billing.invoice.draft',
        'staffing.reports.view'          => 'Compatibility alias; source gate prefers reports.view',
        'staffing.export.run'            => 'Export Staffing data through governed datasets',
        'staffing.settings.manage'       => 'Manage staffing module settings (week-start, contracted hours, OT thresholds)',
    ],

    'audit_events' => [
        'staffing.readiness.payroll_marked',
        'staffing.readiness.billing_marked',
        'staffing.clients.exported',
    ],

    'export_datasets' => [
        'staffing_clients' => [
            'dataset'     => 'staffing_clients',
            'label'       => 'Staffing Clients',
            'permission'  => 'staffing.export.run',
            'formats'     => ['csv'],
            'audit_event' => 'staffing.clients.exported',
        ],
    ],

    'report_datasets' => [
        'staffing_clients' => [
            'dataset'    => 'staffing_clients',
            'label'      => 'Staffing Clients',
            'permission' => 'staffing.export.run',
            'source'     => 'export_dataset',
        ],
    ],

    'people_graph' => [
        'consumes' => true,
        'mode' => 'consumer_orchestrator',
        'consumes_from' => ['people', 'placements', 'time', 'payroll', 'billing', 'accounting', 'reports'],
        'object_types' => [
            'workbench' => [
                'responsibilities' => ['owner', 'operator', 'notifier', 'escalation_contact'],
            ],
            'readiness_exception' => [
                'responsibilities' => ['owner', 'reviewer', 'operator', 'escalation_contact'],
            ],
        ],
        'source_of_truth_note' => 'Staffing resolves source-object authority from the owning modules instead of owning people, placement, time, payroll, billing, accounting, or reporting records.',
    ],
];
