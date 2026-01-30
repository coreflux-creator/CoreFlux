<?php
/**
 * People Module - Hiring Pipeline (Admin Only)
 */
$isAdmin = in_array($user['role'] ?? '', ['admin', 'tenant_admin']);

if (!$isAdmin) {
    echo '<div class="empty-state"><h3 class="empty-state-title">Access Denied</h3><p class="empty-state-description">You do not have permission to view the hiring pipeline.</p></div>';
    return;
}

// Demo candidates
$stages = [
    'applied' => ['name' => 'Applied', 'color' => '#64748b'],
    'screening' => ['name' => 'Screening', 'color' => '#3b82f6'],
    'interview' => ['name' => 'Interview', 'color' => '#f59e0b'],
    'offer' => ['name' => 'Offer', 'color' => '#10b981'],
    'hired' => ['name' => 'Hired', 'color' => '#059669'],
];

$candidates = [
    ['id' => 1, 'name' => 'Alice Brown', 'position' => 'Senior Developer', 'stage' => 'interview', 'applied' => '2026-01-15'],
    ['id' => 2, 'name' => 'Bob Martinez', 'position' => 'Product Designer', 'stage' => 'screening', 'applied' => '2026-01-18'],
    ['id' => 3, 'name' => 'Carol White', 'position' => 'Senior Developer', 'stage' => 'offer', 'applied' => '2026-01-10'],
    ['id' => 4, 'name' => 'David Lee', 'position' => 'Sales Representative', 'stage' => 'applied', 'applied' => '2026-01-22'],
    ['id' => 5, 'name' => 'Eva Garcia', 'position' => 'Marketing Specialist', 'stage' => 'interview', 'applied' => '2026-01-12'],
];
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: flex-start;">
    <div>
        <h1 class="page-title">Hiring Pipeline</h1>
        <p class="page-subtitle"><?= count($candidates) ?> active candidates</p>
    </div>
    <div>
        <button class="btn btn-primary">+ Add Candidate</button>
    </div>
</div>

<!-- Pipeline Board -->
<div class="pipeline-board">
    <?php foreach ($stages as $stageKey => $stage): ?>
    <div class="pipeline-column">
        <div class="pipeline-header" style="border-left: 3px solid <?= $stage['color'] ?>;">
            <span class="pipeline-stage-name"><?= $stage['name'] ?></span>
            <span class="pipeline-count"><?= count(array_filter($candidates, fn($c) => $c['stage'] === $stageKey)) ?></span>
        </div>
        <div class="pipeline-cards">
            <?php foreach ($candidates as $candidate): ?>
                <?php if ($candidate['stage'] === $stageKey): ?>
                <div class="pipeline-card">
                    <div class="pipeline-card-header">
                        <strong><?= htmlspecialchars($candidate['name']) ?></strong>
                    </div>
                    <div class="pipeline-card-body">
                        <p><?= htmlspecialchars($candidate['position']) ?></p>
                        <small>Applied <?= date('M j', strtotime($candidate['applied'])) ?></small>
                    </div>
                    <div class="pipeline-card-actions">
                        <button class="btn btn-secondary" style="font-size: 11px; padding: 2px 8px;">View</button>
                        <button class="btn btn-secondary" style="font-size: 11px; padding: 2px 8px;">Move →</button>
                    </div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<style>
.pipeline-board {
    display: flex;
    gap: 16px;
    overflow-x: auto;
    padding-bottom: 16px;
}

.pipeline-column {
    flex: 0 0 260px;
    background: var(--color-bg);
    border-radius: 8px;
    padding: 12px;
}

.pipeline-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    background: white;
    border-radius: 6px;
    margin-bottom: 12px;
}

.pipeline-stage-name {
    font-weight: 600;
    font-size: 13px;
}

.pipeline-count {
    background: var(--color-border);
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.pipeline-cards {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.pipeline-card {
    background: white;
    border-radius: 6px;
    padding: 12px;
    box-shadow: var(--shadow-sm);
}

.pipeline-card-header strong {
    font-size: 14px;
}

.pipeline-card-body {
    margin-top: 8px;
}

.pipeline-card-body p {
    font-size: 13px;
    color: var(--color-text-secondary);
    margin: 0;
}

.pipeline-card-body small {
    font-size: 11px;
    color: var(--color-text-muted);
}

.pipeline-card-actions {
    margin-top: 12px;
    display: flex;
    gap: 6px;
}
</style>
