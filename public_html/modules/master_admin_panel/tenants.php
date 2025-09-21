<?php
include('partials/header.php');
?>

<div class="main-content">
  <h1>Manage Tenants</h1>
  <table class="styled-table">
    <thead>
      <tr>
        <th>Tenant Name</th>
        <th>Subdomain</th>
        <th>Status</th>
        <th>Modules</th>
        <th>Design Mode</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <!-- Example row - replace with database logic -->
      <tr>
        <td>BrightPath Group</td>
        <td>brightpath.corefluxapp.com</td>
        <td><span class="status-active">Active</span></td>
        <td>People, Finance</td>
        <td>Swirl</td>
        <td>
          <a href="tenant_edit.php?id=1" class="button small">Edit</a>
          <a href="#" class="button small danger">Disable</a>
        </td>
      </tr>
      <tr>
        <td>Northstar Advisors</td>
        <td>northstar.corefluxapp.com</td>
        <td><span class="status-disabled">Disabled</span></td>
        <td>People</td>
        <td>White</td>
        <td>
          <a href="tenant_edit.php?id=2" class="button small">Edit</a>
          <a href="#" class="button small success">Enable</a>
        </td>
      </tr>
      <!-- End example -->
    </tbody>
  </table>
</div>

<?php
include('partials/footer.php');
?>
