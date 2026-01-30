<?php
/**
 * Generic Module Overview
 * Displays module hero and available actions
 * Used when a specific view doesn't exist
 */

$moduleId = $activeModule['id'] ?? 'people';
$moduleName = $activeModule['name'] ?? 'Module';
$moduleDescription = $activeModule['description'] ?? '';
$moduleIcon = $activeModule['icon'] ?? '/assets/icons/icon-people.png';

// Get hero image if exists
$heroImage = "/assets/icons/hero-{$moduleId}.png";
if (!file_exists(__DIR__ . "/../../assets/icons/hero-{$moduleId}.png")) {
    $heroImage = "/assets/icons/hero-illustration.png";
}

// Get actions for this module
$actions = $moduleActions ?? [];
?>

<!-- Module Hero -->
<div class="module-hero">
    <div class="module-hero-content">
        <h1 class="module-hero-title"><?= htmlspecialchars($moduleName) ?></h1>
        <p class="module-hero-description"><?= htmlspecialchars($moduleDescription) ?></p>
    </div>
    <img src="<?= htmlspecialchars($heroImage) ?>" alt="" class="module-hero-image">
</div>

<!-- Quick Actions -->
<div class="page-header">
    <h2 class="page-title">Quick Actions</h2>
    <p class="page-subtitle">Select an action to get started</p>
</div>

<div class="action-cards">
    <?php foreach ($actions as $action): ?>
        <?php if ($action['route'] !== 'overview'): ?>
        <a href="?page=<?= urlencode($action['route']) ?>" class="action-card">
            <h3 class="action-card-title"><?= htmlspecialchars($action['name']) ?></h3>
            <p class="action-card-description">
                <?php
                // Generate description based on action name
                $descriptions = [
                    'enter_time' => 'Submit your time entries for the current period.',
                    'timesheets' => 'View and manage timesheets. Approve pending submissions.',
                    'employee_directory' => 'Browse the employee directory and contact information.',
                    'reports' => 'Generate and export reports for analysis.',
                    'hiring_pipeline' => 'Manage candidates and track hiring progress.',
                    'chart_of_accounts' => 'View and manage the chart of accounts.',
                    'journal_entries' => 'Create and post journal entries.',
                    'accounts_payable' => 'Manage vendor invoices and payments.',
                    'accounts_receivable' => 'Track customer invoices and receipts.',
                    'budgets' => 'Create and manage budgets.',
                    'forecasts' => 'Build financial forecasts and projections.',
                ];
                echo htmlspecialchars($descriptions[$action['route']] ?? 'Access ' . $action['name'] . ' functionality.');
                ?>
            </p>
        </a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<?php if (empty($actions) || count($actions) <= 1): ?>
<div class="empty-state">
    <h3 class="empty-state-title">No Actions Available</h3>
    <p class="empty-state-description">This module is being set up. Check back soon for available actions.</p>
</div>
<?php endif; ?>
