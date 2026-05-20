<?php
/**
 * CoreFlux Data Layer
 * Handles tenants, modules, permissions with hierarchy support
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
        SELECT t.id, t.name, t.logo_url, t.subdomain, t.parent_id,
               MIN(tm.persona_type) AS role,
               MAX(tm.is_primary)   AS is_default
        FROM tenant_memberships tm
        JOIN tenants t ON tm.tenant_id = t.id
        WHERE tm.user_id = ? AND tm.status = 'active'
        GROUP BY t.id, t.name, t.logo_url, t.subdomain, t.parent_id
        ORDER BY is_default DESC, t.name ASC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Get sub-tenants for a parent tenant
 */
function getSubTenants(int $parentId): array {
    $pdo = getDB();
    if (!$pdo) return [];
    
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE parent_id = ?");
    $stmt->execute([$parentId]);
    return $stmt->fetchAll();
}

/**
 * Check if tenant is a primary (has sub-tenants) or sub-tenant
 */
function getTenantHierarchyInfo(int $tenantId): array {
    $pdo = getDB();
    if (!$pdo) return ['is_primary' => true, 'is_sub' => false, 'parent_id' => null, 'sub_tenants' => []];
    
    // Get tenant info
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();
    
    if (!$tenant) {
        return ['is_primary' => true, 'is_sub' => false, 'parent_id' => null, 'sub_tenants' => []];
    }
    
    $isSub = !empty($tenant['parent_id']);
    
    // Get sub-tenants if this is a primary
    $subTenants = [];
    if (!$isSub) {
        $subTenants = getSubTenants($tenantId);
    }
    
    return [
        'is_primary' => !$isSub && count($subTenants) > 0,
        'is_sub' => $isSub,
        'parent_id' => $tenant['parent_id'],
        'sub_tenants' => $subTenants,
    ];
}

/**
 * Get modules enabled for a specific tenant
 * Respects tenant_modules subscriptions
 */
function getTenantSubscribedModules(int $tenantId): array {
    $pdo = getDB();
    if (!$pdo) return [];
    
    // Check if tenant has specific module subscriptions
    $stmt = $pdo->prepare("
        SELECT tm.module_key, m.id, m.name, m.link, am.description
        FROM tenant_modules tm
        JOIN modules m ON LOWER(REPLACE(m.name, ' ', '_')) = tm.module_key
        LEFT JOIN admin_modules am ON LOWER(am.name) = LOWER(m.name)
        WHERE tm.tenant_id = ? AND tm.is_enabled = 1 AND m.is_active = 1
    ");
    $stmt->execute([$tenantId]);
    $modules = $stmt->fetchAll();
    
    // If no specific subscriptions, tenant has no modules (or hasn't been configured)
    // For now, return empty - admin needs to enable modules for tenant
    return $modules;
}

/**
 * Get all active modules (for master admin or unconfigured tenants)
 */
function getAllActiveModules(): array {
    $pdo = getDB();
    if (!$pdo) return [];
    
    $stmt = $pdo->query("
        SELECT m.id, m.name, m.link, am.description
        FROM modules m
        LEFT JOIN admin_modules am ON LOWER(am.name) = LOWER(m.name)
        WHERE m.is_active = 1
        ORDER BY m.id
    ");
    return $stmt->fetchAll();
}

/**
 * Get modules for a user in a specific tenant context
 * Implements the hierarchy rules:
 * - Sub-tenant user: only sub-tenant's modules
 * - Primary tenant user: primary's modules + all sub-tenant modules
 * - Master admin: all modules
 */
function getModulesForUserInTenant(int $userId, int $tenantId, string $globalRole, string $tenantRole): array {
    $pdo = getDB();
    if (!$pdo) return [];
    
    // Master admin sees all modules
    if ($globalRole === 'master_admin') {
        $modules = getAllActiveModules();
        return formatModules($modules);
    }
    
    // Get tenant hierarchy info
    $hierarchy = getTenantHierarchyInfo($tenantId);
    
    // Get tenant's subscribed modules
    $tenantModules = getTenantSubscribedModules($tenantId);
    
    // If no modules configured for tenant, fall back to all active modules
    // (This allows the system to work before tenant_modules is populated)
    if (empty($tenantModules)) {
        $tenantModules = getAllActiveModules();
    }
    
    // If this is a primary tenant, also get sub-tenant modules
    if (!$hierarchy['is_sub'] && !empty($hierarchy['sub_tenants'])) {
        $subModuleKeys = [];
        foreach ($hierarchy['sub_tenants'] as $subTenant) {
            $subMods = getTenantSubscribedModules($subTenant['id']);
            foreach ($subMods as $sm) {
                $key = $sm['module_key'] ?? strtolower(str_replace(' ', '_', $sm['name']));
                $subModuleKeys[$key] = $sm;
            }
        }
        
        // Merge with primary tenant modules (primary gets union)
        foreach ($tenantModules as $tm) {
            $key = $tm['module_key'] ?? strtolower(str_replace(' ', '_', $tm['name']));
            $subModuleKeys[$key] = $tm;
        }
        
        $tenantModules = array_values($subModuleKeys);
    }
    
    // For non-admin users, could further filter by user_modules table
    // For now, tenant_admin and admin see all tenant modules
    if (!in_array($tenantRole, ['tenant_admin', 'admin'])) {
        // TODO: Filter by user_modules or role_permissions
        // For now, employees see all tenant modules
    }
    
    return formatModules($tenantModules);
}

/**
 * Format raw module data into standard structure
 */
function formatModules(array $rawModules): array {
    $modules = [];
    $iconMap = [
        'people' => 'icon-people.png',
        'finance' => 'icon-finance.png',
        'accounting' => 'icon-accounting.png',
        'tax' => 'icon-tax.png',
        'wealth_management' => 'icon-wealth.png',
        'wealth management' => 'icon-wealth.png',
        'crm' => 'icon-crm.png',
        'reporting' => 'icon-reporting.png',
    ];
    
    foreach ($rawModules as $row) {
        $moduleId = strtolower(str_replace(' ', '_', $row['name']));
        $modules[] = [
            'id' => $moduleId,
            'db_id' => $row['id'],
            'name' => $row['name'],
            'icon' => '/assets/icons/' . ($iconMap[$moduleId] ?? $iconMap[strtolower($row['name'])] ?? 'icon-module.png'),
            'description' => $row['description'] ?? '',
            'link' => $row['link'] ?? '',
        ];
    }
    
    return $modules;
}

/**
 * Get sidebar items for a module
 */
function getModuleSidebarItems(string $moduleName): array {
    $moduleActions = [
        'people' => [
            ['name' => 'Overview', 'route' => 'overview'],
            ['name' => 'Enter Time', 'route' => 'enter_time'],
            ['name' => 'Timesheets', 'route' => 'timesheets'],
            ['name' => 'Employee Directory', 'route' => 'employee_directory'],
            ['name' => 'Reports', 'route' => 'reports'],
            ['name' => 'Hiring Pipeline', 'route' => 'hiring_pipeline', 'admin_only' => true],
        ],
        'accounting' => [
            ['name' => 'Overview', 'route' => 'overview'],
            ['name' => 'Chart of Accounts', 'route' => 'chart_of_accounts'],
            ['name' => 'Journal Entries', 'route' => 'journal_entries'],
            ['name' => 'Accounts Payable', 'route' => 'accounts_payable'],
            ['name' => 'Accounts Receivable', 'route' => 'accounts_receivable'],
            ['name' => 'Bank Reconciliation', 'route' => 'bank_reconciliation'],
            ['name' => 'Reports', 'route' => 'reports'],
        ],
        'finance' => [
            ['name' => 'Overview', 'route' => 'overview'],
            ['name' => 'Budgets', 'route' => 'budgets'],
            ['name' => 'Forecasts', 'route' => 'forecasts'],
            ['name' => 'Reports', 'route' => 'reports'],
        ],
        'tax' => [
            ['name' => 'Overview', 'route' => 'overview'],
        ],
        'wealth_management' => [
            ['name' => 'Overview', 'route' => 'overview'],
        ],
        'crm' => [
            ['name' => 'Overview', 'route' => 'overview'],
            ['name' => 'Contacts', 'route' => 'contacts'],
            ['name' => 'Companies', 'route' => 'companies'],
            ['name' => 'Deals', 'route' => 'deals'],
        ],
        'reporting' => [
            ['name' => 'Overview', 'route' => 'overview'],
            ['name' => 'Dashboards', 'route' => 'dashboards'],
            ['name' => 'Reports', 'route' => 'reports'],
        ],
    ];
    
    $key = strtolower(str_replace(' ', '_', $moduleName));
    return $moduleActions[$key] ?? [['name' => 'Overview', 'route' => 'overview']];
}

/**
 * Check if user is master admin
 */
function isMasterAdmin(array $user): bool {
    return ($user['global_role'] ?? $user['role'] ?? '') === 'master_admin';
}

/**
 * Get master admin dashboard stats
 */
function getMasterAdminStats(): array {
    $pdo = getDB();
    if (!$pdo) return [];
    
    $stats = [];
    
    // Total tenants
    $stats['total_tenants'] = $pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    
    // Total users
    $stats['total_users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
    
    // Active modules
    $stats['active_modules'] = $pdo->query("SELECT COUNT(*) FROM modules WHERE is_active = 1")->fetchColumn();
    
    // Total employees
    $stats['total_employees'] = $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
    
    return $stats;
}

/**
 * Get all tenants (for master admin)
 */
function getAllTenants(): array {
    $pdo = getDB();
    if (!$pdo) return [];
    
    $stmt = $pdo->query("
        SELECT t.*, 
               (SELECT COUNT(DISTINCT tm.user_id) FROM tenant_memberships tm WHERE tm.tenant_id = t.id AND tm.status = 'active') as user_count,
               (SELECT COUNT(*) FROM tenants sub WHERE sub.parent_id = t.id) as sub_tenant_count
        FROM tenants t
        ORDER BY t.parent_id IS NULL DESC, t.name ASC
    ");
    return $stmt->fetchAll();
}

/**
 * Get all users (for master admin)
 */
function getAllUsers(): array {
    $pdo = getDB();
    if (!$pdo) return [];
    
    $stmt = $pdo->query("
        SELECT u.*, 
               GROUP_CONCAT(DISTINCT t.name SEPARATOR ', ') as tenant_names
        FROM users u
        LEFT JOIN tenant_memberships tm ON u.id = tm.user_id AND tm.status = 'active'
        LEFT JOIN tenants t ON tm.tenant_id = t.id
        GROUP BY u.id
        ORDER BY u.name
    ");
    return $stmt->fetchAll();
}
