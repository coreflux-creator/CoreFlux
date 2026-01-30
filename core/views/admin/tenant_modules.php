<?php
/**
 * Master Admin - Tenant Module Configuration
 */
$pdo = getDB();

$tenantId = (int)($_GET['id'] ?? 0);
if (!$tenantId) {
    header("Location: ?admin=1&page=tenants");
    exit;
}

// Get tenant info
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant) {
    header("Location: ?admin=1&page=tenants");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enabledModules = $_POST['modules'] ?? [];
    
    // Get all modules
    $allModules = $pdo->query("SELECT id, name FROM modules WHERE is_active = 1")->fetchAll();
    
    foreach ($allModules as $mod) {
        $moduleKey = strtolower(str_replace(' ', '_', $mod['name']));
        $isEnabled = in_array($moduleKey, $enabledModules) ? 1 : 0;
        
        // Check if record exists
        $stmt = $pdo->prepare("SELECT id FROM tenant_modules WHERE tenant_id = ? AND module_key = ?");
        $stmt->execute([$tenantId, $moduleKey]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update
            $stmt = $pdo->prepare("UPDATE tenant_modules SET is_enabled = ?, enabled_at = IF(? = 1, NOW(), enabled_at), disabled_at = IF(? = 0, NOW(), disabled_at) WHERE id = ?");
            $stmt->execute([$isEnabled, $isEnabled, $isEnabled, $existing['id']]);
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO tenant_modules (tenant_id, module_key, is_enabled, enabled_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$tenantId, $moduleKey, $isEnabled]);
        }
    }
    
    $successMessage = "Module configuration saved successfully.";
}

// Get all active modules
$allModules = $pdo->query("SELECT m.*, am.description FROM modules m LEFT JOIN admin_modules am ON LOWER(am.name) = LOWER(m.name) WHERE m.is_active = 1 ORDER BY m.id")->fetchAll();

// Get tenant's current module subscriptions
$stmt = $pdo->prepare("SELECT module_key, is_enabled FROM tenant_modules WHERE tenant_id = ?");
$stmt->execute([$tenantId]);
$tenantModules = [];
while ($row = $stmt->fetch()) {
    $tenantModules[$row['module_key']] = $row['is_enabled'];
}

// If no tenant_modules records exist, default to all enabled
$hasConfig = !empty($tenantModules);
?>

<div class="page-header">
    <div style="display: flex; align-items: center; gap: 12px;">
        <a href="?admin=1&page=tenants" class="btn btn-secondary" style="padding: 6px 12px;">← Back</a>
        <div>
            <h1 class="page-title">Module Configuration</h1>
            <p class="page-subtitle">Configure modules for <strong><?= htmlspecialchars($tenant['name']) ?></strong></p>
        </div>
    </div>
</div>

<?php if (!empty($successMessage)): ?>
<div class="alert alert-success" style="background: rgba(16, 185, 129, 0.1); border: 1px solid var(--color-success); color: var(--color-success); padding: 12px 16px; border-radius: 6px; margin-bottom: 24px;">
    <?= htmlspecialchars($successMessage) ?>
</div>
<?php endif; ?>

<?php if (!$hasConfig): ?>
<div class="alert alert-info" style="background: rgba(59, 130, 246, 0.1); border: 1px solid var(--color-info); color: var(--color-info); padding: 12px 16px; border-radius: 6px; margin-bottom: 24px;">
    <strong>Note:</strong> This tenant has no module configuration yet. All modules are currently accessible by default. Save to set explicit permissions.
</div>
<?php endif; ?>

<form method="POST">
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 class="card-title">Available Modules</h3>
            <div>
                <button type="button" onclick="toggleAll(true)" class="btn btn-secondary" style="font-size: 12px; padding: 4px 12px;">Enable All</button>
                <button type="button" onclick="toggleAll(false)" class="btn btn-secondary" style="font-size: 12px; padding: 4px 12px;">Disable All</button>
            </div>
        </div>
        <div class="card-body">
            <div class="module-grid">
                <?php foreach ($allModules as $mod): 
                    $moduleKey = strtolower(str_replace(' ', '_', $mod['name']));
                    $isEnabled = $hasConfig ? ($tenantModules[$moduleKey] ?? 0) : 1;
                ?>
                <div class="module-card <?= $isEnabled ? 'enabled' : 'disabled' ?>">
                    <label class="module-toggle">
                        <input type="checkbox" 
                               name="modules[]" 
                               value="<?= htmlspecialchars($moduleKey) ?>"
                               <?= $isEnabled ? 'checked' : '' ?>
                               onchange="this.closest('.module-card').classList.toggle('enabled', this.checked); this.closest('.module-card').classList.toggle('disabled', !this.checked);">
                        <span class="toggle-switch"></span>
                    </label>
                    <div class="module-info">
                        <img src="/assets/icons/icon-<?= htmlspecialchars($moduleKey) ?>.png" alt="" class="module-icon" onerror="this.style.display='none'">
                        <div>
                            <h4><?= htmlspecialchars($mod['name']) ?></h4>
                            <p><?= htmlspecialchars($mod['description'] ?? 'No description') ?></p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card-footer" style="padding: 16px 24px; border-top: 1px solid var(--color-border-light); display: flex; justify-content: flex-end; gap: 12px;">
            <a href="?admin=1&page=tenants" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Configuration</button>
        </div>
    </div>
</form>

<style>
.module-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
}

.module-card {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 16px;
    border: 2px solid var(--color-border);
    border-radius: 8px;
    transition: all 0.2s ease;
}

.module-card.enabled {
    border-color: var(--color-success);
    background: rgba(16, 185, 129, 0.05);
}

.module-card.disabled {
    border-color: var(--color-border);
    background: var(--color-bg);
    opacity: 0.7;
}

.module-toggle {
    position: relative;
    cursor: pointer;
    flex-shrink: 0;
}

.module-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
    position: absolute;
}

.toggle-switch {
    display: block;
    width: 48px;
    height: 26px;
    background: var(--color-border);
    border-radius: 13px;
    position: relative;
    transition: background 0.2s ease;
}

.toggle-switch::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 20px;
    height: 20px;
    background: white;
    border-radius: 50%;
    transition: transform 0.2s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.module-toggle input:checked + .toggle-switch {
    background: var(--color-success);
}

.module-toggle input:checked + .toggle-switch::after {
    transform: translateX(22px);
}

.module-info {
    display: flex;
    gap: 12px;
    align-items: flex-start;
}

.module-icon {
    width: 40px;
    height: 40px;
    flex-shrink: 0;
}

.module-info h4 {
    font-size: 15px;
    font-weight: 600;
    margin: 0 0 4px 0;
    color: var(--color-text);
}

.module-info p {
    font-size: 13px;
    color: var(--color-text-secondary);
    margin: 0;
    line-height: 1.4;
}
</style>

<script>
function toggleAll(enable) {
    document.querySelectorAll('.module-card input[type="checkbox"]').forEach(cb => {
        cb.checked = enable;
        cb.closest('.module-card').classList.toggle('enabled', enable);
        cb.closest('.module-card').classList.toggle('disabled', !enable);
    });
}
</script>
