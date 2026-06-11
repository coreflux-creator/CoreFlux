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
        ['name' => 'Placements',         'route' => 'placements',         'permission' => 'staffing.view'],
        ['name' => 'Timesheets',         'route' => 'timesheets',         'permission' => 'staffing.time.view'],
        ['name' => 'Approvals',          'route' => 'approvals',          'permission' => 'staffing.time.approve'],
        ['name' => 'Payroll Readiness',  'route' => 'payroll-readiness',  'permission' => 'staffing.payroll.view'],
        ['name' => 'Billing Readiness',  'route' => 'billing-readiness',  'permission' => 'staffing.billing.view'],
        ['name' => 'Profitability',      'route' => 'profitability',      'permission' => 'staffing.reports.view'],
        ['name' => 'Settings',           'route' => 'settings',           'permission' => 'staffing.settings.manage'],
    ],

    'permissions' => [
        'staffing.view'                  => 'View the Staffing module (gates the umbrella)',
        'staffing.time.view'             => 'View timesheets',
        'staffing.time.create'           => 'Create / edit own timesheet entries',
        'staffing.time.submit'           => 'Submit timesheet for review',
        'staffing.time.approve'          => 'Approve timesheets (two-eye control)',
        'staffing.time.reject'           => 'Reject timesheets with reason',
        'staffing.payroll.view'          => 'View payroll-readiness queue',
        'staffing.payroll.manage'        => 'Mark staffing work as pushed to payroll',
        'staffing.billing.view'          => 'View billing-readiness queue',
        'staffing.billing.manage'        => 'Mark staffing work as invoiced for billing',
        'staffing.reports.view'          => 'View staffing analytics',
        'staffing.settings.manage'       => 'Manage staffing module settings (week-start, contracted hours, OT thresholds)',
    ],

    'audit_events' => [
        'staffing.readiness.payroll_marked',
        'staffing.readiness.billing_marked',
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
