<?php
/**
 * CoreFlux Module Definitions
 * Defines available modules and their navigation structure
 * This will evolve into manifest.php per module
 */

function getModuleDefinitions(): array {
    return [
        'people' => [
            'id' => 'people',
            'name' => 'People',
            'icon' => '/assets/icons/icon-people.png',
            'description' => 'Employee directory, compensation, tax, banking, and time off',
            'actions' => [
                ['name' => 'Directory',  'route' => 'directory',  'permission' => 'people.view'],
                ['name' => 'Org Chart',  'route' => 'org_chart',  'permission' => 'people.view'],
                ['name' => 'Time Off',   'route' => 'time_off',   'permission' => 'people.timeoff.manage'],
                ['name' => 'Onboarding', 'route' => 'onboarding', 'permission' => 'people.manage'],
            ]
        ],
        'accounting' => [
            'id' => 'accounting',
            'name' => 'Accounting',
            'icon' => '/assets/icons/icon-accounting.png',
            'description' => 'General ledger, AP, AR, and financial reporting',
            'actions' => [
                ['name' => 'Overview', 'route' => 'overview', 'permission' => 'accounting.view'],
                ['name' => 'Chart of Accounts', 'route' => 'chart_of_accounts', 'permission' => 'accounting.coa.view'],
                ['name' => 'Journal Entries', 'route' => 'journal_entries', 'permission' => 'accounting.journal.view'],
                ['name' => 'Accounts Payable', 'route' => 'accounts_payable', 'permission' => 'accounting.ap.view'],
                ['name' => 'Accounts Receivable', 'route' => 'accounts_receivable', 'permission' => 'accounting.ar.view'],
                ['name' => 'Reports', 'route' => 'reports', 'permission' => 'accounting.reports.view'],
            ]
        ],
        'finance' => [
            'id' => 'finance',
            'name' => 'Finance',
            'icon' => '/assets/icons/icon-finance.png',
            'description' => 'Budgets, forecasts, and financial planning',
            'actions' => [
                ['name' => 'Overview', 'route' => 'overview', 'permission' => 'finance.view'],
                ['name' => 'Budgets', 'route' => 'budgets', 'permission' => 'finance.budgets.view'],
                ['name' => 'Forecasts', 'route' => 'forecasts', 'permission' => 'finance.forecasts.view'],
            ]
        ],
        'tax' => [
            'id' => 'tax',
            'name' => 'Tax',
            'icon' => '/assets/icons/icon-tax.png',
            'description' => 'Tax returns and compliance management',
            'actions' => [
                ['name' => 'Overview', 'route' => 'overview', 'permission' => 'tax.view'],
            ]
        ],
        'wealth' => [
            'id' => 'wealth',
            'name' => 'Wealth Management',
            'icon' => '/assets/icons/icon-wealth.png',
            'description' => 'Portfolio tracking and investment management',
            'actions' => [
                ['name' => 'Overview', 'route' => 'overview', 'permission' => 'wealth.view'],
            ]
        ],
        'reporting' => [
            'id' => 'reporting',
            'name' => 'Reporting',
            'icon' => '/assets/icons/icon-reporting.png',
            'description' => 'Cross-module analytics and dashboards',
            'actions' => [
                ['name' => 'Overview', 'route' => 'overview', 'permission' => 'reporting.view'],
            ]
        ],
    ];
}

/**
 * Get modules accessible to user based on role
 */
function getUserModules(string $role): array {
    $allModules = getModuleDefinitions();
    
    // For now, role-based access (will evolve to permission-based)
    $roleModules = match($role) {
        'master_admin' => array_keys($allModules),
        'tenant_admin', 'admin' => ['people', 'accounting', 'finance', 'reporting'],
        'manager' => ['people', 'reporting'],
        'employee' => ['people'],
        default => ['people']
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
