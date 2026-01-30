<?php
session_start();
require_once('../core/db_config.php');

// Fetch current branding settings
$stmt = $pdo->query("SELECT * FROM admin_branding_settings LIMIT 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<?php include('../partials/header.php'); ?>

<div class="main-panel">
    <h2>Branding & Email Settings</h2>

    <form action="save_branding_settings.php" method="post" enctype="multipart/form-data">
        <div class="setting-block">
            <label>Tenant Logo:</label><br>
            <?php if (!empty($settings['tenant_logo'])): ?>
                <img src="../assets/logos/<?= htmlspecialchars($settings['tenant_logo']) ?>" height="80" />
            <?php endif; ?>
            <input type="file" name="tenant_logo" accept="image/*">
        </div>

        <div class="setting-block">
            <label>Email From Name:</label>
            <input type="text" name="email_from_name" value="<?= htmlspecialchars($settings['email_from_name']) ?>" required>

            <label>Email From Address:</label>
            <input type="email" name="email_from" value="<?= htmlspecialchars($settings['email_from']) ?>" required>

            <label>Reply-To Address:</label>
            <input type="email" name="reply_to" value="<?= htmlspecialchars($settings['reply_to']) ?>" required>
        </div>

        <div class="setting-block">
            <label>Primary Branding Color:</label>
            <input type="color" name="primary_color" value="<?= htmlspecialchars($settings['primary_color']) ?>">

            <label>Light or Dark Mode:</label>
            <select name="color_mode">
                <option value="light" <?= $settings['color_mode'] === 'light' ? 'selected' : '' ?>>Light</option>
                <option value="dark" <?= $settings['color_mode'] === 'dark' ? 'selected' : '' ?>>Dark</option>
            </select>
        </div>

        <button type="submit" class="btn-primary">Save Branding Settings</button>
    </form>
</div>

<?php include('../partials/footer.php'); ?>
