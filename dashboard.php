<?php
/**
 * CoreFlux Dashboard - Main Shell Entrypoint
 */

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/data.php';

initSession();

// Auth check
if (!isAuthenticated()) {
    header("Location: login.html");
    exit;
}

// Get session data
$user = getCurrentUser();
$modules = getSessionModules();
$activeModule = getActiveModule();
$tenant = getCurrentTenant();
$tenantId = $_SESSION['tenant_id'] ?? 1;
$tenants = $user['tenants'] ?? [];

// Handle tenant switching
if (isset($_GET['switch_tenant'])) {
    $newTenantId = (int)$_GET['switch_tenant'];
    
    // Verify user has access to this tenant
    foreach ($tenants as $t) {
        if ($t['id'] === $newTenantId) {
            $_SESSION['tenant'] = $t['name'];
            $_SESSION['tenant_id'] = $t['id'];
            $_SESSION['tenant_role'] = $t['role'];
            
            // Reload modules for new tenant
            $modules = getTenantModules($newTenantId);
            foreach ($modules as &$mod) {
                $mod['actions'] = getModuleSidebarItems($mod['name']);
            }
            unset($mod);
            
            $_SESSION['modules'] = $modules;
            $_SESSION['active_module'] = $modules[0] ?? null;
            
            header("Location: dashboard.php");
            exit;
        }
    }
}

// Handle module switching
if (isset($_GET['module'])) {
    $requestedModule = $_GET['module'];
    foreach ($modules as $mod) {
        if ($mod['id'] === $requestedModule) {
            $_SESSION['active_module'] = $mod;
            $activeModule = $mod;
            break;
        }
    }
}

// Ensure we have an active module
if (!$activeModule && !empty($modules)) {
    $_SESSION['active_module'] = $modules[0];
    $activeModule = $modules[0];
}

// Get current user's role in tenant
$userRole = $_SESSION['tenant_role'] ?? $user['role'] ?? 'employee';
$isAdmin = in_array($userRole, ['master_admin', 'tenant_admin', 'admin']);

// Get module actions filtered by role
$moduleActions = [];
if ($activeModule && isset($activeModule['actions'])) {
    foreach ($activeModule['actions'] as $action) {
        // Filter admin-only actions
        if (!empty($action['admin_only']) && !$isAdmin) {
            continue;
        }
        $moduleActions[] = $action;
    }
}

// Parse page route
$page = $_GET['page'] ?? 'overview';
$page = preg_replace('/[^a-zA-Z0-9_-]/', '', $page);

// Build view path
$moduleId = $activeModule['id'] ?? 'people';
$viewPaths = [
    __DIR__ . "/modules/{$moduleId}/views/{$page}.php",
    __DIR__ . "/modules/{$moduleId}/{$page}.php",
    __DIR__ . "/{$moduleId}/{$page}.php",
];

$viewPath = null;
foreach ($viewPaths as $path) {
    if (file_exists($path)) {
        $viewPath = $path;
        break;
    }
}

if (!$viewPath) {
    $viewPath = __DIR__ . "/core/views/module_overview.php";
}

// Handle AJAX requests
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($activeModule['name'] ?? 'Dashboard') ?> | <?= htmlspecialchars($tenant) ?> - CoreFlux</title>
    <link rel="stylesheet" href="/assets/css/dashboard.css">
</head>
<body class="dashboard-page">

<!-- Top Navigation -->
<header class="top-header">
    <div class="header-left">
        <a href="/dashboard.php" class="logo-link">
            <img src="/assets/icons/logo-white.png" alt="CoreFlux" class="header-logo">
        </a>
    </div>
    
    <div class="header-center">
        <!-- Module Switcher -->
        <div class="dropdown module-dropdown">
            <button class="dropdown-trigger" type="button">
                <?php if ($activeModule): ?>
                    <img src="<?= htmlspecialchars($activeModule['icon']) ?>" alt="" class="dropdown-icon" onerror="this.style.display='none'">
                    <span><?= htmlspecialchars($activeModule['name']) ?></span>
                <?php else: ?>
                    <span>Select Module</span>
                <?php endif; ?>
                <svg class="caret" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            </button>
            <div class="dropdown-menu">
                <?php foreach ($modules as $mod): ?>
                    <a href="?module=<?= urlencode($mod['id']) ?>" 
                       class="dropdown-item <?= ($mod['id'] === ($activeModule['id'] ?? '')) ? 'active' : '' ?>">
                        <img src="<?= htmlspecialchars($mod['icon']) ?>" alt="" class="dropdown-icon" onerror="this.style.display='none'">
                        <span><?= htmlspecialchars($mod['name']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <div class="header-right">
        <!-- Tenant Switcher -->
        <?php if (count($tenants) > 1): ?>
        <div class="dropdown tenant-dropdown">
            <button class="dropdown-trigger" type="button">
                <span><?= htmlspecialchars($tenant) ?></span>
                <svg class="caret" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            </button>
            <div class="dropdown-menu">
                <?php foreach ($tenants as $t): ?>
                    <a href="?switch_tenant=<?= urlencode($t['id']) ?>" 
                       class="dropdown-item <?= ($t['name'] === $tenant) ? 'active' : '' ?>">
                        <?= htmlspecialchars($t['name']) ?>
                        <small style="opacity: 0.6; margin-left: 8px;"><?= htmlspecialchars($t['role']) ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <span class="tenant-name"><?= htmlspecialchars($tenant) ?></span>
        <?php endif; ?>
        
        <!-- User Menu -->
        <div class="dropdown user-dropdown">
            <button class="dropdown-trigger user-trigger" type="button">
                <?php if (!empty($user['avatar'])): ?>
                    <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="" class="user-avatar">
                <?php else: ?>
                    <span class="user-avatar-placeholder">
                        <?= strtoupper(substr($user['first_name'] ?? 'U', 0, 1)) ?>
                    </span>
                <?php endif; ?>
                <span class="user-name"><?= htmlspecialchars($user['first_name'] ?? 'User') ?></span>
                <svg class="caret" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            </button>
            <div class="dropdown-menu dropdown-menu-right">
                <div class="dropdown-header">
                    <strong><?= htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?></strong>
                    <small><?= htmlspecialchars($user['email'] ?? '') ?></small>
                    <small style="display: block; margin-top: 4px; color: var(--color-accent);"><?= htmlspecialchars(ucfirst($userRole)) ?></small>
                </div>
                <hr class="dropdown-divider">
                <a href="?page=profile" class="dropdown-item">Profile</a>
                <a href="?page=settings" class="dropdown-item">Settings</a>
                <hr class="dropdown-divider">
                <a href="/logout.php" class="dropdown-item dropdown-item-danger">Logout</a>
            </div>
        </div>
    </div>
</header>

<!-- Main Layout -->
<div class="dashboard-layout">
    
    <!-- Sidebar Navigation -->
    <aside class="sidebar">
        <?php if ($activeModule): ?>
            <div class="sidebar-header">
                <h3><?= htmlspecialchars($activeModule['name']) ?></h3>
            </div>
            <nav class="sidebar-nav">
                <?php foreach ($moduleActions as $action): ?>
                    <?php 
                    $isActive = ($page === $action['route']);
                    $href = "?page=" . urlencode($action['route']);
                    ?>
                    <a href="<?= $href ?>" 
                       class="sidebar-link <?= $isActive ? 'active' : '' ?>"
                       data-page="<?= htmlspecialchars($action['route']) ?>">
                        <?= htmlspecialchars($action['name']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        
        <div class="sidebar-footer">
            <small>CoreFlux v1.0</small>
        </div>
    </aside>
    
    <!-- Main Content Area -->
    <main class="main-content" id="main-content">
        <?php include $viewPath; ?>
    </main>
    
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const main = document.getElementById('main-content');
    
    // AJAX navigation for sidebar links
    document.body.addEventListener('click', (e) => {
        const link = e.target.closest('a.sidebar-link');
        if (!link) return;
        
        e.preventDefault();
        const url = new URL(link.href, window.location.origin);
        
        document.querySelectorAll('.sidebar-link').forEach(l => l.classList.remove('active'));
        link.classList.add('active');
        
        fetch(url.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.ok ? res.text() : Promise.reject('Failed'))
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newContent = doc.querySelector('#main-content');
            if (newContent) {
                main.innerHTML = newContent.innerHTML;
                window.history.pushState({}, '', url.href);
            }
        })
        .catch(() => window.location.href = url.href);
    });
    
    window.addEventListener('popstate', () => window.location.reload());
    
    // Dropdown behavior
    document.querySelectorAll('.dropdown-trigger').forEach(trigger => {
        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const dropdown = trigger.closest('.dropdown');
            const isOpen = dropdown.classList.contains('open');
            document.querySelectorAll('.dropdown.open').forEach(d => d.classList.remove('open'));
            if (!isOpen) dropdown.classList.add('open');
        });
    });
    
    document.addEventListener('click', () => {
        document.querySelectorAll('.dropdown.open').forEach(d => d.classList.remove('open'));
    });
});
</script>

</body>
</html>
