<?php
/**
 * Module Overview Page Template
 * 
 * This is the default landing page when users access the module.
 * It displays the hero section and feature cards from the manifest.
 */

// Load the manifest
$manifest = require __DIR__ . '/../manifest.php';

// Include core UI components
require_once __DIR__ . '/../../../core/components/ui.php';

// Access global variables from dashboard.php
global $user, $tenant, $tenantId, $tenantRole;
?>

<!-- Page Hero -->
<?php cfPageHero($manifest['hero']); ?>

<!-- Feature Cards -->
<section class="cf-section">
    <div class="cf-section-header">
        <h2>Quick Actions</h2>
    </div>
    
    <div class="cf-feature-grid">
        <?php foreach ($manifest['features'] as $feature): ?>
            <?php cfFeatureCard($feature); ?>
        <?php endforeach; ?>
    </div>
</section>

<!-- Recent Activity (Example) -->
<section class="cf-section cf-mt-8">
    <div class="cf-section-header">
        <h2>Recent Activity</h2>
        <a href="?page=activity" class="cf-link">View All</a>
    </div>
    
    <?php
    cfCard(['title' => 'Activity Feed'], function() {
        cfEmptyState([
            'title' => 'No recent activity',
            'description' => 'Activity will appear here as you use the module.',
            'icon' => '/assets/icons/icon-activity.png',
        ]);
    });
    ?>
</section>
