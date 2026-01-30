<?php include '../partials/layout_start.php'; ?>

<section class="dashboard-welcome">
  <h1>Import Employees (CSV)</h1>
  <p>Upload a CSV file with: Name, Email, Role (employee/approver/tenant_user)</p>
</section>

<section class="dashboard-grid">
  <div class="card" style="grid-column: span 2;">
    <form method="POST" enctype="multipart/form-data" action="process_import.php">
      <input type="file" name="csv_file" accept=".csv" required />
      <button type="submit" class="button" style="margin-top: 1rem;">Upload</button>
    </form>
  </div>
</section>

<?php include '../partials/layout_end.php'; ?>
