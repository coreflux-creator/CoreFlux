<?php
/**
 * Payroll Module Manifest
 *
 * Computes pay deterministically from People-module employee data.
 * AI is used ONLY for advisory narrative (run summary, variance) — never for
 * numbers or decisions. See AI_INTEGRATION_RULES.md.
 */

return [
    'id'          => 'payroll',
    'name'        => 'Payroll',
    'icon'        => '/assets/icons/icon-payroll.png',
    'description' => 'Pay schedules, runs, and gross-to-net calculation',
    'version'     => '0.1.0',

    'actions' => [
        ['name' => 'Overview',       'route' => 'overview',       'permission' => 'payroll.view'],
        ['name' => 'Pay Schedules',  'route' => 'pay_schedules',  'permission' => 'payroll.schedules.manage'],
        ['name' => 'Pay Periods',    'route' => 'pay_periods',    'permission' => 'payroll.runs.manage'],
        ['name' => 'Employee Setup', 'route' => 'profiles',       'permission' => 'payroll.profiles.manage'],
        ['name' => 'Runs',           'route' => 'runs',           'permission' => 'payroll.runs.view'],
        ['name' => 'Settings',       'route' => 'settings',       'permission' => 'payroll.manage'],
    ],

    'permissions' => [
        'payroll.view',
        'payroll.manage',
        'payroll.schedules.manage',
        'payroll.profiles.manage',
        'payroll.profiles.view',
        'payroll.runs.view',
        'payroll.runs.manage',
        'payroll.runs.approve',
    ],

    'default_roles' => ['master_admin', 'tenant_admin', 'admin'],

    // Modules this depends on (must be installed in this tenant first)
    'depends_on' => ['people'],
];
