<?php
include '../partials/header.php';

$moduleTogglesFile = '../data/module_toggles.json';
$moduleToggles = file_exists($moduleTogglesFile) ? json_decode(file_get_contents($moduleTogglesFile), true) : [];
$availableModules = [
    "People" => ["Custom Fields", "Timesheet Approvals", "Sub-Tenant Support"],
    "Finance" => ["Invoicing", "Forecasting", "Budgeting"],
    "Wealth" => ["Advisor Dashboard", "Investor Memos", "Scenario Modeling"],
    "Tax" => ["Return Prep", "E-Filing Integration", "ERO Sync"],
    "Accounting" => ["General Ledger", "AP/AR", "Bank Reconciliation"]
];
?>

<div class="main-content">
    <h2>Module & Feature Toggles</h2>
    <form method="post" action="save_module_toggles.php">
        <?php foreach ($availableModules as $module => $features): ?>
            <div class="module-toggle-group">
                <h3>
                    <label>
                        <input type="checkbox" name="modules[<?= $module ?>][enabled]" <?= !empty($moduleToggles[$module]['enabled']) ? 'checked' : '' ?>>
                        <?= $module ?>
                    </label>
                </h3>
                <ul>
                    <?php foreach ($features as $feature): ?>
                        <li>
                            <label>
                                <input type="checkbox"
                                    name="modules[<?= $module ?>][features][<?= $feature ?>]"
                                    <?= !empty($moduleToggles[$module]['features'][$feature]) ? 'checked' : '' ?>>
                                <?= $feature ?>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
        <button type="submit" class="btn-save">Save Toggles</button>
    </form>
</div>

<?php include '../partials/footer.php'; ?>
