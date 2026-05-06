<?php
/**
 * Reports Module Manifest
 *
 * Industry-aware analytics hub per /app/memory/PRD.md Reports spec.
 * Phase 1 (this sprint): Staffing industry — Overview dashboard + 4 drill reports.
 * Future phases: Custom Report Builder, Other Reports catalog, additional verticals.
 *
 * Data foundation: `v_timesheet_day_fin` (MySQL view over time_entries +
 * placement_rates). All queries tenant-scoped.
 */

return [
    'id'          => 'reports',
    'name'        => 'Reports',
    'icon'        => '/assets/icons/icon-reports.png',
    'description' => 'Industry-aware analytics: staffing dashboards, margin reports, custom builder.',
    'version'     => '1.0.0',

    'actions' => [
        ['name' => 'Staffing Overview',        'route' => 'overview',             'permission' => 'reports.view'],
        ['name' => 'Executive Snapshot',       'route' => 'executive_snapshot',   'permission' => 'reports.view'],
        ['name' => 'Client Profitability',     'route' => 'client_profitability', 'permission' => 'reports.view'],
        ['name' => 'Rate & Spread Monitor',    'route' => 'rate_spread',          'permission' => 'reports.view'],
        ['name' => 'Overtime Watch',           'route' => 'overtime_watch',       'permission' => 'reports.view'],
    ],

    'permissions' => [
        'reports.view'        => 'View dashboards and reports scoped by role',
        'reports.export'      => 'Export reports to CSV/PDF',
        'reports.custom.build'=> 'Build and save custom reports',
        'reports.custom.share'=> 'Share saved custom reports tenant-wide',
    ],

    'audit_events' => [
        'reports.dashboard.viewed',
        'reports.exported',
        'reports.custom.created',
        'reports.custom.updated',
        'reports.custom.deleted',
    ],

    'default_roles' => ['master_admin', 'tenant_admin', 'admin', 'manager'],

    'depends_on' => ['people', 'placements', 'time'],
];
