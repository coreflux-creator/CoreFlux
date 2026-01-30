<?php
/**
 * CoreFlux Shell - Header Component
 * 
 * Usage:
 * <?php cfShellHeader([
 *     'logo' => '/assets/icons/logo-white.png',
 *     'moduleName' => 'Accounting',
 *     'modules' => $modules,
 *     'activeModule' => $activeModule,
 *     'tenant' => $tenant,
 *     'tenants' => $tenants,
 *     'user' => $user,
 *     'showAdminLink' => true,
 * ]); ?>
 */

function cfShellHeader(array $props): void {
    $logo = $props['logo'] ?? '/assets/icons/logo-white.png';
    $moduleName = $props['moduleName'] ?? '';
    $modules = $props['modules'] ?? [];
    $activeModule = $props['activeModule'] ?? null;
    $tenant = $props['tenant'] ?? '';
    $tenants = $props['tenants'] ?? [];
    $user = $props['user'] ?? [];
    $showAdminLink = $props['showAdminLink'] ?? false;
    $isAdminMode = $props['isAdminMode'] ?? false;
?>
<header class="cf-shell-header">
    <div class="cf-header-left">
        <a href="/dashboard.php" class="cf-logo-link">
            <img src="<?= htmlspecialchars($logo) ?>" alt="CoreFlux" class="cf-header-logo">
        </a>
        <?php if ($isAdminMode): ?>
            <span class="cf-admin-badge">Master Admin</span>
        <?php endif; ?>
    </div>
    
    <div class="cf-header-center">
        <?php if (!$isAdminMode && !empty($modules)): ?>
        <div class="cf-dropdown" id="module-dropdown">
            <button class="cf-dropdown-trigger cf-module-trigger" type="button" onclick="toggleDropdown('module-dropdown')">
                <?php if ($activeModule): ?>
                    <img src="<?= htmlspecialchars($activeModule['icon'] ?? '') ?>" alt="" class="cf-dropdown-icon" onerror="this.style.display='none'">
                    <span><?= htmlspecialchars($activeModule['name'] ?? $moduleName) ?></span>
                <?php else: ?>
                    <span>Select Module</span>
                <?php endif; ?>
                <svg class="cf-caret" viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            </button>
            <div class="cf-dropdown-menu">
                <?php foreach ($modules as $mod): ?>
                    <a href="?module=<?= urlencode($mod['id']) ?>" 
                       class="cf-dropdown-item <?= ($mod['id'] === ($activeModule['id'] ?? '')) ? 'active' : '' ?>">
                        <img src="<?= htmlspecialchars($mod['icon'] ?? '') ?>" alt="" class="cf-dropdown-icon" onerror="this.style.display='none'">
                        <span><?= htmlspecialchars($mod['name']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="cf-header-right">
        <?php if ($showAdminLink): ?>
        <a href="<?= $isAdminMode ? '/dashboard.php' : '/dashboard.php?admin=1' ?>" 
           class="cf-btn cf-btn-header <?= $isAdminMode ? 'cf-btn-active' : '' ?>">
            <?= $isAdminMode ? 'Exit Admin' : 'Admin Panel' ?>
        </a>
        <?php endif; ?>
        
        <?php if (!$isAdminMode && count($tenants) > 1): ?>
        <div class="cf-dropdown" id="tenant-dropdown">
            <button class="cf-dropdown-trigger" type="button" onclick="toggleDropdown('tenant-dropdown')">
                <span><?= htmlspecialchars($tenant) ?></span>
                <svg class="cf-caret" viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            </button>
            <div class="cf-dropdown-menu">
                <?php foreach ($tenants as $t): ?>
                    <a href="?switch_tenant=<?= urlencode($t['id']) ?>" 
                       class="cf-dropdown-item <?= ($t['name'] === $tenant) ? 'active' : '' ?>">
                        <?= htmlspecialchars($t['name']) ?>
                        <?php if (!empty($t['parent_id'])): ?>
                            <small class="cf-text-muted">(sub)</small>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php elseif (!$isAdminMode): ?>
        <span class="cf-tenant-name"><?= htmlspecialchars($tenant) ?></span>
        <?php endif; ?>
        
        <div class="cf-dropdown" id="user-dropdown">
            <button class="cf-dropdown-trigger cf-user-trigger" type="button" onclick="toggleDropdown('user-dropdown')">
                <span class="cf-user-avatar">
                    <?= strtoupper(substr($user['first_name'] ?? 'U', 0, 1)) ?>
                </span>
                <span class="cf-user-name"><?= htmlspecialchars($user['first_name'] ?? 'User') ?></span>
                <svg class="cf-caret" viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            </button>
            <div class="cf-dropdown-menu cf-dropdown-right">
                <div class="cf-dropdown-header">
                    <strong><?= htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?></strong>
                    <small><?= htmlspecialchars($user['email'] ?? '') ?></small>
                </div>
                <hr class="cf-dropdown-divider">
                <a href="?page=profile" class="cf-dropdown-item">Profile</a>
                <a href="?page=settings" class="cf-dropdown-item">Settings</a>
                <hr class="cf-dropdown-divider">
                <a href="/logout.php" class="cf-dropdown-item cf-dropdown-item-danger">Logout</a>
            </div>
        </div>
    </div>
</header>
<?php
}
