<?php
session_start();
require_once('../core/db_config.php');

$modules = $pdo->query("SELECT * FROM admin_modules")->fetchAll(PDO::FETCH_ASSOC);
$features = $pdo->query("SELECT * FROM admin_module_features")->fetchAll(PDO::FETCH_ASSOC);

$features_by_module = [];
foreach ($features as $feature) {
    $features_by_module[$feature['module_id']][] = $feature;
}
?>

<?php include('../partials/header.php'); ?>

<div class="main-panel">
    <h2>Module Access & Feature Toggles</h2>

    <form action="save_module_toggles.php" method="post">
        <?php foreach ($modules as $module): ?>
            <div class="module-toggle-block">
                <label>
                    <input type="checkbox" name="modules[<?= $module['id'] ?>]" value="1" <?= $module['is_active'] ? 'checked' : '' ?>>
                    <strong><?= htmlspecialchars($module['name']) ?></strong>
                </label>
                <p><?= htmlspecialchars($module['description']) ?></p>

                <?php if (!empty($features_by_module[$module['id']])): ?>
                    <div class="feature-subtoggles">
                        <?php foreach ($features_by_module[$module['id']] as $feature): ?>
                            <label>
                                <input type="checkbox"
                                       name="features[<?= $feature['id'] ?>]"
                                       value="1"
                                       <?= $feature['is_enabled'] ? 'checked' : '' ?>>
                                <?= htmlspecialchars($feature['feature_name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <button type="submit" class="btn-primary">Save Settings</button>
    </form>
</div>

<?php include('../partials/footer.php'); ?>
