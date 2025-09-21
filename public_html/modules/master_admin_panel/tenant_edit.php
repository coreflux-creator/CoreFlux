<?php
include('partials/header.php');

// Mock data – in actual use, you'd fetch tenant info from the DB based on `$_GET['id']`
$tenant = [
  'name' => 'BrightPath Group',
  'subdomain' => 'brightpath',
  'status' => 'active',
  'modules' => ['People', 'Finance'],
  'design_mode' => 'swirl',
  'hero_enabled' => true,
  'reply_to_email' => 'admin@brightpath.com',
  'logo_path' => '/assets/logos/brightpath.png'
];

$allModules = ['People', 'Finance', 'Accounting', 'Wealth Management', 'Tax'];
$designModes = ['abstract', 'swirl', 'white', 'block'];
?>

<div class="main-content">
  <h1>Edit Tenant: <?= htmlspecialchars($tenant['name']) ?></h1>

  <form action="tenant_update.php" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="tenant_id" value="1">

    <label for="tenant_name">Tenant Name</label>
    <input type="text" id="tenant_name" name="tenant_name" value="<?= htmlspecialchars($tenant['name']) ?>" required>

    <label for="subdomain">Subdomain</label>
    <input type="text" id="subdomain" name="subdomain" value="<?= htmlspecialchars($tenant['subdomain']) ?>" required>

    <label for="status">Status</label>
    <select name="status" id="status">
      <option value="active" <?= $tenant['status'] === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="disabled" <?= $tenant['status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option>
    </select>

    <label for="modules">Enabled Modules</label>
    <div class="checkbox-group">
      <?php foreach ($allModules as $module): ?>
        <label>
          <input type="checkbox" name="modules[]" value="<?= $module ?>" <?= in_array($module, $tenant['modules']) ? 'checked' : '' ?>>
          <?= $module ?>
        </label>
      <?php endforeach; ?>
    </div>

    <label for="design_mode">Design Mode</label>
    <select name="design_mode" id="design_mode">
      <?php foreach ($designModes as $mode): ?>
        <option value="<?= $mode ?>" <?= $tenant['design_mode'] === $mode ? 'selected' : '' ?>><?= ucfirst($mode) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="hero_enabled">
      <input type="checkbox" id="hero_enabled" name="hero_enabled" <?= $tenant['hero_enabled'] ? 'checked' : '' ?>>
      Enable Hero Illustration
    </label>

    <label for="reply_to_email">Reply-To Email</label>
    <input type="email" name="reply_to_email" id="reply_to_email" value="<?= htmlspecialchars($tenant['reply_to_email']) ?>">

    <label for="logo">Tenant Logo</label>
    <input type="file" name="logo" id="logo" accept="image/*">
    <p><img src="<?= $tenant['logo_path'] ?>" alt="Current Logo" class="preview-logo"></p>

    <button type="submit" class="button primary">Save Changes</button>
  </form>
</div>

<?php
include('partials/footer.php');
?>
