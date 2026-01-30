<?php
/**
 * Master Admin - Tenants Management
 */
$allTenants = getAllTenants();

// Separate into primary and sub-tenants
$primaryTenants = array_filter($allTenants, fn($t) => empty($t['parent_id']));
$subTenants = array_filter($allTenants, fn($t) => !empty($t['parent_id']));
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: flex-start;">
    <div>
        <h1 class="page-title">Tenants</h1>
        <p class="page-subtitle"><?= count($allTenants) ?> total tenants (<?= count($primaryTenants) ?> primary, <?= count($subTenants) ?> sub-tenants)</p>
    </div>
    <button class="btn btn-primary" onclick="alert('Create tenant form coming soon')">+ New Tenant</button>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Tenant</th>
                    <th>Type</th>
                    <th>Domain</th>
                    <th>Users</th>
                    <th>Sub-tenants</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allTenants as $t): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($t['name']) ?></strong>
                        <?php if ($t['subdomain']): ?>
                            <br><small style="color: var(--color-text-secondary);"><?= htmlspecialchars($t['subdomain']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (empty($t['parent_id'])): ?>
                            <span class="badge badge-info">Primary</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Sub-tenant</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($t['domain'] ?: '-') ?></td>
                    <td><?= $t['user_count'] ?? 0 ?></td>
                    <td><?= $t['sub_tenant_count'] ?? 0 ?></td>
                    <td><?= date('M j, Y', strtotime($t['created_at'])) ?></td>
                    <td>
                        <a href="?admin=1&page=tenant_edit&id=<?= $t['id'] ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;">Edit</a>
                        <a href="?admin=1&page=tenant_modules&id=<?= $t['id'] ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;">Modules</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
