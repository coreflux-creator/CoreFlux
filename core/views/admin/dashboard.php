<?php
/**
 * Master Admin Dashboard
 */
$stats = getMasterAdminStats();
?>

<div class="page-header">
    <h1 class="page-title">Master Admin Dashboard</h1>
    <p class="page-subtitle">Platform-wide administration and monitoring</p>
</div>

<!-- Stats Grid -->
<div class="grid grid-cols-4" style="margin-bottom: 32px;">
    <div class="card">
        <div class="card-body" style="text-align: center;">
            <div style="font-size: 36px; font-weight: 700; color: var(--color-primary);">
                <?= $stats['total_tenants'] ?? 0 ?>
            </div>
            <div style="font-size: 14px; color: var(--color-text-secondary); margin-top: 4px;">
                Tenants
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-body" style="text-align: center;">
            <div style="font-size: 36px; font-weight: 700; color: var(--color-primary);">
                <?= $stats['total_users'] ?? 0 ?>
            </div>
            <div style="font-size: 14px; color: var(--color-text-secondary); margin-top: 4px;">
                Active Users
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-body" style="text-align: center;">
            <div style="font-size: 36px; font-weight: 700; color: var(--color-primary);">
                <?= $stats['active_modules'] ?? 0 ?>
            </div>
            <div style="font-size: 14px; color: var(--color-text-secondary); margin-top: 4px;">
                Active Modules
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-body" style="text-align: center;">
            <div style="font-size: 36px; font-weight: 700; color: var(--color-primary);">
                <?= $stats['total_employees'] ?? 0 ?>
            </div>
            <div style="font-size: 14px; color: var(--color-text-secondary); margin-top: 4px;">
                Employees
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="grid grid-cols-2">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Quick Actions</h3>
        </div>
        <div class="card-body">
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <a href="?admin=1&page=tenants" class="action-link">
                    <strong>Manage Tenants</strong>
                    <span>Create, edit, or configure tenant settings</span>
                </a>
                <a href="?admin=1&page=users" class="action-link">
                    <strong>Manage Users</strong>
                    <span>Add users, assign roles and tenants</span>
                </a>
                <a href="?admin=1&page=modules" class="action-link">
                    <strong>Configure Modules</strong>
                    <span>Enable/disable modules per tenant</span>
                </a>
                <a href="?admin=1&page=settings" class="action-link">
                    <strong>Global Settings</strong>
                    <span>Platform-wide configuration</span>
                </a>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">System Status</h3>
        </div>
        <div class="card-body">
            <div style="display: flex; flex-direction: column; gap: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span>Database</span>
                    <span class="badge badge-success">Connected</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span>Email Service</span>
                    <span class="badge badge-success">Active</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span>Background Jobs</span>
                    <span class="badge badge-info">0 pending</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span>Last Backup</span>
                    <span style="color: var(--color-text-secondary);">N/A</span>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.action-link {
    display: flex;
    flex-direction: column;
    padding: 12px;
    border-radius: 6px;
    transition: background 0.15s ease;
}
.action-link:hover {
    background: var(--color-bg);
}
.action-link strong {
    font-size: 14px;
    color: var(--color-text);
}
.action-link span {
    font-size: 12px;
    color: var(--color-text-secondary);
    margin-top: 2px;
}
</style>
