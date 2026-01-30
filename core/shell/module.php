<?php
/**
 * CoreFlux Module Contract
 * 
 * Each module should provide a manifest that follows this contract.
 * This file defines the expected structure and provides helpers.
 */

/**
 * Module Manifest Structure
 * 
 * A module manifest should return an array with:
 * 
 * return [
 *     'id' => 'accounting',           // Unique module identifier (lowercase, underscores)
 *     'name' => 'Accounting',         // Display name
 *     'icon' => '/assets/icons/icon-accounting.png',
 *     'description' => 'General ledger, AP, AR, and financial reporting.',
 *     
 *     // Navigation items for the sidebar
 *     'navItems' => [
 *         [
 *             'name' => 'Overview',
 *             'route' => 'overview',
 *             'icon' => 'icon-dashboard.png',    // Optional
 *             'permission' => 'accounting.view', // Optional - for RBAC
 *             'feature_flag' => 'accounting_enabled', // Optional
 *         ],
 *         [
 *             'name' => 'Journal Entries',
 *             'route' => 'journal_entries',
 *             'children' => [                    // Optional - sub-navigation
 *                 ['name' => 'New Entry', 'route' => 'journal_new'],
 *                 ['name' => 'Pending', 'route' => 'journal_pending'],
 *             ],
 *         ],
 *     ],
 *     
 *     // Hero configuration for the module overview page
 *     'hero' => [
 *         'eyebrow' => 'Financial Management',
 *         'title' => 'Accounting',
 *         'subtitle' => 'Manage your general ledger, accounts payable, and reporting.',
 *         'actions' => [
 *             ['label' => 'New Entry', 'href' => '?page=journal_new', 'primary' => true],
 *         ],
 *     ],
 *     
 *     // Feature cards for the overview page
 *     'features' => [
 *         [
 *             'title' => 'General Ledger',
 *             'description' => 'Chart of accounts, journal entries, trial balance.',
 *             'icon' => '/assets/icons/icon-gl.png',
 *             'href' => '?page=general_ledger',
 *         ],
 *     ],
 *     
 *     // API endpoints exposed by this module
 *     'api' => [
 *         'prefix' => '/api/accounting',
 *         'endpoints' => [
 *             'GET /accounts' => 'List accounts',
 *             'POST /journal' => 'Create journal entry',
 *         ],
 *     ],
 *     
 *     // Permissions declared by this module
 *     'permissions' => [
 *         'accounting.view' => 'View Accounting module',
 *         'accounting.journal.create' => 'Create journal entries',
 *     ],
 * ];
 */

/**
 * Load a module manifest
 * 
 * @param string $moduleId
 * @return array|null
 */
function cfLoadModuleManifest(string $moduleId): ?array {
    $manifestPath = __DIR__ . "/../../modules/{$moduleId}/manifest.php";
    
    if (!file_exists($manifestPath)) {
        return null;
    }
    
    $manifest = require $manifestPath;
    
    if (!is_array($manifest) || empty($manifest['id'])) {
        return null;
    }
    
    return $manifest;
}

/**
 * Get all registered module manifests
 * 
 * @return array
 */
function cfGetAllModuleManifests(): array {
    $modulesDir = __DIR__ . '/../../modules';
    $manifests = [];
    
    if (!is_dir($modulesDir)) {
        return $manifests;
    }
    
    $dirs = glob($modulesDir . '/*', GLOB_ONLYDIR);
    
    foreach ($dirs as $dir) {
        $manifestPath = $dir . '/manifest.php';
        if (file_exists($manifestPath)) {
            $manifest = require $manifestPath;
            if (is_array($manifest) && !empty($manifest['id'])) {
                $manifests[$manifest['id']] = $manifest;
            }
        }
    }
    
    return $manifests;
}

/**
 * Render module shell with standard layout
 * 
 * @param array $manifest - Module manifest
 * @param string $page - Current page/view
 * @param array $shellProps - Additional shell properties
 * @param callable $content - Content callback
 */
function cfRenderModuleShell(array $manifest, string $page, array $shellProps, callable $content): void {
    // Include components
    require_once __DIR__ . '/header.php';
    require_once __DIR__ . '/sidebar.php';
    require_once __DIR__ . '/events.php';
    require_once __DIR__ . '/../components/ui.php';
    
    // Merge shell props with manifest
    $navItems = $manifest['navItems'] ?? [];
    $moduleName = $manifest['name'] ?? '';
    $moduleIcon = $manifest['icon'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($shellProps['pageTitle'] ?? $moduleName) ?> | <?= htmlspecialchars($shellProps['tenant'] ?? 'CoreFlux') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/coreflux.css">
</head>
<body>

<div class="cf-shell">
    <?php cfShellHeader([
        'moduleName' => $moduleName,
        'modules' => $shellProps['modules'] ?? [],
        'activeModule' => $shellProps['activeModule'] ?? null,
        'tenant' => $shellProps['tenant'] ?? '',
        'tenants' => $shellProps['tenants'] ?? [],
        'user' => $shellProps['user'] ?? [],
        'showAdminLink' => $shellProps['showAdminLink'] ?? false,
        'isAdminMode' => $shellProps['isAdminMode'] ?? false,
    ]); ?>
    
    <div class="cf-shell-body">
        <?php cfShellSidebar([
            'title' => $moduleName,
            'navItems' => $navItems,
            'activePage' => $page,
        ]); ?>
        
        <main class="cf-shell-main" id="main-content">
            <?php $content(); ?>
        </main>
    </div>
</div>

<?php cfEventSystem(); ?>

</body>
</html>
<?php
}

/**
 * Render module overview page using manifest config
 * 
 * @param array $manifest
 */
function cfRenderModuleOverview(array $manifest): void {
    $hero = $manifest['hero'] ?? [];
    $features = $manifest['features'] ?? [];
    
    // Render hero
    if (!empty($hero)) {
        cfPageHero([
            'icon' => $manifest['icon'] ?? null,
            'eyebrow' => $hero['eyebrow'] ?? null,
            'title' => $hero['title'] ?? $manifest['name'] ?? '',
            'subtitle' => $hero['subtitle'] ?? $manifest['description'] ?? '',
            'actions' => $hero['actions'] ?? [],
        ]);
    }
    
    // Render features grid
    if (!empty($features)) {
        cfSection(['title' => 'Quick Actions'], function() use ($features) {
            cfFeatureGrid(function() use ($features) {
                foreach ($features as $feature) {
                    cfFeatureCard($feature);
                }
            });
        });
    }
}
