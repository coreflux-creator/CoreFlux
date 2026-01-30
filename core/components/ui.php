<?php
/**
 * CoreFlux UI Components
 * Reusable primitives for all modules
 */

/**
 * Page Hero Component
 * The prominent header section with gradient background
 * 
 * Usage:
 * <?php cfPageHero([
 *     'icon' => '/assets/icons/icon-accounting.png',
 *     'eyebrow' => 'Module',
 *     'title' => 'Accounting',
 *     'subtitle' => 'Manage your general ledger, accounts payable, and financial reporting.',
 *     'actions' => [
 *         ['label' => 'New Entry', 'href' => '?page=new', 'primary' => true],
 *         ['label' => 'Export', 'href' => '#', 'primary' => false],
 *     ],
 * ]); ?>
 */
function cfPageHero(array $props): void {
    $icon = $props['icon'] ?? null;
    $eyebrow = $props['eyebrow'] ?? null;
    $title = $props['title'] ?? '';
    $subtitle = $props['subtitle'] ?? '';
    $actions = $props['actions'] ?? [];
?>
<div class="cf-page-hero">
    <div class="cf-page-hero-content">
        <?php if ($icon): ?>
        <img src="<?= htmlspecialchars($icon) ?>" alt="" class="cf-page-hero-icon">
        <?php endif; ?>
        <div class="cf-page-hero-text">
            <?php if ($eyebrow): ?>
            <div class="cf-page-hero-eyebrow"><?= htmlspecialchars($eyebrow) ?></div>
            <?php endif; ?>
            <h1 class="cf-page-hero-title"><?= htmlspecialchars($title) ?></h1>
            <?php if ($subtitle): ?>
            <p class="cf-page-hero-subtitle"><?= htmlspecialchars($subtitle) ?></p>
            <?php endif; ?>
            <?php if (!empty($actions)): ?>
            <div class="cf-page-hero-actions">
                <?php foreach ($actions as $action): ?>
                <a href="<?= htmlspecialchars($action['href'] ?? '#') ?>" 
                   class="cf-btn <?= !empty($action['primary']) ? 'cf-btn-primary cf-btn-hero' : 'cf-btn-secondary cf-btn-hero' ?>">
                    <?= htmlspecialchars($action['label']) ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
}

/**
 * Feature Grid Container
 */
function cfFeatureGrid(callable $content): void {
    echo '<div class="cf-feature-grid">';
    $content();
    echo '</div>';
}

/**
 * Feature Card Component
 * 
 * Usage:
 * <?php cfFeatureCard([
 *     'href' => '?page=general_ledger',
 *     'icon' => '/assets/icons/icon-gl.png',
 *     'title' => 'General Ledger',
 *     'description' => 'Chart of accounts, journal entries, and trial balance.',
 *     'badge' => '3 pending',
 * ]); ?>
 */
function cfFeatureCard(array $props): void {
    $href = $props['href'] ?? '#';
    $icon = $props['icon'] ?? null;
    $title = $props['title'] ?? '';
    $description = $props['description'] ?? '';
    $badge = $props['badge'] ?? null;
?>
<a href="<?= htmlspecialchars($href) ?>" class="cf-feature-card">
    <?php if ($icon): ?>
    <img src="<?= htmlspecialchars($icon) ?>" alt="" class="cf-feature-card-icon">
    <?php endif; ?>
    <h3 class="cf-feature-card-title"><?= htmlspecialchars($title) ?></h3>
    <p class="cf-feature-card-description"><?= htmlspecialchars($description) ?></p>
    <?php if ($badge): ?>
    <span class="cf-feature-card-badge"><?= htmlspecialchars($badge) ?></span>
    <?php endif; ?>
</a>
<?php
}

/**
 * Section Component
 * 
 * Usage:
 * <?php cfSection(['title' => 'Recent Activity', 'subtitle' => 'Last 7 days'], function() { ?>
 *     <!-- section content -->
 * <?php }); ?>
 */
function cfSection(array $props, callable $content): void {
    $title = $props['title'] ?? '';
    $subtitle = $props['subtitle'] ?? '';
    $actions = $props['actions'] ?? [];
?>
<section class="cf-section">
    <?php if ($title || !empty($actions)): ?>
    <div class="cf-section-header">
        <div>
            <?php if ($title): ?>
            <h2 class="cf-section-title"><?= htmlspecialchars($title) ?></h2>
            <?php endif; ?>
            <?php if ($subtitle): ?>
            <p class="cf-section-subtitle"><?= htmlspecialchars($subtitle) ?></p>
            <?php endif; ?>
        </div>
        <?php if (!empty($actions)): ?>
        <div class="cf-section-actions cf-flex cf-gap-2">
            <?php foreach ($actions as $action): ?>
            <a href="<?= htmlspecialchars($action['href'] ?? '#') ?>" 
               class="cf-btn <?= !empty($action['primary']) ? 'cf-btn-primary' : 'cf-btn-secondary' ?> cf-btn-sm">
                <?= htmlspecialchars($action['label']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php $content(); ?>
</section>
<?php
}

/**
 * Card Component
 */
function cfCard(array $props, callable $content): void {
    $title = $props['title'] ?? '';
    $actions = $props['actions'] ?? [];
    $footer = $props['footer'] ?? null;
?>
<div class="cf-card">
    <?php if ($title || !empty($actions)): ?>
    <div class="cf-card-header">
        <h3 class="cf-card-title"><?= htmlspecialchars($title) ?></h3>
        <?php if (!empty($actions)): ?>
        <div class="cf-flex cf-gap-2">
            <?php foreach ($actions as $action): ?>
            <a href="<?= htmlspecialchars($action['href'] ?? '#') ?>" 
               class="cf-btn cf-btn-sm <?= !empty($action['primary']) ? 'cf-btn-primary' : 'cf-btn-secondary' ?>">
                <?= htmlspecialchars($action['label']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="cf-card-body">
        <?php $content(); ?>
    </div>
    <?php if ($footer): ?>
    <div class="cf-card-footer">
        <?php $footer(); ?>
    </div>
    <?php endif; ?>
</div>
<?php
}

/**
 * Empty State Component
 */
function cfEmptyState(array $props): void {
    $icon = $props['icon'] ?? null;
    $title = $props['title'] ?? 'No data';
    $description = $props['description'] ?? '';
    $action = $props['action'] ?? null;
?>
<div class="cf-empty-state">
    <?php if ($icon): ?>
    <img src="<?= htmlspecialchars($icon) ?>" alt="" class="cf-empty-state-icon">
    <?php endif; ?>
    <h3 class="cf-empty-state-title"><?= htmlspecialchars($title) ?></h3>
    <?php if ($description): ?>
    <p class="cf-empty-state-description"><?= htmlspecialchars($description) ?></p>
    <?php endif; ?>
    <?php if ($action): ?>
    <a href="<?= htmlspecialchars($action['href'] ?? '#') ?>" class="cf-btn cf-btn-primary">
        <?= htmlspecialchars($action['label']) ?>
    </a>
    <?php endif; ?>
</div>
<?php
}

/**
 * Badge Component
 */
function cfBadge(string $text, string $variant = 'info'): void {
    echo '<span class="cf-badge cf-badge-' . htmlspecialchars($variant) . '">' . htmlspecialchars($text) . '</span>';
}

/**
 * Stats Card (for dashboards)
 */
function cfStatCard(array $props): void {
    $value = $props['value'] ?? '0';
    $label = $props['label'] ?? '';
    $change = $props['change'] ?? null;
    $changeType = $props['changeType'] ?? 'neutral'; // positive, negative, neutral
?>
<div class="cf-card">
    <div class="cf-card-body cf-text-center">
        <div class="cf-stat-value"><?= htmlspecialchars($value) ?></div>
        <div class="cf-stat-label"><?= htmlspecialchars($label) ?></div>
        <?php if ($change): ?>
        <div class="cf-stat-change cf-stat-change-<?= $changeType ?>">
            <?= htmlspecialchars($change) ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php
}
