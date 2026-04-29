<?php
/**
 * People Module - Timesheets List
 */
$userRole = $user['role'] ?? 'employee';
$isManager = in_array($userRole, ['admin', 'manager', 'tenant_admin']);

// Demo data
$timesheets = [
    ['id' => 1, 'employee' => 'John Smith', 'period' => 'Jan 20 - Jan 26, 2026', 'hours' => 40, 'status' => 'pending', 'submitted' => '2026-01-24'],
    ['id' => 2, 'employee' => 'Sarah Johnson', 'period' => 'Jan 20 - Jan 26, 2026', 'hours' => 38.5, 'status' => 'approved', 'submitted' => '2026-01-23'],
    ['id' => 3, 'employee' => 'Mike Davis', 'period' => 'Jan 20 - Jan 26, 2026', 'hours' => 42, 'status' => 'pending', 'submitted' => '2026-01-24'],
    ['id' => 4, 'employee' => 'Emily Chen', 'period' => 'Jan 13 - Jan 19, 2026', 'hours' => 40, 'status' => 'approved', 'submitted' => '2026-01-17'],
    ['id' => 5, 'employee' => 'Alex Wilson', 'period' => 'Jan 13 - Jan 19, 2026', 'hours' => 36, 'status' => 'rejected', 'submitted' => '2026-01-17'],
];

$statusColors = [
    'pending' => 'warning',
    'approved' => 'success',
    'rejected' => 'danger',
    'draft' => 'info',
];
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: flex-start;">
    <div>
        <h1 class="page-title">Timesheets</h1>
        <p class="page-subtitle"><?= $isManager ? 'Review and approve team timesheets' : 'View your submitted timesheets' ?></p>
    </div>
    <div>
        <button class="btn btn-secondary" style="margin-right: 8px;">Export</button>
        <a href="?page=enter_time" class="btn btn-primary">+ New Entry</a>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-body" style="display: flex; gap: 16px; align-items: center;">
        <div class="form-group" style="margin-bottom: 0; flex: 1;">
            <input type="text" class="form-input" placeholder="Search by employee name...">
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <select class="form-select">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <select class="form-select">
                <option value="">All Periods</option>
                <option value="current">Current Week</option>
                <option value="last">Last Week</option>
                <option value="month">This Month</option>
            </select>
        </div>
    </div>
</div>

<!-- Timesheets Table -->
<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <?php if ($isManager): ?>
                    <th>Employee</th>
                    <?php endif; ?>
                    <th>Period</th>
                    <th>Hours</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th style="width: 150px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($timesheets as $ts): ?>
                <tr>
                    <?php if ($isManager): ?>
                    <td>
                        <strong><?= htmlspecialchars($ts['employee']) ?></strong>
                    </td>
                    <?php endif; ?>
                    <td><?= htmlspecialchars($ts['period']) ?></td>
                    <td><?= htmlspecialchars($ts['hours']) ?></td>
                    <td>
                        <span class="badge badge-<?= $statusColors[$ts['status']] ?>">
                            <?= ucfirst($ts['status']) ?>
                        </span>
                    </td>
                    <td><?= date('M j, Y', strtotime($ts['submitted'])) ?></td>
                    <td>
                        <a href="?page=view_timesheet&id=<?= $ts['id'] ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;">View</a>
                        <?php if ($isManager && $ts['status'] === 'pending'): ?>
                        <button class="btn btn-primary" style="padding: 4px 8px; font-size: 12px;" onclick="approveTimesheet(<?= $ts['id'] ?>)">Approve</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function approveTimesheet(id) {
    if (confirm('Approve this timesheet?')) {
        alert('Timesheet #' + id + ' approved!');
        location.reload();
    }
}
</script>
