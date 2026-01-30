<?php
/**
 * CoreFlux Data Layer
 * Pulls core data (tenants, modules, permissions) from database
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Get user's tenants from database
 */
function getUserTenants(int $userId): array {
    $pdo = getDB();
    if (!$pdo) return [];
    
    $stmt = $pdo->prepare("
        SELECT t.id, t.name, t.logo_url, t.subdomain, ut.role, ut.is_default
        FROM user_tenants ut
        JOIN tenants t ON ut.tenant_id = t.id
        WHERE ut.user_id = ? AND ut.status = 'active'
        ORDER BY ut.is_default DESC, t.name ASC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Get tenant by ID
 */
function getTenantById(int $tenantId): ?array {
    $pdo = getDB();
    if (!$pdo) return null;
    
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    return $stmt->fetch() ?: null;
}

/**
 * Get all active modules from database
 */
function getModulesFromDB(): array {
    $pdo = getDB();
    if (!$pdo) return [];
    
    $stmt = $pdo->query("
        SELECT m.id, m.name, m.link, m.is_active,
               am.description
        FROM modules m
        LEFT JOIN admin_modules am ON LOWER(am.name) = LOWER(m.name)
        WHERE m.is_active = 1
        ORDER BY m.id
    ");
    
    $modules = [];
    while ($row = $stmt->fetch()) {
        $moduleId = strtolower(str_replace(' ', '_', $row['name']));
        
        // Map module name to icon
        $iconMap = [
            'people' => 'icon-people.png',
            'finance' => 'icon-finance.png',
            'accounting' => 'icon-accounting.png',
            'tax' => 'icon-tax.png',
            'wealth_management' => 'icon-wealth.png',
            'crm' => 'icon-crm.png',
            'reporting' => 'icon-reporting.png',
        ];
        
        $modules[] = [
            'id' => $moduleId,
            'db_id' => $row['id'],
            'name' => $row['name'],
            'icon' => '/assets/icons/' . ($iconMap[$moduleId] ?? 'icon-module.png'),
            'description' => $row['description'] ?? '',
            'link' => $row['link'],
        ];
    }
    
    return $modules;
}

/**
 * Get modules enabled for a specific tenant
 */
function getTenantModules(int $tenantId): array {
    $pdo = getDB();
    if (!$pdo) return getModulesFromDB(); // Fall back to all modules
    
    // Check if tenant_modules has entries for this tenant
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM tenant_modules WHERE tenant_id = ?
    ");
    $stmt->execute([$tenantId]);
    $count = $stmt->fetchColumn();
    
    if ($count == 0) {
        // No tenant-specific config, return all active modules
        return getModulesFromDB();
    }
    
    // Get tenant-specific modules
    $stmt = $pdo->prepare("
        SELECT m.id, m.name, m.link, am.description
        FROM tenant_modules tm
        JOIN modules m ON tm.module_key = LOWER(REPLACE(m.name, ' ', '_'))
        LEFT JOIN admin_modules am ON LOWER(am.name) = LOWER(m.name)
        WHERE tm.tenant_id = ? AND tm.is_enabled = 1 AND m.is_active = 1
    ");
    $stmt->execute([$tenantId]);
    
    $modules = [];
    while ($row = $stmt->fetch()) {
        $moduleId = strtolower(str_replace(' ', '_', $row['name']));
        $modules[] = [
            'id' => $moduleId,
            'db_id' => $row['id'],
            'name' => $row['name'],
            'icon' => '/assets/icons/icon-' . $moduleId . '.png',
            'description' => $row['description'] ?? '',
            'link' => $row['link'],
        ];
    }
    
    return $modules ?: getModulesFromDB();
}

/**
 * Get permissions for a role
 */
function getRolePermissions(string $roleSlug): array {
    $pdo = getDB();
    if (!$pdo) return [];
    
    $stmt = $pdo->prepare("
        SELECT p.slug
        FROM permissions p
        JOIN role_permissions rp ON p.id = rp.permission_id
        JOIN roles r ON r.id = rp.role_id
        WHERE r.slug = ?
    ");
    $stmt->execute([$roleSlug]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Get all permissions (for master_admin/tenant_admin)
 */
function getAllPermissions(): array {
    $pdo = getDB();
    if (!$pdo) return [];
    
    $stmt = $pdo->query("SELECT slug FROM permissions");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Get sidebar items for a module
 */
function getModuleSidebarItems(string $moduleName): array {
    // For now, return hardcoded actions per module
    // TODO: Create module_sidebar_items table or use module-specific config
    
    $moduleActions = [
        'people' => [
            ['name' => 'Overview', 'route' => 'overview', 'icon' => 'icon-dashboard.png'],
            ['name' => 'Enter Time', 'route' => 'enter_time', 'icon' => 'icon-timesheet.png'],
            ['name' => 'Timesheets', 'route' => 'timesheets', 'icon' => 'icon-approvals.png'],
            ['name' => 'Employee Directory', 'route' => 'employee_directory', 'icon' => 'icon-directory.png'],
            ['name' => 'Reports', 'route' => 'reports', 'icon' => 'icon-reporting.png'],
            ['name' => 'Hiring Pipeline', 'route' => 'hiring_pipeline', 'icon' => 'icon-hiring.png', 'admin_only' => true],
        ],
        'accounting' => [
            ['name' => 'Overview', 'route' => 'overview', 'icon' => 'icon-dashboard.png'],
            ['name' => 'Chart of Accounts', 'route' => 'chart_of_accounts', 'icon' => 'icon-coa.png'],
            ['name' => 'Journal Entries', 'route' => 'journal_entries', 'icon' => 'icon-journal.png'],
            ['name' => 'Accounts Payable', 'route' => 'accounts_payable', 'icon' => 'icon-ap.png'],
            ['name' => 'Accounts Receivable', 'route' => 'accounts_receivable', 'icon' => 'icon-ar.png'],
            ['name' => 'Bank Reconciliation', 'route' => 'bank_reconciliation', 'icon' => 'icon-bank.png'],
            ['name' => 'Reports', 'route' => 'reports', 'icon' => 'icon-reporting.png'],
        ],
        'finance' => [
            ['name' => 'Overview', 'route' => 'overview', 'icon' => 'icon-dashboard.png'],
            ['name' => 'Budgets', 'route' => 'budgets', 'icon' => 'icon-budget.png'],
            ['name' => 'Forecasts', 'route' => 'forecasts', 'icon' => 'icon-forecast.png'],
            ['name' => 'Reports', 'route' => 'reports', 'icon' => 'icon-reporting.png'],
        ],
        'tax' => [
            ['name' => 'Overview', 'route' => 'overview', 'icon' => 'icon-dashboard.png'],
        ],
        'wealth_management' => [
            ['name' => 'Overview', 'route' => 'overview', 'icon' => 'icon-dashboard.png'],
        ],
        'crm' => [
            ['name' => 'Overview', 'route' => 'overview', 'icon' => 'icon-dashboard.png'],
            ['name' => 'Contacts', 'route' => 'contacts', 'icon' => 'icon-contacts.png'],
            ['name' => 'Companies', 'route' => 'companies', 'icon' => 'icon-companies.png'],
            ['name' => 'Deals', 'route' => 'deals', 'icon' => 'icon-deals.png'],
        ],
        'reporting' => [
            ['name' => 'Overview', 'route' => 'overview', 'icon' => 'icon-dashboard.png'],
            ['name' => 'Dashboards', 'route' => 'dashboards', 'icon' => 'icon-dashboard.png'],
            ['name' => 'Reports', 'route' => 'reports', 'icon' => 'icon-reporting.png'],
        ],
    ];
    
    $key = strtolower(str_replace(' ', '_', $moduleName));
    return $moduleActions[$key] ?? [['name' => 'Overview', 'route' => 'overview', 'icon' => 'icon-dashboard.png']];
}

/**
 * Check if user has permission
 */
function userHasPermission(array $user, string $permission, ?int $tenantId = null): bool {
    // Master admin and tenant_admin have all permissions
    $role = $user['role'] ?? '';
    if (in_array($role, ['master_admin', 'tenant_admin', 'admin'])) {
        return true;
    }
    
    // Check role_permissions table
    $permissions = getRolePermissions($role);
    return in_array($permission, $permissions);
}

/**
 * Get user's role in a specific tenant
 */
function getUserRoleInTenant(int $userId, int $tenantId): ?string {
    $pdo = getDB();
    if (!$pdo) return null;
    
    $stmt = $pdo->prepare("
        SELECT role FROM user_tenants 
        WHERE user_id = ? AND tenant_id = ? AND status = 'active'
    ");
    $stmt->execute([$userId, $tenantId]);
    $result = $stmt->fetch();
    return $result['role'] ?? null;
}
