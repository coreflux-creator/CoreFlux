<?php
/**
 * CoreFlux Shell - Sidebar/Nav Rail Component
 * 
 * Usage:
 * <?php cfShellSidebar([
 *     'title' => 'Accounting',
 *     'navItems' => [
 *         ['name' => 'Overview', 'route' => 'overview', 'icon' => 'icon-dashboard.png'],
 *         ['name' => 'Journal Entries', 'route' => 'journal_entries', 'icon' => 'icon-journal.png'],
 *     ],
 *     'activePage' => 'overview',
 *     'footerText' => 'CoreFlux v1.0',
 * ]); ?>
 */

function cfShellSidebar(array $props): void {
    $title = $props['title'] ?? '';
    $navItems = $props['navItems'] ?? [];
    $activePage = $props['activePage'] ?? 'overview';
    $footerText = $props['footerText'] ?? 'CoreFlux v1.0';
    $baseUrl = $props['baseUrl'] ?? '?page=';
?>
<aside class="cf-shell-sidebar">
    <?php if ($title): ?>
    <div class="cf-nav-header">
        <h3 class="cf-nav-title"><?= htmlspecialchars($title) ?></h3>
    </div>
    <?php endif; ?>
    
    <nav class="cf-nav-items">
        <?php foreach ($navItems as $item): ?>
            <?php 
            $isActive = ($activePage === $item['route']);
            $href = $baseUrl . urlencode($item['route']);
            $icon = $item['icon'] ?? null;
            ?>
            <a href="<?= $href ?>" 
               class="cf-nav-item <?= $isActive ? 'active' : '' ?>"
               data-page="<?= htmlspecialchars($item['route']) ?>">
                <?php if ($icon): ?>
                <img src="/assets/icons/<?= htmlspecialchars($icon) ?>" alt="" class="cf-nav-item-icon" onerror="this.style.display='none'">
                <?php endif; ?>
                <span><?= htmlspecialchars($item['name']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    
    <div class="cf-nav-footer">
        <span class="cf-nav-footer-text"><?= htmlspecialchars($footerText) ?></span>
    </div>
</aside>
<?php
}
