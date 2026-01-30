<h2>Generate Reports</h2>
<p>Choose a date range to view a time summary report.</p>

<form method="GET" style="max-width: 400px; margin: 2rem auto;">
  <label>Start Date:</label>
  <input type="date" name="from" required><br><br>

  <label>End Date:</label>
  <input type="date" name="to" required><br><br>

  <button type="submit">Generate</button>
</form>

<?php if (!empty($_GET['from']) && !empty($_GET['to'])): ?>
  <h3>Summary from <?= htmlspecialchars($_GET['from']) ?> to <?= htmlspecialchars($_GET['to']) ?></h3>
  <ul>
    <li>Total Hours: 42.5</li>
    <li>Total Entries: 5</li>
    <li>Most active day: 2024-08-01</li>
  </ul>
<?php endif; ?>
