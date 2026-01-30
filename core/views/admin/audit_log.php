<?php
/**
 * Master Admin - Audit Log
 */
$pdo = getDB();
$logs = $pdo->query("
    SELECT al.*, u.name as user_name, t.name as tenant_name
    FROM audit_log al
    LEFT JOIN users u ON al.user_id = u.id
    LEFT JOIN tenants t ON al.tenant_id = t.id
    ORDER BY al.created_at DESC
    LIMIT 50
")->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title">Audit Log</h1>
    <p class="page-subtitle">Platform activity history</p>
</div>

<div class="card">
    <?php if (empty($logs)): ?>
    <div class="card-body">
        <div class="empty-state">
            <h3 class="empty-state-title">No audit logs yet</h3>
            <p class="empty-state-description">Activity will be recorded here as users interact with the platform.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Tenant</th>
                    <th>Entity</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= date('M j, Y H:i:s', strtotime($log['created_at'])) ?></td>
                    <td><?= htmlspecialchars($log['user_name'] ?? 'System') ?></td>
                    <td><?= htmlspecialchars($log['tenant_name'] ?? '-') ?></td>
                    <td>
                        <code style="font-size: 12px;"><?= htmlspecialchars($log['entity']) ?></code>
                        <?php if ($log['entity_id']): ?>
                            <small>#<?= $log['entity_id'] ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($log['action']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
