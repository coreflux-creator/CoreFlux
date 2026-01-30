<?php
/**
 * Master Admin - Modules Management
 */
$pdo = getDB();
$modules = $pdo->query("SELECT * FROM modules ORDER BY id")->fetchAll();
$adminModules = $pdo->query("SELECT * FROM admin_modules")->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title">Modules</h1>
    <p class="page-subtitle">Manage platform modules and tenant subscriptions</p>
</div>

<div class="grid grid-cols-2">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Platform Modules</h3>
        </div>
        <div class="card-body">
            <table>
                <thead>
                    <tr>
                        <th>Module</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($modules as $m): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($m['name']) ?></strong>
                        </td>
                        <td>
                            <?php if ($m['is_active']): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;">
                                <?= $m['is_active'] ? 'Disable' : 'Enable' ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Tenant Module Subscriptions</h3>
        </div>
        <div class="card-body">
            <p style="color: var(--color-text-secondary); margin-bottom: 16px;">
                Configure which modules each tenant has access to.
            </p>
            <?php
            $tenants = getAllTenants();
            foreach (array_slice($tenants, 0, 5) as $t):
            ?>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--color-border-light);">
                <span><?= htmlspecialchars($t['name']) ?></span>
                <a href="?admin=1&page=tenant_modules&id=<?= $t['id'] ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;">
                    Configure
                </a>
            </div>
            <?php endforeach; ?>
            <?php if (count($tenants) > 5): ?>
            <div style="margin-top: 12px;">
                <a href="?admin=1&page=tenants" style="color: var(--color-accent);">View all tenants →</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
