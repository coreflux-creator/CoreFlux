<h2>Your Timesheets</h2>
<table border="1" cellpadding="8" cellspacing="0" style="margin: 2rem auto; width: 80%;">
  <thead>
    <tr>
      <th>Date</th>
      <th>Hours</th>
      <th>Description</th>
    </tr>
  </thead>
  <tbody>
    <?php
    // Dummy static rows – replace with database query later
    $entries = [
      ['2024-08-01', 8, 'Worked on feature implementation.'],
      ['2024-08-02', 6.5, 'Bug fixing and testing.']
    ];
    foreach ($entries as [$date, $hours, $desc]):
    ?>
      <tr>
        <td><?= htmlspecialchars($date) ?></td>
        <td><?= htmlspecialchars($hours) ?></td>
        <td><?= htmlspecialchars($desc) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
