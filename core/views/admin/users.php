<?php
/**
 * Master Admin - Users Management
 */
$allUsers = getAllUsers();
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: flex-start;">
    <div>
        <h1 class="page-title">Users</h1>
        <p class="page-subtitle"><?= count($allUsers) ?> total users</p>
    </div>
    <button class="btn btn-primary" onclick="alert('Create user form coming soon')">+ New User</button>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Email</th>
                    <th>Global Role</th>
                    <th>Tenants</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allUsers as $u): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                        <?php
                        $roleBadge = match($u['role']) {
                            'master_admin' => 'badge-danger',
                            'admin', 'tenant_admin' => 'badge-warning',
                            default => 'badge-info'
                        };
                        ?>
                        <span class="badge <?= $roleBadge ?>"><?= htmlspecialchars($u['role']) ?></span>
                    </td>
                    <td>
                        <small><?= htmlspecialchars($u['tenant_names'] ?: 'None') ?></small>
                    </td>
                    <td>
                        <?php if ($u['is_active']): ?>
                            <span class="badge badge-success">Active</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="?admin=1&page=user_edit&id=<?= $u['id'] ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;">Edit</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
