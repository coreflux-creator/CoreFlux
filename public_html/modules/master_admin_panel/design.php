<?php include('includes/header.php'); ?>

<div class="admin-container">
  <h2>Design Mode Settings</h2>
  <form action="save_design.php" method="POST" class="form-grid">

    <label>Select Tenant:</label>
    <select name="tenant_id" id="tenant_id" required>
      <?php
      $tenants = json_decode(file_get_contents('tenants.json'), true);
      foreach ($tenants as $id => $tenant) {
        echo "<option value=\"$id\">{$tenant['name']}</option>";
      }
      ?>
    </select>

    <fieldset>
      <legend>Select Design Mode per Page Type:</legend>
      <?php
      $page_types = [
        'home' => 'Home Page',
        'module_landing' => 'Module Landing Pages',
        'dashboard' => 'Post-login Dashboards',
        'internal' => 'Other Module Pages'
      ];
      $modes = ['abstract', 'swirl', 'white', 'block'];

      foreach ($page_types as $type => $label) {
        echo "<label>$label:</label><select name='design[$type]'>";
        foreach ($modes as $mode) {
          echo "<option value='$mode'>$mode</option>";
        }
        echo "</select><br>";
      }
      ?>
    </fieldset>

    <label>
      <input type="checkbox" name="show_hero" value="1"> Show Hero Icon Illustrations
    </label>

    <button type="submit" class="btn-save">Save Design Settings</button>
  </form>
</div>

<?php include('includes/footer.php'); ?>
