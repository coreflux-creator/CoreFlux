<?php include('includes/header.php');

$infraFile = 'data/global_infrastructure.json';
$infra = file_exists($infraFile) ? json_decode(file_get_contents($infraFile), true) : [
  'default_region' => 'us-east-1',
  'failover_enabled' => true,
  'api_key_rotation_days' => 90,
  'enforce_mfa' => true,
  'allow_export' => true
];
?>

<div class="admin-container">
  <h2>Global Infrastructure & Security</h2>

  <form action="save_infrastructure.php" method="POST" class="form-grid">
    <label>Default Data Region:</label>
    <select name="default_region" required>
      <?php foreach (['us-east-1', 'us-west-2', 'eu-central-1', 'ap-southeast-1'] as $region): ?>
        <option value="<?= $region ?>" <?= $infra['default_region'] === $region ? 'selected' : '' ?>>
          <?= strtoupper($region) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label>Failover Enabled:</label>
    <select name="failover_enabled">
      <option value="1" <?= $infra['failover_enabled'] ? 'selected' : '' ?>>Yes</option>
      <option value="0" <?= !$infra['failover_enabled'] ? 'selected' : '' ?>>No</option>
    </select>

    <label>API Key Rotation (days):</label>
    <input type="number" name="api_key_rotation_days" min="30" value="<?= $infra['api_key_rotation_days'] ?>">

    <label>Enforce MFA Globally:</label>
    <select name="enforce_mfa">
      <option value="1" <?= $infra['enforce_mfa'] ? 'selected' : '' ?>>Yes</option>
      <option value="0" <?= !$infra['enforce_mfa'] ? 'selected' : '' ?>>No</option>
    </select>

    <label>Allow Data Export:</label>
    <select name="allow_export">
      <option value="1" <?= $infra['allow_export'] ? 'selected' : '' ?>>Yes</option>
      <option value="0" <?= !$infra['allow_export'] ? 'selected' : '' ?>>No</option>
    </select>

    <button type="submit" class="btn-save">Save Settings</button>
  </form>
</div>

<?php include('includes/footer.php'); ?>
