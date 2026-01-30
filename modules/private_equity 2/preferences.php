<?php
session_start();
require_once('../core/db_config.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST as $key => $value) {
        $stmt = $pdo->prepare("INSERT INTO system_preferences (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$key, $value]);
    }
    header("Location: preferences.php?saved=1");
    exit;
}

// Load current preferences
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_preferences")->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<?php include('../partials/header.php'); ?>

<div class="main-panel">
    <h2>System Preferences</h2>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert-success">Settings saved successfully.</div>
    <?php endif; ?>

    <form method="post" class="form-grid">
        <label>Default Timezone
            <input type="text" name="default_timezone" value="<?= htmlspecialchars($settings['default_timezone'] ?? 'UTC') ?>">
        </label>

        <label>Fiscal Year Starts (MM-DD)
            <input type="text" name="fiscal_year_start" value="<?= htmlspecialchars($settings['fiscal_year_start'] ?? '01-01') ?>">
        </label>

        <label>Default Design Mode
            <select name="default_design_mode">
                <option value="abstract" <?= ($settings['default_design_mode'] ?? '') === 'abstract' ? 'selected' : '' ?>>Abstract</option>
                <option value="swirl" <?= ($settings['default_design_mode'] ?? '') === 'swirl' ? 'selected' : '' ?>>Swirl Logo</option>
                <option value="white" <?= ($settings['default_design_mode'] ?? '') === 'white' ? 'selected' : '' ?>>White</option>
                <option value="block" <?= ($settings['default_design_mode'] ?? '') === 'block' ? 'selected' : '' ?>>Block</option>
            </select>
        </label>

        <label>Enable Beta Features
            <select name="enable_beta">
                <option value="1" <?= ($settings['enable_beta'] ?? '') == '1' ? 'selected' : '' ?>>Yes</option>
                <option value="0" <?= ($settings['enable_beta'] ?? '') == '0' ? 'selected' : '' ?>>No</option>
            </select>
        </label>

        <label>Maintenance Mode
            <select name="maintenance_mode">
                <option value="1" <?= ($settings['maintenance_mode'] ?? '') == '1' ? 'selected' : '' ?>>Enabled</option>
                <option value="0" <?= ($settings['maintenance_mode'] ?? '') == '0' ? 'selected' : '' ?>>Disabled</option>
            </select>
        </label>

        <button type="submit" class="btn-primary">Save Settings</button>
    </form>
</div>

<?php include('../partials/footer.php'); ?>
