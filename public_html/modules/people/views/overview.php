<?php
/**
 * People Module - Overview
 */
$moduleId = 'people';
$moduleName = 'People';
$moduleDescription = 'Manage employees, timesheets, and HR operations. Track time, approve submissions, and generate workforce reports.';
$heroImage = '/assets/icons/hero-people.png';

$quickStats = [
    ['label' => 'Employees', 'value' => '24', 'change' => '+2 this month'],
    ['label' => 'Pending Timesheets', 'value' => '5', 'change' => 'Awaiting approval'],
    ['label' => 'Hours This Week', 'value' => '186', 'change' => 'Team total'],
];
?>

<!-- Module Hero -->
<div class="module-hero">
    <div class="module-hero-content">
        <h1 class="module-hero-title"><?= htmlspecialchars($moduleName) ?></h1>
        <p class="module-hero-description"><?= htmlspecialchars($moduleDescription) ?></p>
    </div>
    <img src="<?= htmlspecialchars($heroImage) ?>" alt="" class="module-hero-image">
</div>

<!-- Quick Stats -->
<div class="grid grid-cols-3" style="margin-bottom: var(--space-xl);">
    <?php foreach ($quickStats as $stat): ?>
    <div class="card">
        <div class="card-body" style="text-align: center;">
            <div style="font-size: 32px; font-weight: 600; color: var(--color-primary);">
                <?= htmlspecialchars($stat['value']) ?>
            </div>
            <div style="font-size: 14px; font-weight: 500; margin-top: 4px;">
                <?= htmlspecialchars($stat['label']) ?>
            </div>
            <div style="font-size: 12px; color: var(--color-text-secondary); margin-top: 4px;">
                <?= htmlspecialchars($stat['change']) ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Quick Actions -->
<div class="page-header">
    <h2 class="page-title">Quick Actions</h2>
</div>

<div class="action-cards">
    <a href="?page=enter_time" class="action-card">
        <img src="/assets/icons/icon-timesheet.png" alt="" class="action-card-icon">
        <h3 class="action-card-title">Enter Time</h3>
        <p class="action-card-description">Submit your time entries for the current period.</p>
    </a>
    
    <a href="?page=timesheets" class="action-card">
        <img src="/assets/icons/icon-approvals.png" alt="" class="action-card-icon">
        <h3 class="action-card-title">Timesheets</h3>
        <p class="action-card-description">View and manage timesheets. Approve pending submissions.</p>
    </a>
    
    <a href="?page=employee_directory" class="action-card">
        <img src="/assets/icons/icon-directory.png" alt="" class="action-card-icon">
        <h3 class="action-card-title">Employee Directory</h3>
        <p class="action-card-description">Browse the employee directory and contact information.</p>
    </a>
    
    <a href="?page=reports" class="action-card">
        <img src="/assets/icons/icon-reporting.png" alt="" class="action-card-icon">
        <h3 class="action-card-title">Reports</h3>
        <p class="action-card-description">Generate and export workforce reports.</p>
    </a>
</div>
