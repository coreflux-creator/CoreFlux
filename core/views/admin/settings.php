<?php
/**
 * Master Admin - Global Settings
 */
$pdo = getDB();
$settings = $pdo->query("SELECT * FROM admin_global_settings LIMIT 1")->fetch();
?>

<div class="page-header">
    <h1 class="page-title">Global Settings</h1>
    <p class="page-subtitle">Platform-wide configuration</p>
</div>

<div class="grid grid-cols-2">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Security</h3>
        </div>
        <div class="card-body">
            <form>
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" <?= ($settings['force_https'] ?? 0) ? 'checked' : '' ?>>
                        Force HTTPS
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" <?= ($settings['rate_limiting'] ?? 0) ? 'checked' : '' ?>>
                        Rate Limiting
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label">Login Attempt Limit</label>
                    <input type="number" class="form-input" value="<?= $settings['login_attempt_limit'] ?? 5 ?>" style="width: 100px;">
                </div>
                <div class="form-group">
                    <label class="form-label">Max Sessions per User</label>
                    <input type="number" class="form-input" value="<?= $settings['max_sessions'] ?? 5 ?>" style="width: 100px;">
                </div>
                <button type="button" class="btn btn-primary">Save Security Settings</button>
            </form>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Maintenance</h3>
        </div>
        <div class="card-body">
            <form>
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" <?= ($settings['maintenance_mode'] ?? 0) ? 'checked' : '' ?>>
                        Maintenance Mode
                    </label>
                    <small style="display: block; color: var(--color-text-secondary); margin-top: 4px;">
                        When enabled, only master admins can access the platform
                    </small>
                </div>
                <div class="form-group">
                    <label class="form-label">Allowed Admin IPs (maintenance mode)</label>
                    <textarea class="form-input" rows="3" placeholder="One IP per line"><?= $settings['allowed_admin_ips'] ?? '' ?></textarea>
                </div>
                <button type="button" class="btn btn-primary">Save Maintenance Settings</button>
            </form>
        </div>
    </div>
</div>
