<?php
/**
 * Master Admin - Permissions
 */
$pdo = getDB();
$permissions = $pdo->query("SELECT * FROM permissions ORDER BY slug")->fetchAll();
$roles = $pdo->query("SELECT * FROM roles ORDER BY name")->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title">Permissions</h1>
    <p class="page-subtitle">Manage roles and permissions</p>
</div>

<div class="grid grid-cols-2">
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 class="card-title">Permissions</h3>
            <button class="btn btn-secondary" style="font-size: 12px;">+ Add</button>
        </div>
        <div class="card-body">
            <?php if (empty($permissions)): ?>
                <p style="color: var(--color-text-secondary);">No permissions defined yet.</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Slug</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($permissions as $p): ?>
                    <tr>
                        <td><code style="font-size: 12px;"><?= htmlspecialchars($p['slug']) ?></code></td>
                        <td><small><?= htmlspecialchars($p['description']) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 class="card-title">Roles</h3>
            <button class="btn btn-secondary" style="font-size: 12px;">+ Add</button>
        </div>
        <div class="card-body">
            <?php if (empty($roles)): ?>
                <p style="color: var(--color-text-secondary);">No custom roles defined. Using default roles:</p>
                <ul style="margin-top: 12px; color: var(--color-text-secondary); font-size: 13px;">
                    <li><strong>master_admin</strong> - Full platform access</li>
                    <li><strong>tenant_admin</strong> - Full tenant access</li>
                    <li><strong>admin</strong> - Administrative access</li>
                    <li><strong>manager</strong> - Team management access</li>
                    <li><strong>employee</strong> - Basic access</li>
                </ul>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Slug</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['name']) ?></td>
                        <td><code><?= htmlspecialchars($r['slug']) ?></code></td>
                        <td>
                            <button class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;">Edit</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>
