<?php
/**
 * Master Admin - Edit User
 */
require_once __DIR__ . '/../../memberships.php';
$pdo = getDB();

$userId = (int)($_GET['id'] ?? 0);
$isNew = ($userId === 0);

if (!$isNew) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $editUser = $stmt->fetch();
    
    if (!$editUser) {
        header("Location: ?admin=1&page=users");
        exit;
    }
    
    // Get user's current tenant assignments
    $stmt = $pdo->prepare("SELECT tenant_id, MIN(persona_type) AS role, MAX(is_primary) AS is_default FROM " . membershipReadSourceSql() . " src WHERE src.user_id = ? GROUP BY tenant_id");
    $stmt->execute([$userId]);
    $userTenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $userTenantMap = [];
    foreach ($userTenants as $ut) {
        $userTenantMap[$ut['tenant_id']] = $ut;
    }
} else {
    $editUser = [
        'name' => '',
        'email' => '',
        'role' => 'user',
        'is_active' => 1,
    ];
    $userTenantMap = [];
}

// Get all tenants
$allTenants = $pdo->query("SELECT id, name, parent_id FROM tenants ORDER BY parent_id IS NULL DESC, name")->fetchAll();

// Available roles
$roles = [
    'master_admin' => 'Master Admin (Full platform access)',
    'tenant_admin' => 'Tenant Admin (Full tenant access)',
    'admin' => 'Admin (Administrative access)',
    'manager' => 'Manager (Team management)',
    'employee' => 'Employee (Basic access)',
    'user' => 'User (Minimal access)',
];

$tenantRoles = [
    'tenant_admin' => 'Tenant Admin',
    'admin' => 'Admin',
    'manager' => 'Manager',
    'employee' => 'Employee',
    'approver' => 'Approver',
    'viewer' => 'Viewer',
];

// Handle form submission
$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'user';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $password = $_POST['password'] ?? '';
    $tenantAssignments = $_POST['tenant'] ?? [];
    
    // Validation
    if (empty($name)) $errors[] = "Name is required.";
    if (empty($email)) $errors[] = "Email is required.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format.";
    if ($isNew && empty($password)) $errors[] = "Password is required for new users.";
    
    // Check for duplicate email
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $userId]);
    if ($stmt->fetch()) {
        $errors[] = "This email is already in use.";
    }
    
    if (empty($errors)) {
        if ($isNew) {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $email, $passwordHash, $passwordHash, $role, $isActive]);
            $userId = $pdo->lastInsertId();
        } else {
            if (!empty($password)) {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, password = ?, password_hash = ?, role = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$name, $email, $passwordHash, $passwordHash, $role, $isActive, $userId]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$name, $email, $role, $isActive, $userId]);
            }
        }
        
        // Update tenant assignments — purge then re-provision via the central helper.
        // The helper dual-writes both user_tenants + tenant_memberships so the
        // legacy bridge stays in sync.
        require_once __DIR__ . '/../../memberships.php';
        purgeMembershipsForUser($userId);

        foreach ($tenantAssignments as $tenantId => $assignment) {
            if (!empty($assignment['enabled'])) {
                $tenantRole = $assignment['role'] ?? 'employee';
                $isDefault = !empty($assignment['default']);
                provisionMembership((int) $userId, (int) $tenantId, (string) $tenantRole, [
                    'is_primary'    => $isDefault,
                    'persona_label' => 'Primary',
                    'status'        => 'active',
                ]);
            }
        }
        
        if ($isNew) {
            header("Location: ?admin=1&page=user_edit&id={$userId}&saved=1");
            exit;
        }
        $successMessage = "User updated successfully.";
        
        // Refresh data
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $editUser = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT tenant_id, MIN(persona_type) AS role, MAX(is_primary) AS is_default FROM " . membershipReadSourceSql() . " src WHERE src.user_id = ? GROUP BY tenant_id");
        $stmt->execute([$userId]);
        $userTenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $userTenantMap = [];
        foreach ($userTenants as $ut) {
            $userTenantMap[$ut['tenant_id']] = $ut;
        }
    }
}

if (isset($_GET['saved'])) {
    $successMessage = "User created successfully.";
}
?>

<div class="page-header">
    <div style="display: flex; align-items: center; gap: 12px;">
        <a href="?admin=1&page=users" class="btn btn-secondary" style="padding: 6px 12px;">← Back</a>
        <div>
            <h1 class="page-title"><?= $isNew ? 'Create User' : 'Edit User' ?></h1>
            <?php if (!$isNew): ?>
            <p class="page-subtitle"><?= htmlspecialchars($editUser['email']) ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="background: rgba(239, 68, 68, 0.1); border: 1px solid var(--color-danger); color: var(--color-danger); padding: 12px 16px; border-radius: 6px; margin-bottom: 24px;">
    <ul style="margin: 0; padding-left: 20px;">
        <?php foreach ($errors as $error): ?>
        <li><?= htmlspecialchars($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($successMessage): ?>
<div class="alert alert-success" style="background: rgba(16, 185, 129, 0.1); border: 1px solid var(--color-success); color: var(--color-success); padding: 12px 16px; border-radius: 6px; margin-bottom: 24px;">
    <?= htmlspecialchars($successMessage) ?>
</div>
<?php endif; ?>

<form method="POST">
    <div class="grid grid-cols-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">User Information</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($editUser['name']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email *</label>
                    <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($editUser['email']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label"><?= $isNew ? 'Password *' : 'New Password' ?></label>
                    <input type="password" name="password" class="form-input" <?= $isNew ? 'required' : '' ?>>
                    <?php if (!$isNew): ?>
                    <small style="color: var(--color-text-secondary);">Leave blank to keep current password</small>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Global Role</label>
                    <select name="role" class="form-select">
                        <?php foreach ($roles as $roleKey => $roleLabel): ?>
                        <option value="<?= $roleKey ?>" <?= ($editUser['role'] === $roleKey) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($roleLabel) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: var(--color-text-secondary);">Platform-wide role. Tenant-specific roles are set below.</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" name="is_active" <?= ($editUser['is_active'] ?? 1) ? 'checked' : '' ?>>
                        Active
                    </label>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Tenant Access</h3>
            </div>
            <div class="card-body">
                <p style="color: var(--color-text-secondary); margin-bottom: 16px; font-size: 13px;">
                    Assign this user to tenants and set their role within each tenant.
                </p>
                
                <div class="tenant-assignments">
                    <?php foreach ($allTenants as $t): 
                        $isAssigned = isset($userTenantMap[$t['id']]);
                        $currentRole = $userTenantMap[$t['id']]['role'] ?? 'employee';
                        $isDefault = $userTenantMap[$t['id']]['is_default'] ?? 0;
                    ?>
                    <div class="tenant-row <?= $isAssigned ? 'assigned' : '' ?>">
                        <label class="tenant-checkbox">
                            <input type="checkbox" 
                                   name="tenant[<?= $t['id'] ?>][enabled]" 
                                   value="1"
                                   <?= $isAssigned ? 'checked' : '' ?>
                                   onchange="this.closest('.tenant-row').classList.toggle('assigned', this.checked)">
                            <span>
                                <?= htmlspecialchars($t['name']) ?>
                                <?php if ($t['parent_id']): ?>
                                    <small style="color: var(--color-text-muted);">(sub-tenant)</small>
                                <?php endif; ?>
                            </span>
                        </label>
                        <div class="tenant-options" style="<?= $isAssigned ? '' : 'opacity: 0.5;' ?>">
                            <select name="tenant[<?= $t['id'] ?>][role]" class="form-select" style="font-size: 12px; padding: 4px 8px; width: auto;">
                                <?php foreach ($tenantRoles as $trKey => $trLabel): ?>
                                <option value="<?= $trKey ?>" <?= ($currentRole === $trKey) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($trLabel) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <label style="font-size: 12px; white-space: nowrap;">
                                <input type="checkbox" name="tenant[<?= $t['id'] ?>][default]" value="1" <?= $isDefault ? 'checked' : '' ?>>
                                Default
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 12px;">
        <a href="?admin=1&page=users" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create User' : 'Save Changes' ?></button>
    </div>
</form>

<style>
.tenant-assignments {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.tenant-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    border: 1px solid var(--color-border);
    border-radius: 6px;
    transition: all 0.15s ease;
}

.tenant-row.assigned {
    border-color: var(--color-success);
    background: rgba(16, 185, 129, 0.05);
}

.tenant-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 14px;
}

.tenant-options {
    display: flex;
    align-items: center;
    gap: 12px;
    transition: opacity 0.15s ease;
}

.tenant-row.assigned .tenant-options {
    opacity: 1 !important;
}
</style>
