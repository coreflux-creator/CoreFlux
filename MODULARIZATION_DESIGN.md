# Pass 2: CoreFlux Modularization Design

## Based on Platform Product Specification

---

## 1. Module Contract Interface

Every module must ship a **manifest** that declares its integration with the Core platform.

### Module Manifest Schema

```php
// /modules/{module_id}/manifest.php
return [
    'module_id'    => 'accounting',
    'name'         => 'Accounting',
    'icon'         => '/assets/icons/icon-accounting.png',
    'description'  => 'Enterprise-grade accounting with GL, AP, AR, and financial reporting.',
    
    // Views rendered inside the shell
    'views' => [
        'accounting/overview',
        'accounting/chart_of_accounts',
        'accounting/journal_entries',
        'accounting/accounts_payable',
        'accounting/accounts_receivable',
        'accounting/bank_reconciliation',
        'accounting/period_close',
        'accounting/reports',
        'accounting/allocations',
    ],
    
    // Navigation sections for sidebar
    'nav_sections' => [
        [
            'label' => 'General Ledger',
            'items' => [
                ['label' => 'Chart of Accounts', 'view' => 'accounting/chart_of_accounts', 'permission' => 'accounting.coa.view'],
                ['label' => 'Journal Entries', 'view' => 'accounting/journal_entries', 'permission' => 'accounting.journal.view'],
                ['label' => 'Period Close', 'view' => 'accounting/period_close', 'permission' => 'accounting.period.manage'],
            ]
        ],
        [
            'label' => 'Operations',
            'items' => [
                ['label' => 'Accounts Payable', 'view' => 'accounting/accounts_payable', 'permission' => 'accounting.ap.view'],
                ['label' => 'Accounts Receivable', 'view' => 'accounting/accounts_receivable', 'permission' => 'accounting.ar.view'],
                ['label' => 'Bank Reconciliation', 'view' => 'accounting/bank_reconciliation', 'permission' => 'accounting.bank.view'],
            ]
        ],
        [
            'label' => 'Analysis',
            'items' => [
                ['label' => 'Allocations', 'view' => 'accounting/allocations', 'permission' => 'accounting.allocations.view'],
                ['label' => 'Reports', 'view' => 'accounting/reports', 'permission' => 'accounting.reports.view'],
            ]
        ],
    ],
    
    // Permission declarations
    'permissions' => [
        'accounting.view'              => 'Access Accounting module',
        'accounting.coa.view'          => 'View Chart of Accounts',
        'accounting.coa.edit'          => 'Edit Chart of Accounts',
        'accounting.journal.view'      => 'View Journal Entries',
        'accounting.journal.create'    => 'Create Journal Entries',
        'accounting.journal.post'      => 'Post Journal Entries',
        'accounting.journal.reverse'   => 'Reverse Journal Entries',
        'accounting.ap.view'           => 'View Accounts Payable',
        'accounting.ap.manage'         => 'Manage AP transactions',
        'accounting.ar.view'           => 'View Accounts Receivable',
        'accounting.ar.manage'         => 'Manage AR transactions',
        'accounting.bank.view'         => 'View Bank Reconciliation',
        'accounting.bank.reconcile'    => 'Perform reconciliations',
        'accounting.period.manage'     => 'Manage period close',
        'accounting.allocations.view'  => 'View allocations',
        'accounting.allocations.run'   => 'Run allocations',
        'accounting.reports.view'      => 'View financial reports',
        'accounting.reports.export'    => 'Export reports',
    ],
    
    // API endpoints for AJAX actions
    'api_endpoints' => [
        'POST /api/accounting/coa'                  => 'Create account',
        'PUT  /api/accounting/coa/{id}'             => 'Update account',
        'POST /api/accounting/journal'              => 'Create journal entry',
        'POST /api/accounting/journal/{id}/post'    => 'Post journal entry',
        'POST /api/accounting/journal/{id}/reverse' => 'Reverse journal entry',
        'POST /api/accounting/period/{id}/close'    => 'Close period',
        'POST /api/accounting/allocations/run'      => 'Run allocation',
        'GET  /api/accounting/reports/{type}'       => 'Generate report',
    ],
    
    // Exportable reports
    'exports' => [
        'trial_balance'      => ['formats' => ['csv', 'pdf'], 'permission' => 'accounting.reports.export'],
        'income_statement'   => ['formats' => ['csv', 'pdf'], 'permission' => 'accounting.reports.export'],
        'balance_sheet'      => ['formats' => ['csv', 'pdf'], 'permission' => 'accounting.reports.export'],
        'general_ledger'     => ['formats' => ['csv', 'pdf'], 'permission' => 'accounting.reports.export'],
        'ap_aging'           => ['formats' => ['csv', 'pdf'], 'permission' => 'accounting.ap.view'],
        'ar_aging'           => ['formats' => ['csv', 'pdf'], 'permission' => 'accounting.ar.view'],
    ],
    
    // Audit events generated
    'audit_events' => [
        'coa.created',
        'coa.updated',
        'coa.deactivated',
        'journal.created',
        'journal.posted',
        'journal.reversed',
        'period.closed',
        'period.reopened',
        'allocation.rule_created',
        'allocation.rule_updated',
        'allocation.run_completed',
        'ap.invoice_created',
        'ap.payment_recorded',
        'ar.invoice_created',
        'ar.payment_received',
        'bank.reconciliation_completed',
    ],
    
    // Workflow definitions
    'workflows' => [
        'journal_approval' => [
            'states' => ['draft', 'pending_approval', 'approved', 'posted', 'reversed'],
            'transitions' => [
                'draft' => ['pending_approval'],
                'pending_approval' => ['approved', 'draft'],
                'approved' => ['posted'],
                'posted' => ['reversed'],
            ],
        ],
    ],
    
    // Custom field entity types
    'custom_field_entities' => [
        'accounting_account',
        'accounting_journal',
        'accounting_vendor',
        'accounting_customer',
    ],
];
```

---

## 2. Module Registry (Core Platform)

The Core platform discovers and loads modules through a central registry.

### File: `/core/ModuleRegistry.php`

```php
<?php
/**
 * CoreFlux Module Registry
 * 
 * Discovers, validates, and provides access to module manifests.
 * All module loading goes through this class.
 */

class ModuleRegistry {
    private static ?ModuleRegistry $instance = null;
    private array $modules = [];
    private array $loadedManifests = [];
    
    private function __construct() {
        $this->discoverModules();
    }
    
    public static function getInstance(): ModuleRegistry {
        if (self::$instance === null) {
            self::$instance = new ModuleRegistry();
        }
        return self::$instance;
    }
    
    /**
     * Scan /modules/ directory for manifest.php files
     */
    private function discoverModules(): void {
        $modulesDir = __DIR__ . '/../modules';
        $dirs = glob($modulesDir . '/*', GLOB_ONLYDIR);
        
        foreach ($dirs as $dir) {
            $manifestPath = $dir . '/manifest.php';
            if (file_exists($manifestPath)) {
                $manifest = require $manifestPath;
                if ($this->validateManifest($manifest)) {
                    $this->modules[$manifest['module_id']] = $manifest;
                }
            }
        }
    }
    
    /**
     * Validate manifest has required fields
     */
    private function validateManifest(array $manifest): bool {
        $required = ['module_id', 'name', 'views', 'permissions'];
        foreach ($required as $field) {
            if (!isset($manifest[$field])) {
                error_log("Module manifest missing required field: {$field}");
                return false;
            }
        }
        return true;
    }
    
    /**
     * Get all registered modules
     */
    public function getAllModules(): array {
        return $this->modules;
    }
    
    /**
     * Get a specific module manifest
     */
    public function getModule(string $moduleId): ?array {
        return $this->modules[$moduleId] ?? null;
    }
    
    /**
     * Get modules accessible to a user based on their permissions
     */
    public function getAccessibleModules(array $userPermissions): array {
        $accessible = [];
        foreach ($this->modules as $moduleId => $manifest) {
            // Check if user has the base module view permission
            $basePermission = "{$moduleId}.view";
            if (in_array($basePermission, $userPermissions)) {
                $accessible[$moduleId] = $manifest;
            }
        }
        return $accessible;
    }
    
    /**
     * Get navigation items for a module filtered by permissions
     */
    public function getModuleNav(string $moduleId, array $userPermissions): array {
        $module = $this->getModule($moduleId);
        if (!$module || !isset($module['nav_sections'])) {
            return [];
        }
        
        $filteredNav = [];
        foreach ($module['nav_sections'] as $section) {
            $filteredItems = [];
            foreach ($section['items'] as $item) {
                if (in_array($item['permission'], $userPermissions)) {
                    $filteredItems[] = $item;
                }
            }
            if (!empty($filteredItems)) {
                $filteredNav[] = [
                    'label' => $section['label'],
                    'items' => $filteredItems,
                ];
            }
        }
        return $filteredNav;
    }
    
    /**
     * Check if a view exists and user has permission
     */
    public function canAccessView(string $moduleId, string $view, array $userPermissions): bool {
        $module = $this->getModule($moduleId);
        if (!$module) return false;
        
        // Check view exists
        if (!in_array($view, $module['views'])) return false;
        
        // Find permission for this view
        foreach ($module['nav_sections'] as $section) {
            foreach ($section['items'] as $item) {
                if ($item['view'] === $view) {
                    return in_array($item['permission'], $userPermissions);
                }
            }
        }
        
        // Default: require base module permission
        return in_array("{$moduleId}.view", $userPermissions);
    }
    
    /**
     * Get all permissions declared by all modules
     */
    public function getAllPermissions(): array {
        $permissions = [];
        foreach ($this->modules as $manifest) {
            $permissions = array_merge($permissions, $manifest['permissions'] ?? []);
        }
        return $permissions;
    }
    
    /**
     * Get audit event types for a module
     */
    public function getAuditEvents(string $moduleId): array {
        $module = $this->getModule($moduleId);
        return $module['audit_events'] ?? [];
    }
}
```

---

## 3. Updated Application Structure

### Directory Layout

```
/app/
├── core/                           # Platform core (shared services)
│   ├── ModuleRegistry.php          # Module discovery & access
│   ├── Auth.php                    # Authentication
│   ├── RBAC.php                    # Role-based access control
│   ├── Audit.php                   # Audit logging service
│   ├── Workflow.php                # Workflow engine
│   ├── Export.php                  # Export service
│   ├── Notifications.php           # Email/notification service
│   ├── CustomFields.php            # Custom field management
│   ├── db.php                      # Database connection
│   └── config.php                  # Platform configuration
│
├── modules/                        # Standalone modules
│   ├── people/                     # People module
│   │   ├── manifest.php            # Module manifest
│   │   ├── api/                    # API endpoints
│   │   │   ├── employees.php
│   │   │   ├── timesheets.php
│   │   │   └── reports.php
│   │   ├── views/                  # View partials (rendered in shell)
│   │   │   ├── overview.php
│   │   │   ├── enter_time.php
│   │   │   ├── timesheets.php
│   │   │   ├── employee_directory.php
│   │   │   ├── hiring_pipeline.php
│   │   │   └── reports.php
│   │   ├── includes/               # Module-specific helpers
│   │   │   └── people_helpers.php
│   │   └── assets/                 # Module-specific assets
│   │       └── people.css
│   │
│   ├── accounting/                 # Accounting module (MODEL)
│   │   ├── manifest.php
│   │   ├── api/
│   │   │   ├── coa.php             # Chart of accounts
│   │   │   ├── journal.php         # Journal entries
│   │   │   ├── ap.php              # Accounts payable
│   │   │   ├── ar.php              # Accounts receivable
│   │   │   ├── bank.php            # Bank reconciliation
│   │   │   ├── periods.php         # Period management
│   │   │   ├── allocations.php     # Allocations
│   │   │   └── reports.php         # Financial reports
│   │   ├── views/
│   │   │   ├── overview.php
│   │   │   ├── chart_of_accounts.php
│   │   │   ├── journal_entries.php
│   │   │   ├── accounts_payable.php
│   │   │   ├── accounts_receivable.php
│   │   │   ├── bank_reconciliation.php
│   │   │   ├── period_close.php
│   │   │   ├── allocations.php
│   │   │   └── reports.php
│   │   ├── includes/
│   │   │   ├── accounting_helpers.php
│   │   │   └── financial_calc.php
│   │   └── assets/
│   │       └── accounting.css
│   │
│   ├── finance/
│   │   └── manifest.php
│   ├── tax/
│   │   └── manifest.php
│   ├── wealth_management/
│   │   └── manifest.php
│   └── private_equity/
│       └── manifest.php
│
├── partials/                       # Shell UI components
│   ├── shell.php                   # Main shell wrapper
│   ├── header.php                  # Top nav (uses ModuleRegistry)
│   ├── sidebar.php                 # Sidebar (uses ModuleRegistry)
│   └── footer.php
│
├── includes/                       # Legacy (migrate to /core/)
│   └── config.php
│
├── assets/                         # Shared assets
│   ├── css/
│   │   └── styles.css
│   ├── js/
│   │   └── core.js
│   └── icons/
│
├── api/                            # API router
│   └── index.php                   # Routes to module APIs
│
├── dashboard.php                   # Main shell entrypoint
├── login.php                       # Login handler
├── logout.php                      # Logout handler
└── session.php                     # Session API
```

---

## 4. Shell Integration (dashboard.php)

### Updated Shell with Module Registry

```php
<?php
// /dashboard.php - Main Shell Entrypoint
session_start();

require_once __DIR__ . '/core/ModuleRegistry.php';
require_once __DIR__ . '/core/RBAC.php';
require_once __DIR__ . '/core/config.php';

// Auth check
if (!isset($_SESSION['user'])) {
    header("Location: login.html");
    exit;
}

$user = $_SESSION['user'];
$tenant = $_SESSION['tenant'];
$userPermissions = RBAC::getUserPermissions($user['id'], $tenant['id']);

// Get module registry
$registry = ModuleRegistry::getInstance();
$accessibleModules = $registry->getAccessibleModules($userPermissions);

// Parse route: dashboard.php?page=accounting/journal_entries
$page = $_GET['page'] ?? '';
$parts = explode('/', $page, 2);
$moduleId = $parts[0] ?? '';
$view = $page; // Full path like "accounting/journal_entries"

// Determine active module
if ($moduleId && isset($accessibleModules[$moduleId])) {
    $activeModule = $accessibleModules[$moduleId];
    $moduleNav = $registry->getModuleNav($moduleId, $userPermissions);
} else {
    // Default to first accessible module
    $moduleId = array_key_first($accessibleModules);
    $activeModule = $accessibleModules[$moduleId] ?? null;
    $moduleNav = $activeModule ? $registry->getModuleNav($moduleId, $userPermissions) : [];
    $view = "{$moduleId}/overview";
}

// Validate view access
if ($view && !$registry->canAccessView($moduleId, $view, $userPermissions)) {
    http_response_code(403);
    $view = '403'; // Show forbidden page
}

// Resolve view file path
$viewPath = __DIR__ . "/modules/{$view}.php";
if (!file_exists($viewPath)) {
    $viewPath = __DIR__ . "/modules/{$moduleId}/views/" . basename($view) . ".php";
}
if (!file_exists($viewPath)) {
    http_response_code(404);
    $viewPath = __DIR__ . "/partials/404.php";
}

// Handle AJAX requests (return only main content)
if (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) {
    ob_start();
    include $viewPath;
    $content = ob_get_clean();
    echo '<main class="main-content" id="main-content">' . $content . '</main>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CoreFlux - <?= htmlspecialchars($activeModule['name'] ?? 'Dashboard') ?></title>
    <link rel="stylesheet" href="/assets/css/styles.css">
    <?php if ($activeModule && file_exists(__DIR__ . "/modules/{$moduleId}/assets/{$moduleId}.css")): ?>
    <link rel="stylesheet" href="/modules/<?= $moduleId ?>/assets/<?= $moduleId ?>.css">
    <?php endif; ?>
</head>
<body>

<?php include __DIR__ . '/partials/header.php'; ?>

<div class="layout">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    
    <main class="main-content" id="main-content">
        <?php include $viewPath; ?>
    </main>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>

<script src="/assets/js/core.js"></script>
</body>
</html>
```

---

## 5. Updated Sidebar (uses ModuleRegistry)

```php
<?php
// /partials/sidebar.php
// Variables available: $activeModule, $moduleNav, $moduleId
?>
<aside class="sidebar">
    <?php if ($activeModule): ?>
        <div class="sidebar-module-header">
            <img src="<?= htmlspecialchars($activeModule['icon'] ?? '') ?>" alt="" class="module-icon">
            <h3><?= htmlspecialchars($activeModule['name']) ?></h3>
        </div>
        
        <nav class="sidebar-nav">
            <?php foreach ($moduleNav as $section): ?>
                <div class="nav-section">
                    <h4 class="nav-section-label"><?= htmlspecialchars($section['label']) ?></h4>
                    <ul>
                        <?php foreach ($section['items'] as $item): ?>
                            <?php 
                            $isActive = ($view === $item['view']);
                            $href = "dashboard.php?page=" . urlencode($item['view']);
                            ?>
                            <li>
                                <a href="<?= $href ?>" 
                                   class="sidebar-link <?= $isActive ? 'active' : '' ?>"
                                   data-view="<?= htmlspecialchars($item['view']) ?>">
                                    <?= htmlspecialchars($item['label']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</aside>
```

---

## 6. Updated Header (Module Switcher)

```php
<?php
// /partials/header.php
// Variables available: $user, $tenant, $accessibleModules, $activeModule
?>
<header class="top-nav">
    <div class="logo-container">
        <img src="/assets/icons/logo.png" alt="CoreFlux" class="logo">
    </div>
    
    <nav class="header-nav">
        <!-- Module Switcher -->
        <div class="dropdown">
            <button class="dropbtn">
                <?= htmlspecialchars($activeModule['name'] ?? 'Modules') ?>
                <span class="caret">▼</span>
            </button>
            <div class="dropdown-content">
                <?php foreach ($accessibleModules as $mod): ?>
                    <a href="dashboard.php?page=<?= urlencode($mod['module_id']) ?>/overview"
                       class="<?= $mod['module_id'] === $moduleId ? 'active' : '' ?>">
                        <img src="<?= htmlspecialchars($mod['icon'] ?? '') ?>" alt="" class="menu-icon">
                        <?= htmlspecialchars($mod['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Tenant Switcher -->
        <?php if (!empty($user['tenants']) && count($user['tenants']) > 1): ?>
        <div class="dropdown">
            <button class="dropbtn">
                <?= htmlspecialchars($tenant['name'] ?? 'Tenant') ?>
                <span class="caret">▼</span>
            </button>
            <div class="dropdown-content">
                <?php foreach ($user['tenants'] as $t): ?>
                    <a href="switch_tenant.php?tenant_id=<?= urlencode($t['id']) ?>">
                        <?= htmlspecialchars($t['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- User Menu -->
        <div class="dropdown user-menu">
            <button class="dropbtn">
                <?php if (!empty($user['avatar'])): ?>
                    <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="" class="avatar">
                <?php else: ?>
                    <span class="avatar-placeholder"><?= strtoupper(substr($user['first_name'] ?? 'U', 0, 1)) ?></span>
                <?php endif; ?>
            </button>
            <div class="dropdown-content">
                <a href="dashboard.php?page=profile">Profile</a>
                <a href="dashboard.php?page=settings">Settings</a>
                <hr>
                <a href="logout.php">Logout</a>
            </div>
        </div>
    </nav>
</header>
```

---

## 7. API Router

```php
<?php
// /api/index.php - API Router
header('Content-Type: application/json');

session_start();
require_once __DIR__ . '/../core/ModuleRegistry.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/Audit.php';

// Generate request ID for tracing
$requestId = bin2hex(random_bytes(8));
header("X-Request-ID: {$requestId}");

// Auth check
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'errors' => ['Not authenticated']]);
    exit;
}

$user = $_SESSION['user'];
$tenant = $_SESSION['tenant'];

// Parse route: /api/accounting/journal
$path = $_SERVER['PATH_INFO'] ?? '';
$parts = explode('/', trim($path, '/'));
$moduleId = $parts[0] ?? '';
$endpoint = $parts[1] ?? '';

// Validate module exists
$registry = ModuleRegistry::getInstance();
$module = $registry->getModule($moduleId);

if (!$module) {
    http_response_code(404);
    echo json_encode(['success' => false, 'errors' => ['Module not found']]);
    exit;
}

// Route to module API
$apiPath = __DIR__ . "/../modules/{$moduleId}/api/{$endpoint}.php";

if (!file_exists($apiPath)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'errors' => ['Endpoint not found']]);
    exit;
}

// Include module API (it handles permission checks internally)
require $apiPath;
```

---

## 8. Next Steps: Implementing Accounting as Model Module

### Step 1: Create Accounting Manifest
File: `/modules/accounting/manifest.php`

### Step 2: Create Accounting Views
- `overview.php` - Module dashboard
- `chart_of_accounts.php` - COA management
- `journal_entries.php` - Journal entry list/create
- `accounts_payable.php` - AP dashboard
- `accounts_receivable.php` - AR dashboard
- `bank_reconciliation.php` - Bank recon
- `period_close.php` - Period management
- `allocations.php` - Allocation rules/runs
- `reports.php` - Financial statements

### Step 3: Create Accounting APIs
- `coa.php` - CRUD for accounts
- `journal.php` - Journal entry operations
- `ap.php` - AP transactions
- `ar.php` - AR transactions
- `bank.php` - Bank reconciliation
- `periods.php` - Period open/close
- `allocations.php` - Allocation engine
- `reports.php` - Report generation

### Step 4: Database Schema
- Create accounting tables following spec

### Step 5: Integration Tests
- Verify manifest loads
- Verify nav renders
- Verify views load in shell
- Verify API endpoints work
- Verify audit events emit

---

## Summary: Module Contract Checklist

For a module to be valid in CoreFlux:

| Requirement | Location |
|-------------|----------|
| ✅ `manifest.php` with required fields | `/modules/{id}/manifest.php` |
| ✅ Views as partials (not full HTML) | `/modules/{id}/views/*.php` |
| ✅ API endpoints return JSON | `/modules/{id}/api/*.php` |
| ✅ Permissions declared | `manifest.php → permissions` |
| ✅ Audit events declared | `manifest.php → audit_events` |
| ✅ Nav sections defined | `manifest.php → nav_sections` |
| ✅ Uses core services (Auth, Audit, Export) | Imports from `/core/` |
| ✅ Tenant-scoped queries | All DB queries include `tenant_id` |

---

*Pass 2 Complete - Ready for Pass 3: Implement Accounting Module*
