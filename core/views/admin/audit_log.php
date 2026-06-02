<?php
/**
 * Master Admin - Audit Log
 *
 * Reads from the canonical `audit_log` schema:
 *   (id, tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)
 *
 * Legacy installs that still have the old `entity`/`action` columns are
 * supported because migration 097_audit_log_event_column.sql backfills
 * `event` from `action` and adds `target_id` when missing. We fall back
 * to those columns at read-time as a belt-and-braces measure.
 */
$pdo = getDB();
$logs = $pdo->query("
    SELECT al.id,
           al.tenant_id,
           COALESCE(al.actor_user_id, al.user_id) AS user_id,
           COALESCE(NULLIF(al.event,''), al.action) AS event,
           COALESCE(al.target_id, al.entity_id)    AS target_id,
           al.meta_json,
           al.created_at,
           u.name as user_name,
           t.name as tenant_name
    FROM audit_log al
    LEFT JOIN users u ON COALESCE(al.actor_user_id, al.user_id) = u.id
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
                    <th>Target</th>
                    <th>Event</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= date('M j, Y H:i:s', strtotime($log['created_at'])) ?></td>
                    <td><?= htmlspecialchars($log['user_name'] ?? 'System') ?></td>
                    <td><?= htmlspecialchars($log['tenant_name'] ?? '-') ?></td>
                    <td>
                        <?php if (!empty($log['target_id'])): ?>
                            <code style="font-size: 12px;">#<?= (int) $log['target_id'] ?></code>
                        <?php else: ?>
                            <span style="color:#94a3b8">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-info"><?= htmlspecialchars((string) ($log['event'] ?? '')) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
