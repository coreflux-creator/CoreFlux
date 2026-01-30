<?php
/**
 * CoreFlux Dashboard - Main Shell Entrypoint
 * Renders the logged-in application shell with module content
 */

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/modules.php';

// Initialize session
initSession();

// Auth check - redirect to login if not authenticated
if (!isAuthenticated()) {
    // For development: auto-create demo session
    // Remove this block when DB auth is ready
    if (isset($_GET['demo'])) {
        $role = $_GET['demo'] === 'employee' ? 'employee' : 'admin';
        createDemoSession($role);
    } else {
        header("Location: login.html");
        exit;
    }
}

// Get session data
$user = getCurrentUser();
$modules = getSessionModules();
$activeModule = getActiveModule();
$tenant = getCurrentTenant();
$tenants = $user['tenants'] ?? [];

// Handle module switching via GET param
if (isset($_GET['module'])) {
    $requestedModule = $_GET['module'];
    if (setActiveModule($requestedModule)) {
        $activeModule = getActiveModule();
    }
}

// Ensure we have an active module
if (!$activeModule && !empty($modules)) {
    $_SESSION['active_module'] = $modules[0];
    $activeModule = $modules[0];
}

// Get filtered actions for current user's role
$moduleActions = $activeModule ? getModuleActions($activeModule, $user['role']) : [];

// Parse page route
$page = $_GET['page'] ?? 'overview';
$page = preg_replace('/[^a-zA-Z0-9_-]/', '', $page); // Sanitize

// Build view path - check multiple locations
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

// Fallback to generic overview if view not found
if (!$viewPath) {
    $viewPath = __DIR__ . "/core/views/module_overview.php";
}

// Handle AJAX requests (return only main content)
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
    <title><?= htmlspecialchars($activeModule['name'] ?? 'Dashboard') ?> | CoreFlux</title>
    <link rel="stylesheet" href="/assets/css/dashboard.css">
</head>
<body class="dashboard-page">

<!-- Top Navigation -->
<header class="top-header">
    <div class="header-left">
        <a href="/dashboard.php" class="logo-link">
            <img src="/assets/icons/logo.png" alt="CoreFlux" class="header-logo">
        </a>
    </div>
    
    <div class="header-center">
        <!-- Module Switcher -->
        <div class="dropdown module-dropdown">
            <button class="dropdown-trigger" type="button">
                <?php if ($activeModule): ?>
                    <img src="<?= htmlspecialchars($activeModule['icon']) ?>" alt="" class="dropdown-icon">
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
                       class="dropdown-item <?= ($mod['id'] === $activeModule['id']) ? 'active' : '' ?>">
                        <img src="<?= htmlspecialchars($mod['icon']) ?>" alt="" class="dropdown-icon">
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
                    <strong><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></strong>
                    <small><?= htmlspecialchars($user['email']) ?></small>
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
// AJAX Navigation for smooth page transitions
document.addEventListener('DOMContentLoaded', () => {
    const main = document.getElementById('main-content');
    
    // Handle sidebar link clicks
    document.body.addEventListener('click', (e) => {
        const link = e.target.closest('a.sidebar-link');
        if (!link) return;
        
        e.preventDefault();
        const url = new URL(link.href, window.location.origin);
        
        // Update active state immediately
        document.querySelectorAll('.sidebar-link').forEach(l => l.classList.remove('active'));
        link.classList.add('active');
        
        // Fetch new content
        fetch(url.href, { 
            headers: { 'X-Requested-With': 'XMLHttpRequest' } 
        })
        .then(res => {
            if (!res.ok) throw new Error('Failed to load');
            return res.text();
        })
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newContent = doc.querySelector('#main-content');
            if (newContent) {
                main.innerHTML = newContent.innerHTML;
                window.history.pushState({}, '', url.href);
            }
        })
        .catch(err => {
            console.error('Navigation error:', err);
            // Fallback to full page load
            window.location.href = url.href;
        });
    });
    
    // Handle browser back/forward
    window.addEventListener('popstate', () => {
        window.location.reload();
    });
    
    // Dropdown toggle behavior
    document.querySelectorAll('.dropdown-trigger').forEach(trigger => {
        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const dropdown = trigger.closest('.dropdown');
            const isOpen = dropdown.classList.contains('open');
            
            // Close all dropdowns
            document.querySelectorAll('.dropdown.open').forEach(d => d.classList.remove('open'));
            
            // Toggle this one
            if (!isOpen) {
                dropdown.classList.add('open');
            }
        });
    });
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', () => {
        document.querySelectorAll('.dropdown.open').forEach(d => d.classList.remove('open'));
    });
});
</script>

</body>
</html>
