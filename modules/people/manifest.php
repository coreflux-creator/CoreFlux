<?php
/**
 * People Module Manifest
 * HR system of record — employees, comp, tax, banking, time off, documents.
 */

return [
    'id'          => 'people',
    'name'        => 'People',
    'icon'        => '/assets/icons/icon-people.png',
    'description' => 'Employee directory, compensation, tax, banking, and time off',
    'version'     => '0.1.0',

    'actions' => [
        ['name' => 'Directory',      'route' => 'directory',      'permission' => 'people.view'],
        ['name' => 'Org Chart',      'route' => 'org_chart',      'permission' => 'people.view'],
        ['name' => 'Time Off',       'route' => 'time_off',       'permission' => 'people.timeoff.manage'],
        ['name' => 'Onboarding',     'route' => 'onboarding',     'permission' => 'people.manage'],
    ],

    'permissions' => [
        'people.view',
        'people.manage',
        'people.terminate',
        'people.pii.view',
        'people.comp.view',
        'people.comp.manage',
        'people.tax.view',
        'people.tax.manage',
        'people.banking.view',
        'people.banking.manage',
        'people.docs.manage',
        'people.timeoff.manage',
    ],

    'default_roles' => ['master_admin', 'tenant_admin', 'admin'],
];
