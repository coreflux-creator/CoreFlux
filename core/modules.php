<?php
/**
 * CoreFlux Module Definitions
 *
 * Source of truth for what modules the session exposes to the SPA sidebar.
 * Each action's `route` segment is appended to `/modules/<id>/` when the
 * sidebar link is built. Must match the React router paths inside each
 * module's `ui/<Module>Module.jsx`.
 *
 * NOTE: This file is the legacy "pre-manifest" registry. The new-style
 * per-module `manifest.php` files (e.g. /modules/time/manifest.php) are the
 * long-term home for this data, but until the sidebar/session pipeline is
 * switched to read from ModuleRegistry, this hardcoded list stays in sync.
 */

function getModuleDefinitions(): array {
    return [
        'people' => [
            'id' => 'people',
            'name' => 'People',
            'icon' => '/assets/icons/icon-people.png',
            'description' => 'Talent system of record — directory, pipeline, documents, custom fields.',
            'actions' => [
                ['name' => 'Directory',       'route' => 'directory',     'permission' => 'people.view'],
                ['name' => 'Hiring Pipeline', 'route' => 'pipeline',      'permission' => 'people.view'],
                ['name' => 'Companies',       'route' => 'companies',     'permission' => 'people.view'],
                ['name' => 'Document Vault',  'route' => 'documents',     'permission' => 'people.view'],
                ['name' => 'Custom Fields',   'route' => 'custom_fields', 'permission' => 'people.manage'],
            ]
        ],
        'placements' => [
            'id' => 'placements',
            'name' => 'Placements',
            'icon' => '/assets/icons/icon-placements.png',
            'description' => 'Active engagements — rates, vendor chain, commissions, referrals, C2C.',
            'actions' => [
                ['name' => 'Active Placements', 'route' => 'list',        'permission' => 'placements.view'],
                ['name' => 'Expiring Soon',     'route' => 'expiring',    'permission' => 'placements.view'],
                ['name' => 'New Placement',     'route' => 'new',         'permission' => 'placements.create'],
                ['name' => 'Reports',           'route' => 'reports',     'permission' => 'placements.view'],
            ]
        ],
        'time' => [
            'id' => 'time',
            'name' => 'Time',
            'icon' => '/assets/icons/icon-time.png',
            'description' => 'Time entries, review queue, tokenized client approvals, downstream feeds.',
            'actions' => [
                ['name' => 'My Time',      'route' => 'entries',    'permission' => 'time.entry.self'],
                ['name' => 'Review Queue', 'route' => 'review',     'permission' => 'time.approve'],
                ['name' => 'Pay Periods',  'route' => 'periods',    'permission' => 'time.period.manage'],
                ['name' => 'Reports',      'route' => 'reports',    'permission' => 'time.view'],
                ['name' => 'Categories',   'route' => 'categories', 'permission' => 'time.period.manage'],
                ['name' => 'Bulk Upload',  'route' => 'bulk',       'permission' => 'time.entry.manage'],
            ]
        ],
        'billing' => [
            'id' => 'billing',
            'name' => 'Billing',
            'icon' => '/assets/icons/icon-billing.png',
            'description' => 'Customer invoices, payments, AR aging, customer portal.',
            'actions' => [
                ['name' => 'Invoices', 'route' => 'invoices', 'permission' => 'billing.view'],
                ['name' => 'Payments', 'route' => 'payments', 'permission' => 'billing.payments.record'],
                ['name' => 'Aging',    'route' => 'aging',    'permission' => 'billing.reports.view'],
            ]
        ],
        'ap' => [
            'id' => 'ap',
            'name' => 'Accounts Payable',
            'icon' => '/assets/icons/icon-ap.png',
            'description' => 'Vendor bills, payments, expenses, AP aging, 1099-NEC ledger.',
            'actions' => [
                ['name' => 'Bills',            'route' => 'bills',    'permission' => 'ap.view'],
                ['name' => 'Payments',         'route' => 'payments', 'permission' => 'ap.payment.create'],
                ['name' => 'Vendors',          'route' => 'vendors',  'permission' => 'ap.view'],
                ['name' => 'Expense Reports',  'route' => 'expenses', 'permission' => 'ap.expense.submit'],
                ['name' => 'AP Aging',         'route' => 'aging',    'permission' => 'ap.reports.view'],
                ['name' => '1099 Ledger',      'route' => '1099',     'permission' => 'ap.1099.view'],
            ]
        ],
        'accounting' => [
            'id' => 'accounting',
            'name' => 'Accounting',
            'icon' => '/assets/icons/icon-accounting.png',
            'description' => 'General ledger (v1.0 pending). GL posting for Billing + AP is stubbed until this ships.',
            'actions' => [
                ['name' => 'Overview', 'route' => 'overview', 'permission' => 'accounting.view'],
            ]
        ],
        'payroll' => [
            'id' => 'payroll',
            'name' => 'Payroll',
            'icon' => '/assets/icons/icon-payroll.png',
            'description' => 'Pay schedules, runs, and gross-to-net calculation.',
            'actions' => [
                ['name' => 'Overview',       'route' => 'overview',       'permission' => 'payroll.view'],
                ['name' => 'Pay Schedules',  'route' => 'pay_schedules',  'permission' => 'payroll.schedules.manage'],
                ['name' => 'Pay Periods',    'route' => 'pay_periods',    'permission' => 'payroll.runs.manage'],
                ['name' => 'Employee Setup', 'route' => 'profiles',       'permission' => 'payroll.profiles.manage'],
                ['name' => 'Runs',           'route' => 'runs',           'permission' => 'payroll.runs.view'],
                ['name' => 'Settings',       'route' => 'settings',       'permission' => 'payroll.manage'],
            ]
        ],
    ];
}

/**
 * Get modules accessible to user based on role.
 */
function getUserModules(string $role): array {
    $allModules = getModuleDefinitions();

    $roleModules = match($role) {
        'master_admin'                => array_keys($allModules),
        'tenant_admin', 'admin'       => ['people', 'placements', 'time', 'billing', 'ap', 'accounting', 'payroll'],
        'manager'                     => ['people', 'placements', 'time', 'billing', 'ap'],
        'employee'                    => ['people', 'time'],
        default                       => ['people', 'time']
    };

    $accessible = [];
    foreach ($roleModules as $moduleId) {
        if (isset($allModules[$moduleId])) {
            $accessible[] = $allModules[$moduleId];
        }
    }

    return $accessible;
}

/**
 * Get module by ID
 */
function getModule(string $moduleId): ?array {
    $modules = getModuleDefinitions();
    return $modules[$moduleId] ?? null;
}

/**
 * Filter module actions by role
 */
function getModuleActions(array $module, string $role): array {
    $isAdmin = in_array($role, ['master_admin', 'tenant_admin', 'admin']);

    return array_filter($module['actions'], function($action) use ($isAdmin) {
        if (!empty($action['admin_only']) && !$isAdmin) {
            return false;
        }
        return true;
    });
}
