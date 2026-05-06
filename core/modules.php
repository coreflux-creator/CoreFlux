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
                ['name' => 'Clients',         'route' => 'clients',       'permission' => 'people.view'],
                ['name' => 'Vendors',         'route' => 'vendors',       'permission' => 'people.view'],
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
                ['name' => 'Overview',          'route' => 'overview',    'permission' => 'placements.view'],
                ['name' => 'Active Placements', 'route' => 'list',        'permission' => 'placements.view'],
                ['name' => 'Expiring Soon',     'route' => 'expiring',    'permission' => 'placements.view'],
                ['name' => 'New Placement',     'route' => 'new',         'permission' => 'placements.create'],
                ['name' => 'Commissions',       'route' => 'commissions', 'permission' => 'placements.view'],
                ['name' => 'Referrals',         'route' => 'referrals',   'permission' => 'placements.view'],
                ['name' => 'CSV Import',        'route' => 'csv_import',  'permission' => 'placements.create'],
                ['name' => 'Reports',           'route' => 'reports',     'permission' => 'placements.view'],
            ]
        ],
        'time' => [
            'id' => 'time',
            'name' => 'Time',
            'icon' => '/assets/icons/icon-time.png',
            'description' => 'Time entries, review queue, tokenized client approvals, downstream feeds.',
            'actions' => [
                ['name' => 'Overview',     'route' => 'overview',   'permission' => 'time.view'],
                ['name' => 'My Time',      'route' => 'entries',    'permission' => 'time.entry.self'],
                ['name' => 'Inbox',        'route' => 'inbox',      'permission' => 'time.approve'],
                ['name' => 'Review Queue', 'route' => 'review',     'permission' => 'time.approve'],
                ['name' => 'Missing Time', 'route' => 'missing',    'permission' => 'time.approve'],
                ['name' => 'Settlement',   'route' => 'settlement', 'permission' => 'time.approve'],
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
                ['name' => 'Export',           'route' => 'export',   'permission' => 'ap.export.run'],
                ['name' => 'Settings',         'route' => 'settings', 'permission' => 'ap.view'],
            ]
        ],
        'accounting' => [
            'id' => 'accounting',
            'name' => 'Accounting',
            'icon' => '/assets/icons/icon-accounting.png',
            'description' => 'General ledger — Chart of Accounts, Journal Entries, Trial Balance. AP + Billing post here.',
            'actions' => [
                ['name' => 'Chart of Accounts', 'route' => 'accounts', 'permission' => 'accounting.coa.view'],
                ['name' => 'Journal Entries',   'route' => 'journal',  'permission' => 'accounting.je.create'],
                ['name' => 'Trial Balance',     'route' => 'trial',    'permission' => 'accounting.coa.view'],
                ['name' => 'Income Statement',  'route' => 'pnl',      'permission' => 'accounting.coa.view'],
                ['name' => 'Balance Sheet',     'route' => 'balance',  'permission' => 'accounting.coa.view'],
                ['name' => 'Cash Flow',         'route' => 'cash-flow','permission' => 'accounting.coa.view'],
                ['name' => 'Bank Rec',          'route' => 'bank-rec', 'permission' => 'accounting.coa.view'],
                ['name' => 'Recurring JEs',    'route' => 'recurring','permission' => 'accounting.je.create'],
                ['name' => 'Standard Reports', 'route' => 'reports',  'permission' => 'accounting.reports.view'],
                ['name' => 'CSV Import',       'route' => 'import',   'permission' => 'accounting.coa.manage'],
                ['name' => 'Intercompany',     'route' => 'intercompany','permission' => 'accounting.intercompany.manage'],
                ['name' => 'Consolidation',    'route' => 'consolidation','permission' => 'accounting.intercompany.manage'],
                ['name' => 'Elimination',      'route' => 'elimination','permission' => 'accounting.intercompany.manage'],
                ['name' => 'Periods',           'route' => 'periods',  'permission' => 'accounting.coa.view'],
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
                ['name' => 'Pay Cycles',     'route' => 'cycles',         'permission' => 'payroll.cycles.manage'],
                ['name' => 'Pay Periods',    'route' => 'pay_periods',    'permission' => 'payroll.runs.manage'],
                ['name' => 'Employee Setup', 'route' => 'profiles',       'permission' => 'payroll.profiles.manage'],
                ['name' => 'Runs',           'route' => 'runs',           'permission' => 'payroll.runs.view'],
                ['name' => 'Anomalies',      'route' => 'anomalies',      'permission' => 'payroll.anomalies.view'],
                ['name' => 'Settings',       'route' => 'settings',       'permission' => 'payroll.manage'],
            ]
        ],
        'treasury' => [
            'id' => 'treasury',
            'name' => 'Treasury',
            'icon' => '/assets/icons/icon-treasury.png',
            'description' => 'Deposit + liability account ledgers, bank feeds, cash position.',
            'actions' => [
                ['name' => 'Overview',           'route' => 'overview',    'permission' => 'treasury.view'],
                ['name' => 'Deposit Accounts',   'route' => 'deposits',    'permission' => 'treasury.deposit.manage'],
                ['name' => 'Liability Accounts', 'route' => 'liabilities', 'permission' => 'treasury.liability.manage'],
            ]
        ],
        'reports' => [
            'id' => 'reports',
            'name' => 'Reports',
            'icon' => '/assets/icons/icon-reports.png',
            'description' => 'Industry-aware analytics — staffing dashboards, margin reports, custom builder.',
            'actions' => [
                ['name' => 'Staffing Overview',     'route' => 'overview',             'permission' => 'reports.view'],
                ['name' => 'Executive Snapshot',    'route' => 'executive_snapshot',   'permission' => 'reports.view'],
                ['name' => 'Client Profitability',  'route' => 'client_profitability', 'permission' => 'reports.view'],
                ['name' => 'Rate & Spread',         'route' => 'rate_spread',          'permission' => 'reports.view'],
                ['name' => 'Overtime Watch',        'route' => 'overtime_watch',       'permission' => 'reports.view'],
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
        'tenant_admin', 'admin'       => ['people', 'placements', 'time', 'billing', 'ap', 'accounting', 'payroll', 'treasury', 'reports'],
        'manager'                     => ['people', 'placements', 'time', 'billing', 'ap', 'reports'],
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
