<?php
/**
 * CoreStaffing Module Manifest
 *
 * The umbrella module for labor-based service delivery: clients, jobs,
 * placements, weekly timesheets, approvals, payroll & billing readiness,
 * margin/spread, and downstream feeds.
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
    'description' => 'Client-facing labor: placements, weekly timesheets, approvals, payroll/billing readiness, margin.',
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
        'staffing.billing.view'          => 'View billing-readiness queue',
        'staffing.reports.view'          => 'View staffing analytics',
        'staffing.settings.manage'       => 'Manage staffing module settings (week-start, contracted hours, OT thresholds)',
    ],
];
