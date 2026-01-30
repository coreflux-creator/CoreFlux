<?php
/**
 * Master Admin - Edit Tenant
 */
$pdo = getDB();

$tenantId = (int)($_GET['id'] ?? 0);
$isNew = ($tenantId === 0);

if (!$isNew) {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();
    
    if (!$tenant) {
        header("Location: ?admin=1&page=tenants");
        exit;
    }
} else {
    $tenant = [
        'name' => '',
        'slug' => '',
        'domain' => '',
        'subdomain' => '',
        'parent_id' => null,
        'landing_enabled' => 1,
        'logo_url' => '',
        'primary_color' => '',
        'hero_title' => '',
        'hero_subtitle' => '',
        'login_cta' => '',
    ];
}

// Get potential parent tenants (those without a parent)
$parentTenants = $pdo->query("SELECT id, name FROM tenants WHERE parent_id IS NULL ORDER BY name")->fetchAll();

// Handle form submission
$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $domain = trim($_POST['domain'] ?? '');
    $subdomain = trim($_POST['subdomain'] ?? '');
    $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    $landingEnabled = isset($_POST['landing_enabled']) ? 1 : 0;
    $logoUrl = trim($_POST['logo_url'] ?? '');
    $primaryColor = trim($_POST['primary_color'] ?? '');
    $heroTitle = trim($_POST['hero_title'] ?? '');
    $heroSubtitle = trim($_POST['hero_subtitle'] ?? '');
    $loginCta = trim($_POST['login_cta'] ?? '');
    
    // Validation
    if (empty($name)) {
        $errors[] = "Tenant name is required.";
    }
    
    // Check for duplicate slug
    if (!empty($slug)) {
        $stmt = $pdo->prepare("SELECT id FROM tenants WHERE slug = ? AND id != ?");
        $stmt->execute([$slug, $tenantId]);
        if ($stmt->fetch()) {
            $errors[] = "This slug is already in use.";
        }
    }
    
    if (empty($errors)) {
        if ($isNew) {
            $stmt = $pdo->prepare("INSERT INTO tenants (name, slug, domain, subdomain, parent_id, landing_enabled, logo_url, primary_color, hero_title, hero_subtitle, login_cta, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$name, $slug, $domain, $subdomain, $parentId, $landingEnabled, $logoUrl, $primaryColor, $heroTitle, $heroSubtitle, $loginCta]);
            $tenantId = $pdo->lastInsertId();
            header("Location: ?admin=1&page=tenant_edit&id={$tenantId}&saved=1");
            exit;
        } else {
            $stmt = $pdo->prepare("UPDATE tenants SET name = ?, slug = ?, domain = ?, subdomain = ?, parent_id = ?, landing_enabled = ?, logo_url = ?, primary_color = ?, hero_title = ?, hero_subtitle = ?, login_cta = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$name, $slug, $domain, $subdomain, $parentId, $landingEnabled, $logoUrl, $primaryColor, $heroTitle, $heroSubtitle, $loginCta, $tenantId]);
            $successMessage = "Tenant updated successfully.";
            
            // Refresh tenant data
            $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
            $stmt->execute([$tenantId]);
            $tenant = $stmt->fetch();
        }
    }
}

if (isset($_GET['saved'])) {
    $successMessage = "Tenant created successfully.";
}
?>

<div class="page-header">
    <div style="display: flex; align-items: center; gap: 12px;">
        <a href="?admin=1&page=tenants" class="btn btn-secondary" style="padding: 6px 12px;">← Back</a>
        <div>
            <h1 class="page-title"><?= $isNew ? 'Create Tenant' : 'Edit Tenant' ?></h1>
            <?php if (!$isNew): ?>
            <p class="page-subtitle"><?= htmlspecialchars($tenant['name']) ?></p>
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
                <h3 class="card-title">Basic Information</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Tenant Name *</label>
                    <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($tenant['name']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Slug</label>
                    <input type="text" name="slug" class="form-input" value="<?= htmlspecialchars($tenant['slug'] ?? '') ?>" placeholder="e.g., acme-corp">
                    <small style="color: var(--color-text-secondary);">URL-friendly identifier</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Domain</label>
                    <input type="text" name="domain" class="form-input" value="<?= htmlspecialchars($tenant['domain'] ?? '') ?>" placeholder="e.g., acme.com">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Subdomain</label>
                    <input type="text" name="subdomain" class="form-input" value="<?= htmlspecialchars($tenant['subdomain'] ?? '') ?>" placeholder="e.g., acme">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Parent Tenant</label>
                    <select name="parent_id" class="form-select">
                        <option value="">None (Primary Tenant)</option>
                        <?php foreach ($parentTenants as $pt): ?>
                            <?php if ($pt['id'] != $tenantId): ?>
                            <option value="<?= $pt['id'] ?>" <?= ($tenant['parent_id'] == $pt['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pt['name']) ?>
                            </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: var(--color-text-secondary);">Select if this is a sub-tenant</small>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Branding</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Logo URL</label>
                    <input type="text" name="logo_url" class="form-input" value="<?= htmlspecialchars($tenant['logo_url'] ?? '') ?>" placeholder="https://...">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Primary Color</label>
                    <input type="text" name="primary_color" class="form-input" value="<?= htmlspecialchars($tenant['primary_color'] ?? '') ?>" placeholder="#002c70">
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" name="landing_enabled" <?= ($tenant['landing_enabled'] ?? 1) ? 'checked' : '' ?>>
                        Enable Landing Page
                    </label>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Hero Title</label>
                    <input type="text" name="hero_title" class="form-input" value="<?= htmlspecialchars($tenant['hero_title'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Hero Subtitle</label>
                    <input type="text" name="hero_subtitle" class="form-input" value="<?= htmlspecialchars($tenant['hero_subtitle'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Login CTA</label>
                    <input type="text" name="login_cta" class="form-input" value="<?= htmlspecialchars($tenant['login_cta'] ?? '') ?>" placeholder="Sign In">
                </div>
            </div>
        </div>
    </div>
    
    <div style="margin-top: 24px; display: flex; justify-content: space-between;">
        <div>
            <?php if (!$isNew): ?>
            <a href="?admin=1&page=tenant_modules&id=<?= $tenantId ?>" class="btn btn-secondary">Configure Modules</a>
            <?php endif; ?>
        </div>
        <div style="display: flex; gap: 12px;">
            <a href="?admin=1&page=tenants" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create Tenant' : 'Save Changes' ?></button>
        </div>
    </div>
</form>
