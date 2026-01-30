<?php
session_start();
require_once('../core/db_config.php');

// Fetch current settings from DB
$stmt = $pdo->query("SELECT * FROM admin_design_modes");
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['page_class']] = [
        'mode' => $row['design_mode'],
        'hero' => $row['show_hero']
    ];
}
?>

<?php include('../partials/header.php'); ?>

<div class="main-panel">
    <h2>Design Mode & Hero Settings</h2>
    <form action="save_design_modes.php" method="post">
        <?php
        $page_classes = ['home', 'module_landing', 'module_dashboard', 'internal_page'];
        $modes = ['abstract', 'swirl', 'white', 'block'];
        foreach ($page_classes as $class):
            $current_mode = $settings[$class]['mode'] ?? 'abstract';
            $current_hero = $settings[$class]['hero'] ?? 1;
        ?>
        <div class="setting-block">
            <h3><?= ucfirst(str_replace('_', ' ', $class)) ?></h3>
            <label>Design Mode:</label>
            <select name="design_mode[<?= $class ?>]">
                <?php foreach ($modes as $mode): ?>
                    <option value="<?= $mode ?>" <?= $mode === $current_mode ? 'selected' : '' ?>><?= ucfirst($mode) ?></option>
                <?php endforeach; ?>
            </select>
            <label>
                <input type="checkbox" name="show_hero[<?= $class ?>]" value="1" <?= $current_hero ? 'checked' : '' ?>>
                Show Hero Illustration
            </label>
        </div>
        <hr>
        <?php endforeach; ?>
        <button type="submit" class="btn-primary">Save Design Settings</button>
    </form>
</div>

<?php include('../partials/footer.php'); ?>
